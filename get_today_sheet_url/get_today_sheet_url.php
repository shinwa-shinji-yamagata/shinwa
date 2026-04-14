<?php
$basePath = __DIR__;
require_once $basePath. '/../vendor/autoload.php';

define('ROOT_FOLDER_ID', '1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt');

require_once $basePath . '/../wp-load.php'; // WordPress環境をロード

$client = init_google_client([
    'credentials_path' => $basePath . '/../client_secrets.json',
    'token_path'       => $basePath . '/../token.json',
    'scopes' => [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/spreadsheets'
    ],
    'app_name'         => 'Drive Role Manager',
]);

$drive = new Google_Service_Drive($client);
$sheets = new Google_Service_Sheets($client);

// 現在の日付を取得
date_default_timezone_set('Asia/Tokyo');
$today = new DateTime();
$year = $today->format('Y');       // 例: 2025
$month = $today->format('n');      // 例: 10
$day = $today->format('j');        // 例: 3

// フォルダ名とファイル名を構築
$yearFolderName = "{$year}年";
$spreadsheetName = "{$year}年{$month}月";

// Google Driveから年フォルダIDを取得
function getFolderIdByName($drive, $parentId, $folderName) {
    $response = $drive->files->listFiles([
        'q' => "mimeType='application/vnd.google-apps.folder' and name='{$folderName}' and '{$parentId}' in parents and trashed=false",
        'fields' => 'files(id, name)',
    ]);
    return count($response->files) ? $response->files[0]->id : null;
}

// Google DriveからスプレッドシートIDを取得
function getSpreadsheetIdByName($drive, $folderId, $fileName) {
    $response = $drive->files->listFiles([
        'q' => "mimeType='application/vnd.google-apps.spreadsheet' and name='{$fileName}' and '{$folderId}' in parents and trashed=false",
        'fields' => 'files(id, name)',
    ]);
    return count($response->files) ? $response->files[0]->id : null;
}

// ルートフォルダID（必要に応じて設定）
$rootFolderId = '1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt'; // ←ここにルートフォルダIDを指定

// 年フォルダIDを取得
$yearFolderId = getFolderIdByName($drive, $rootFolderId, $yearFolderName);
if (!$yearFolderId) {
    throw new Exception("年フォルダが見つかりません: {$yearFolderName}");
    sw_log(basename(__FILE__),"年フォルダが見つかりません: {$yearFolderName}","ERROR");
}

// スプレッドシートIDを取得
$spreadsheetId = getSpreadsheetIdByName($drive, $yearFolderId, $spreadsheetName);
if (!$spreadsheetId) {
    throw new Exception("スプレッドシートが見つかりません: {$spreadsheetName}");
    sw_log(basename(__FILE__),"スプレッドシートが見つかりません: {$spreadsheetName}","ERROR");
}

// 日付シート名（例：「3日」）
$sheetName = "{$day}日";

$spreadsheet = $sheets->spreadsheets->get($spreadsheetId);
$sheetGid = null;

foreach ($spreadsheet->getSheets() as $sheet) {
    $title = $sheet->getProperties()->getTitle();
    if ($title === "{$day}日") {
        $sheetGid = $sheet->getProperties()->getSheetId();
        break;
    }
}

if ($sheetGid === null) {
    throw new Exception("{$day}日 シートが見つかりません");
    sw_log(basename(__FILE__),"{$day}日 シートが見つかりません","ERROR");
}

$sheetUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit#gid={$sheetGid}&range={$day}日!A1";

// WordPress DB更新
// DB接続
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($mysqli->connect_error) {
    exit("DB接続失敗: " . $mysqli->connect_error . "\n");
}

// テーブル名
$table = 'sw_today_sheet';

// UPDATE実行
$stmt = $mysqli->prepare("UPDATE {$table} SET url = ? WHERE id = 1");
$stmt->bind_param('s', $sheetUrl);
$kekka = true;
if ($stmt->execute()) {
    echo "✅ URL更新成功: {$sheetUrl}\n";
} else {
    $kekka = false;
    echo "❌ URL更新失敗: " . $stmt->error . "\n";
}

$stmt->close();
$mysqli->close();

// ▼▼▼ 管理部作業日報（6人分）のURL更新処理 ▼▼▼

global $wpdb;
$table_nippou = "sw_kanribu_nippou";

// 今日の year, month を文字列化
$yearStr  = "{$year}年";
$monthStr = "{$year}年{$month}月";

// 対象レコードを取得（6人分）
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, name, url FROM {$table_nippou} WHERE year = %s AND month = %s",
        $yearStr,
        $monthStr
    )
);

if ($rows) {
    foreach ($rows as $row) {

        // URL から spreadsheetId を抽出
        // 例: https://docs.google.com/spreadsheets/d/XXXXX/edit...
        if (!preg_match('#/d/([a-zA-Z0-9-_]+)#', $row->url, $m)) {
            sw_log(basename(__FILE__), "URLからspreadsheetIdを抽出できません: {$row->url}", "ERROR");
            continue;
        }

        $spreadsheetId = $m[1];

        // スプレッドシート取得
        try {
            $spreadsheet = $sheets->spreadsheets->get($spreadsheetId);
        } catch (Exception $e) {
            sw_log(basename(__FILE__), "スプレッドシート取得失敗: {$spreadsheetId}", "ERROR");
            continue;
        }

        // 今日の「〇日」シートの gid を探す
        $sheetGid = null;
        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() === "{$day}日") {
                $sheetGid = $sheet->getProperties()->getSheetId();
                break;
            }
        }

        if ($sheetGid === null) {
            sw_log(basename(__FILE__), "{$row->name} の {$day}日 シートが見つかりません", "ERROR");
            continue;
        }

        // 新しいURLを生成
        $newUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit#gid={$sheetGid}&range={$day}日!A1";

        // DB更新
        $wpdb->update(
            $table_nippou,
            ['url' => $newUrl],
            ['id' => $row->id],
            ['%s'],
            ['%d']
        );

    }

    echo "✅ 管理部作業日報（6人分）のURL更新完了\n";
} else {
    $kekka = false;
    echo "⚠ 対象の管理部作業日報レコードがありません\n";
}

if( $kekka ) {
    sw_log(basename(__FILE__),"日毎管理表、作業日報のシートURLを更新しました。");
} else {
    sw_log(basename(__FILE__),"日毎管理表または作業日報のシートURLの更新に失敗しました。","ERROR");
}
