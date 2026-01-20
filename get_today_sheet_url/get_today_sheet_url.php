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
    sw_log("get_today_sheet_url.php","年フォルダが見つかりません: {$yearFolderName}","ERROR");
}

// スプレッドシートIDを取得
$spreadsheetId = getSpreadsheetIdByName($drive, $yearFolderId, $spreadsheetName);
if (!$spreadsheetId) {
    throw new Exception("スプレッドシートが見つかりません: {$spreadsheetName}");
    sw_log("get_today_sheet_url.php","スプレッドシートが見つかりません: {$spreadsheetName}","ERROR");
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
    sw_log("get_today_sheet_url.php","{$day}日 シートが見つかりません","ERROR");
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
if ($stmt->execute()) {
    echo "✅ URL更新成功: {$sheetUrl}\n";
    sw_log("get_today_sheet_url.php","{$year}年{$month}月{$day}日のシートURLを更新しました。URL=$sheetUrl");
} else {
    echo "❌ URL更新失敗: " . $stmt->error . "\n";
}

$stmt->close();
$mysqli->close();
