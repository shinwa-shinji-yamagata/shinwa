<?php
if (is_admin() && !wp_doing_ajax()) {
  return;
}
global $wpdb;

$table_name = 'sw_logs';
$limit_options = [100, 200, 300, 400, 500];

// 受け取りパラメータ
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_options) ? (int)$_GET['limit'] : 100;
$page_no = isset($_GET['page_no']) ? max(1, (int)$_GET['page_no']) : 1;

$from_dt = isset($_GET['from_dt']) ? sanitize_text_field($_GET['from_dt']) : '';
$to_dt   = isset($_GET['to_dt'])   ? sanitize_text_field($_GET['to_dt'])   : '';
$program = isset($_GET['program']) ? sanitize_text_field($_GET['program']) : '';
$level   = isset($_GET['level'])   ? sanitize_text_field($_GET['level'])   : '';
$user    = isset($_GET['user'])    ? sanitize_text_field($_GET['user'])    : '';
$message = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';

$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1'; // 非同期要求かどうか

// WHERE句の構築
$where = [];
$params = [];

if ($from_dt !== '') {
  // datetime-localは "YYYY-MM-DDTHH:MM" 形式。MySQL DATETIMEに合うように' 'へ変換
  $from_mysql = str_replace('T', ' ', $from_dt) . ':00';
  $where[] = "log_time >= %s";
  $params[] = $from_mysql;
}
if ($to_dt !== '') {
  $to_mysql = str_replace('T', ' ', $to_dt) . ':59';
  $where[] = "log_time <= %s";
  $params[] = $to_mysql;
}
if ($program !== '') {
  $where[] = "program = %s";
  $params[] = $program;
}
if ($level !== '') {
  $where[] = "level = %s";
  $params[] = $level;
}
if ($user !== '') {
  $where[] = "user_name = %s";
  $params[] = $user;
}
if ($message !== '') {
  $where[] = "message LIKE %s";
  $params[] = '%' . $wpdb->esc_like($message) . '%';
}

$where_sql = '';
if (!empty($where)) {
  $where_sql = 'WHERE ' . implode(' AND ', $where);
}

// 件数・ページング
$max_pages = 100;

// 総件数を取得（prepare不要）
$count_sql = "SELECT COUNT(*) FROM $table_name $where_sql";

// $params（WHERE句用）が空なら prepare を使わない
if (!empty($params)) {
  $total_count = $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
} else {
  $total_count = $wpdb->get_var($count_sql);
}
// 総ページ数を計算
$total_pages = ceil($total_count / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($total_pages > $max_pages) {
  $total_pages = $max_pages;
}


// 初期表示時は必ず1ページ目にする
if (empty($page_no) || $page_no < 1) {
    $page_no = 1;
}
$page_no = min($page_no, $total_pages);

// OFFSET計算
$offset = ($page_no - 1) * $limit;

// データ取得（LIMIT/OFFSETは %d があるので prepare を使う）
$data_sql = "SELECT id, log_time, program, level, user_name, message FROM $table_name $where_sql ORDER BY log_time DESC, id DESC LIMIT %d OFFSET %d";

// プレースホルダーが2つ → 引数も2つ、個別に渡す
$logs = $wpdb->get_results($wpdb->prepare($data_sql, ...array_merge($params, [(int)$limit, (int)$offset])));

// オートコンプリートの候補（常に全件を取得）
$programs = $wpdb->get_col("SELECT DISTINCT program FROM $table_name ORDER BY program ASC LIMIT 500");
$levels   = $wpdb->get_col("SELECT DISTINCT level   FROM $table_name ORDER BY level ASC   LIMIT 500");
$users    = $wpdb->get_col("SELECT DISTINCT user_name FROM $table_name ORDER BY user_name ASC LIMIT 500");

// クエリベース（ページングリンク用・フィルター維持）
function build_query_base($limit, $from_dt, $to_dt, $program, $level, $user, $message) {
  $q = [
    'limit'   => $limit,
    'from_dt' => $from_dt,
    'to_dt'   => $to_dt,
    'program' => $program,
    'level'   => $level,
    'user'    => $user,
    'message' => $message,
  ];
  // 空文字も維持したいので http_build_query に任せる
  return '?' . http_build_query($q);
}
$base_url = strtok($_SERVER['REQUEST_URI'], '?');
$query_base = build_query_base($limit, $from_dt, $to_dt, $program, $level, $user, $message) . '&page_no=';

// Ajax要求ならコンテンツ部分のみ返す
if ($is_ajax) {
  ob_start();
  include __DIR__ . '/log_display_partial.php';
  $html = ob_get_clean();
  echo $html;
  exit;
}
?>

<div class="log-wrapper">
  <div class="log-header">
    <h2>ログ一覧</h2>
    <form id="limit-form" method="get">
      <label for="limit">表示件数:</label>
      <select name="limit" id="limit">
        <?php foreach ($limit_options as $opt): ?>
          <option value="<?php echo $opt; ?>" <?php selected($limit, $opt); ?>><?php echo $opt; ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- フィルターエリア -->
  <form id="filter-form" class="log-filters" autocomplete="on">
    <div class="filter-grid">
      <div class="filter-item">
        <label for="from_dt">開始日時</label>
        <input type="datetime-local" id="from_dt" name="from_dt" value="<?php echo esc_attr($from_dt); ?>">
      </div>
      <div class="filter-item">
        <label for="to_dt">終了日時</label>
        <input type="datetime-local" id="to_dt" name="to_dt" value="<?php echo esc_attr($to_dt); ?>">
      </div>
      <div class="filter-item">
        <label for="program">プログラム名</label>
        <input list="program-list" id="program" name="program" value="<?php echo esc_attr($program); ?>" placeholder="例) スプレッドシート">
        <datalist id="program-list">
          <?php foreach ($programs as $p): ?>
            <option value="<?php echo esc_attr($p); ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="filter-item">
        <label for="level">レベル</label>
        <input list="level-list" id="level" name="level" value="<?php echo esc_attr($level); ?>" placeholder="例) INFO / WARN / ERROR">
        <datalist id="level-list">
          <?php foreach ($levels as $lv): ?>
            <option value="<?php echo esc_attr($lv); ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="filter-item">
        <label for="user">ユーザー名</label>
        <input list="user-list" id="user" name="user" value="<?php echo esc_attr($user); ?>" placeholder="例) 山形">
        <datalist id="user-list">
          <?php foreach ($users as $u): ?>
            <option value="<?php echo esc_attr($u); ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="filter-item filter-message">
        <label for="message">メッセージ</label>
        <input type="text" id="message" name="message" value="<?php echo esc_attr($message); ?>" placeholder="部分一致で検索">
      </div>
    </div>

    <div class="filter-actions">
      <button type="submit" id="search-btn">検索</button>
      <button type="button" id="clear-btn">クリア</button>
    </div>
  </form>
  <!-- ページング〜ログ内容〜ページング（置き換え対象） -->
  <div id="log-content">
    <?php
      // 部分テンプレートをサーバーでも使う
      if (!$is_ajax) {
        include __DIR__ . '/log_display_partial.php';
      }
    ?>
  </div>
</div>

<style>
.log-wrapper {
  max-width: 1200px;
  margin: auto;
  padding: 0 0.5em 0.5em 0.5em;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  font-family: 'Segoe UI', sans-serif;
  box-sizing: border-box;
  overflow-x: auto;
}
.log-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.log-header select {
  padding: 0.4em;
  border-radius: 4px;
  border: 1px solid #ccc;
}

/* フィルター */
.log-filters {
  margin: 0.75em 0 1em;
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 0.75em;
  background: #f9fafb;
}
.filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.75em;
}
.filter-item {
  display: flex;
  flex-direction: column;
  min-width: 160px;
}
.filter-item label {
  font-size: 0.8em;   /* 少し小さめ */
  margin-bottom: 0.2em;
}

.filter-item input {
  font-size: 0.85em;  /* 入力文字も小さめ */
  padding: 0.35em;    /* ボックスもコンパクトに */
}
.filter-item.filter-message {
  grid-column: span 1;
}
.filter-actions {
  margin-top: 0.5em;
  display: flex;
  gap: 0.5em;
}
.filter-actions button {
  font-size: 0.85em;
  padding: 0.4em 0.7em;
}
#search-btn { background: #0073aa; color: #fff; }
#search-btn:hover { background: #006097; }
#clear-btn { background: #e5e7eb; color: #333; }
#clear-btn:hover { background: #d1d5db; }

/* テーブル */
.log-table {
  width: 100%;
  border-collapse: collapse;
  box-sizing: border-box;
  table-layout: auto;
}
.log-table th, .log-table td {
  padding: 0.05em 0.35em 0.05em 0.35em;
  border-bottom: 1px solid #eee;
  text-align: left;
}
.log-table th {
  background-color: #f9f9f9;
  font-weight: 600;
  color: #333;
}
.log-table tr:hover {
  background-color: #f0f8ff;
}
.log-table td,
.log-table th {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.log-table td:last-child,
.log-table th:last-child {
  white-space: normal;
  word-break: break-word;
}

/* ページング */
.pagination {
  margin: 1em 0;
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 6px;
}
.pagination.top {
  margin-top: 0.5em;
  margin-bottom: 0.9em;
  text-align: center;
}
.pagination a {
  display: inline-block;
  min-width: 28px;
  padding: 6px 10px;
  background: #f9f9f9;
  color: #333;
  text-decoration: none;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 13px;
  text-align: center;
  cursor: pointer;
}
.pagination a:hover {
  background-color: #e0f0ff;
  color: #0073aa;
  border-color: #0073aa;
}
.pagination a.active {
  background: #0073aa;
  color: #fff;
  border-color: #0073aa;
  font-weight: bold;
}
.pagination a.nav {
  background: #eee;
}

/* レスポンシブ */
@media (max-width: 1024px) {
  .filter-grid { grid-template-columns: repeat(3, 1fr); }
  .filter-item.filter-message { grid-column: span 3; }
}
@media (max-width: 640px) {
  .filter-grid { grid-template-columns: repeat(1, 1fr); }
  .filter-item.filter-message { grid-column: span 1; }
  .pagination a { padding: 0.4em 0.6em; }
}
</style>

<script>
(function(){
  // クエリ文字列を組み立て
  function buildQuery(params) {
    return new URLSearchParams(params).toString();
  }

  // 現在のフォーム値を取得
  function getFilterParams(){
    const fd = new FormData(document.getElementById('filter-form'));
    const params = {};
    fd.forEach((v,k)=>params[k]=v);
    params['limit'] = document.getElementById('limit').value;
    return params;
  }

  // Ajaxでログ部分を更新
  async function fetchLogs(params){
    if(!params['page_no']) params['page_no'] = 1;
    params['action'] = 'fetch_logs';

    const url = '/wp-admin/admin-ajax.php?' + new URLSearchParams(params).toString();
    console.log(url);

    const res = await fetch(url,{method:'GET',credentials:'same-origin'});
    if(!res.ok) throw new Error('Failed to fetch');
    const html = await res.text();
    document.getElementById('log-content').innerHTML = html;

    // ページングリンク再バインド（ここが重要！）
    setTimeout(() => {
      bindPagination();
    }, 0);

    // 履歴に反映
    const historyParams = Object.assign({}, params);
    delete historyParams['action'];
    const historyUrl = window.location.pathname + '?' + new URLSearchParams(historyParams).toString();
    window.history.replaceState(null,'',historyUrl);
  }

  document.getElementById('limit-form').addEventListener('submit', function(e) {
    e.preventDefault(); // ← これが重要！
  });

  function getInitialPageNo() {
    const urlParams = new URLSearchParams(window.location.search);
    const page = parseInt(urlParams.get('page_no'), 10);
    return isNaN(page) || page < 1 ? 1 : page;
  }

  document.addEventListener('DOMContentLoaded', () => {
    const content = document.getElementById('log-content');
    if (!content || content.innerHTML.trim() === '') {
      const params = getCurrentParams();
      params.page_no = getInitialPageNo();
      fetchLogs(params);
    }
  });

  function getCurrentParams() {
    return {
      from_dt: document.querySelector('#from_dt')?.value || '',
      to_dt: document.querySelector('#to_dt')?.value || '',
      program: document.querySelector('#program')?.value || '',
      level: document.querySelector('#level')?.value || '',
      user: document.querySelector('#user')?.value || '',
      message: document.querySelector('#message')?.value || '',
      limit: document.querySelector('#limit')?.value || 100,
      page_no: 1 // デフォルト
    };
  }

  function bindPagination() {
    document.querySelectorAll('.pagination a').forEach(link => {
      link.addEventListener('click', e => {
        e.preventDefault();
        const page = e.currentTarget.dataset.page;
        if (!page) return;

        const params = getCurrentParams(); // ← ここでフォーム値を取得
        params.page_no = page;

        fetchLogs(params);
      });
    });
  }

  // 検索ボタン
  document.getElementById('filter-form').addEventListener('submit',function(e){
    e.preventDefault();
    const params = getFilterParams();
    params['page_no'] = 1;
    fetchLogs(params).catch(console.error);
  });

  // クリアボタン
  document.getElementById('clear-btn').addEventListener('click',function(){
    const form = document.getElementById('filter-form');
    form.reset();
    const params = getFilterParams();
    params['from_dt']='';
    params['to_dt']='';
    params['program']='';
    params['level']='';
    params['user']='';
    params['message']='';
    params['page_no']=1;
    fetchLogs(params).catch(console.error);
  });

  // 表示件数変更
  document.getElementById('limit').addEventListener('change', function(e){
    e.preventDefault();
    const params = getFilterParams();
    params['page_no'] = 1;
    fetchLogs(params).catch(console.error);
  });

  // 初期バインド
  bindPagination();
})();
</script>
