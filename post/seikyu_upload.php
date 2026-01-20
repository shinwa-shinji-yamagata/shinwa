<?php
error_log('FILES: ' . print_r($_FILES, true));
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$uploadedFiles = $_FILES['excels'] ?? null;
$savedPaths = [];

if ($uploadedFiles && is_array($uploadedFiles['name'])) {
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/post/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    foreach ($uploadedFiles['name'] as $i => $name) {
        if ($uploadedFiles['error'][$i] === UPLOAD_ERR_OK) {
            $tmpName = $uploadedFiles['tmp_name'][$i];
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            if (strtolower($ext) !== 'xlsx') continue;

            $newName = uniqid('excel_', true) . '.' . $ext;
            $destPath = $uploadDir . $newName;

            if (move_uploaded_file($tmpName, $destPath)) {
                $savedPaths[] = ['path' => $destPath, 'name' => $name];
            }
        }
    }
}

if (!empty($savedPaths)) {
    $_SESSION['uploaded_files'] = $savedPaths;
    $_SESSION['current_index'] = 0;
    $_SESSION['write_row'] = 9;

    $templatePath = $_SERVER['DOCUMENT_ROOT'] . '/post/template.xlsx';
    $lastDate = date('Y-m-d');
    $year = date('Y', strtotime($lastDate));
    $month = date('n', strtotime($lastDate));
    $outputDir = $_SERVER['DOCUMENT_ROOT'] . '/post/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }
    $outputPath = $outputDir . "/PROCESS向け請求書_{$year}年{$month}月.xlsx";

    if (copy($templatePath, $outputPath)) {
        $_SESSION['output_path'] = $outputPath;
        // JSONでリダイレクト先を返す
        header('Content-Type: application/json');
        echo json_encode(['redirect' => '/post/seikyu_process.php']);
        exit;
    } else {
        http_response_code(500);
        echo 'テンプレートのコピーに失敗しました。';
        exit;
    }
} else {
    http_response_code(400);
    error_log('アップロード失敗: $_FILES = ' . print_r($_FILES, true));
    echo 'ファイルのアップロードに失敗しました。';
    exit;
}
