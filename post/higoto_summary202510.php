<?php
$year = $argv[1] ?? null;
$month = $argv[2] ?? null;

if (!$year || !$month) {
  echo "年と月を指定してください\n";
  exit;
}

// 退避しておく
$cliArgs = [$year, $month];

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

// 退避した値を復元
$year = $cliArgs[0];
$month = $cliArgs[1];

// 年フォルダを取得
$currentYear = "{$year}年";
$yearFolderId = getFolderId($drive, ROOT_FOLDER_ID, $currentYear);

$month = is_array($month) ? implode('', $month) : $month;
// 月スプレッドシートを取得
$currentMonth = "{$year}年{$month}月";
var_dump($currentMonth);
$spreadsheetId = getSpreadsheetId($drive, $yearFolderId, $currentMonth);
if (!is_string($spreadsheetId)) {
    echo "❌ スプレッドシートIDが取得できませんでした\n";
    var_dump($spreadsheetId);
    exit;
}

// 多次元配列で人工と外注を集計
$siteData = [];

for ($day = 1; $day <= 31; $day++) {
    $sheetName = "{$day}日";
    $range = "{$sheetName}!C5:N104"; // N列まで取得
    try {
        $response = $sheets->spreadsheets_values->get($spreadsheetId, $range);
        $rows = $response->getValues();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $site = isset($row[0]) ? preg_replace("/\r\n|\r|\n/", '', $row[0]) : '';
                $manpower = isset($row[9]) ? normalizeManpower($row[9]) : 0;
                $outsourcingRaw = isset($row[11]) ? $row[11] : '';
                $outsourcingCount = 0;

                if ($site !== '') {
                    // 外注数をカウント
                    $outsourcingRaw = str_replace(["、", ","], ",", $outsourcingRaw);
                    $outsourcingList = array_filter(array_map('trim', explode(",", $outsourcingRaw)));
                    $outsourcingCount = count($outsourcingList);

                    // 初期化
                    if (!isset($siteData[$site])) {
                        $siteData[$site] = ['manpower' => 0, 'outsourcing' => 0];
                    }

                    // 加算
                    $siteData[$site]['manpower'] += $manpower;
                    $siteData[$site]['outsourcing'] += $outsourcingCount;
                }
            }
        }
    } catch (Exception $e) {
        continue;
    }
}

// キー（現場名）で昇順にソート
ksort($siteData);

// 月集計のB4:D300をクリア
$clearRange = '月集計!B4:D300';
$emptyRows = [];
for ($i = 0; $i < 297; $i++) {
    $emptyRows[] = ['', '', ''];
}
$clearBody = new Google_Service_Sheets_ValueRange([
    'values' => $emptyRows
]);

$sheets->spreadsheets_values->update(
    $spreadsheetId,
    $clearRange,
    $clearBody,
    ['valueInputOption' => 'USER_ENTERED']
);

// 月集計シートに書き込み
$updateData = [];
foreach ($siteData as $site => $data) {
    $manpower = $data['manpower'];
    $outsourcing = $data['outsourcing'];

    // 両方0ならスキップ
    if ($manpower == 0 && $outsourcing == 0) {
        continue;
    }

    // 書き込みデータ構築
    $row = [$site, $manpower];
    $row[] = ($outsourcing > 0) ? $outsourcing : '';
    $updateData[] = $row;
}

$body = new Google_Service_Sheets_ValueRange([
    'values' => $updateData
]);

$sheets->spreadsheets_values->update(
    $spreadsheetId,
    '月集計!B4:D' . (count($updateData) + 3),
    $body,
    ['valueInputOption' => 'USER_ENTERED']
);

echo '集計が完了しました';

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
    $name = trim($name);
    $query = "'$parentId' in parents and mimeType='application/vnd.google-apps.spreadsheet' and name = '$name'";
    echo "🔍 クエリ: $query\n";

    $response = $drive->files->listFiles([
        'q' => $query,
        'fields' => 'files(id, name)'
    ]);

    $files = $response->getFiles();
    foreach ($files as $file) {
        echo "📄 見つかった: " . $file->getName() . "\n";
    }

    if (is_array($files) && count($files) > 0) {
        return $files[0]->getId();
    }

    return null;
}
function normalizeManpower($value) {
    $value = trim($value);
    $value = mb_convert_kana($value, 'a');
    $value = str_replace(['−', '－'], '-', $value);
    return is_numeric($value) ? floatval($value) : 0;
}
?>
