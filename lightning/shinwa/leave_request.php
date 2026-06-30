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

$department = swl_get_user_department($current_user);
$name       = swl_get_user_full_name($current_user);
$today      = current_time('Y-m-d');
$now_dt     = current_time('mysql');
$one_year_later = date('Y-m-d', strtotime($today . ' +1 year'));
$holidays   = swl_get_holidays();

// --------------------------------------------------
// POST処理
// --------------------------------------------------
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['swl_action']) && $_POST['swl_action'] === 'submit_leave') {

    // 多重送信防止トークン
    $token = isset($_POST['swl_token']) ? sanitize_text_field($_POST['swl_token']) : '';
    if (!isset($_SESSION)) session_start();

    if (empty($_SESSION['swl_token']) || $token !== $_SESSION['swl_token']) {
        $error = '不正な送信です。もう一度やり直してください。';
    } else {

        unset($_SESSION['swl_token']);

        // 入力値取得
        $type       = sanitize_text_field($_POST['type'] ?? '');
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date   = sanitize_text_field($_POST['end_date'] ?? '');
        $am = ($_POST['am'] ?? '') === 'am';
        $pm = ($_POST['pm'] ?? '') === 'pm';
        if ($am && $pm) {
            $error = '午前と午後の両方は選択できません。';
        }
        $half = $am ? 'am' : ($pm ? 'pm' : '');
        $is_half = ($half !== '');
        $reason = trim(sanitize_textarea_field($_POST['reason'] ?? ''));

        // -------------------------
        // バリデーション
        // -------------------------
        if (!$type) $error = '種別を選択してください。';

        if (!$error) {
            if (strlen($start_date) === 0 || strlen($end_date) === 0) {
                $error = '期日を指定してください。';
            }
        }

        if (!$error && $is_half && $start_date !== $end_date) {
            $error = '半休の場合は複数日を選択できません。1日間で指定してください。';
        }

        $days = 0;
        if (!$error && $start_date && $end_date) {
            $days = swl_calc_days_business($start_date, $end_date, $department, $is_half);
            if ($days <= 0) $error = '期日の指定が不正です。';
        }

        if (!$error) {
            $one_month_ago = (new DateTime())->modify('-1 month')->format('Y-m-d');
            if ($start_date < $one_month_ago || $end_date < $one_month_ago) {
                $error = '過去1か月より前の日付は申請できません。';
            }
        }

        if (!$error) {
             $start_ts = strtotime($start_date);
             $today_ts = strtotime(date('Y-m-d'));

             if ($start_ts < $today_ts) {
                 $error = '過去日への変更はできません。';
             }
         }

        if (!$error) {
            $start_ts = strtotime($start_date);
            $end_ts   = strtotime($end_date);
            $one_year_later_ts = strtotime('+1 year', strtotime($today));

            if (!$error && ($start_ts > $one_year_later_ts || $end_ts > $one_year_later_ts)) {
                $error = '1年以上先の休暇申請はできません。';
            }
        }

        if (!$error && $is_half && $days > 1) {
            $error = '半休の場合は複数日を選択できません。1日間で指定してください。';
        }

        if (!$error && empty($reason)) {
            $error = '事由を入力してください。';
        }

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
                       AND t.deleted_at IS NULL        /* ★ 追加：取消済は除外 */
                       AND t.start_date <= %s
                       AND t.end_date >= %s",
                    $current_user->ID,
                    $current_user->ID,
                    $end_date,
                    $start_date
                )
            );

            if ($overlap > 0) {
                $error = '既存の休暇申請と日付が重複しています。';
            }
        }

        // --------------------------------------------------
        // 新規登録
        // --------------------------------------------------
        if (!$error) {

            $has_error = false;
            $error_message = '';
            $hash = swl_generate_hash();

            // INSERT
            $inserted = $wpdb->insert(
                $table,
                [
                    'thread_id'  => 0,
                    'hash'       => $hash,
                    'user_id'    => $current_user->ID,
                    'department' => $department,
                    'name'       => $name,
                    'type'       => $type,
                    'start_date' => $start_date ?: null,
                    'end_date'   => $end_date ?: null,
                    'days'       => $days,
                    'half'       => $half ?: null,
                    'reason'     => $reason,
                    'created_at' => $now_dt,
                    'updated_at' => $now_dt,
                ]
            );

            if ($inserted === false) {
                $has_error = true;
                $error_message = '申請データの登録に失敗しました。';
            }

            $leave_id = $wpdb->insert_id;

            // UPDATE thread_id
            if (!$has_error) {
                $updated = $wpdb->update(
                    $table,
                    ['thread_id' => $leave_id],
                    ['id' => $leave_id]
                );

                if ($updated === false) {
                    $has_error = true;
                    $error_message = '申請データの更新に失敗しました。（thread_id）';
                }
            }

            // approvals INSERT
            if (!$has_error) {
                foreach (['bucho', 'shacho', 'soumu'] as $role) {
                    $ok = $wpdb->insert(
                        'sw_leave_approvals',
                        [
                            'thread_id'        => $leave_id,
                            'hash'             => $hash,   // ★ 必須
                            'role'             => $role,
                            'approved_at'      => null,
                            'mailed_at'        => null,
                            'cancel_mailed_at' => null,
                            'created_at'       => current_time('mysql'),
                            'updated_at'       => current_time('mysql'),
                        ]
                    );

                    if ($ok === false) {
                        $has_error = true;
                        $error_message = "承認テーブル（{$role}）の登録に失敗しました。";
                        break;
                    }
                }
            }

            // エラーならここで終了
            if ($has_error) {
                $error = $error_message;
                return;
            }

            // --------------------------------------------------
            // メール送信
            // --------------------------------------------------
            $dep = $department;
            $nm  = $name;

            $bucho_email  = swl_get_bucho_email_for_department($dep);
            $shacho_email = swl_get_shacho_email();
            $soumu_email  = swl_get_soumu_email();

            $is_soumu_user = in_array('soumu', $current_user->roles, true) ||
                             in_array('総務', $current_user->roles, true);

            if ($is_soumu_user) {
                if ($bucho_email === $soumu_email) {
                    swl_send_request_mail($soumu_email, '部長', $dep, $nm, $hash);
                } else {
                    swl_send_request_mail($bucho_email, '部長', $dep, $nm, $hash);
                }
            } else {
                swl_send_request_mail($bucho_email, '部長', $dep, $nm, $hash);
            }

            $wpdb->update(
                'sw_leave_approvals',
                ['mailed_at' => current_time('mysql')],
                ['thread_id' => $leave_id, 'role' => 'bucho']
            );

            $message = '申請しました。承認完了までお待ちください。';
        }
    }
}
// --------------------------------------------------
// トークン生成
// --------------------------------------------------
if (!isset($_SESSION)) session_start();
$_SESSION['swl_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['swl_token'];

// --------------------------------------------------
// 初期値（新規専用）
// --------------------------------------------------
$init_type        = '';
$init_start_date  = '';
$init_end_date    = '';
$init_am          = false;
$init_pm          = false;
$init_reason      = '';

// POST があれば上書き
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['type']))        $init_type = sanitize_text_field($_POST['type']);
    if (isset($_POST['start_date']))  $init_start_date = sanitize_text_field($_POST['start_date']);
    if (isset($_POST['end_date']))    $init_end_date = sanitize_text_field($_POST['end_date']);
    $init_am = isset($_POST['am']);
    $init_pm = isset($_POST['pm']);
    if (isset($_POST['reason']))      $init_reason = sanitize_textarea_field($_POST['reason']);
}

// ① 申請禁止の役職
$position = get_user_meta($current_user->ID, 'position', true);
$deny_positions = ['社長', '会長', '副社長'];
$deny_by_position = in_array($position, $deny_positions, true);

// ② 申請を許可する部署（今は管理部だけ）
$allowed_departments = ['管理部'];
// 設計部を追加する時はこのようにする
// $allowed_departments = ['管理部', '設計部'];

// ユーザーの部署（ロールから判定）
$user_department = swl_get_user_department($current_user);
$allow_by_department = in_array($user_department, $allowed_departments, true);

// ③ 最終的に申請可能かどうか
$can_apply = (!$deny_by_position) && $allow_by_department;

// ④ ボタン制御
$disabled = $can_apply ? '' : 'disabled';

?>
<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/shinwa/leave_style.css?ver=' . time()); ?>">

<div class="swl-container">
  <div class="swl-card">
    <h2 class="swl-title">休暇申請</h2>

    <?php if ($error): ?>
      <div class="swl-alert swl-alert-error"><?php echo esc_html($error); ?></div>
    <?php endif; ?>

    <?php if ($message && !$error): ?>
      <div class="swl-alert swl-alert-success">
        <?php echo esc_html($message); ?>
        <div class="swl-actions">
          <a href="<?php echo esc_url(home_url('/leave-log/')); ?>" class="swl-btn swl-btn-secondary">履歴を参照する</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$message): ?>
    <form id="swl-request-form" method="post">
      <input type="hidden" name="swl_action" value="submit_leave">
      <input type="hidden" name="swl_token" value="<?php echo esc_attr($token); ?>">

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
          <input type="date" id="swl-start-date" name="start_date" value="<?php echo esc_attr($init_start_date); ?>">
          <span>から</span>
          <input type="date" id="swl-end-date" name="end_date" value="<?php echo esc_attr($init_end_date); ?>">
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

      <div class="swl-field swl-inline">
        <div>
          <label>年月日</label>
          <div><?php echo esc_html($today); ?></div>
        </div>
        <div>
          <label>部署名</label>
          <div><?php echo esc_html($department); ?></div>
        </div>
        <div>
          <label>氏名</label>
          <div><?php echo esc_html($name); ?></div>
        </div>
      </div>

      <div class="swl-actions">
        <a href="<?php echo esc_url(home_url('/leave-log/')); ?>" class="swl-btn swl-btn-secondary">履歴参照</a>
        <button type="submit" id="swl-submit-btn" class="swl-btn swl-btn-primary" <?php echo $disabled; ?>>申請</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/shinwa/js/leave_request.js?ver=' . time()); ?>"></script>
<script>
  window.SWL_REQUEST_CONFIG = {
    confirmMessage: '申請しますか？',
    halfErrorMessage: '半休指定の場合は複数日を選択できません。1日間で指定してください。'
  };
</script>
