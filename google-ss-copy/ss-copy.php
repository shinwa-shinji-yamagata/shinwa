<?php
$basePath = __DIR__;
require_once $basePath . '/../wp-load.php'; // WordPress環境をロード
require_once $basePath . '/../vendor/autoload.php'; // Google API クライアントライブラリの読み込み

$config = [
    'app_name' => 'Google Sheets Aggregator',
    'scopes' => [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/script.projects',
        'https://www.googleapis.com/auth/script.scriptapp'
    ],
    'credentials_path' => $basePath . '/../client_secrets.json',
    'token_path' => $basePath . '/../token.json',
];

$client = init_google_client($config);
$driveService = new Google_Service_Drive($client);
$sheetsService = new Google_Service_Sheets($client);

// フォルダ名からIDを取得
function get_folder_id($driveService, $folderName) {
    $parentId = '1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt';
    $query = sprintf("'%s' in parents and trashed = false and name = '%s' and mimeType = 'application/vnd.google-apps.folder'", $parentId, $folderName);

    $results = $driveService->files->listFiles([
        'q' => $query,
        'spaces' => 'drive',
        'fields' => 'files(id, name)',
    ]);

    $files = $results->getFiles();
    if (empty($files)) {
        error_log("フォルダ '{$folderName}' が見つかりませんでした。");
        return null;
    }

    return $files[0]->getId();
}

// ファイルを移動
function move_file($driveService, $fileId, $newParentId) {
    $file = $driveService->files->get($fileId, ['fields' => 'parents']);
    $previousParents = join(',', $file->getParents());

    $updatedFile = $driveService->files->update($fileId, new Google_Service_Drive_DriveFile(), [
        'addParents' => $newParentId,
        'removeParents' => $previousParents,
        'fields' => 'id, parents',
    ]);

    error_log("ファイル移動完了: " . $updatedFile->getId());
}

// シートに年と月を書き込む
function write_to_sheet($sheetsService, $spreadsheetId, $year, $month) {
    $sheetsService->spreadsheets_values->update($spreadsheetId, '1日!AC1', new Google_Service_Sheets_ValueRange([
        'values' => [[$year]],
    ]), ['valueInputOption' => 'RAW']);

    $sheetsService->spreadsheets_values->update($spreadsheetId, '1日!AD1', new Google_Service_Sheets_ValueRange([
        'values' => [[$month]],
    ]), ['valueInputOption' => 'RAW']);
}

// 「月工程」シートのAT1に newYear/newMonth/01 を書き込む
function write_month_start_date($sheetsService, $spreadsheetId, $year, $month) {
    $dateStr = sprintf("%04d/%02d/01", $year, $month);

    $sheetsService->spreadsheets_values->update(
        $spreadsheetId,
        '月工程!AT1',
        new Google_Service_Sheets_ValueRange([
            'values' => [[ $dateStr ]],
        ]),
        ['valueInputOption' => 'USER_ENTERED']
    );
}

function adjust_month_columns($sheetsService, $spreadsheetId, $daysInMonth) {

    $spreadsheet = $sheetsService->spreadsheets->get($spreadsheetId);
    $sheetId = null;

    foreach ($spreadsheet->getSheets() as $sheet) {
        if ($sheet->getProperties()->getTitle() === '月工程') {
            $sheetId = $sheet->getProperties()->getSheetId();
            break;
        }
    }

    if ($sheetId === null) {
        error_log("シート '月工程' が見つかりません。");
        return;
    }

    $requests = [];

    $startIndex = match ($daysInMonth) {
        30 => 38, // 31日削除
        29 => 37, // 30・31日削除
        28 => 36, // 29〜31日削除
        default => null, // 31日の月は削除なし
    };

    if ($startIndex !== null) {
        $requests[] = [
            'deleteDimension' => [
                'range' => [
                    'sheetId' => $sheetId,
                    'dimension' => 'COLUMNS',
                    'startIndex' => $startIndex,
                    'endIndex' => 39
                ]
            ]
        ];
    }

    if (!empty($requests)) {
        $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => $requests
        ]);
        $sheetsService->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);
    }
}

// 指定名のシートを削除
function delete_sheet_by_name($sheetsService, $spreadsheetId, $sheetName) {
    $spreadsheet = $sheetsService->spreadsheets->get($spreadsheetId);
    $sheets = $spreadsheet->getSheets();

    $sheetId = null;
    foreach ($sheets as $sheet) {
        if ($sheet->getProperties()->getTitle() === $sheetName) {
            $sheetId = $sheet->getProperties()->getSheetId();
            break;
        }
    }

    if ($sheetId === null) {
        error_log("シート '{$sheetName}' が見つかりませんでした。");
        return;
    }

    $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
        'requests' => [
            ['deleteSheet' => ['sheetId' => $sheetId]],
        ],
    ]);

    $sheetsService->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);
}

// 日付処理とファイル操作
$now = new DateTime();
$year = (int)$now->format('Y');
$month = (int)$now->format('n');
$newYear = $year;
$newMonth = $month + 1;

if ($month === 12) {
    $newYear++;
    $newMonth = 1;

    $folderMetadata = new Google_Service_Drive_DriveFile([
        'name' => "{$newYear}年",
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => ['1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt'],
    ]);

    $folder = $driveService->files->create($folderMetadata, ['fields' => 'id']);
}

$targetFolder = "{$newYear}年";
$fileName = "{$newYear}年{$newMonth}月";
$fileId = '1vtXoWyzUCNzOxPPkt-tvM0gQWY7eom3OZOScLDP9bxg';

$targetFolderId = get_folder_id($driveService, $targetFolder);

$copiedFile = $driveService->files->copy($fileId, new Google_Service_Drive_DriveFile([
    'name' => $fileName,
    'parents' => [$targetFolderId],
]));

error_log("ファイルコピー完了: {$fileName}");
sw_log(basename(__FILE__),"スプレッドシート template を {$newYear}年/{$fileName} へコピーしました。");

$newFileId = $copiedFile->getId();
move_file($driveService, $newFileId, $targetFolderId);
write_to_sheet($sheetsService, $newFileId, $newYear, $newMonth);
write_month_start_date($sheetsService, $newFileId, $newYear, $newMonth);

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $newMonth, $newYear);
adjust_month_columns($sheetsService, $newFileId, $daysInMonth);
for ($i = $daysInMonth + 1; $i <= 31; $i++) {
    delete_sheet_by_name($sheetsService, $newFileId, "{$i}日");
}

$spreadsheetUrl = "https://docs.google.com/spreadsheets/d/{$newFileId}/edit";

$message  = "{$newYear}年{$newMonth}月の日毎表を作成しました。\r\n\r\n";
$message .= "以下のURLにアクセスし、拡張機能の「Apps Script」からトリガーを追加してください。\r\n\r\n";
$message .= "{$spreadsheetUrl}\r\n\r\n";

$admins = get_users([ 'role' => 'administrator' ]);

foreach ($admins as $admin) {
    wp_mail(
        $admin->user_email,
        "日毎表作成通知：{$newYear}年{$newMonth}月",
        $message
    );
}

