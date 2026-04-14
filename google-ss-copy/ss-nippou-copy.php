<?php
$basePath = __DIR__;
require_once $basePath . '/../wp-load.php';
require_once $basePath . '/../vendor/autoload.php';

$config = [
    'app_name' => 'Google Sheets Aggregator',
    'scopes' => [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/spreadsheets'
    ],
    'credentials_path' => $basePath . '/../client_secrets.json',
    'token_path' => $basePath . '/../token.json',
];

$client = init_google_client($config);
$driveService = new Google_Service_Drive($client);
$sheetsService = new Google_Service_Sheets($client);

// ------------------------------------------------------------
// 1. 日付処理：現在 → 1ヶ月後（やっぱり1日実行にするので当月）
// ------------------------------------------------------------
$now = new DateTime('now');
$next = (clone $now)->modify('+1 month');
$next = $now;

$year = $next->format('Y');      // 2026
$month = $next->format('n');     // 5
$yearFolderName = "{$year}年";   // 2026年
$monthFolderName = "{$year}年{$month}月"; // 2026年5月

$firstDateStr = $next->format("Y/m/1"); // A1 に書く日付

// ------------------------------------------------------------
// 2. フォルダ作成（存在しなければ）
// ------------------------------------------------------------
$TOP_FOLDER_ID = "1IlvrmqXMSnB8sINF2prIhMX4Q-bdA0Lj";
$SOURCE_FOLDER_ID = "16Nc9CsL8vH5cGAbTPmMNPjZb9yvBvQ7o";

function getOrCreateFolder($driveService, $parentId, $name) {
    $query = sprintf(
        "mimeType='application/vnd.google-apps.folder' and name='%s' and '%s' in parents and trashed=false",
        addslashes($name),
        $parentId
    );
    $res = $driveService->files->listFiles(['q' => $query]);

    if (count($res->files) > 0) {
        return $res->files[0]->id;
    }

    $folder = new Google_Service_Drive_DriveFile([
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId]
    ]);
    $created = $driveService->files->create($folder, ['fields' => 'id']);
    return $created->id;
}

$yearFolderId = getOrCreateFolder($driveService, $TOP_FOLDER_ID, $yearFolderName);
$monthFolderId = getOrCreateFolder($driveService, $yearFolderId, $monthFolderName);

// ------------------------------------------------------------
// 3. テンプレートを6人分の名前でコピーする
// ------------------------------------------------------------
$templateId = "1_ik3abvuk2_myuvFAA7sv5zwN8Pqt5jFxvqfkYa6vAM";

$targetNames = ["門脇", "時田", "黒田", "栗原", "栗山", "稲垣"];

$permissionMap = [
    "門脇" => ["shinwa.h.kadowaki@gmail.com", "shinwa1.soumu@gmail.com", "shinwa.h.kurihara@gmail.com"],
    "時田" => ["shinwa.s.tokita@gmail.com", "shinwa1.soumu@gmail.com", "shinwa.h.kurihara@gmail.com"],
    "黒田" => ["shinwa1.soumu@gmail.com", "shinwa.h.kurihara@gmail.com"],
    "栗原" => ["shinwa.h.kurihara@gmail.com", "shinwa1.soumu@gmail.com", "shinwa.h.kurihara@gmail.com"],
    "栗山" => ["keirishinwa1@gmail.com", "shinwa1.soumu@gmail.com", "shinwa.h.kurihara@gmail.com"],
    "稲垣" => ["shinwa.t.inagaki@gmail.com", "shinwa1.soumu@gmail.com", "shinwa.h.kurihara@gmail.com"],
];

// ------------------------------------------------------------
// 4. 各ファイルをコピー → シート編集
// ------------------------------------------------------------
foreach ($targetNames as $name) {

    // --- コピー ---
    $copyMeta = new Google_Service_Drive_DriveFile([
        'name' => $name,              // ← 個人名でコピー
        'parents' => [$monthFolderId]
    ]);

    $copied = $driveService->files->copy($templateId, $copyMeta, ['fields' => 'id']);
    $newFileId = $copied->id;

    // --- 個別権限を付与（継承された権限は削除しない） ---
    if (!isset($permissionMap[$name])) {
        error_log("ERROR: permissionMap にキーがありません → " . $name);
    } else {

        $allowedUsers = $permissionMap[$name];

        // 本人（先頭）
        $ownerEmail = array_shift($allowedUsers);

        // 本人 → writer
        try {
            $ownerPerm = new Google_Service_Drive_Permission([
                'type' => 'user',
                'role' => 'writer',
                'emailAddress' => $ownerEmail
            ]);
            $driveService->permissions->create($newFileId, $ownerPerm, ['sendNotificationEmail' => false]);
        } catch (Exception $e) {
            error_log("OWNER PERMISSION ERROR: " . $e->getMessage());
        }

        // その他 → reader
        foreach ($allowedUsers as $email) {
            try {
                $newPerm = new Google_Service_Drive_Permission([
                    'type' => 'user',
                    'role' => 'reader',
                    'emailAddress' => $email
                ]);
                $driveService->permissions->create($newFileId, $newPerm, ['sendNotificationEmail' => false]);
            } catch (Exception $e) {
                error_log("READER PERMISSION ERROR: " . $e->getMessage());
            }
        }
    }

    // --- A1 に日付を書き込む ---
    $range = "1日!A1";
    $body = new Google_Service_Sheets_ValueRange([
        'values' => [[ $firstDateStr ]]
    ]);
    $sheetsService->spreadsheets_values->update(
        $newFileId,
        $range,
        $body,
        ['valueInputOption' => 'USER_ENTERED']
    );

    // --- 存在しない日のシートを削除 ---
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    $spreadsheet = $sheetsService->spreadsheets->get($newFileId);
    $sheets = $spreadsheet->getSheets();

    $deleteRequests = [];

    foreach ($sheets as $sheet) {
        $title = $sheet['properties']['title'];

        // 「◯日」形式のシートのみ対象
        if (preg_match('/^(\d{1,2})日$/u', $title, $m)) {
            $day = intval($m[1]);
            if ($day > $daysInMonth) {
                $deleteRequests[] = [
                    'deleteSheet' => [
                        'sheetId' => $sheet['properties']['sheetId']
                    ]
                ];
            }
        }
    }

    if (!empty($deleteRequests)) {
        $batch = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
            'requests' => $deleteRequests
        ]);
        $sheetsService->spreadsheets->batchUpdate($newFileId, $batch);
    }

    global $wpdb;

    $table = "sw_kanribu_nippou";

    $yearStr  = $year . "年";
    $monthStr = $year . "年" . $month . "月";
    $nameStr  = $name;
    $urlStr   = "https://docs.google.com/spreadsheets/d/" . $newFileId;

    // 既存レコードの存在チェック
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table} WHERE year = %s AND month = %s AND name = %s",
            $yearStr,
            $monthStr,
            $nameStr
        )
    );

    if ($exists) {
        // 存在する → UPDATE
        $wpdb->update(
            $table,
            ['url' => $urlStr],
            ['id' => $exists],
            ['%s'],
            ['%d']
        );
    } else {
        // 存在しない → INSERT
        $wpdb->insert(
            $table,
            [
                'year'  => $yearStr,
                'month' => $monthStr,
                'name'  => $nameStr,
                'url'   => $urlStr
            ],
            ['%s','%s','%s','%s']
        );
    }
}

sw_log(basename(__FILE__),"作業日報テンプレートを {$year}年{$month}月 へコピーしました。");
