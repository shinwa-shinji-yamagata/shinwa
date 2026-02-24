<?php
require_once __DIR__ . '/../wp-load.php';
require_once __DIR__ . '/../vendor/autoload.php';

// 初期設定
$sourceRootFolderId = '1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt';
$backupRootFolderId = '1Si1Mdr7COWJRGwIowx0SN15xXtlYyf_M';

$config = [
    'app_name' => 'Google Sheets Aggregator',
    'scopes' => [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/script.projects',
        'https://www.googleapis.com/auth/script.scriptapp'
    ],
    'credentials_path' => __DIR__ . '/../client_secrets.json',
    'token_path' => __DIR__ . '/../token.json',
];

$client = init_google_client($config);
$driveService = new Google_Service_Drive($client);

// 日付情報
$today = new DateTime();
$year = $today->format('Y');
$month = $today->format('n');
$ymd = $today->format('Ymd');
$sheetName = "{$year}年{$month}月";

// 🔍 元フォルダから対象シートを探す
$sourceFolderName = "{$year}年";
$sourceFolderId = findFolder($driveService, $sourceRootFolderId, $sourceFolderName);
if (!$sourceFolderId) {
    echo "❌ 元フォルダ '{$sourceFolderName}' が見つかりません。\n";
    sw_log(basename(__FILE__),"元フォルダ '{$sourceFolderName}' が見つかりません。","ERROR");
    exit;
}

$sheetFile = findSheetByName($driveService, $sourceFolderId, $sheetName);
if (!$sheetFile) {
    echo "❌ シート '{$sheetName}' が '{$sourceFolderName}' の中に見つかりません。\n";
    sw_log(basename(__FILE__),"シート '{$sheetName}' が '{$sourceFolderName}' の中に見つかりません。","ERROR");
    exit;
}

// 📁 バックアップ先フォルダを作成（なければ）
$backupYearFolderId = getOrCreateFolder($driveService, $backupRootFolderId, "{$year}年");
$backupMonthFolderId = getOrCreateFolder($driveService, $backupYearFolderId, "{$month}月");

// 📄 シートをコピー
$copiedFile = new Google_Service_Drive_DriveFile([
    'name' => "{$sheetName}_{$ymd}",
    'parents' => [$backupMonthFolderId]
]);
$driveService->files->copy($sheetFile->getId(), $copiedFile);

echo "✅ バックアップ完了：{$sheetName}_{$ymd}\n";
sw_log(basename(__FILE__),"バックアップ完了：{$sheetName}_{$ymd}");

// 🧹 3か月以上前のフォルダを削除
$threshold = (new DateTime())->modify('-3 months');
deleteOldFolders($driveService, $backupRootFolderId, $threshold);

function findFolder($driveService, $parentId, $name) {
    if (!$parentId) {
        echo "❌ 親フォルダIDが null です（探す対象: {$name}）\n";
        return null;
    }

    $query = "mimeType='application/vnd.google-apps.folder' and name='{$name}' and '{$parentId}' in parents and trashed=false";
    $results = $driveService->files->listFiles(['q' => $query]);

    if (count($results->getFiles()) === 0) {
        return null;
    }

    return $results->getFiles()[0]->getId();
}

function getOrCreateFolder($driveService, $parentId, $name) {
    $folderId = findFolder($driveService, $parentId, $name);
    if ($folderId) return $folderId;

    echo "⚠️ フォルダ '{$name}' が親 '{$parentId}' の下に見つからなかったため作成します。\n";
    sw_log(basename(__FILE__),"フォルダ '{$name}' が親 '{$parentId}' の下に見つからなかったため作成します。");

    $fileMetadata = new Google_Service_Drive_DriveFile([
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId]
    ]);
    $folder = $driveService->files->create($fileMetadata, ['fields' => 'id']);
    return $folder->id;
}

function findSheetByName($driveService, $parentId, $name) {
    $query = "mimeType='application/vnd.google-apps.spreadsheet' and name='{$name}' and '{$parentId}' in parents and trashed=false";
    $results = $driveService->files->listFiles(['q' => $query, 'pageSize' => 1]);

    if (count($results->getFiles()) === 0) {
        return null;
    }

    return $results->getFiles()[0];
}

function deleteOldFolders($driveService, $parentId, $thresholdDate) {
    $query = "mimeType='application/vnd.google-apps.folder' and '{$parentId}' in parents and trashed=false";
    $folders = $driveService->files->listFiles(['q' => $query, 'fields' => 'files(id, name, createdTime)']);

    foreach ($folders->getFiles() as $folder) {
        $createdTime = $folder->getCreatedTime();
        if (!$createdTime) {
            echo "⚠️ フォルダ '{$folder->getName()}' の作成日時が取得できませんでした。スキップします。\n";
            sw_log(basename(__FILE__),"フォルダ '{$folder->getName()}' の作成日時が取得できませんでした。スキップします。","WARN");
            continue;
        }

        $created = new DateTime($createdTime);
        if ($created < $thresholdDate) {
            echo "🗑️ 古いフォルダ削除：{$folder->getName()}（作成日: {$created->format('Y-m-d')}）\n";
            sw_log(basename(__FILE__),"古いフォルダ削除：{$folder->getName()}（作成日: {$created->format('Y-m-d')}）");
            $driveService->files->delete($folder->getId());
        }
    }
}
