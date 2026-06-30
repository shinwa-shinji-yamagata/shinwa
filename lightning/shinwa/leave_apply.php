<?php
// shinwa/leave_apply.php

if (!defined('ABSPATH')) exit;

require_once get_template_directory() . '/shinwa/leave_common.php';

global $wpdb;
$table         = swl_get_table_name();
$current_user  = wp_get_current_user();

if (!$current_user || 0 === $current_user->ID) {
    echo '<p>ログインが必要です。</p>';
    return;
}

$position      = swl_get_user_position($current_user);
$department    = swl_get_user_department($current_user); // 管理部 / 工事部 / 設計部 / 技術営業
$current_email = $current_user->user_email;

// --------------------------------------------------
// 1. URL の hash から最新レコードを取得
// --------------------------------------------------
$key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
if (!$key) {
    echo '<p>URLが不正です。</p>';
    return;
}

$row = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE hash = %s", $key)
);
if (!$row) {
    echo '<p>申請データが見つかりません。</p>';
    return;
}

// thread_id の最新レコードを取得（表示用）
$thread_id = $row->thread_id;
$latest    = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE thread_id = %d ORDER BY id DESC LIMIT 1", $thread_id)
);

// --------------------------------------------------
// 2. アクセス権限チェック
// --------------------------------------------------
$roles   = $current_user->roles;
$allowed = false;

if (in_array($position, ['社長', '副社長', '部長', '総務'], true)) {
    $allowed = true;
}
if (in_array('soumu', $roles, true) || in_array('総務', $roles, true)) {
    $allowed = true;
}

if (!$allowed) {
    echo '<p>このページにアクセスする権限がありません。</p>';
    return;
}

// --------------------------------------------------
// 3. 承認状態の取得（sw_leave_approvals：thread_id + hash）
// --------------------------------------------------
$approvals = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM sw_leave_approvals WHERE thread_id = %d AND hash = %s",
        $thread_id,
        $key
    ),
    ARRAY_A
);

$ap = [
    'bucho'  => null,
    'shacho' => null,
    'soumu'  => null,
];

foreach ($approvals as $a) {
    $ap[$a['role']] = $a;
}

// --------------------------------------------------
// 4. 承認可能な役職か判定
// --------------------------------------------------
$bucho_email  = swl_get_bucho_email_for_department($latest->department);
$shacho_email = swl_get_shacho_email();
$soumu_email  = swl_get_soumu_email();

$can_approve  = false;
$approve_role = null;

if ($current_email === $bucho_email) {
    $can_approve  = true;
    $approve_role = 'bucho';
}
if ($current_email === $shacho_email) {
    $can_approve  = true;
    $approve_role = 'shacho';
}
if ($current_email === $soumu_email) {
    $can_approve  = true;
    $approve_role = 'soumu';
}

// --------------------------------------------------
// 5. 社長・副社長・総務の名前取得
// --------------------------------------------------
$shacho_user = get_users([
    'meta_key'   => 'position',
    'meta_value' => '社長',
    'number'     => 1,
])[0] ?? null;
$shacho_name = $shacho_user ? $shacho_user->last_name : '';

$fukushacho_user = get_users([
    'meta_key'   => 'position',
    'meta_value' => '副社長',
    'number'     => 1,
])[0] ?? null;
$fukushacho_name = $fukushacho_user ? $fukushacho_user->last_name : '';

$soumu_user = get_users([
    'role'   => 'soumu',
    'number' => 1,
])[0] ?? null;
$soumu_name = $soumu_user ? $soumu_user->last_name : '';

// --------------------------------------------------
// 6. 部長の名前取得（部署ロールを使って一元的に）
// --------------------------------------------------
$department = $latest->department;
$bucho_user    = null;

$roles_for_dep = swl_department_to_roles($department);

if ($department === '管理部') {

    $bucho_user = get_users([
        'role'       => 'soumu',
        'meta_key'   => 'position',
        'meta_value' => '部長',
        'number'     => 1,
    ])[0] ?? null;

} elseif ($department === '技術営業') {

    $bucho_user = get_users([
        'meta_key'   => 'position',
        'meta_value' => '副社長',
        'number'     => 1,
    ])[0] ?? null;

} else {

    $bucho_user = get_users([
        'role__in'   => $roles_for_dep,
        'meta_key'   => 'position',
        'meta_value' => '部長',
        'number'     => 1,
    ])[0] ?? null;
}

$bucho_name = $bucho_user ? $bucho_user->last_name : '';

if (!$can_approve) {
    echo '<p>この申請を承認する権限がありません。</p>';
    return;
}

// --------------------------------------------------
// 5. POST（承認処理）
// --------------------------------------------------
$message = '';
$error   = '';

// --------------------------------------------------
// 0. 最新ハッシュの取得
// --------------------------------------------------
$latest_hash = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT hash FROM sw_leave WHERE thread_id=%d ORDER BY id DESC LIMIT 1",
        $thread_id
    )
);

// --------------------------------------------------
// 1. 古いURL（古いhash）を無効化
// --------------------------------------------------
if ($key !== $latest_hash) {
    $error = 'この申請は変更されています。最新のメールに記載されたURLから承認してください。';
}

// --------------------------------------------------
// 2. 取消済みの申請を無効化
// --------------------------------------------------
if (!empty($latest->deleted_at)) {
    $error = 'この申請は取消済みのため承認できません。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['swl_action'])
    && $_POST['swl_action'] === 'approve') {

    // ★ エラーがあれば承認処理を実行しない（return しない）
    if (!empty($error)) {
        $message = $error;
        // 承認処理をスキップ
    } else {
        // すでに承認済みなら何もしない
        if (!empty($ap[$approve_role]['approved_at'])) {
            $message = 'すでに承認済みです。';
        } else {

            // --------------------------------------------------
            // 3. 現在の承認状況（あなたのコードそのまま）
            // --------------------------------------------------
            $bucho_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT approved_at FROM sw_leave_approvals 
                     WHERE thread_id=%d AND hash=%s AND role='bucho'",
                    $thread_id,
                    $key
                )
            );

            $shacho_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT approved_at FROM sw_leave_approvals 
                     WHERE thread_id=%d AND hash=%s AND role='shacho'",
                    $thread_id,
                    $key
                )
            );

            $soumu_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT approved_at FROM sw_leave_approvals 
                     WHERE thread_id=%d AND hash=%s AND role='soumu'",
                    $thread_id,
                    $key
                )
            );

            // --------------------------------------------------
            // 4. 承認順序の制御
            // --------------------------------------------------
            $approve_role = null;

            // 申請者の現在の役職を取得
            $applicant = get_user_by('id', $latest->user_id);
            $applicant_position = get_user_meta($applicant->ID, 'position', true);

            // 申請者が部長かどうか
            $is_bucho_user = ($applicant_position === '部長');

            // ① 部長承認
            if ($current_email === $bucho_email && empty($bucho_row->approved_at)) {
                $approve_role = 'bucho';
            }

            // ② 社長承認（申請者が部長のときだけ）
            elseif ($current_email === $shacho_email
                && $is_bucho_user
                && !empty($bucho_row->approved_at)
                && empty($shacho_row->approved_at)) {

                $approve_role = 'shacho';
            }

            // ③ 総務承認
            elseif ($current_email === $soumu_email
                && !empty($bucho_row->approved_at)
                && (
                    // 申請者が部長 → 社長承認済み
                    ($is_bucho_user && !empty($shacho_row->approved_at))
                    ||
                    // 申請者が部長以外 → 社長承認不要
                    (!$is_bucho_user)
                )
                && empty($soumu_row->approved_at)) {

                $approve_role = 'soumu';
            }

            // --------------------------------------------------
            // 5. 承認実行
            // --------------------------------------------------
            if ($approve_role) {

                $now = current_time('mysql');

                $wpdb->update(
                    'sw_leave_approvals',
                    [
                        'approved_at' => $now,
                        'updated_at'  => $now,
                    ],
                    [
                        'thread_id' => $thread_id,
                        'hash'      => $key,
                        'role'      => $approve_role
                    ]
                );

                // --------------------------------------------------
                // 6. 承認後のメール送信（変更申請対応版）
                // --------------------------------------------------

                // 過去にメールを送信したことがあるか判定
                $prev_shacho = swl_has_mailed_before($thread_id, 'shacho');
                $prev_soumu  = swl_has_mailed_before($thread_id, 'soumu');

                // ① 部長承認後
                if ($approve_role === 'bucho') {

                    if ($is_bucho_user) {
                        // 申請者が部長 → 社長へ
                        if ($prev_shacho) {
                            swl_send_change_mail($shacho_email, '社長', $latest->department, $latest->name, $latest->hash);
                        } else {
                            swl_send_request_mail($shacho_email, '社長', $latest->department, $latest->name, $latest->hash);
                        }

                        $wpdb->update(
                            'sw_leave_approvals',
                            ['mailed_at' => $now],
                            ['thread_id' => $thread_id, 'role' => 'shacho']
                        );

                    } else {
                        // 申請者が部長以外 → 総務へ
                        if ($prev_soumu) {
                            swl_send_change_mail($soumu_email, '総務', $latest->department, $latest->name, $latest->hash);
                        } else {
                            swl_send_request_mail($soumu_email, '総務', $latest->department, $latest->name, $latest->hash);
                        }

                        $wpdb->update(
                            'sw_leave_approvals',
                            ['mailed_at' => $now],
                            ['thread_id' => $thread_id, 'role' => 'soumu']
                        );
                    }
                }

                // ② 社長承認後 → 総務へ
                if ($approve_role === 'shacho') {

                    if ($prev_soumu) {
                        swl_send_change_mail($soumu_email, '総務', $latest->department, $latest->name, $latest->hash);
                    } else {
                        swl_send_request_mail($soumu_email, '総務', $latest->department, $latest->name, $latest->hash);
                    }

                    $wpdb->update(
                        'sw_leave_approvals',
                        ['mailed_at' => $now],
                        ['thread_id' => $thread_id, 'role' => 'soumu']
                    );
                }

                // ③ 総務承認後 → 申請者へ
                if ($approve_role === 'soumu') {

                    $user = get_user_by('id', $latest->user_id);
                    if ($user) {
                        swl_send_approved_mail_to_user(
                            $user->user_email,
                            $latest->department,
                            $latest->name
                        );
                    }
                }

                $_SESSION['swl_flash'] = '承認しました。';
                wp_redirect( home_url('/leave-apply/?key=' . $row->hash) );
                exit;

            } else {
                $error = '承認条件を満たしていません。';
            }
        }
    }
}

if (!isset($_SESSION)) session_start();
if (!empty($_SESSION['swl_flash'])) {
    $message = $_SESSION['swl_flash'];
    unset($_SESSION['swl_flash']); // 一度だけ表示
}

?>
<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/shinwa/leave_style.css?ver=' . time()); ?>">

<div class="swl-container">
  <div class="swl-card">
    <h2 class="swl-title">休暇申請内容</h2>

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

    <!-- 申請内容 -->

    <div class="swl-field">
      <label>申請ID：</label>
      <div><?php echo esc_html($thread_id); ?></div>
    </div>

    <div class="swl-field">
      <label>申請者：</label>
      <div><?php echo esc_html($latest->department . ' / ' . $latest->name); ?></div>
    </div>

    <div class="swl-field">
      <label>種別：</label>
      <div><?php echo esc_html($latest->type); ?></div>
    </div>

    <div class="swl-field">
      <label>期日：</label>
      <div>
        <?php
        if ($latest->start_date && $latest->end_date) {
            echo esc_html($latest->start_date . ' から ' . $latest->end_date . ' まで ' . swl_format_days($latest->days) . '日間');
        } else {
            echo '―';
        }
        ?>
      </div>
    </div>

    <div class="swl-field">
      <label>時間：</label>
      <div>
      <?php
          if ($latest->half === 'am') {
              echo '午前休';
          } elseif ($latest->half === 'pm') {
              echo '午後休';
          } else {
              echo '―';
          }
      ?>
      </div>
    </div>

    <div class="swl-field">
      <label>事由：</label>
      <div><?php echo nl2br(esc_html($latest->reason)); ?></div>
    </div>

    <!-- 承認状況 -->
    <div class="swl-approve-status">
      <div><label>申請日：</label><?php echo esc_html($latest->created_at); ?></div>

      <div>
        <label>部長（<?php echo esc_html($bucho_name); ?>）：</label>
        <span class="swl-status-badge <?php echo $ap['bucho']['approved_at'] ? 'swl-status-approved' : 'swl-status-pending'; ?>">
          <?php echo $ap['bucho']['approved_at'] ? '承認済' : '未承認'; ?>
        </span>
      </div>

      <div>
        <label>社長（<?php echo esc_html($shacho_name); ?>）：</label>
        <span class="swl-status-badge <?php echo $ap['shacho']['approved_at'] ? 'swl-status-approved' : 'swl-status-pending'; ?>">
          <?php echo $ap['shacho']['approved_at'] ? '承認済' : '未承認'; ?>
        </span>
      </div>

      <div>
        <label>総務（<?php echo esc_html($soumu_name); ?>）：</label>
        <span class="swl-status-badge <?php echo $ap['soumu']['approved_at'] ? 'swl-status-approved' : 'swl-status-pending'; ?>">
          <?php echo $ap['soumu']['approved_at'] ? '承認済' : '未承認'; ?>
        </span>
      </div>
    </div>

    <!-- 承認ボタン -->
    <?php if (!$message && $can_approve && $ap[$approve_role]['approved_at'] === null): ?>
    <form method="post" id="swl-approve-form">
      <input type="hidden" name="swl_action" value="approve">

      <div class="swl-actions" style="display:flex; gap:10px; align-items:center;">

        <!-- 履歴参照ボタン（別タブ） -->
        <a href="<?php echo esc_url(home_url('/leave-log/')); ?>" target="_blank" class="swl-btn swl-btn-secondary">履歴参照</a>

        <!-- 承認ボタン -->
        <button type="submit" class="swl-btn swl-btn-primary"
            <?php if (!empty($error)) echo 'disabled style="opacity:0.5; cursor:not-allowed;"'; ?>>
            承認
        </button>

      </div>
    </form>
    <?php endif; ?>

  </div>
</div>

<script src="<?php echo esc_url(get_template_directory_uri() . '/shinwa/js/leave_apply.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('swl-approve-form');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    // ボタンが disabled の場合は確認を出さない
    const btn = form.querySelector('button[type="submit"]');
    if (btn && btn.disabled) {
      return;
    }

    if (!confirm('承認しますか？')) {
      e.preventDefault();
    }
  });
});
</script>
