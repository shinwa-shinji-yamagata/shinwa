<?php
global $wpdb;

$limit = 50;
$page = isset($_POST['page_no']) ? (int)$_POST['page_no']
      : (isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1);
$page = max(1, $page);
$offset = ($page - 1) * $limit;

$search = $_POST['search'] ?? '';
$table_name = 'sw_genba_master';

$where = '';
$params = [];

$total_count = 0;
$total_pages = 0;
$genba_list = [];

if ($search !== '') {
  $where = "WHERE name LIKE %s OR subject LIKE %s";
  $params[] = '%' . $wpdb->esc_like($search) . '%';
  $params[] = '%' . $wpdb->esc_like($search) . '%';
}

$total_count = empty($params)
  ? $wpdb->get_var("SELECT COUNT(*) FROM $table_name")
  : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name $where", ...$params));

$total_pages = ceil($total_count / $limit);

$query = empty($params)
  ? "SELECT code, d_code, name, subject FROM $table_name ORDER BY code DESC, id DESC, d_code DESC LIMIT %d OFFSET %d"
  : "SELECT code, d_code, name, subject FROM $table_name $where ORDER BY code DESC, id DESC, d_code DESC LIMIT %d OFFSET %d";

$params[] = $limit;
$params[] = $offset;

$genba_list = $wpdb->get_results($wpdb->prepare($query, ...$params));

?>

<div class="pagination">
  <?php
    $last_page = $total_pages;
    $current_page = $page;

    // ページリンク生成関数
    if (!function_exists('page_link')) {
        function page_link($label, $target, $current_page, $class = '') {
          $active = $target == $current_page ? 'active' : '';
          return "<a href='?page_no={$target}' class='$class $active'>$label</a>";
        }
    }
    // 「<<」「<」
    if ($current_page > 1) {
      echo page_link('<<', 1, $current_page, 'nav');
      echo page_link('<', $current_page - 1, $current_page, 'nav');
    }

    // 数字リンク（最大8件）
    $max_numbers = 8;
    $half = floor($max_numbers / 2);
    $start = max(1, $current_page - $half);
    $end   = min($last_page, $start + $max_numbers - 1);

    // もし最後の方なら調整
    if ($end - $start + 1 < $max_numbers) {
      $start = max(1, $end - $max_numbers + 1);
    }

    for ($i = $start; $i <= $end; $i++) {
      echo page_link($i, $i, $current_page);
    }

    // 「>」「>>」
    if ($current_page < $last_page) {
      echo page_link('>', $current_page + 1, $current_page, 'nav');
      echo page_link('>>', $last_page, $current_page, 'nav');
    }
  ?>
</div>

<table class="log-table">
  <thead>
    <tr>
      <th>工事コード</th>
      <th>工事詳細コード</th>
      <th>現場名</th>
      <th>工事名</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($genba_list as $row): ?>
      <tr>
        <td><?php echo esc_html($row->code); ?></td>
        <td><?php echo esc_html($row->d_code); ?></td>
        <td><?php echo esc_html($row->name); ?></td>
        <td><?php echo esc_html($row->subject); ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="pagination">
  <?php
    $last_page = $total_pages;
    $current_page = $page;

    // ページリンク生成関数
    if (!function_exists('page_link')) {
        function page_link($label, $target, $current_page, $class = '') {
          $active = $target == $current_page ? 'active' : '';
          return "<a href='#' class='$class $active page-link' data-page='{$target}'>$label</a>";
        }
    }

    // 「<<」「<」
    if ($current_page > 1) {
      echo page_link('<<', 1, $current_page, 'nav');
      echo page_link('<', $current_page - 1, $current_page, 'nav');
    }

    // 数字リンク（最大8件）
    $max_numbers = 8;
    $half = floor($max_numbers / 2);
    $start = max(1, $current_page - $half);
    $end   = min($last_page, $start + $max_numbers - 1);

    // もし最後の方なら調整
    if ($end - $start + 1 < $max_numbers) {
      $start = max(1, $end - $max_numbers + 1);
    }

    for ($i = $start; $i <= $end; $i++) {
      echo page_link($i, $i, $current_page);
    }

    // 「>」「>>」
    if ($current_page < $last_page) {
      echo page_link('>', $current_page + 1, $current_page, 'nav');
      echo page_link('>>', $last_page, $current_page, 'nav');
    }
  ?>
</div>
