<?php
// 役割（Role）のベース "limited" を作成
function add_limited_role() {
    if (!get_role('limited')) {
        add_role('limited', '制限ユーザー', array(
            'read'           => false, // 必要なら true に
            'edit_posts'     => false,
            'publish_posts'  => false,
            'delete_posts'   => false,
            'edit_profile'   => false,
            'edit_users'     => false,
        ));
    }
}
add_action('init', 'add_limited_role');

// 派生ロールの作成
function add_custom_roles() {
    $limited = get_role('limited');
    $caps = $limited ? $limited->capabilities : array();

    if (!get_role('keiri'))     { add_role('keiri',     '経理',     $caps); }
    if (!get_role('soumu'))     { add_role('soumu',     '総務',     $caps); }
    if (!get_role('sekkeibu'))  { add_role('sekkeibu',  '設計部',   $caps); }
    if (!get_role('koujibu'))   { add_role('koujibu',   '工事部',   $caps); }
    if (!get_role('kjimu'))     { add_role('kjimu',     '工事事務', $caps); }
    if (!get_role('koubai'))    { add_role('koubai',    '購買',     $caps); }
    if (!get_role('geigyou'))   { add_role('geigyou',   '技術営業', $caps); }
    if (!get_role('sonota'))    { add_role('sonota',    'その他',   $caps); }
}
add_action('init', 'add_custom_roles');

// 管理画面に「役職設定」メニューを追加
function add_position_settings_page() {
    add_options_page('役職設定', '役職設定', 'manage_options', 'position-settings', 'render_position_settings_page');
}
add_action('admin_menu', 'add_position_settings_page');

// 設定ページの中身（HTMLはechoで安全に出力）
function render_position_settings_page() {
    if (isset($_POST['positions'])) {
        check_admin_referer('position_settings_save');

        $raw = explode("\n", (string)$_POST['positions']);
        $positions = array_map('sanitize_text_field', $raw);
        $positions = array_filter($positions, function($v) { return $v !== ''; });

        update_option('custom_user_positions', $positions);
        echo '<div class="updated"><p>保存しました！</p></div>';
    }

    $saved_positions = get_option('custom_user_positions', array('社長', '副社長', '部長', 'マネージャー', '一般'));
    $text = implode("\n", $saved_positions);

    echo '<div class="wrap">';
    echo '<h1>役職設定</h1>';
    echo '<form method="post">';
    wp_nonce_field('position_settings_save');
    echo '<textarea name="positions" rows="10" cols="40">' . esc_textarea($text) . '</textarea><br>';
    echo '<p>1行に1つずつ役職を入力してください。</p>';
    submit_button();
    echo '</form>';
    echo '</div>';
}

// ユーザー編集画面に「役職」フィールドを追加
function add_user_position_field($user) {
    $positions = get_option('custom_user_positions', array('社長', '副社長', '部長', 'マネージャー', '一般'));
    $current = get_user_meta($user->ID, 'position', true);

    echo '<h3>役職情報</h3>';
    echo '<table class="form-table"><tr>';
    echo '<th><label for="position">役職</label></th>';
    echo '<td><select name="position" id="position">';
    foreach ($positions as $pos) {
        $selected = selected($current, $pos, false);
        echo '<option value="' . esc_attr($pos) . '" ' . $selected . '>' . esc_html($pos) . '</option>';
    }
    echo '</select>';
    echo '<br><span class="description">このユーザーの役職を選択してください。</span>';
    echo '</td></tr></table>';
}
add_action('show_user_profile', 'add_user_position_field');
add_action('edit_user_profile', 'add_user_position_field');

// 保存処理
function save_user_position_field($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }
    if (isset($_POST['position'])) {
        update_user_meta($user_id, 'position', sanitize_text_field($_POST['position']));
    }
}
add_action('personal_options_update', 'save_user_position_field');
add_action('edit_user_profile_update', 'save_user_position_field');
