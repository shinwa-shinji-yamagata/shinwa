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

// 多次元配列で現場名ごとの人工と外注を集計
$siteData = [];

for ($day = 1; $day <= 31; $day++) {
    $sheetName = "{$day}日";
    $range = "{$sheetName}!C5:N104"; // C〜N列まで取得
    try {
        $response = $sheets->spreadsheets_values->get($spreadsheetId, $range);
        $rows = $response->getValues();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                // 現場名が空ならスキップ
                if (!isset($row[0])) {
                    continue;
                }
                // C列：現場名（index 0）
                $site = isset($row[0]) ? preg_replace("/\r\n|\r|\n/", '', trim($row[0])) : '';

                // D列：工事名（index 1）
                $construction = isset($row[1]) ? preg_replace("/\r\n|\r|\n/", '', trim($row[1])) : '';

                // L列：人工（index 9）
                $manpower = isset($row[9]) ? normalizeManpower($row[9]) : 0;

                // N列：外注（index 11）
                $outsourcingRaw = isset($row[11]) ? $row[11] : '';
                $outsourcingRaw = str_replace(['、', ','], ',', $outsourcingRaw);
                $outsourcingList = array_filter(array_map('trim', explode(',', $outsourcingRaw)));
                $outsourcingCount = count($outsourcingList);

                $key = "{$site}|{$construction}";
                if (!isset($siteData[$key])) {
                    $siteData[$key] = [
                        'site' => $site,
                        'construction' => $construction,
                        'manpower' => 0,
                        'outsourcing' => 0,
                        'days' => [] // ← 追加
                    ];
                }
                $siteData[$key]['manpower'] += $manpower;
                $siteData[$key]['outsourcing'] += $outsourcingCount;
                $siteData[$key]['days'][$day] = true; // ← この日にデータがあったことを記録
            }
        }
    } catch (Exception $e) {
        continue;
    }
}

// 昇順ソート（現場名＋工事名）
ksort($siteData);

// 月集計のC4:F300をクリア
$clearRange = '月集計!C4:F300';
$emptyRows = array_fill(0, 297, ['', '', '', '']);
$clearBody = new Google_Service_Sheets_ValueRange([
    'values' => $emptyRows
]);

$sheets->spreadsheets_values->update(
    $spreadsheetId,
    $clearRange,
    $clearBody,
    ['valueInputOption' => 'USER_ENTERED']
);

// J列〜AN列（31列）をクリア
$clearRange2 = '月集計!J4:AN300';
$emptyRows2 = array_fill(0, 297, array_fill(0, 31, ''));
$clearBody2 = new Google_Service_Sheets_ValueRange([
    'values' => $emptyRows2
]);

$sheets->spreadsheets_values->update(
    $spreadsheetId,
    $clearRange2,
    $clearBody2,
    ['valueInputOption' => 'USER_ENTERED']
);

// 月集計シートに書き込み
$basicData = [];
foreach ($siteData as $key => $data) {
    $manpower = $data['manpower'];
    $outsourcing = $data['outsourcing'];

    if ($manpower == 0 && $outsourcing == 0) {
        continue;
    }

    $basicData[] = [
        $data['site'],
        $data['construction'],
        $manpower,
        $outsourcing ?: ''
    ];
}

$body = new Google_Service_Sheets_ValueRange([
    'values' => $basicData
]);

$sheets->spreadsheets_values->update(
    $spreadsheetId,
    '月集計!C4',
    $body,
    ['valueInputOption' => 'USER_ENTERED']
);

$circleData = [];
foreach ($siteData as $key => $data) {
    $manpower = $data['manpower'];
    $outsourcing = $data['outsourcing'];
    $days = $data['days'];

    if ($manpower == 0 && $outsourcing == 0) {
        continue;
    }

    $row = [];
    for ($d = 1; $d <= 31; $d++) {
        $row[] = isset($days[$d]) ? '〇' : '';
    }
    $circleData[] = $row;
}

$body = new Google_Service_Sheets_ValueRange([
    'values' => $circleData
]);

$sheets->spreadsheets_values->update(
    $spreadsheetId,
    '月集計!J4',
    $body,
    ['valueInputOption' => 'USER_ENTERED']
);

echo '✅ 月集計（現場名＋工事名＋人工＋外注）の集計が完了しました';

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
