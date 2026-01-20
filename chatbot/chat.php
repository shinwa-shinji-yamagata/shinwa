<?php
/**
 * chat.php
 * 質問を受けて JSON で回答を返す
 */
require_once(__DIR__ . '/../wp-config.php');
global $wpdb;

// OpenAI APIキー（直書き要望に合わせます）
$OPENAI_API_KEY = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';

header("Content-Type: application/json; charset=utf-8");

// 入力チェック
$question = trim($_POST['q'] ?? '');
if ($question === '') { http_response_code(400); echo json_encode(["error"=>"質問が空です"]); exit; }

// --- Embedding生成 ---
function get_embedding($apiKey, $text) {
    $url = "https://api.openai.com/v1/embeddings";
    $payload = json_encode(["model"=>"text-embedding-3-small", "input"=>$text], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: " . "Bearer " . $apiKey
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data["data"][0]["embedding"] ?? null;
}
$query_emb = get_embedding($OPENAI_API_KEY, $question);
if (!$query_emb) { echo json_encode(["error"=>"Embedding生成に失敗しました"]); exit; }

// --- 候補取得（全文検索で絞り込み + フォールバック） ---
$table = "sw_documents";
$rows = $wpdb->get_results(
    $wpdb->prepare("SELECT id, title, section, page, text, embedding FROM {$table} WHERE MATCH(text) AGAINST(%s IN NATURAL LANGUAGE MODE) LIMIT 500", $question),
    ARRAY_A
);
if (!$rows || count($rows) === 0) {
    // フォールバック：更新順で上から
    $rows = $wpdb->get_results("SELECT id, title, section, page, text, embedding FROM {$table} ORDER BY updated_at DESC LIMIT 300", ARRAY_A);
}

// --- 類似度計算（PHPでコサイン） ---
function cosine_similarity($a, $b) {
    $dot=0.0; $na=0.0; $nb=0.0; $len = min(count($a), count($b));
    for ($i=0; $i<$len; $i++) { $dot += $a[$i]*$b[$i]; $na += $a[$i]*$a[$i]; $nb += $b[$i]*$b[$i]; }
    return $dot / (sqrt($na)*sqrt($nb) + 1e-12);
}
$scored = [];
foreach ($rows as $row) {
    $emb = json_decode($row["embedding"], true);
    if (!is_array($emb)) { continue; }
    $score = cosine_similarity($query_emb, $emb);
    $row["score"] = $score;
    $scored[] = $row;
}
usort($scored, fn($x,$y) => $y["score"] <=> $x["score"]);
$topK = array_slice($scored, 0, 6);

// --- 回答生成 ---
function answer_with_context($apiKey, $question, $contexts) {
    $system = "あなたは日本語で丁寧に回答するアシスタントです。";
    $contextText = "";
    foreach ($contexts as $i => $c) {
        $contextText .= "【#".($i+1)."】 ".$c["title"]." ".$c["section"]." p".$c["page"]."\n".$c["text"]."\n\n";
    }

    // コンテキストが空なら「一般質問モード」
    if (empty($contexts)) {
        $userPrompt = $question;
    } else {
        $userPrompt = "質問:\n".$question."\n\n参照可能な文書抜粋:\n".$contextText;
    }

    $url = "https://api.openai.com/v1/chat/completions";
    $payload = [
        "model" => "gpt-4o",
        "temperature" => 0.2,
        "messages" => [
            ["role"=>"system", "content"=>$system],
            ["role"=>"user", "content"=>$userPrompt]
        ]
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer ".$apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    return $data["choices"][0]["message"]["content"] ?? "回答が取得できませんでした: ".$res;
}

$answer = answer_with_context($OPENAI_API_KEY, $question, $topK);

// --- 返却 ---
echo json_encode([
    "answer" => $answer,
    "sources" => array_map(fn($c)=>[
        "id"=>$c["id"], "title"=>$c["title"], "section"=>$c["section"], "page"=>$c["page"], "score"=>round($c["score"], 4)
    ], $topK)
], JSON_UNESCAPED_UNICODE);
