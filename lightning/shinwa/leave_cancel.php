<?php
// shinwa/leave_cancel.php

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

// --------------------------------------------------
// 1. GET パラメータ（id）
// --------------------------------------------------
if (!isset($_GET['id'])) {
    echo '<p>URLが不正です。</p>';
    return;
}

$cancel_id = intval($_GET['id']);

// 対象レコード取得
$row = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $cancel_id)
);

if (!$row) {
    echo '<p>申請データが見つかりません。</p>';
    return;
}

// thread_id の最新レコードを取得
$thread_id = $row->thread_id;
$latest = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE thread_id = %d ORDER BY id DESC LIMIT 1", $thread_id)
);

// --------------------------------------------------
// 2. 権限チェック
// --------------------------------------------------
$is_soumu = in_array('soumu', $current_user->roles, true) || in_array('総務', $current_user->roles, true);

// 自分の申請でなければ取消不可（総務は例外）
if (!$is_soumu && intval($latest->user_id) !== $current_user->ID) {
    echo '<p>この申請を取消する権限がありません。</p>';
    return;
}

// --------------------------------------------------
// 3. 取消可能条件
// --------------------------------------------------

// すでに取消済み
if ($latest->deleted_at !== null) {
    echo '<p>この申請はすでに取消されています。</p>';
    return;
}

// 過去日チェック
if ($latest->end_date < $today) {
    if (!$is_soumu) {
        echo '<p>過去日の申請は取消できません。</p>';
        return;
    }
} else {
    // 未来日 → 総務は取消不可
    if ($is_soumu) {
        echo '<p>総務は未来日の取消はできません。</p>';
        return;
    }
}

// --------------------------------------------------
// 4. POST（取消実行）
// --------------------------------------------------
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['swl_action']) && $_POST['swl_action'] === 'cancel') {

    // 取消実行
    $wpdb->update(
        $table,
        ['deleted_at' => $now_dt],
        ['id' => $latest->id],
        ['%s'],
        ['%d']
    );

    $current_user_id = get_current_user_id();
    $is_soumu = swl_is_soumu($current_user_id);
    $is_past  = (strtotime($latest->start_date) < strtotime(date('Y-m-d')));

    // --------------------------------------------------
    // メール送信条件
    // --------------------------------------------------

    // ★ 総務が過去日を取消 → メール送らない
    if (!($is_soumu && $is_past)) {

        // 承認状況取得
        $approvals = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM sw_leave_approvals WHERE thread_id = %d", $thread_id),
            ARRAY_A
        );

        foreach ($approvals as $ap) {

            if ($ap['mailed_at'] !== null && $ap['cancel_mailed_at'] === null) {

                if ($ap['role'] === 'bucho') {
                    $email = swl_get_bucho_email_for_department($latest->department);
                    swl_send_cancel_mail($email, '部長', $latest->department, $latest->name, $latest->hash, $thread_id);
                }

                if ($ap['role'] === 'shacho') {
                    $email = swl_get_shacho_email();
                    swl_send_cancel_mail($email, '社長', $latest->department, $latest->name, $latest->hash, $thread_id);
                }

                if ($ap['role'] === 'soumu') {
                    $email = swl_get_soumu_email();
                    swl_send_cancel_mail($email, '総務', $latest->department, $latest->name, $latest->hash, $thread_id);
                }

                $wpdb->update(
                    'sw_leave_approvals',
                    ['cancel_mailed_at' => $now_dt],
                    ['thread_id' => $thread_id, 'role' => $ap['role']]
                );
            }
        }
    }

    $message = '申請を取消しました。';
}

?>

<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/shinwa/leave_style.css'); ?>">

<div class="swl-container">
  <div class="swl-card">
    <h2 class="swl-title">休暇申請取消</h2>

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
    <div class="swl-field">
      <label>申請者：</label>
      <div><?php echo esc_html($latest->department . ' / ' . $latest->name); ?></div>
    </div>

    <div class="swl-field">
      <label>期間：</label>
      <div><?php echo esc_html($latest->start_date . ' 〜 ' . $latest->end_date); ?></div>
    </div>

    <div class="swl-field">
      <label>事由：</label>
      <div><?php echo nl2br(esc_html($latest->reason)); ?></div>
    </div>

    <form method="post">
      <input type="hidden" name="swl_action" value="cancel">
      <div class="swl-actions">
        <button type="submit" class="swl-btn swl-btn-danger">取消する</button>
      </div>
    </form>
    <?php endif; ?>

  </div>
</div>
