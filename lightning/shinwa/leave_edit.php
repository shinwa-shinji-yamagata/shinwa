<?php
if (!defined('ABSPATH')) exit;

require_once get_template_directory() . '/shinwa/leave_common.php';

global $wpdb;
$table = swl_get_table_name();
$current_user = wp_get_current_user();
if (!$current_user || 0 === $current_user->ID) {
    echo '<p>ログインが必要です。</p>';
    return;
}

$today  = current_time('Y-m-d');
$now_dt = current_time('mysql');
$holidays = swl_get_holidays();

// --------------------------------------------------
// 1. 編集対象の取得
// --------------------------------------------------
if (!isset($_GET['id'])) {
    echo '<p>対象データがありません。</p>';
    return;
}

$edit_id = intval($_GET['id']);
$edit_row = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $edit_id)
);

if (!$edit_row) {
    echo '<p>対象データが存在しません。</p>';
    return;
}

// thread_id の最新レコードを取得
$thread_id = $edit_row->thread_id;
$latest = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE thread_id = %d ORDER BY id DESC LIMIT 1", $thread_id)
);

// --------------------------------------------------
// 2. 権限チェック
// --------------------------------------------------
$department = $edit_row->department;
$name       = $edit_row->name;
$is_soumu   = in_array('soumu', $current_user->roles, true) || in_array('総務', $current_user->roles, true);

$end_ts   = strtotime($latest->end_date);
$today_ts = strtotime($today);

$is_future = ($end_ts > $today_ts);
$is_today  = ($end_ts === $today_ts);
$is_past   = ($end_ts < $today_ts);

$is_owner = ($latest->user_id == $current_user->ID);

// -----------------------------
// 最新行のみ編集可能（変更済は不可）
// -----------------------------
if ($latest->deleted_at !== null) {
    echo '<p>取消済の申請は変更できません。</p>';
    return;
}

// -----------------------------
// ■ 過去日（総務は全員OK）
// -----------------------------
if ($is_past) {
    if ($is_soumu) {
        // OK
    } else {
        echo '<p>過去日の申請は変更できません。</p>';
        return;
    }
}

// -----------------------------
// ■ 当日（総務は全員OK）
// -----------------------------
if ($is_today) {
    if ($is_soumu) {
        // OK
    } else {
        echo '<p>当日の申請は変更できません。</p>';
        return;
    }
}

// -----------------------------
// ■ 未来日（本人のみOK）
// -----------------------------
if ($is_future) {
    if ($is_owner) {
        // OK（総務本人も含む）
    } else {
        echo '<p>未来日の申請は本人のみ変更できます。</p>';
        return;
    }
}

// --------------------------------------------------
// 3. POST処理
// --------------------------------------------------
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['swl_action']) && $_POST['swl_action'] === 'edit_leave') {

    $type       = sanitize_text_field($_POST['type']);
    $start_date = sanitize_text_field($_POST['start_date']);
    $end_date   = sanitize_text_field($_POST['end_date']);
    $am = ($_POST['am'] ?? '') === 'am';
    $pm = ($_POST['pm'] ?? '') === 'pm';
    if ($am && $pm) {
        $error = '午前と午後の両方は選択できません。';
    }
    $half = $am ? 'am' : ($pm ? 'pm' : '');
    $is_half = ($half !== '');
    $reason = trim(sanitize_textarea_field($_POST['reason']));

    // バリデーション（新規と同じ）
    if (!$type) $error = '種別を選択してください。';
    if (!$error && !$start_date && !$is_half) $error = '期日または半休を指定してください。';

    if (!$error && $is_half && $start_date !== $end_date) {
        $error = '半休の場合は複数日を選択できません。1日間で指定してください。';
    }

    $days = 0;
    if (!$error && $start_date && $end_date) {
        $days = swl_calc_days_business($start_date, $end_date, $department, $is_half);
        if ($days <= 0) $error = '期日の指定が不正です。';
    }

    if (!$error) {
        $start_ts = strtotime($start_date);
        $end_ts   = strtotime($end_date);
        $one_year_later_ts = strtotime('+1 year', strtotime($today));

        if (!$error && ($start_ts > $one_year_later_ts || $end_ts > $one_year_later_ts)) {
            $error = '1年以上先の休暇申請はできません。';
        }
    }

    // 半休チェック
    if (!$error && $is_half && $days > 1) {
        $error = '半休指定の場合は複数日を選択できません。';
    }

    if (!$error && empty($reason)) {
        $error = '事由を入力してください。';
    }

    // 土日祝チェック（工事部以外）
    if (!$error && $department !== '工事部') {
        if ($is_half) {
            $w = date('w', strtotime($start_date));
            $isWeekend = ($w == 0 || $w == 6);
            $isHoliday = in_array($start_date, $holidays);
            if ($isWeekend || $isHoliday) {
                $error = '土日祝の半休は申請できません。';
            }
        } else {
            if ($days <= 0) {
                $error = '土日祝のみの申請はできません。';
            }
        }
    }

    if (!$error) {
        $applicant_id = $latest->user_id;
        $overlap = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
                 FROM {$table} AS t
                 INNER JOIN (
                     SELECT thread_id, MAX(id) AS latest_id
                     FROM {$table}
                     WHERE user_id = %d
                     GROUP BY thread_id
                 ) AS latest
                 ON t.id = latest.latest_id
                 WHERE t.user_id = %d
                   AND t.thread_id != %d
                   AND t.deleted_at IS NULL        /* ★ 追加：取消済は除外 */
                   AND t.start_date <= %s
                   AND t.end_date >= %s",
                $applicant_id,
                $applicant_id,
                $edit_row->thread_id,
                $end_date,
                $start_date
            )
        );

        if ($overlap > 0) {
            $error = '既存の休暇申請と日付が重複しています。';
        }
    }

    if (!$error) {
        $latest = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE thread_id = %d
                 ORDER BY id DESC
                 LIMIT 1",
                $edit_row->thread_id
            )
        );

        // ① 完全一致 → NG
        if ($latest->start_date === $start_date &&
            $latest->end_date   === $end_date &&
            $latest->half       === $half) {
            $error = '同一内容への変更はできません。';
        }

        // ② 同一日で half だけ違う → OK（何もしない）

        // ③ 同一日が含まれていても期間が変わる → OK（何もしない）
    }

    if (!$error) {
        // ① 変更前の承認状態を取得（INSERT の前）
        $prev_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT role, approved_at
                 FROM sw_leave_approvals
                 WHERE thread_id = %d AND hash = %s",
                $thread_id,
                $edit_row->hash
            )
        );
        $prev_status = [
            'bucho'  => null,
            'shacho' => null,
            'soumu'  => null,
        ];

        foreach ($prev_rows as $row) {
            $prev_status[$row->role] = $row->approved_at;
        }

        // --------------------------------------------------
        // 4. INSERT（変更内容を新規レコードとして追加）
        // --------------------------------------------------
        $hash = swl_generate_hash();

        $wpdb->insert(
            $table,
            [
                'thread_id'  => $thread_id,
                'hash'       => $hash,
                'user_id'    => $edit_row->user_id,
                'department' => $department,
                'name'       => $name,
                'type'       => $type,
                'start_date' => $start_date,
                'end_date'   => $end_date,
                'days'       => $days,
                'half'       => $half,
                'reason'     => $reason,
                'created_at' => $now_dt,
                'updated_at' => $now_dt,
                'deleted_at' => null,
            ]
        );

        $new_id = $wpdb->insert_id;


        // --------------------------------------------------
        // 5. approvals に「新しい hash」で 3 行 INSERT（全部未承認）
        // --------------------------------------------------
        $roles = ['bucho', 'shacho', 'soumu'];

        foreach ($roles as $role) {
            $wpdb->insert(
                'sw_leave_approvals',
                [
                    'thread_id'        => $thread_id,
                    'hash'             => $hash,     // ★ 新しい hash
                    'role'             => $role,
                    'approved_at'      => null,
                    'mailed_at'        => null,
                    'cancel_mailed_at' => null,
                    'created_at'       => $now_dt,
                    'updated_at'       => $now_dt,
                ]
            );
        }

        $current_user_id = get_current_user_id();
        $is_soumu = swl_is_soumu($current_user_id); // ← 総務判定
        $today = date('Y-m-d');

        $end_ts   = strtotime($end_date);
        $today_ts = strtotime($today);

        $is_past  = ($end_ts < $today_ts);
        $is_today = ($end_ts === $today_ts);
        $is_future = ($end_ts > $today_ts);

        // 申請者の承認履歴があるか
        $has_prev = !empty($prev_rows);

        // 総務かどうか
        $is_soumu = swl_is_soumu($current_user->ID);

        // 変更前の日付が過去日かどうか
        $original_start = $edit_row->start_date;
        $original_ts    = strtotime($original_start);
        $today_ts       = strtotime(date('Y-m-d'));

        $is_original_past = ($original_ts < $today_ts);

        // ★ 総務が「過去日の申請」を変更した場合のみ承認状態を引き継ぐ
        if ($is_soumu && $is_original_past && $has_prev) {

            foreach ($prev_status as $role => $approved_at) {
                $wpdb->update(
                    'sw_leave_approvals',
                    ['approved_at' => $approved_at],
                    [
                        'thread_id' => $thread_id,
                        'hash'      => $hash,
                        'role'      => $role
                    ]
                );
            }
        }

        // --------------------------------------------------
        // 6. 部長にだけ「変更通知」メールを送る（承認フローの最初）
        // --------------------------------------------------

        // ★ 総務が「過去日の申請」を変更した場合はメール送らない
        if (!($is_soumu && $is_original_past)) {

            // メール送信
            $bucho_email = swl_get_bucho_email_for_department($department);

            swl_send_change_mail(
                $bucho_email,
                '部長',
                $department,
                $name,
                $hash
            );

            $wpdb->update(
                'sw_leave_approvals',
                ['mailed_at' => $now_dt],
                [
                    'thread_id' => $thread_id,
                    'hash'      => $hash,
                    'role'      => 'bucho'
                ]
            );

            $message = '変更を登録しました。部長へ変更通知を送信しました。';

        } else {

            // メール送らない
            $message = '変更を登録しました。（総務による過去日の変更のため通知メールは送信されません）';
        }
    }
}

// --------------------------------------------------
// 7. 初期値セット
// --------------------------------------------------
$init_type       = $latest->type;
$init_start_date = $latest->start_date;
$init_end_date   = $latest->end_date;
$init_am         = ($latest->half === 'am');
$init_pm         = ($latest->half === 'pm');
$init_reason     = $latest->reason;

?>
<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/shinwa/leave_style.css'); ?>">

<div class="swl-container">
  <div class="swl-card">
    <h2 class="swl-title">休暇申請変更</h2>

    <?php if ($error): ?>
      <div class="swl-alert swl-alert-error"><?php echo esc_html($error); ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
      <div class="swl-alert swl-alert-success">
        <?php echo esc_html($message); ?>
        <div class="swl-actions">
          <a href="<?php echo esc_url(home_url('/leave-log/')); ?>" class="swl-btn swl-btn-secondary">履歴を参照する</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$message): ?>
    <form method="post">
      <input type="hidden" name="swl_action" value="edit_leave">

      <div class="swl-field">
        <label>種別</label>
        <div class="swl-radio-group">
          <label><input type="radio" name="type" value="有給休暇" <?php checked($init_type, '有給休暇'); ?>> 有給休暇</label>
          <label><input type="radio" name="type" value="慶弔休暇" <?php checked($init_type, '慶弔休暇'); ?>> 慶弔休暇</label>
          <label><input type="radio" name="type" value="その他" <?php checked($init_type, 'その他'); ?>> その他</label>
        </div>
      </div>

      <div class="swl-field">
        <label>期日</label>
        <div class="swl-date-range">
          <input type="date" name="start_date" value="<?php echo esc_attr($init_start_date); ?>">
          <span>から</span>
          <input type="date" name="end_date" value="<?php echo esc_attr($init_end_date); ?>">
          <span>まで</span>
        </div>
      </div>

      <div class="swl-field">
        <label>半休</label>
        <div class="swl-checkbox-group">
            <label>
              <input type="checkbox" id="am" name="am" value="am" <?php checked($init_am); ?>>
              午前
            </label>
            <label>
              <input type="checkbox" id="pm" name="pm" value="pm" <?php checked($init_pm); ?>>
              午後
            </label>
        </div>
      </div>

      <div class="swl-field">
        <label>事由（32文字以内）</label>
        <input type="text" name="reason" maxlength="32" value="<?php echo esc_attr($init_reason); ?>">
      </div>

      <div class="swl-actions">
        <a href="<?php echo esc_url(home_url('/leave-log/')); ?>" class="swl-btn swl-btn-secondary">履歴参照</a>
        <button type="submit" class="swl-btn swl-btn-primary" onclick="return confirm('変更すると全ての承認状態が取り消されます。変更しますか？');">変更申請</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/shinwa/js/leave_request.js?ver=' . time()); ?>"></script>
