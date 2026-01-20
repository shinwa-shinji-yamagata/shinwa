<?php
$basePath = $_SERVER['DOCUMENT_ROOT'];
require $basePath  . '/vendor/autoload.php';

require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'); // WordPressのロード
global $wpdb;

$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);

if (!$year || !$month) {
  echo json_encode(['success' => false, 'message' => '年または月が未入力です']);
  exit;
}

define('ROOT_FOLDER_ID', '1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt');

$sheetName = "{$year}年{$month}月";
$yearFolderName = "{$year}年";

$client = init_google_client([
    'credentials_path' => $basePath . '/client_secrets.json',
    'token_path'       => $basePath . '/token.json',
    'scopes' => [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/spreadsheets'
    ],
    'app_name'         => 'Drive Role Manager',
]);

$drive = new Google_Service_Drive($client);
$sheets = new Google_Service_Sheets($client);

ob_start();
header('Content-Type: application/json');

try {
  // Google Drive APIでフォルダとファイルの存在確認（仮想処理）
  // 実際にはGoogle API Clientを使って認証・検索処理を行う必要あり
  $folderExists = true; // ←ここは実装に応じて変更
  $sheetExists = true;  // ←ここも同様

  $yearFolderId = getFolderId($drive, ROOT_FOLDER_ID, $yearFolderName);
  if (!$yearFolderId) {
    throw new Exception("年フォルダが存在しません: $yearFolderName");
  }
  $spreadsheetId = getSpreadsheetId($drive, $yearFolderId, $sheetName);
  if (!$spreadsheetId) {
    throw new Exception("スプレッドシートが存在しません: $sheetName");
  }

  $php_file = "higoto_summary.php";
  if ($year < 2025 || ($year == 2025 && $month <= 10)) {
    $php_file = "higoto_summary202510.php";
  }

  // 集計コマンド実行
  $command = "php /home/shinwax/shinwa1.com/public_html/post/$php_file $year $month 2>&1";
  $phpPath = '/opt/php-8.3.21/bin/php';
  $scriptPath = "/home/shinwax/shinwa1.com/public_html/post/$php_file";
  $command = "{$phpPath} {$scriptPath} {$year} {$month} 2>&1";
  $output = shell_exec($command);

  if (strpos($output, '集計が完了しました') === false) {
    echo json_encode([
      'success' => false,
      'message' => "集計エラー: $output"
    ]);
    exit;
  }

  $spreadsheet = $sheets->spreadsheets->get($spreadsheetId);
  $sheetList = $spreadsheet->getSheets();
  $targetGid = null;
  foreach ($sheetList as $sheet) {
    $properties = $sheet->getProperties();
    if ($properties->getTitle() === '月集計') {
      $targetGid = $properties->getSheetId();
      break;
    }
  }
  $link = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit#gid={$targetGid}";

  ob_clean();

  // 完了メッセージ
  echo json_encode(['success' => true, 'message' => "✅ {$year}年{$month}月分の集計が完了しました<br><br><a href='{$link}' target='_blank'>月集計シートを開く</a>"]);
  sw_log("post_day_kanri_summary.php","{$year}年{$month}月分の集計が完了しました");

} catch (Exception $e) {
  sw_log("post_day_kanri_summary.php",$e->getMessage(),"ERROR");
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// フォルダID取得関数
function getFolderId($drive, $parentId, $folderName) {
    $response = $drive->files->listFiles([
        'q' => "'$parentId' in parents and mimeType='application/vnd.google-apps.folder' and name='$folderName'",
        'fields' => 'files(id, name)'
    ]);
    return $response->files[0]->id ?? null;
}

// スプレッドシートID取得関数
function getSpreadsheetId($drive, $parentId, $name) {
    $response = $drive->files->listFiles([
        'q' => "'$parentId' in parents and mimeType='application/vnd.google-apps.spreadsheet' and name contains '$name'",
        'fields' => 'files(id, name)'
    ]);
    return $response->files[0]->id ?? null;
}
