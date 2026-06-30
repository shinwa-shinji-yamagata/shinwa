<?php
// shinwa/leave_common.php

if (!defined('ABSPATH')) {
    exit;
}

function swl_get_table_name() {
    global $wpdb;
    // プレフィックスなしで sw_leave
    return 'sw_leave';
}

function swl_department_role_map() {
    return [
        '管理部' => [
            'soumu', 'keiri', 'kjimu', 'koubai', 'administrator',
            '総務', '経理', '工事事務', '購買', 'Administrator'
        ],
        '工事部' => ['koujibu', '工事部'],
        '設計部' => ['sekkeibu', '設計部'],
        '技術営業' => ['geigyou', '技術営業'],
    ];
}

/**
 * ロール → 部署名
 */
function swl_get_department_by_roles($roles) {
    if (empty($roles) || !is_array($roles)) return '';

    $map = swl_department_role_map();

    foreach ($roles as $role) {
        foreach ($map as $dep => $role_list) {
            if (in_array($role, $role_list, true)) {
                return $dep;
            }
        }
    }
    return '';
}

/**
 * ユーザーの部署名取得
 */
function swl_get_user_department($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    if (!$user || 0 === $user->ID) {
        return '';
    }
    return swl_get_department_by_roles($user->roles);
}

// --------------------------------------------------
// 部署名 → ロールのマッピング
// --------------------------------------------------
function swl_department_to_roles($department) {
    $map = swl_department_role_map();
    return $map[$department] ?? [];
}

/**
 * 氏名取得（姓＋名）
 */
function swl_get_user_full_name($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    if (!$user || 0 === $user->ID) {
        return '';
    }

    // 一般的な WP の first_name / last_name
    $last_name  = get_user_meta($user->ID, 'last_name', true);
    $first_name = get_user_meta($user->ID, 'first_name', true);

    // もし「姓」「名」で保存しているならこちらも見る
    if (!$last_name) {
        $last_name = get_user_meta($user->ID, '姓', true);
    }
    if (!$first_name) {
        $first_name = get_user_meta($user->ID, '名', true);
    }

    if ($last_name || $first_name) {
        return trim($last_name . ' ' . $first_name);
    }

    return $user->display_name;
}

/**
 * 役職取得（user_meta: position）
 */
function swl_get_user_position($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    if (!$user || 0 === $user->ID) {
        return '';
    }
    $pos = get_user_meta($user->ID, 'position', true);
    return $pos ? $pos : '';
}

/**
 * 承認権限を持つかどうか
 */
function swl_is_approver($user = null) {
    if (!$user) {
        $user = wp_get_current_user();
    }
    if (!$user || 0 === $user->ID) {
        return false;
    }
    $pos = swl_get_user_position($user);
    $roles = $user->roles;

    // 役職ベース
    if (in_array($pos, ['社長', '会長', '副社長', '部長', '総務'], true)) {
        return true;
    }

    // ロール名で総務などを持っている場合も許可
    if (in_array('soumu', $roles, true) || in_array('総務', $roles, true)) {
        return true;
    }

    return false;
}

function swl_is_soumu($user_id) {
    $user = get_user_by('id', $user_id);
    if (!$user) return false;

    $roles = (array) $user->roles;

    // WordPress ロール名 or 日本語ロール名で判定
    return in_array('soumu', $roles, true) || in_array('総務', $roles, true);
}

/**
 * 祝日一覧（必要に応じて更新）
 */
function swl_get_holidays() {
    return [
        // 2026
        '2026-01-01','2026-01-12','2026-02-11','2026-02-23','2026-03-20','2026-04-29','2026-05-03','2026-05-04','2026-05-05','2026-05-06','2026-07-20','2026-08-11','2026-09-21','2026-09-23','2026-10-12','2026-11-03','2026-11-23',
        '2026-12-29','2026-12-30','2026-12-31','2026-01-02','2026-01-03','2026-01-04',
        // 2027
        '2027-01-01','2027-01-11','2027-02-11','2027-02-23','2027-03-21','2027-03-22','2027-04-29','2027-05-03','2027-05-04','2027-05-05','2027-07-19','2027-08-11','2027-09-20','2027-09-23','2027-10-11','2027-11-03','2027-11-23',
        '2027-12-29','2027-12-30','2027-12-31','2027-01-02','2027-01-03','2027-01-04',
        // 2028
        '2028-01-01','2028-01-10','2028-02-11','2028-02-23','2028-03-20','2028-04-29','2028-05-03','2028-05-04','2028-05-05','2028-07-17','2028-08-11','2028-09-18','2028-09-22','2028-10-09','2028-11-03','2028-11-23',
        '2028-12-29','2028-12-30','2028-12-31','2028-01-02','2028-01-03','2028-01-04',
        // 2029
        '2029-01-01','2029-01-08','2029-02-11','2029-02-12','2029-02-23','2029-03-20','2029-04-29','2029-04-30','2029-05-03','2029-05-04','2029-05-05','2029-07-16','2029-08-11','2029-09-17','2029-09-23','2029-09-24','2029-10-08','2029-11-03','2029-11-23',
        '2029-12-29','2029-12-30','2029-12-31','2029-01-02','2029-01-03','2029-01-04',
        // 2030
        '2030-01-01','2030-01-14','2030-02-11','2030-02-23','2030-03-20','2030-04-29','2030-05-03','2030-05-04','2030-05-05','2030-05-06','2030-07-15','2030-08-11','2030-08-12','2030-09-16','2030-09-23','2030-10-14','2030-11-03','2030-11-04','2030-11-23',
        '2030-12-29','2030-12-30','2030-12-31','2030-01-02','2030-01-03','2030-01-04',
        // 2031
        '2031-01-01','2031-01-13','2031-02-11','2031-02-23','2031-02-24','2031-03-21','2031-04-29','2031-05-03','2031-05-04','2031-05-05','2031-05-06','2031-07-21','2031-08-11','2031-09-15','2031-09-23','2031-10-13','2031-11-03','2031-11-23','2031-11-24',
        '2031-12-29','2031-12-30','2031-12-31','2031-01-02','2031-01-03','2031-01-04',
        // 2032
        '2032-01-01','2032-01-12','2032-02-11','2032-02-23','2032-03-20','2032-04-29','2032-05-03','2032-05-04','2032-05-05','2032-07-19','2032-08-11','2032-09-20','2032-09-21','2032-09-22','2032-10-11','2032-11-03','2032-11-23',
        '2032-12-29','2032-12-30','2032-12-31','2032-01-02','2032-01-03','2032-01-04',
        // 2033
        '2033-01-01','2033-01-10','2033-02-11','2033-02-23','2033-03-20','2033-03-21','2033-04-29','2033-05-03','2033-05-04','2033-05-05','2033-07-18','2033-08-11','2033-09-19','2033-09-23','2033-10-10','2033-11-03','2033-11-23',
        '2033-12-29','2033-12-30','2033-12-31','2033-01-02','2033-01-03','2033-01-04',
        // 2034
        '2034-01-01','2034-01-02','2034-01-09','2034-02-11','2034-02-23','2034-03-20','2034-04-29','2034-05-03','2034-05-04','2034-05-05','2034-07-17','2034-08-11','2034-09-18','2034-09-23','2034-10-09','2034-11-03','2034-11-23',
        '2034-12-29','2034-12-30','2034-12-31','2034-01-02','2034-01-03','2034-01-04',
        // 2035
        '2035-01-01','2035-01-08','2035-02-11','2035-02-12','2035-02-23','2035-03-21','2035-04-29','2035-04-30','2035-05-03','2035-05-04','2035-05-05','2035-07-16','2035-08-11','2035-09-17','2035-09-23','2035-09-24','2035-10-08','2035-11-03','2035-11-23',
        '2035-12-29','2035-12-30','2035-12-31','2035-01-02','2035-01-03','2035-01-04',
        // 2036
        '2036-01-01','2036-01-14','2036-02-11','2036-02-23','2036-03-20','2036-04-29','2036-05-03','2036-05-04','2036-05-05','2036-05-06','2036-07-21','2036-08-11','2036-09-15','2036-09-22','2036-10-13','2036-11-03','2036-11-23','2036-11-24',
        '2036-12-29','2036-12-30','2036-12-31','2036-01-02','2036-01-03','2036-01-04',
    ];
}

/**
 * 日数計算（開始日・終了日を含めた日数）
 */
function swl_calc_days_business($start_date, $end_date, $department, $is_half) {

    // 半休なら 0.5 日で即返す
    if ($is_half) {
        return 0.5;
    }

    try {
        $s = new DateTime($start_date);
        $e = new DateTime($end_date);
    } catch (Exception $e) {
        return 0;
    }

    if ($e < $s) return 0;

    // 工事部は土日祝を含める
    $include_weekends = ($department === '工事部');

    $holidays = swl_get_holidays();

    $days = 0;
    $current = clone $s;

    while ($current <= $e) {

        $w = (int)$current->format('w'); // 0=日,6=土
        $d = $current->format('Y-m-d');

        if ($include_weekends) {
            // 設計部 → 全日カウント
            $days++;
        } else {
            // 土日祝を除外
            if ($w !== 0 && $w !== 6 && !in_array($d, $holidays, true)) {
                $days++;
            }
        }

        $current->modify('+1 day');
    }

    return $days;
}

function swl_format_days($days) {
    // 小数点以下が 0 の場合は整数表示
    if (floor($days) == $days) {
        return (string)intval($days);
    }
    // それ以外（0.5 など）はそのまま
    return rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');
}

/**
 * ランダムハッシュ生成（32文字）
 */
function swl_generate_hash() {
    return bin2hex(random_bytes(16));
}

/**
 * 社長メールアドレス取得（テスト時は固定）
 */
function swl_get_shacho_email() {
    $users = get_users([
        'meta_key'   => 'position',
        'meta_value' => '社長',
        'number'     => 1,
        'fields'     => ['user_email'],
    ]);

    if (!empty($users)) {
        return $users[0]->user_email;
    }

    return '';
}

/**
 * 部長（または技術営業の場合は副社長）メールアドレス取得
 * 部署名を元にユーザーを探す
 */
function swl_get_bucho_email_for_department($department) {

    // 技術営業は display_name が「佐藤 信行」の副社長に固定
    if ($department === '技術営業') {

        $users = get_users([
            'meta_key'   => 'position',
            'meta_value' => '副社長',
        ]);

        foreach ($users as $u) {
            if ($u->display_name === '佐藤 信行') {
                return $u->user_email;
            }
        }

        // 念のため fallback（副社長の誰か）
        return $users[0]->user_email ?? '';
    }

    // 部署 → ロール一覧
    $map = swl_department_role_map();
    $roles = $map[$department] ?? [];

    if (empty($roles)) return '';

    // 部署ロール＋部長で検索
    $users = get_users([
        'role__in'   => $roles,
        'meta_key'   => 'position',
        'meta_value' => '部長',
        'number'     => 1,
    ]);

    return $users[0]->user_email ?? '';
}

/**
 * 総務メールアドレス取得
 */
function swl_get_soumu_email() {
    $users = get_users([
        'role__in' => ['soumu', '総務'],
        'number'   => 1,
    ]);
    if (!empty($users)) {
        return $users[0]->user_email;
    }
    // ロールで見つからない場合、position=総務
    $users = get_users([
        'meta_key'   => 'position',
        'meta_value' => '総務',
        'number'     => 1,
    ]);
    if (!empty($users)) {
        return $users[0]->user_email;
    }
    return '';
}

function swl_get_last_name_from_email($email) {
    if (!$email) return '';

    $user = get_user_by('email', $email);
    if (!$user) return '';

    // WordPress の姓フィールド（last_name）を使う
    $last_name = get_user_meta($user->ID, 'last_name', true);

    return $last_name ?: '';
}

/**
 * 承認用URL生成
 */
function swl_get_approve_url($hash) {
    return home_url('/leave-apply/?key=' . urlencode($hash));
}

/**
 * 過去にメールを送ったかどうかを判定する関数
 */
function swl_has_mailed_before($thread_id, $role) {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT mailed_at 
             FROM sw_leave_approvals 
             WHERE thread_id = %d 
               AND role = %s 
               AND mailed_at IS NOT NULL
             ORDER BY mailed_at ASC 
             LIMIT 1",
            $thread_id,
            $role
        )
    );
    return $row !== null;
}

/**
 * メール送信（共通）
 */
function swl_send_mail($to, $subject, $message) {
    if (!$to) {
        return false;
    }
    $from_name  = '新和休暇申請システム';
    $from_email = 'no-reply@shinwa1.jp';
    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        "From: {$from_name} <{$from_email}>"
    ];
    return wp_mail($to, $subject, $message, $headers);
}

/**
 * 申請者向け承認完了メール
 */
function swl_send_approved_mail_to_user($user_email, $department, $name) {
    if (!$user_email) {
        return;
    }
    $subject = '休暇申請承認通知';
    $body = "{$department} {$name} 様\n\n休暇申請が承認されました。\n\n申請履歴は以下のURLから参照できます。\n\n" .
        home_url('/leave-log/') . "\n\n";
    $body .= "※本メールは自動送信されています。";
    swl_send_mail($user_email, $subject, $body);
}

/**
 * 休暇申請メール（社長／部長／総務宛）
 */
function swl_send_request_mail($to, $role_label, $department, $name, $hash) {
    if (!$to) return;

    // ★ 役職者の苗字を取得
    $last_name = swl_get_last_name_from_email($to);

    // 苗字が取れたら「部長 鈴木様」、取れなければ従来通り「部長 様」
    $role_display = $last_name ? "{$role_label} {$last_name}様" : "{$role_label} 様";

    $subject = "【休暇申請】{$name}";
    $url = swl_get_approve_url($hash);

    $body  = "{$role_display}\n\n";
    $body .= "{$department}の{$name}さんより休暇申請がありました。\n\n";
    $body .= "以下のURLにアクセスして承認してください。\n\n{$url}\n\n";
    $body .= "※本メールは自動送信されています。";

    swl_send_mail($to, $subject, $body);
}

/**
 * 休暇申請変更メール（社長／部長／総務宛）
 */
function swl_send_change_mail($to, $role_label, $department, $name, $hash) {
    if (!$to) return;

    // ★ 役職者の苗字を取得（request_mail と同じ）
    $last_name = swl_get_last_name_from_email($to);

    // 「社長 鈴木様」形式に統一
    $role_display = $last_name ? "{$role_label} {$last_name}様" : "{$role_label} 様";

    $subject = "【休暇申請変更】{$name}";
    $url = swl_get_approve_url($hash);

    $body  = "{$role_display}\n\n";
    $body .= "{$department}の{$name}さんより休暇申請の変更がありました。\n\n";
    $body .= "以下のURLにアクセスして承認してください。\n\n{$url}\n\n";
    $body .= "※本メールは自動送信されています。";

    swl_send_mail($to, $subject, $body);
}

/**
 * 取消通知メール送信
 */
function swl_send_cancel_mail($to_email, $role_label, $department, $name, $hash, $thread_id) {

    $subject = "【休暇申請】申請が取り消されました（{$department} / {$name}）";

    $last_name = swl_get_last_name_from_email($to_email);

    // 履歴画面のURL（固定でOK）
    $log_url = home_url('/leave-log/');

    // ★ 役職者の苗字を取得（request_mail と同じ）
    $last_name = swl_get_last_name_from_email($to);
    $role_display = $last_name ? "{$role_label} {$last_name}様" : "{$role_label} 様";

    $body  = "{$role_display}\n\n";
    $body .= "以下の休暇申請が申請者により取り消されました。\n\n";

    $body .= "【申請ID】\n";
    $body .= "{$thread_id}\n\n";

    $body .= "【申請者】\n";
    $body .= "{$department} / {$name}\n\n";

    $body .= "【履歴画面】\n";
    $body .= "{$log_url}\n\n";

    $body .= "※本メールは自動送信されています。";

    // WordPress 標準メール送信
    wp_mail($to_email, $subject, $body);
}
