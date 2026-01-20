<?php
require_once(__DIR__ . '/../wp-config.php');
global $wpdb;

require __DIR__ . '/../vendor/autoload.php';

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as ExcelIOFactory;
use PhpOffice\PhpPresentation\IOFactory as PptIOFactory;

// OpenAI APIキー
$OPENAI_API_KEY = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';

// Embedding生成
function get_embedding($apiKey, $text) {
    $url = "https://api.openai.com/v1/embeddings";
    $payload = json_encode([
        "model" => "text-embedding-3-small",
        "input" => $text
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer ".$apiKey
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    return $data["data"][0]["embedding"] ?? null;
}

// ファイルごとのテキスト抽出
function read_txt($path) {
    return file_get_contents($path);
}

function read_pdf($path) {
    $parser = new PdfParser();
    $pdf = $parser->parseFile($path);
    return $pdf->getText();
}

function read_docx($path) {
    $phpWord = WordIOFactory::load($path);
    $text = "";
    foreach ($phpWord->getSections() as $section) {
        foreach ($section->getElements() as $element) {
            if (method_exists($element, "getText")) {
                $text .= $element->getText() . "\n";
            }
        }
    }
    return $text;
}

function read_pptx($path) {
    $ppt = PptIOFactory::createReader('PowerPoint2007')->load($path);
    $text = "";
    foreach ($ppt->getAllSlides() as $slide) {
        foreach ($slide->getShapeCollection() as $shape) {
            if (method_exists($shape, "getText")) {
                $text .= $shape->getText() . "\n";
            }
        }
    }
    return $text;
}

function read_excel($path) {
    $spreadsheet = ExcelIOFactory::load($path);
    $text = "";
    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $cell->getValue();
            }
            $text .= implode("\t", $cells) . "\n";
        }
    }
    return $text;
}

// docフォルダ内のファイルを処理
$dir = __DIR__ . "/doc";
$files = glob($dir . "/*.*");

foreach ($files as $file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    try {
        if ($ext === "txt") {
            $text = read_txt($file);
        } elseif ($ext === "pdf") {
            $text = read_pdf($file);
        } elseif ($ext === "docx") {
            $text = read_docx($file);
        } elseif ($ext === "pptx") {
            $text = read_pptx($file);
        } elseif (in_array($ext, ["xls", "xlsx"])) {
            $text = read_excel($file);
        } else {
            echo "未対応: $file\n";
            continue;
        }

        if (!trim($text)) {
            echo "空のファイル: $file\n";
            continue;
        }

        $embedding = get_embedding($OPENAI_API_KEY, mb_substr($text, 0, 2000));
        if (!$embedding) {
            echo "Embedding失敗: $file\n";
            continue;
        }

    $title = basename($file);
    $page  = 1;

    // 既存チェック
    $exists = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM sw_documents WHERE title=%s AND page=%d", $title, $page)
    );

    if ($exists > 0) {
        echo "既に登録済み: $title p$page\n";
    } else {
        $wpdb->insert("sw_documents", [
            "title"     => $title,
            "section"   => "自動登録",
            "page"      => $page,
            "text"      => mb_substr($text, 0, 2000),
            "embedding" => json_encode($embedding),
            "updated_at"=> current_time('mysql')
        ]);
        echo "登録完了: $title\n";
    }

    } catch (Exception $e) {
        echo "エラー: $file - ".$e->getMessage()."\n";
    }
}
