<?php
if ( ! function_exists('wp_get_current_user') ) {
  echo '<p>WordPress のコンテキストで実行してください。</p>';
  return;
}

$paged   = max(1, intval($_GET['pg'] ?? 1));
$perpage = 50;

// 除外ユーザー
$exclude_id = username_exists('shinwa_user');

// 全ユーザー取得
$args = [
  'exclude' => $exclude_id ? [$exclude_id] : [],
  'number'  => 0,
  'fields'  => ['ID', 'display_name', 'user_email'],
];
$users = get_users($args);

// 並び順定義（役職）
$position_order = [
  '社長' => 1,
  '会長' => 2,
  '副社長' => 3,
  '部長' => 4,
  '課長' => 5,
  'マネージャー' => 6,
  'サブマネージャー' => 7,
  '一般' => 8,
];

// 並び順定義（一般の部署）
$role_order = [
  '工事部'       => 1,
  '設計部'       => 2,
  '技術営業'     => 3,
  '総務'         => 4,
  '経理'         => 5,
  '購買'         => 6,
  '工事事務'     => 7,
  'Administrator' => 8,
];

// ロール表示名取得用
global $wp_roles;
$role_names = is_object($wp_roles) ? $wp_roles->get_names() : [];

$sorted_users = [];

foreach ($users as $user) {
  // 表示名が 'shinwa-user' の場合は除外
  if (trim($user->display_name) === 'shinwa-user') {
    continue;
  }

  $position = get_user_meta($user->ID, 'position', true);
  $position = $position ?: '—';
  $position_priority = $position_order[$position] ?? 999;

  $user_obj = new WP_User($user->ID);
  $roles = array_map(function($r) use ($role_names) {
    return $role_names[$r] ?? $r;
  }, $user_obj->roles);

  // 一般以外はロール順を無視
  if ($position !== '一般') {
    $sorted_users[] = [
      'user'            => $user,
      'position'        => $position,
      'position_order'  => $position_priority,
      'primary_role'    => implode(', ', $roles),
      'role_order'      => 0,
    ];
    continue;
  }

  // 一般の場合はロール順で並べる
  $role_priority = 999;
  $primary_role = '—';
  foreach ($roles as $r) {
    if (isset($role_order[$r]) && $role_order[$r] < $role_priority) {
      $role_priority = $role_order[$r];
      $primary_role = $r;
    }
  }

  if ($primary_role === '—' && !empty($roles)) {
    $primary_role = $roles[0];
  }

  $sorted_users[] = [
    'user'            => $user,
    'position'        => $position,
    'position_order'  => $position_priority,
    'primary_role'    => $primary_role,
    'role_order'      => $role_priority,
  ];
}

// 並び替え：役職順 → （一般のみ）ロール順 → 名前順
usort($sorted_users, function($a, $b) {
  $a_key = [$a['position_order'], $a['position_order'] === 8 ? $a['role_order'] : 0, $a['user']->display_name];
  $b_key = [$b['position_order'], $b['position_order'] === 8 ? $b['role_order'] : 0, $b['user']->display_name];
  return $a_key <=> $b_key;
});

// ページング
$total_count = count($sorted_users);
$total_pages = max(1, (int)ceil($total_count / $perpage));
$offset = ($paged - 1) * $perpage;
$paged_users = array_slice($sorted_users, $offset, $perpage);
?>

<style>
  .employee-table { width: 100%; border-collapse: collapse; }
  .employee-table th, .employee-table td { border: 1px solid #ddd; padding: 8px; }
  .employee-table th { background: #f5f5f5; text-align: left; }
  .employee-table tr:nth-child(even) { background: #fafafa; }
  .employee-meta-muted { color: #666; font-size: 12px; }
  .employee-pager { margin: 12px 0; display: flex; gap: 8px; align-items: center; }
  .employee-pager a { padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; }
  .employee-pager .current { font-weight: bold; }
</style>

<h2>社員マスタ</h2>

<div class="employee-pager">
  <span>ページ: <span class="current"><?php echo esc_html($paged); ?></span> / <?php echo esc_html($total_pages); ?></span>
  <?php if ($paged > 1): ?>
    <a href="?pg=<?php echo esc_attr($paged - 1); ?>">前へ</a>
  <?php endif; ?>
  <?php if ($paged < $total_pages): ?>
    <a href="?pg=<?php echo esc_attr($paged + 1); ?>">次へ</a>
  <?php endif; ?>
</div>

<table class="employee-table">
  <thead>
    <tr>
      <th>社員名</th>
      <th>部署（権限グループ）</th>
      <th>役職</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!empty($paged_users)): ?>
    <?php foreach ($paged_users as $entry): ?>
      <tr>
        <td>
          <?php echo esc_html($entry['user']->display_name); ?>
          <?php if (!empty($entry['user']->user_email)): ?>
            <div class="employee-meta-muted"><?php echo esc_html($entry['user']->user_email); ?></div>
          <?php endif; ?>
        </td>
        <td><?php echo esc_html($entry['primary_role']); ?></td>
        <td><?php echo esc_html($entry['position']); ?></td>
      </tr>
    <?php endforeach; ?>
  <?php else: ?>
    <tr>
      <td colspan="3">該当する社員がいません。</td>
    </tr>
  <?php endif; ?>
  </tbody>
</table>

<div class="employee-pager">
  <span>ページ: <span class="current"><?php echo esc_html($paged); ?></span> / <?php echo esc_html($total_pages); ?></span>
  <?php if ($paged > 1): ?>
    <a href="?pg=<?php echo esc_attr($paged - 1); ?>">前へ</a>
  <?php endif; ?>
  <?php if ($paged < $total_pages): ?>
    <a href="?pg=<?php echo esc_attr($paged + 1); ?>">次へ</a>
  <?php endif; ?>
</div>
