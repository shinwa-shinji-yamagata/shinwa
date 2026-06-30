<?php
// WordPress をロード
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
require_once get_template_directory() . '/shinwa/leave_common.php';

global $wpdb;
$table = swl_get_table_name();

// ログインチェック
$current_user = wp_get_current_user();
if (!$current_user || 0 === $current_user->ID) {
    exit('ログインが必要です。');
}

$position   = swl_get_user_position($current_user);
$department = swl_get_user_department($current_user);
$user_id    = $current_user->ID;
$is_soumu   = in_array('soumu', $current_user->roles, true) || in_array('総務', $current_user->roles, true);

/* --------------------------------------------------
   1. WHERE 条件（leave_log.php と同じ）
-------------------------------------------------- */
$where  = [];
$params = [];

if ($is_soumu || $position === '社長' || $position === '副社長') {

    // 総務・社長・副社長 → 全件

} elseif ($position === '部長') {

    $where[] = 'department = %s';
    $params[] = $department;

} else {

    $where[] = 'user_id = %d';
    $params[] = $user_id;
}

$where_sql = empty($where) ? '1=1' : implode(' AND ', $where);

/* --------------------------------------------------
   2. 全件取得（LIMITなし）
-------------------------------------------------- */
$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY thread_id DESC, id DESC";
$rows = $wpdb->get_results($wpdb->prepare($sql, $params));

/* --------------------------------------------------
   3. approvals 取得
-------------------------------------------------- */
$thread_ids = array_unique(array_column($rows, 'thread_id'));

$approvals = [];

if (!empty($thread_ids)) {
    $placeholders = implode(',', array_fill(0, count($thread_ids), '%d'));

    $approvals_raw = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM sw_leave_approvals WHERE thread_id IN ($placeholders)",
            $thread_ids
        ),
        ARRAY_A
    );

    foreach ($approvals_raw as $ap) {
        $approvals[$ap['thread_id']][$ap['hash']][$ap['role']] = $ap;
    }
}

/* --------------------------------------------------
   4. CSV 出力
-------------------------------------------------- */

// 出力バッファクリア
if (ob_get_length()) ob_end_clean();

header('Content-Type: text/csv; charset=Shift_JIS');
header('Content-Disposition: attachment; filename="休暇申請履歴.csv"');

$fp = fopen('php://output', 'w');
stream_filter_prepend($fp, 'convert.iconv.utf-8/cp932');

// ヘッダー行
fputcsv($fp, [
    '申請ID','申請日','氏名','種別','開始日','終了日','日数','半休','事由','部署',
    '部長','社長','総務','状態'
]);

// thread_id ごとに最新IDを取得
$latest_ids = [];
foreach ($rows as $r) {
    if (!isset($latest_ids[$r->thread_id])) {
        $latest_ids[$r->thread_id] = $r->id; // ORDER BY で最新が先頭
    }
}

foreach ($rows as $r) {

    // 最新行かどうか
    $is_latest = ($r->id == $latest_ids[$r->thread_id]);

    // 状態判定
    if ($is_latest) {
        if ($r->deleted_at !== null) {
            $status = '取消済';
        } else {
            $status = '－';
        }
    } else {
        $status = '変更済';
    }

    // 半休
    $half = ($r->half === 'am') ? '午前休' :
            (($r->half === 'pm') ? '午後休' : '－');

    $ap = $approvals[$r->thread_id][$r->hash] ?? [
        'bucho'  => ['approved_at' => null],
        'shacho' => ['approved_at' => null],
        'soumu'  => ['approved_at' => null],
    ];

    fputcsv($fp, [
        $r->thread_id,
        $r->created_at,
        $r->name,
        $r->type,
        $r->start_date,
        $r->end_date,
        $r->days,
        $half,
        $r->reason,
        $r->department,
        !empty($ap['bucho']['approved_at'])  ? '承認済' : '－',
        !empty($ap['shacho']['approved_at']) ? '承認済' : '－',
        !empty($ap['soumu']['approved_at'])  ? '承認済' : '－',
        $status
    ]);
}

fclose($fp);
exit;
