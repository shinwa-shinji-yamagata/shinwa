<?php
/**
 * db-init.php
 * JSONファイル(upload.json)を読み込み、sw_documentsにINSERT
 */

require_once(__DIR__ . '/../wp-config.php');
global $wpdb;

$json = file_get_contents("upload.json");
$data = json_decode($json, true);

$wpdb->query("TRUNCATE TABLE sw_documents");

foreach ($data as $row) {
    $wpdb->replace(
        "sw_documents",
        [
            "source_path" => $row["source_path"],
            "title" => $row["title"],
            "section" => $row["section"],
            "page" => $row["page"],
            "text" => $row["text"],
            "embedding" => json_encode($row["embedding"]),
            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"]
        ]
    );
}

echo "インポート完了: ".count($data)."件";
