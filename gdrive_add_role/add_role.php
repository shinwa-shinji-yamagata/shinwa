<?php
// === コマンドライン引数の処理 ===
if ($argc < 2) {
    echo "使い方: php script.php <fileId> [csvPath]\n";
    exit(1);
}

$targetFileId = $argv[1];
$csvPath = $argv[2] ?? 'permissions.csv';

// 退避しておく
$cliArgs = [$targetFileId, $csvPath];

$basePath = __DIR__;
require_once $basePath . '/../wp-load.php'; // WordPress環境をロード
require_once $basePath. '/../vendor/autoload.php';

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

// 退避した値を復元
$targetFileId = $cliArgs[0];
$csvPath = $cliArgs[1];

$fileName = '';
try {
    $file = $drive->files->get($targetFileId, ['fields' => 'name']);
    $fileName = $file->getName();
} catch (Exception $e) {
    error_log("ファイル名前取得失敗: " . $e->getMessage());
    exit;
}

define('ROOT_FOLDER_ID', '1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt');

function convertRole($japaneseRole) {
    switch (trim($japaneseRole)) {
        case '閲覧者':
            return 'reader';
        case '編集者':
            return 'writer';
        default:
            return 'commenter';
    }
}

function addPermissions($service, $fileId, $email, $role, $fileName, $disp_role) {
    $permission = new Google_Service_Drive_Permission([
        'type' => 'user',
        'role' => $role,
        'emailAddress' => $email
    ]);

    try {
        $service->permissions->create($fileId, $permission, ['sendNotificationEmail' => false]);
        echo "✅ {$email} に {$role} 権限を付与しました\n";
        sw_log("add_role.php","[$fileName]に権限を付与 {$email}：{$disp_role}");
    } catch (Exception $e) {
        $message = "{$email} への権限付与に失敗: " . $e->getMessage();
        echo "⚠️ $message" . $e->getMessage() . "\n";
        sw_log("add_role.php","[$fileName]への権限付与に失敗 {$email}：$disp_role} " . $e->getMessage(),"ERROR");
    }
}

if (!file_exists($csvPath)) {
    echo "CSVファイルが見つかりません: {$csvPath}\n";
    exit(1);
}

// === メイン処理 ===
$csvFile = fopen($csvPath, 'r');
fgetcsv($csvFile); // ヘッダーをスキップ

while (($data = fgetcsv($csvFile)) !== false) {
    $email = $data[0];
    $role = convertRole($data[1]);
    addPermissions($drive, $targetFileId, $email, $role, $fileName, $data[1]);
}

fclose($csvFile);
