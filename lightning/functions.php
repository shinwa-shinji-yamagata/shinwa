<?php

define( 'LIG_G3_DIR', '_g3' );
define( 'LIG_G2_DIR', '_g2' );

define( 'LIG_DEBUG', false );

require_once __DIR__ . '/vendor/autoload.php';

if ( true === LIG_DEBUG ) {
	function lightning_debug_mode() {
		$options = lightning_get_theme_options();
		// $options = get_option( 'lightning_theme_options' );
		// unset( $options['layout'] );
		// update_option( 'lightning_theme_options', $options );
		print '<pre style="text-align:left">';
		print_r( $options );
		print '</pre>';
	}
	add_action( 'lightning_site_header_after', 'lightning_debug_mode' );
}

/**
 * Check is G3
 *
 * @return bool
 */
function lightning_is_g3() {

	$return = true;
	$g       = get_option( 'lightning_theme_generation' );
	$options = get_option( 'lightning_theme_options' );

	if ( '1' === get_option( 'fresh_site' ) ) {
		// 新規サイトの場合はG3に指定.
		update_option( 'lightning_theme_generation', 'g3' );
		$return = true;
	} else if ( 'g3' === $g ) {
		$return = true;
	} elseif ( 'g2' === $g ) {
		$return = false;
	} else {
		$skin    = get_option( 'lightning_design_skin' );
		if ( 'origin2' === $skin ) {
			update_option( 'lightning_theme_generation', 'g2' );
			$return = false;
		} elseif ( 'origin3' === $skin ) {
			update_option( 'lightning_theme_generation', 'g3' );
			$return = true;
		} elseif ( empty( $options ) ) {
			// 後から Lightning をインストールした場合は G3 にする
			// （新規サイトではない && lightning_theme_options が存在しない）
			update_option( 'lightning_theme_generation', 'g3' );
			$return = true;
		} else {
			// これ以外は旧ユーザー（Lightning Pro）の可能性が高いのでG2.
			update_option( 'lightning_theme_generation', 'g2' );
			$return = false;
		}
	}
	return apply_filters( 'lightning_is_g3', $return );
}

require __DIR__ . '/inc/class-ltg-template-redirect.php';

/**
 * 最終的に各Gディレクトリに移動
 */
if ( ! function_exists( 'lightning_get_template_part' ) ) {
	function lightning_get_template_part( $slug, $name = null, $args = array() ) {

		if ( lightning_is_g3() ) {
			$g_dir = '_g3';
		} else {
			$g_dir = '_g2';
		}

		/**
		 * 読み込み優先度
		 *
		 * 1.child g階層 nameあり
		 * 2.child 直下 nameあり
		 * 3.parent g階層 nameあり
		 *
		 * 4.child g階層 nameなし
		 * 5.child 直下 nameなし
		 * 6.parent g階層 nameなし
		 */

		/* Almost the same as the core */
		$template_path_array = array();
		$name                = (string) $name;

		// Child theme G directory
		if ( preg_match( '/^' . $g_dir . '/', $slug ) ) {
			// 1. g階層がもともと含まれている場合
			if ( '' !== $name ) {
				$template_path_array[] = get_stylesheet_directory() . "/{$slug}-{$name}.php";
			}
		} else {
			// g階層が含まれていない場合

			// 1. g階層付きのファイルパス
			if ( '' !== $name ) {
				$template_path_array[] = get_stylesheet_directory() . '/' . $g_dir . "/{$slug}-{$name}.php";
			}
			// 2. 直下のファイルパス
			if ( '' !== $name ) {
				$template_path_array[] = get_stylesheet_directory() . "/{$slug}-{$name}.php";
			}
		}

		if ( preg_match( '/^' . $g_dir . '/', $slug ) ) {
			// 3. g階層がもともと含まれている場合
			if ( '' !== $name ) {
				$template_path_array[] = get_template_directory() . "/{$slug}-{$name}.php";
			}
		} else {
			// 3. g階層がもともと含まれていない場合
			if ( '' !== $name ) {
				$template_path_array[] = get_template_directory() . '/' . $g_dir . "/{$slug}-{$name}.php";
			}
		}

		// Child theme G directory
		if ( preg_match( '/^' . $g_dir . '/', $slug ) ) {
			// 4. g階層がもともと含まれている場合
			$template_path_array[] = get_stylesheet_directory() . "/{$slug}.php";
		} else {
			// g階層が含まれていない場合
			// 4. g階層付きのファイルパス
			$template_path_array[] = get_stylesheet_directory() . '/' . $g_dir . "/{$slug}.php";
			// 5. 直下のファイルパス
			$template_path_array[] = get_stylesheet_directory() . "/{$slug}.php";
		}

		if ( preg_match( '/^' . $g_dir . '/', $slug ) ) {
			// g階層がもともと含まれている場合
			// 6. 親のg階層
			$template_path_array[] = get_template_directory() . "/{$slug}.php";
		} else {
			// 6. 親のg階層
			$template_path_array[] = get_template_directory() . '/' . $g_dir . "/{$slug}.php";
		}

		foreach ( (array) $template_path_array as $template_path ) {
			if ( file_exists( $template_path ) ) {
				$require_once = false;
				load_template( $template_path, $require_once );
				break;
			}
		}
	}
}

if ( lightning_is_g3() ) {
	require __DIR__ . '/' . LIG_G3_DIR . '/functions.php';
} else {
	require __DIR__ . '/' . LIG_G2_DIR . '/functions.php';
}

require __DIR__ . '/inc/customize-basic.php';
require __DIR__ . '/inc/tgm-plugin-activation/tgm-config.php';
require __DIR__ . '/inc/vk-old-options-notice/vk-old-options-notice-config.php';
require __DIR__ . '/inc/admin-mail-checker.php';
require __DIR__ . '/inc/functions-compatible.php';
require __DIR__ . '/inc/font-awesome/font-awesome-config.php';
require __DIR__ . '/inc/old-page-template.php';

require __DIR__ . '/inc/class-ltg-theme-json-activator.php';
new LTG_Theme_Json_Activator();

/**
 * 世代切り替えした時に同時にスキンも変更する処理
 *
 * 世代は lightning_theme_generation で管理している。
 *
 *      generetionに変更がある場合
 *          今の世代でのスキン名を lightning_theme_options の配列の中に格納しておく
 *          lightning_theme_option の中に格納されている新しい世代のスキンを取得
 *          スキンをアップデートする *
 */

function lightning_change_generation( $old_value, $value, $option ) {
	// 世代変更がある場合
	if ( $value !== $old_value ) {

		// 現状のスキンを取得
		$current_skin = get_option( 'lightning_design_skin' );

		if ( $current_skin ) {
			// オプションを取得
			$options = get_option( 'lightning_theme_options' );
			if ( ! $options || ! is_array( $options ) ) {
				$options = array();
			}
			$options[ 'previous_skin_' . $old_value ] = $current_skin;
			// 既存のスキンをオプションに保存
			update_option( 'lightning_theme_options', $options );
		}

		// 前のスキンが保存されている場合
		if ( ! empty( $options[ 'previous_skin_' . $value ] ) ) {
			$new_skin = esc_attr( $options[ 'previous_skin_' . $value ] );

			// 前のスキンが保存されていない場合
		} elseif ( 'g3' === $value ) {
				$new_skin = 'origin3';
		} else {
			$new_skin = 'origin2';
		}
		update_option( 'lightning_design_skin', $new_skin );
	}
}
add_action( 'update_option_lightning_theme_generation', 'lightning_change_generation', 10, 3 );

add_action('template_redirect', 'redirect_if_not_logged_in');
function redirect_if_not_logged_in() {
    if ( !is_user_logged_in() && !is_page('login') ) {
        wp_redirect( wp_login_url() );
        exit;
    }
}

function custom_login_redirect( $redirect_to, $request, $user ) {
    return home_url(); // トップページにリダイレクト
}
add_filter( 'login_redirect', 'custom_login_redirect', 10, 3 );

function custom_link_buttons_shortcode() {
    global $wpdb;
    $today_url = esc_url($wpdb->get_var("SELECT url FROM sw_today_sheet WHERE id = 1"));

    $buttons = [
        ['label' => '日毎現場表（TOP）', 'url' => 'https://drive.google.com/drive/folders/1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt', 'color' => '#d6eaf8'],
        ['label' => '日毎現場表（今日）', 'url' => $today_url, 'color' => '#fdebd0'],
        ['label' => '日毎現場表 月次集計', 'url' => '/monthly_higoto_genba_sum/', 'color' => '#d5f5e3'],
        ['label' => '請求書→PROCESS向けExcel', 'url' => '/seikyu/', 'color' => '#d0ece7'],
        ['label' => '注文書', 'url' => 'https://drive.google.com/drive/folders/1hQR_l1-4xlnxazHy5GHJlRHxBz96i-m0', 'color' => '#e8daef'],
        ['label' => '業者マスタ', 'url' => '/gyousya_master/', 'color' => '#f9ebea'],
        ['label' => '現場マスタ', 'url' => '/genba_master/', 'color' => '#d1f2eb'],
        ['label' => '新和なんでもAIボット', 'url' => '/shinwabot/', 'color' => '#fef5e7'],
        ['label' => '社員マスタ', 'url' => '/staff_master/', 'color' => '#f5b7b1'],
        ['label' => 'ユーザー管理', 'url' => '/wp-admin/users.php', 'color' => '#d5dbdb'],
        ['label' => 'ログ参照', 'url' => '/log_display/', 'color' => '#f2f3f4'],
    ];

    ob_start();
    ?>
    <style>
        .custom-header {
            background-color: slategray;
            padding: 16px 24px;
            text-align: center;
            font-size: 1.5em;
            font-weight: bold;
            color: #fff; /* 白文字に変更 */
            margin-bottom: 24px;
        }

        .custom-button-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin: 0 0 20px 0;
        }

        @media (min-width: 768px) {
            .custom-button-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        .custom-button-grid a {
            display: block;
            padding: 14px 20px;
            color: #333;
            text-align: center;
            text-decoration: none;
            font-weight: bold;
            border-radius: 8px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .custom-button-grid a:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }
    </style>

    <div class="custom-header">社内システムTOP</div>

    <div class="custom-button-grid">
        <?php foreach ($buttons as $btn): ?>
            <a href="<?php echo esc_url($btn['url']); ?>" target="_blank" style="background-color: <?php echo esc_attr($btn['color']); ?>;">
                <?php echo esc_html($btn['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('custom_link_buttons', 'custom_link_buttons_shortcode');

function enqueue_lightning_scripts() {
  wp_enqueue_script('jquery');
  wp_enqueue_script('jquery-ui-core');
  wp_enqueue_script('jquery-ui-autocomplete');
  wp_enqueue_script('jquery-ui-datepicker');

  wp_enqueue_style(
    'jquery-ui-css',
    'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css'
  );

  wp_enqueue_script(
    'lightning-autocomplete',
    get_template_directory_uri() . '/assets/js/autocomplete.js', // ← 修正ここ！
    ['jquery', 'jquery-ui-core', 'jquery-ui-autocomplete', 'jquery-ui-datepicker'],
    null,
    true
  );

  wp_localize_script('lightning-autocomplete', 'swpad_ajax', [
    'ajax_url' => admin_url('admin-ajax.php')
  ]);
}
add_action('wp_enqueue_scripts', 'enqueue_lightning_scripts');

// Ajaxハンドラ（現場名検索）
add_action('wp_ajax_swpad_search', 'swpad_search_sites');
add_action('wp_ajax_nopriv_swpad_search', 'swpad_search_sites');

function swpad_search_sites() {
  global $wpdb;
  $term = sanitize_text_field($_GET['term']);
  $results = [];

  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name FROM swpad_genba WHERE name LIKE %s ORDER BY create_date DESC LIMIT 10",
    '%' . $wpdb->esc_like($term) . '%'
  ));

  foreach ($rows as $row) {
    $results[] = [
      'label' => $row->name,
      'value' => '/swpad/' . $row->id . '/'
    ];
  }

  wp_send_json($results);
}

function include_php_shortcode($atts) {
  if (is_admin()) {
    return '<p>管理画面では表示されません。</p>';
  }

  $atts = shortcode_atts([
    'file' => ''
  ], $atts);

  $file = $atts['file'];
  if (strpos($file, '..') !== false) {
    return '<p>不正なファイルパスです。</p>';
  }

  $path = get_template_directory() . '/' . $file;

  if (file_exists($path)) {
    ob_start();
    include $path;
    return ob_get_clean();
  } else {
    return '<p>ファイルが見つかりません: ' . esc_html($file) . '</p>';
  }
}
add_shortcode('include_php', 'include_php_shortcode');

require_once get_template_directory() . '/functions/user-role.php';

function hide_admin_bar_for_limited_roles() {
    $user = wp_get_current_user();
    $hidden_roles = array('limited', 'keiribu', 'soumubu', 'sekkeibu', 'koukjibu'); // 必要に応じて追加

    foreach ($hidden_roles as $role) {
        if (in_array($role, $user->roles)) {
            add_filter('show_admin_bar', '__return_false');
            break;
        }
    }
}
add_action('init', 'hide_admin_bar_for_limited_roles');

add_action('admin_footer-user-edit.php', 'hide_no_role_option');
function hide_no_role_option() {
  ?>
  <script>
    jQuery(document).ready(function($) {
      $('#role option[value=""]').remove(); // 空の value を持つ option を削除
    });
  </script>
  <?php
}

function remove_unwanted_roles_from_dropdown($roles) {
    $roles_to_remove = array('limited', 'subscriber', 'contributor', 'author', 'editor');

    foreach ($roles_to_remove as $role) {
        if (isset($roles[$role])) {
            unset($roles[$role]);
        }
    }

    return $roles;
}
add_filter('editable_roles', 'remove_unwanted_roles_from_dropdown');

add_action('wp_head', 'show_menu_for_logged_in_user');
function show_menu_for_logged_in_user(){
  if(is_user_logged_in()){
    echo '<script>
      document.addEventListener("DOMContentLoaded", function() {
        const loginMenu = document.querySelector(".login-only-menu");
        if (loginMenu) {
          loginMenu.style.display = "block";
        }
      });
    </script>';
  }
}

function start_session_if_needed() {
  if (!session_id()) {
    session_start();
  }
}
add_action('init', 'start_session_if_needed');

function add_custom_query_vars($vars) {
  $vars[] = 'page_no';
  return $vars;
}
add_filter('query_vars', 'add_custom_query_vars');

function init_google_client($config = []) {
    $client = new Google_Client();
    $client->setApplicationName($config['app_name'] ?? 'Google Sheets Aggregator');
    $client->setScopes($config['scopes'] ?? []);
    $client->setAuthConfig($config['credentials_path']);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    if (file_exists($config['token_path'])) {
        $accessToken = json_decode(file_get_contents($config['token_path']), true);
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($config['token_path'], json_encode($client->getAccessToken()));
        }
    } else {
        if (php_sapi_name() === 'cli') {
            $authUrl = $client->createAuthUrl();
            echo "Open the following link in your browser:\n$authUrl\n";
            echo 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));
        } else {
            wp_die('Google APIの初期認証が必要です。CLIで実行してください。');
        }

        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
        file_put_contents($config['token_path'], json_encode($accessToken));
        $client->setAccessToken($accessToken);
    }

    return $client;
}

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
    include __DIR__ . '/shinwa/log_display_partial.php';
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
         ORDER BY id DESC LIMIT 20",
        $like, $like, $like
    ));

    $suggestions = array_map(function($row) {
        return $row->name . '(' . $row->code . ')';
    }, $results);

    wp_send_json($suggestions);
}

add_action('wp_ajax_fetch_logs', 'handle_fetch_logs');
add_action('wp_ajax_nopriv_fetch_logs', 'handle_fetch_logs');

function handle_fetch_logs() {
  include __DIR__ . '/log_display.php';
  wp_die(); // Ajax 終了
}

function sw_log($program, $message, $level = 'INFO', $google_account = null) {
    global $wpdb;

    $user_id = null;
    $user_name = 'システム';

    if (!empty($google_account)) {
        $user = get_user_by('email', $google_account);
        if ($user) {
            $user_id = $user->ID;
            $user_name = $user->display_name;
        } else {
            $user_name = $google_account;
        }
    } elseif (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        if ($user) {
            $user_name = $user->display_name;
        }
    }

    $table = 'sw_logs';
    $data = [
        'log_time'   => current_time('mysql'),
        'program'    => $program,
        'level'      => $level,
        'user_name'  => $user_name,
        'message'    => $message,
        'user_id'    => $user_id,
    ];

    $wpdb->insert($table, $data);
}

function swpad_custom_rewrite_rule() {
  // /swpad/1/2/
  add_rewrite_rule(
    '^swpad/([0-9]+)/([0-9]+)/?$',
    'index.php?pagename=swpad&id=$matches[1]&fid=$matches[2]',
    'top'
  );

  // /swpad/1/
  add_rewrite_rule(
    '^swpad/([0-9]+)/?$',
    'index.php?pagename=swpad&id=$matches[1]',
    'top'
  );
}
add_action('init', 'swpad_custom_rewrite_rule');

function swpad_add_query_vars($vars) {
  $vars[] = 'id';
  $vars[] = 'fid';
  return $vars;
}
add_filter('query_vars', 'swpad_add_query_vars');


// 現場名登録Ajax処理
add_action('wp_ajax_swpad_genba_insert', 'swpad_genba_insert');
add_action('wp_ajax_nopriv_swpad_genba_insert', 'swpad_genba_insert');

function swpad_genba_insert() {
  global $wpdb;
  $name = sanitize_file_name($_POST['name'] ?? '');

  if (!$name) {
    wp_send_json_error('名前が空です');
  }

  // DB登録
  $result = $wpdb->insert('swpad_genba', [
    'name' => $name,
    'create_date' => current_time('mysql')
  ]);

  if ($result) {
    // フォルダ作成（ドキュメントルート/wp-content/themes/swpad/img/{現場名}）
    $base_path = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/themes/swpad/img/' . $name;

    if (!file_exists($base_path)) {
      wp_mkdir_p($base_path);
    }

    wp_send_json_success();
  } else {
    wp_send_json_error('DB登録失敗');
  }
}


// Ajaxハンドラ（現場一覧ページング）
add_action('wp_ajax_swpad_genba_list', 'swpad_genba_list');
add_action('wp_ajax_nopriv_swpad_genba_list', 'swpad_genba_list');

function swpad_genba_list() {
  global $wpdb;
  $page = max(1, intval($_GET['page'] ?? 1));
  $limit = 20;
  $offset = ($page - 1) * $limit;

  $total = $wpdb->get_var("SELECT COUNT(*) FROM swpad_genba");
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name FROM swpad_genba ORDER BY create_date DESC LIMIT %d OFFSET %d",
    $limit, $offset
  ));

  wp_send_json([
    'items' => $rows,
    'total_pages' => ceil($total / $limit)
  ]);
}

add_action('wp_ajax_swpad_genba_search', 'swpad_genba_search');
add_action('wp_ajax_nopriv_swpad_genba_search', 'swpad_genba_search');

function swpad_genba_search() {
  global $wpdb;
  $term = sanitize_text_field($_GET['term']);
  $results = [];

  if (mb_strlen($term) < 1) {
    // 0文字なら最新10件を返す
    $rows = $wpdb->get_results("SELECT id, name FROM swpad_genba ORDER BY create_date DESC LIMIT 30");
  } else {
    // 1文字以上なら部分一致検索
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, name FROM swpad_genba WHERE name LIKE %s ORDER BY create_date DESC LIMIT 50",
      '%' . $wpdb->esc_like($term) . '%'
    ));
  }

  foreach ($rows as $row) {
    $results[] = [
      'label' => $row->name,
      'value' => '/swpad/' . $row->id . '/'
    ];
  }

  wp_send_json($results);
}

function swpad_enqueue_scripts() {
  $theme_dir = get_stylesheet_directory();
  $theme_uri = get_stylesheet_directory_uri();

  $mobile_path = $theme_dir . '/js/swpad-mobile.js';
  $mobile_ver = file_exists($mobile_path) ? filemtime($mobile_path) : null;

  wp_enqueue_script(
    'swpad-mobile',
    $theme_uri . '/js/swpad-mobile.js',
    array('jquery'),
    $mobile_ver, // ← キャッシュ対策
    true
  );

  wp_localize_script('swpad-mobile', 'swpad_ajax', array(
    'ajax_url' => admin_url('admin-ajax.php')
  ));
}
add_action('wp_enqueue_scripts', 'swpad_enqueue_scripts');

// フォルダ作成
add_action('wp_ajax_swpad_create_folder', 'swpad_create_folder');
add_action('wp_ajax_nopriv_swpad_create_folder', 'swpad_create_folder');

function swpad_create_folder() {
  global $wpdb;

  $id = intval($_POST['id'] ?? 0);
  $folder_name = sanitize_file_name($_POST['folder_name'] ?? '');

  if (!$id || !$folder_name) {
    wp_send_json_error('IDまたはフォルダ名が不正です');
  }

  $genba = $wpdb->get_var($wpdb->prepare("SELECT name FROM swpad_genba WHERE id = %d", $id));
  if (!$genba) {
    wp_send_json_error('現場が見つかりません');
  }

  // DB登録
  $result = $wpdb->insert('swpad_genba_folder', [
    'genba_id' => $id,
    'name' => $folder_name,
    'create_date' => current_time('mysql')
  ]);

  $base_dir = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/themes/swpad/img/';
  $target_dir = $base_dir . sanitize_file_name($genba) . '/' . $folder_name;

  if (!file_exists($target_dir)) {
    if (!wp_mkdir_p($target_dir)) {
      wp_send_json_error('フォルダ作成に失敗しました');
    }
  }

  $base_dir = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/themes/swpad/tmb/';
  $target_dir = $base_dir . sanitize_file_name($genba) . '/' . $folder_name;

  if (!file_exists($target_dir)) {
    if (!wp_mkdir_p($target_dir)) {
      wp_send_json_error('フォルダ作成に失敗しました');
    }
  }

  wp_send_json_success();
}


// 写真アップロード
add_action('wp_ajax_swpad_upload_photo', 'swpad_upload_photo');
add_action('wp_ajax_nopriv_swpad_upload_photo', 'swpad_upload_photo');

function swpad_upload_photo() {
  global $wpdb;

  if (!isset($_FILES['upload-file']) || $_FILES['upload-file']['error'] !== UPLOAD_ERR_OK) {
    wp_send_json_error('ファイルがありません');
  }

  $file = $_FILES['upload-file'];
  $name = sanitize_file_name($file['name']);

  $id = intval($_POST['id'] ?? 0);
  $genba = $wpdb->get_var($wpdb->prepare("SELECT name FROM swpad_genba WHERE id = %d", $id));
  if (!$genba) {
    wp_send_json_error('現場が見つかりません');
  }

  $fid = intval($_POST['fid'] ?? 0);
  if ($fid) {
    $folder = $wpdb->get_var($wpdb->prepare("SELECT name FROM swpad_genba_folder WHERE id = %d", $fid));
    if (!$folder) {
      wp_send_json_error('フォルダが見つかりません');
    }
  }

  $base_dir = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/themes/swpad/';
  $img_dir = $base_dir . 'img/' . sanitize_file_name($genba);
  $tmb_dir = $base_dir . 'tmb/' . sanitize_file_name($genba);

  if ($folder) {
    $img_dir .= '/' . sanitize_file_name($folder);
    $tmb_dir .= '/' . sanitize_file_name($folder);
  }

  wp_mkdir_p($img_dir);
  wp_mkdir_p($tmb_dir);

  $target_path = $img_dir . '/' . $name;
  if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    wp_send_json_error('アップロード失敗');
  }

  // サムネイル生成
  $image_info = getimagesize($target_path);
  if (!$image_info) {
    wp_send_json_error('画像情報取得失敗');
  }

  list($width, $height) = $image_info;
  $max_size = 256;
  $scale = min($max_size / $width, $max_size / $height, 1);
  $new_width = intval($width * $scale);
  $new_height = intval($height * $scale);

  $thumb = imagecreatetruecolor($new_width, $new_height);
  switch ($image_info['mime']) {
    case 'image/jpeg':
      $src = imagecreatefromjpeg($target_path);
      break;
    case 'image/png':
      $src = imagecreatefrompng($target_path);
      break;
    case 'image/gif':
      $src = imagecreatefromgif($target_path);
      break;
    default:
      wp_send_json_error('未対応の画像形式');
  }

  imagecopyresampled($thumb, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
  $thumb_path = $tmb_dir . '/' . $name;
  imagejpeg($thumb, $thumb_path, 90);
  imagedestroy($thumb);
  imagedestroy($src);

  wp_send_json_success();
}
