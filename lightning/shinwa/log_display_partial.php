<div class="pagination top">
  <?php
    if ($page_no > 1) {
      echo "<a href='#' data-page='1'>&laquo;</a>";
      echo "<a href='#' data-page='" . ($page_no - 1) . "'>&lt;</a>";
    }

$range = 10;
$start = max(1, $page_no - floor($range / 2));
$end = min($total_pages, $start + $range - 1);

if ($end - $start + 1 < $range) {
  $start = max(1, $end - $range + 1);
  $end = min($total_pages, $start + $range - 1); // ← これが抜けてた！
}
    for ($i = $start; $i <= $end; $i++) {
      $active = ($i == $page_no) ? 'active' : '';
      echo "<a href='#' data-page='$i' class='$active'>$i</a>";
    }

    if ($page_no < $total_pages) {
      echo "<a href='#' data-page='" . ($page_no + 1) . "'>&gt;</a>";
      echo "<a href='#' data-page='$total_pages'>&raquo;</a>";
    }
  ?>
</div>

<table class="log-table">
  <thead>
    <tr>
      <th>日時</th>
      <th>プログラム</th>
      <th>レベル</th>
      <th>ユーザー名</th>
      <th>メッセージ</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!empty($logs)): ?>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td><?php echo esc_html($log->log_time); ?></td>
          <td><?php echo esc_html($log->program); ?></td>
          <td><?php echo esc_html($log->level); ?></td>
          <td><?php echo esc_html($log->user_name); ?></td>
          <td><?php echo esc_html($log->message); ?></td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="5">該当するログがありません。</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<div class="pagination">
  <?php
    if ($page_no > 1) {
      echo "<a href='#' data-page='1'>&laquo;</a>";
      echo "<a href='#' data-page='" . ($page_no - 1) . "'>&lt;</a>";
    }

$range = 10; // 表示するページリンク数
$start = max(1, $page_no - floor($range / 2));
$end = min($total_pages, $start + $range - 1);

// $end が total_pages に達してる場合、$start を調整
if ($end - $start + 1 < $range) {
  $start = max(1, $end - $range + 1);
}
    for ($i = $start; $i <= $end; $i++) {
      $active = ($i == $page_no) ? 'active' : '';
      echo "<a href='#' data-page='$i' class='$active'>$i</a>";
    }

    if ($page_no < $total_pages) {
      echo "<a href='#' data-page='" . ($page_no + 1) . "'>&gt;</a>";
      echo "<a href='#' data-page='$total_pages'>&raquo;</a>";
    }
  ?>
</div>
