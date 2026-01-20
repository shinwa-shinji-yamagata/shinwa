<?php

// 業者のオートコンプリート用
add_action('wp_ajax_autocomplete_gyousya', 'autocomplete_gyousya');
add_action('wp_ajax_nopriv_autocomplete_gyousya', 'autocomplete_gyousya');

function autocomplete_gyousya() {
  global $wpdb;
  $query = sanitize_text_field($_GET['query'] ?? '');

  if (mb_strlen($query) < 1) {
    wp_send_json([]);
  }

  $like = '%' . $wpdb->esc_like($query) . '%';

  $results = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT name FROM sw_gyousya_master
     WHERE name_ryaku LIKE %s OR name_kana LIKE %s OR name_zen_kana LIKE %s OR name_hiragana LIKE %s
     ORDER BY name ASC LIMIT 20",
    $like, $like, $like, $like
  ));

  wp_send_json($results);
}

// テーブル表示用
add_action('wp_ajax_filter_gyousya', 'filter_gyousya');
add_action('wp_ajax_nopriv_filter_gyousya', 'filter_gyousya');

function filter_gyousya() {
  global $wpdb;
  $keyword = sanitize_text_field($_GET['keyword'] ?? '');

  $where = '';
  $params = [];

  if (!empty($keyword)) {
    $like = '%' . $wpdb->esc_like($keyword) . '%';
    $where = "WHERE name LIKE %s OR name_ryaku LIKE %s OR name_kana LIKE %s OR name_zen_kana LIKE %s OR name_hiragana LIKE %s";
    $params = [$like, $like, $like, $like, $like];
  }

  $sql = "SELECT code, name, name2 FROM sw_gyousya_master $where ORDER BY code ASC LIMIT 100";

  $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
  wp_send_json($rows);
}

// 現場コードのオートコンプリート
add_action('wp_ajax_get_genba_codes', 'get_genba_codes');
add_action('wp_ajax_nopriv_get_genba_codes', 'get_genba_codes');

function get_genba_codes() {
    global $wpdb;
    $now_d_code = 
    $results = $wpdb->get_results("
        SELECT DISTINCT code 
        FROM sw_genba_master 
        WHERE name like '共通原価%' 
        ORDER BY id DESC
    ");

    $codes = array_map(function($row) {
        return $row->code;
    }, $results);

    wp_send_json($codes);
}

// ログのオートコンプリート
add_action('wp_ajax_fetch_logs', 'handle_ajax_logs');
add_action('wp_ajax_nopriv_fetch_logs', 'handle_ajax_logs');

function handle_ajax_logs() {
    global $wpdb;
    $table_name = 'sw_logs';

    // パラメータ取得
    $limit   = intval($_GET['limit'] ?? 100);
    $page_no = intval($_GET['page_no'] ?? 1);
    $max_pages = 10;

    $from_dt = sanitize_text_field($_GET['from_dt'] ?? '');
    $to_dt   = sanitize_text_field($_GET['to_dt'] ?? '');
    $program = sanitize_text_field($_GET['program'] ?? '');
    $level   = sanitize_text_field($_GET['level'] ?? '');
    $user    = sanitize_text_field($_GET['user'] ?? '');
    $message = sanitize_text_field($_GET['message'] ?? '');

    // WHERE句の構築
    $where   = [];
    $params  = [];

    if ($from_dt !== '') {
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

    // 総件数
    $count_sql = "SELECT COUNT(*) FROM $table_name $where_sql";
    if (!empty($params)) {
        $total_count = $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
    } else {
        $total_count = $wpdb->get_var($count_sql);
    }

    // 総ページ数
    $total_pages = ceil($total_count / $limit);
    if ($total_pages < 1) $total_pages = 1;
    if ($total_pages > $max_pages) $total_pages = $max_pages;

    // ページ番号とオフセット
    if ($page_no < 1) $page_no = 1;
    $page_no = min($page_no, $total_pages);
    $offset = ($page_no - 1) * $limit;

    // データ取得
    $sql = "SELECT id, log_time, program, level, user_name, message FROM $table_name $where_sql ORDER BY log_time DESC, id DESC LIMIT %d OFFSET %d";
    $data_params = array_merge($params, [$limit, $offset]);
    $logs = $wpdb->get_results($wpdb->prepare($sql, ...$data_params));

    // 部分テンプレートに渡す
    include get_template_directory() . '/shinwa/log_display_partial.php';
    wp_die();
}

// 現場マスタ用のオートコンプリート
add_action('wp_ajax_filter_genba', 'filter_genba_callback');
add_action('wp_ajax_nopriv_filter_genba', 'filter_genba_callback');

function filter_genba_callback() {
    global $wpdb;

    $value = isset($_GET['value']) ? trim($_GET['value']) : '';
    $page  = isset($_GET['page_no']) ? intval($_GET['page_no']) : 1;
    $per_page = 50;
    $offset = ($page - 1) * $per_page;

    if ($value === '') {
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT code, d_code, name, subject
            FROM sw_genba_master
            ORDER BY id DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));

        $total = $wpdb->get_var("SELECT COUNT(*) FROM sw_genba_master");

        wp_send_json(['rows' => $rows, 'total' => intval($total)]);
    }

    $like = '%' . $wpdb->esc_like($value) . '%';

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT code, d_code, name, subject
        FROM sw_genba_master
        WHERE name COLLATE utf8mb4_unicode_ci LIKE %s
           OR subject COLLATE utf8mb4_unicode_ci LIKE %s
        ORDER BY id DESC
        LIMIT %d OFFSET %d
    ", $like, $like, $per_page, $offset));

    $total = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM sw_genba_master
        WHERE name COLLATE utf8mb4_unicode_ci LIKE %s
           OR subject COLLATE utf8mb4_unicode_ci LIKE %s
    ", $like, $like));

    wp_send_json(['rows' => $rows, 'total' => intval($total)]);
}

add_action('wp_ajax_filter_genba', 'ajax_filter_genba');
add_action('wp_ajax_nopriv_filter_genba', 'ajax_filter_genba'); // ログイン不要なら

function ajax_filter_genba() {
  global $wpdb;

  $value = sanitize_text_field($_GET['value'] ?? '');
  $per_page = 50;

  $where = '';
  $params = [];

  if ($value !== '') {
    $where = "WHERE name LIKE %s";
    $params[] = '%' . $wpdb->esc_like($value) . '%';
  }

  $sql = "SELECT code, d_code, name, subject FROM sw_genba_master $where ORDER BY name ASC LIMIT %d";
  $params[] = $per_page;

  $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

  $count_sql = "SELECT COUNT(*) FROM sw_genba_master $where";
  $total = $wpdb->get_var($wpdb->prepare($count_sql, ...array_slice($params, 0, -1)));

  wp_send_json([
    'rows' => $rows,
    'total' => (int)$total,
  ]);
}

add_action('wp_ajax_autocomplete_gyousya_v2', 'autocomplete_gyousya_v2');
add_action('wp_ajax_nopriv_autocomplete_gyousya_v2', 'autocomplete_gyousya_v2');

function autocomplete_gyousya_v2() {
    global $wpdb;
    $query = isset($_GET['query']) ? sanitize_text_field($_GET['query']) : '';

    if (mb_strlen($query) < 1) {
        wp_send_json([]);
    }

    $like = '%' . $wpdb->esc_like($query) . '%';

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT name, code FROM sw_gyousya_master
         WHERE name LIKE %s
            OR name_ryaku LIKE %s
            OR name_kana LIKE %s
            OR name_zen_kana LIKE %s
            OR name_hiragana LIKE %s
            OR name_romaji LIKE %s
         ORDER BY id DESC LIMIT 20",
        $like, $like, $like, $like, $like, $like
    ));

    $suggestions = array_map(function($row) {
        return $row->name . '(' . $row->code . ')';
    }, $results);

    wp_send_json($suggestions);
}

add_action('wp_ajax_autocomplete_genba', 'autocomplete_genba');
add_action('wp_ajax_nopriv_autocomplete_genba', 'autocomplete_genba');

function autocomplete_genba() {
    global $wpdb;
    $query = isset($_GET['query']) ? sanitize_text_field($_GET['query']) : '';

    if (mb_strlen($query) < 1) {
        wp_send_json([]);
    }

    $like = '%' . $wpdb->esc_like($query) . '%';

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT name, code FROM sw_genba_master
         WHERE name LIKE %s OR furigana LIKE %s OR romaji LIKE %s
         ORDER BY id DESC LIMIT 30",
        $like, $like, $like
    ));

    $suggestions = array_map(function($row) {
        return $row->name . '(' . $row->code . ')';
    }, $results);

    wp_send_json($suggestions);
}

add_action('wp_ajax_fetch_logs', 'handle_fetch_logs');
add_action('wp_ajax_nopriv_fetch_logs', 'handle_fetch_logs');
