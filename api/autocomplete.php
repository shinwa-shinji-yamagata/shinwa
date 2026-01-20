<?php
header('Content-Type: application/json');

// 認証トークン（Apps Script側と一致させる）
$valid_token = 'your_secret_token';
$token = isset($_GET['token']) ? $_GET['token'] : '';
if ($token !== $valid_token) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

// WordPressの設定とDBアクセスを読み込む
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
global $wpdb;

// パラメータ取得
$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$field = isset($_GET['field']) ? $_GET['field'] : '';

if (empty($query) || empty($field)) {
    echo json_encode([]);
    exit;
}

// 文字列の正規化（ひらがな・カタカナ・ローマ字など）
function normalize($str) {
    return mb_convert_kana($str, 'KVas');
}
function normalize_variants($str) {
    $half = mb_convert_kana($str, 'as'); // 半角英数字・カタカナ
    $full = mb_convert_kana($str, 'AS'); // 全角英数字・カタカナ
    return array_unique([$half, $full]);
}
function generate_variants($str) {
    $variants = [];
    $variants[] = $str;
    $variants[] = mb_convert_kana($str, 'as'); // 半角英数字・カタカナ
    $variants[] = mb_convert_kana($str, 'AS'); // 全角英数字・カタカナ
    $variants[] = mb_convert_kana($str, 'KVas'); // ひらがな・カタカナ・英数字の統一
    return array_unique($variants);
}

$query_norm = normalize($query);
$content = <<<EOT
「{$query_norm}」という略称・通称・あいまいな表現・読みから考えられる正式な病院名を最大10件、JSON配列で返してください。
たとえば：
- 「東海大付属」と入力された場合、「東海大学医学部付属八王子病院」などが該当します。
- 「神奈川医療」と入力された場合、「神奈川県立こども医療センター」などが該当します。
- 「うちの」と入力された場合、「内野先生」などが該当します。
- 「らいふ」と入力された場合、「IMS Me-Lifeクリニック池袋」などが該当します。
英語・カタカナ・漢字が混ざった名称も含めてください。
住所や注釈は含めないでください。
EOT;

// name検索（GPT補完 → DBフィルタ）
if ($field === 'name') {

    $keywords = generate_variants($query);

    // ① まずDB検索（直接ヒット）
    $direct_results = [];
    if (!empty($keywords)) {
        $like_clauses = implode(' OR ', array_fill(0, count($keywords), 'name LIKE %s'));
        $sql = "SELECT DISTINCT name FROM sw_genba_master WHERE {$like_clauses} ORDER BY id DESC LIMIT 30";
        $params = array_map(fn($k) => '%' . $k . '%', $keywords);
        $direct_results = $wpdb->get_col($wpdb->prepare($sql, ...$params));
    }

    // OpenAI API設定
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    $endpoint = 'https://api.openai.com/v1/chat/completions';

    $postData = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => 'あなたは日本の現場名（病院、施設、会社、支社、個人宅、人名など）に詳しいアシスタントです。'],
            ['role' => 'user', 'content' => $content]
        ],
        'temperature' => 0.3,
        'max_tokens' => 200
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? '';

    // GPTの出力がJSON配列形式であることを前提に処理
    $ai_suggestions = json_decode($text, true);
    if (!is_array($ai_suggestions)) {
        // JSON形式でなければ改行・句読点で分割
        $ai_suggestions = array_filter(array_map('trim', preg_split('/[\n、。]/u', $text)));
    }

    // 単語単位でフィルタ処理（記号・空文字・jsonなどを除去）
    $ai_keywords = [];
    foreach ($ai_suggestions as $s) {
        // カッコ内や記号を除去
        $s = preg_replace('/（.*?）|\\(.*?\\)/u', '', $s); // 括弧内削除
        $s = preg_replace('/[`",]/u', '', $s); // 記号除去
        $s = preg_replace('/[^ぁ-んァ-ン一-龥a-zA-Z0-9ー・]/u', '', $s); // 日本語・英数字以外除去
        $s = trim($s);
        if (mb_strlen($s, 'UTF-8') >= 2 && strtolower($s) !== 'json') {
            $variants = generate_variants($s);
            $ai_keywords = array_merge($ai_keywords, $variants);
        }
    }
    $ai_keywords = array_unique($ai_keywords);

    // ⑥ GPT補完キーワードでDB再検索
    $ai_results = [];
    if (!empty($ai_keywords)) {
        $like_clauses = implode(' OR ', array_fill(0, count($ai_keywords), 'name LIKE %s'));
        $sql = "SELECT DISTINCT name FROM sw_genba_master WHERE {$like_clauses} ORDER BY id DESC LIMIT 30";
        $params = array_map(fn($k) => '%' . $k . '%', $ai_keywords);
        $ai_results = $wpdb->get_col($wpdb->prepare($sql, ...$params));
    }

    // ⑦ DB直ヒットとGPT補完ヒットをマージ（重複除外）
    $final_results = array_values(array_unique(array_merge($direct_results, $ai_results)));
    $final_results = array_slice($final_results, 0, 30);

    echo json_encode($final_results ?: ['該当なし']);
    exit;
}

// subject検索（name完全一致）
if ($field === 'subject') {
    $sql = "
        SELECT DISTINCT subject
        FROM sw_genba_master
        WHERE name = %s
        ORDER BY id ASC
        LIMIT 20
    ";
    $results = $wpdb->get_col($wpdb->prepare($sql, $query));

    echo json_encode($results ?: ['該当なし']);
    exit;
}

// その他の field は無効
echo json_encode([]);
exit;
?>
