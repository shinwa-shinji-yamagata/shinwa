<?php
// shinwa/leave_log.php

if (!defined('ABSPATH')) exit;

require_once get_template_directory() . '/shinwa/leave_common.php';

global $wpdb;
$table = swl_get_table_name();
$current_user = wp_get_current_user();
if (!$current_user || 0 === $current_user->ID) {
    echo '<p>ログインが必要です。</p>';
    return;
}

$position   = swl_get_user_position($current_user);
$department = swl_get_user_department($current_user);
$user_id    = $current_user->ID;
$is_soumu   = in_array('soumu', $current_user->roles, true) || in_array('総務', $current_user->roles, true);
$today      = current_time('Y-m-d');
$base_url   = home_url('/leave-log/');


/* --------------------------------------------------
   1. 社長だけ「3日以上の thread_id」を先に取得
-------------------------------------------------- */
/*
$thread_ids = [];
if ($position === '社長') {
    $sql_ids = "
        SELECT DISTINCT thread_id
        FROM {$table}
        WHERE days >= %d
    ";
    $thread_ids = $wpdb->get_col($wpdb->prepare($sql_ids, 3));
}
*/
/* --------------------------------------------------
   2. 権限に応じた WHERE 条件
-------------------------------------------------- */
$where  = [];
$params = [];

if ($is_soumu || $position === '社長' || $position === '副社長') {

    // 総務 → 全件（条件なし）
/*
} elseif ($position === '社長') {

    if (!empty($thread_ids)) {
        // thread_id IN (...)
        $placeholders = implode(',', array_fill(0, count($thread_ids), '%d'));
        $where[] = "thread_id IN ($placeholders)";
        $params  = array_merge($params, $thread_ids);
    } else {
        // 3日以上の thread が無い → 何も表示しない
        $where[] = "1=0";
    }
*/
} elseif ($position === '部長') {

    $where[] = 'department = %s';
    $params[] = $department;

} else {

    $where[] = 'user_id = %d';
    $params[] = $user_id;
}

/* --------------------------------------------------
   3. SQL 実行
-------------------------------------------------- */
$where_sql = empty($where) ? '1=1' : implode(' AND ', $where);

$per_page = 100; // 1ページ100件
$page = isset($_GET['pg']) ? max(1, intval($_GET['pg'])) : 1;
$offset = ($page - 1) * $per_page;

$total = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
        $params
    )
);

$sql = "SELECT * FROM {$table} WHERE {$where_sql}
        ORDER BY thread_id DESC, id DESC
        LIMIT %d OFFSET %d";

$rows = $wpdb->get_results(
    $wpdb->prepare($sql, array_merge($params, [$per_page, $offset]))
);

if (!$rows) {
    echo '<p>申請履歴がありません。</p>';
    return;
}

/* --------------------------------------------------
   3. thread_id ごとにまとめる
-------------------------------------------------- */
$threads = [];
foreach ($rows as $r) {
    $threads[$r->thread_id][] = $r;
}

/* --------------------------------------------------
   4. approvals を thread_id + hash で取得
-------------------------------------------------- */
$thread_ids = array_keys($threads);
$placeholders = implode(',', array_fill(0, count($thread_ids), '%d'));

$approvals_raw = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM sw_leave_approvals WHERE thread_id IN ($placeholders)",
        $thread_ids
    ),
    ARRAY_A
);

$approvals = [];
foreach ($approvals_raw as $ap) {
    $approvals[$ap['thread_id']][$ap['hash']][$ap['role']] = $ap;
}

?>
<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/shinwa/leave_style.css?ver=' . time()); ?>">

<div class="swl-container">
  <div class="swl-card">
    <h2 class="swl-title">休暇申請履歴</h2>

    <div class="swl-table-wrapper">
      <table class="swl-table">
        <thead>
          <tr>
            <th>申請ID</th> <!-- ★ 追加 -->
            <th>申請日</th>
            <th>氏名</th>
            <th>種別</th>
            <th>期日</th>
            <th>半休</th>
            <th>事由</th>
            <th>部署</th>
            <th>部長</th>
            <th>社長</th>
            <th>総務</th>
            <th>変更／取消</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>

<?php foreach ($threads as $thread_id => $items): ?>

<?php
    $latest = $items[0];
    $latest_id = $latest->id;
    $latest_is_deleted = ($latest->deleted_at !== null);
?>

<?php foreach ($items as $row): ?>

<?php
    $hash = $row->hash;

    $ap = $approvals[$thread_id][$hash] ?? [
        'bucho'  => ['approved_at' => null],
        'shacho' => ['approved_at' => null],
        'soumu'  => ['approved_at' => null],
    ];

    $is_latest_row = ($row->id === $latest_id);

    if ($is_latest_row) {
        if ($latest_is_deleted) {
            $status_html = '<span class="swl-status-badge swl-status-canceled">取消済</span>';
        } else {
            $status_html = '－';
        }
    } else {
        $status_html = '<span class="swl-status-badge swl-status-changed">変更済</span>';
    }

    /* ------------------------------
       変更・取消ボタンの表示条件（最新行のみ）
    ------------------------------ */
    $can_edit   = false;
    $can_cancel = false;

    // ★ 日付比較は strtotime で行う
    $end_ts   = strtotime($row->end_date);
    $today_ts = strtotime($today);

    $is_future = ($end_ts > $today_ts);
    $is_today  = ($end_ts === $today_ts);
    $is_past   = ($end_ts < $today_ts);

    $is_owner  = ($row->user_id == $user_id);

    $can_edit   = false;
    $can_cancel = false;

    // ★ 最新行かつ取消済でない場合のみ
    if ($is_latest_row && $row->deleted_at === null) {

        // -----------------------------
        // ■ 過去日（総務は全員OK）
        // -----------------------------
        if ($is_past) {
            if ($is_soumu) {
                $can_edit   = true;
                $can_cancel = true;
            }
        }

        // -----------------------------
        // ■ 当日（総務は全員OK）
        // -----------------------------
        if ($is_today) {
            if ($is_soumu) {
                $can_edit   = true;
                $can_cancel = true;
            }
        }

        // -----------------------------
        // ■ 未来日（本人のみOK）
        // -----------------------------
        if ($is_future) {
            if ($is_owner) {
                $can_edit   = true;
                $can_cancel = true;
            }
        }
    }

    // ★ 取消済みは絶対に操作不可
    if ($row->deleted_at !== null) {
        $can_edit = false;
        $can_cancel = false;
    }

?>

<tr>
  <td class="swl-center" data-label="申請ID"><?php echo esc_html($row->thread_id); ?></td>

  <td data-label="申請日"><?php echo esc_html($row->created_at); ?></td>
  <td data-label="氏名"><?php echo esc_html($row->name); ?></td>
  <td data-label="種別"><?php echo esc_html($row->type); ?></td>

  <td data-label="期日">
    <?php echo esc_html($row->start_date . ' ～ ' . $row->end_date . '（' . swl_format_days($row->days) . '日）'); ?>
  </td>

  <td data-label="半休" class="swl-half">
    <?php
      if ($row->half === 'am') echo '午前休';
      elseif ($row->half === 'pm') echo '午後休';
      else echo '－';
    ?>
  </td>

  <td data-label="事由"><?php echo esc_html($row->reason); ?></td>
  <td data-label="部署"><?php echo esc_html($row->department); ?></td>

  <td data-label="部長"><?php echo !empty($ap['bucho']['approved_at'])  ? '承認済' : '－'; ?></td>
  <td data-label="社長"><?php echo !empty($ap['shacho']['approved_at']) ? '承認済' : '－'; ?></td>
  <td data-label="総務"><?php echo !empty($ap['soumu']['approved_at'])  ? '承認済' : '－'; ?></td>

  <td data-label="変更／取消"><?php echo $status_html; ?></td>

  <td data-label="操作">
    <?php if ($can_edit): ?>
      <a href="<?php echo esc_url(home_url('/leave-edit/?id=' . $row->id)); ?>" class="swl-btn swl-btn-small">変更</a>
    <?php endif; ?>

    <?php if ($can_cancel): ?>
      <a href="<?php echo esc_url(home_url('/leave-cancel/?id=' . $row->id)); ?>" class="swl-btn swl-btn-small swl-btn-danger">取消</a>
    <?php endif; ?>

    <?php if (!$can_edit && !$can_cancel): ?>
      －
    <?php endif; ?>
  </td>
</tr>

<?php endforeach; ?>
<?php endforeach; ?>

        </tbody>
      </table>
<?php
$total_pages = ceil($total / $per_page);

$start = max(1, $page - 5);
$end   = min($total_pages, $page + 4);

echo '<div class="swl-pagination">';

if ($page > 1) {
    echo '<a class="swl-page-btn" href="' . $base_url . '?pg=1">&laquo;</a>';
    echo '<a class="swl-page-btn" href="' . $base_url . '?pg=' . ($page - 1) . '">&lt;</a>';
}

for ($i = $start; $i <= $end; $i++) {
    if ($i == $page) {
        echo '<span class="swl-page-btn current">' . $i . '</span>';
    } else {
        echo '<a class="swl-page-btn" href="' . $base_url . '?pg=' . $i . '">' . $i . '</a>';
    }
}

if ($page < $total_pages) {
    echo '<a class="swl-page-btn" href="' . $base_url . '?pg=' . ($page + 1) . '">&gt;</a>';
    echo '<a class="swl-page-btn" href="' . $base_url . '?pg=' . $total_pages . '">&raquo;</a>';
}

echo '</div>';
?>
<div style="text-align: right; margin-top: 20px;">
  <a href="<?php echo esc_url( get_stylesheet_directory_uri() . '/shinwa/leave_download.php' ); ?>"
     class="swl-btn swl-btn-primary">
      Excel ダウンロード
  </a>
</div>
    </div>
  </div>
</div>

<script src="<?php echo esc_url(get_template_directory_uri() . '/shinwa/js/leave_log.js'); ?>"></script>
