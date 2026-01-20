<?php
session_start();
session_unset();
$uploadDir = __DIR__ . '/uploads';
if (is_dir($uploadDir)) {
    $files = glob($uploadDir . '/*'); // uploads/ 内のすべてのファイルを取得
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file); // ファイルを削除
        }
    }
}
session_destroy();
header('Location: /seikyu/');
exit;
