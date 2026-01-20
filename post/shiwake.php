<?php
$basePath = __DIR__;
require $basePath  . '/../vendor/autoload.php';
require_once $basePath . '/../wp-load.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// 入力値取得
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
$genba_code = $_POST['code'];

if (!$year || !$month) {
  echo json_encode(['success' => false, 'message' => '年または月が未入力です']);
  exit;
}
if ($year < 2025 || ($year == 2025 && $month <= 9)) {
  echo json_encode(['success' => false, 'message' => '2025年10月以降を指定してください']);
  exit;
}
if( empty($genba_code) ) {
  echo json_encode(['success' => false, 'message' => '共通原価の現場コードを指定してください']);
  exit;
}

$outputDir = $_SERVER['DOCUMENT_ROOT'] . '/post/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$monthLabel = sprintf('%02d', $month);
$monthEnd = Date::PHPToExcel(new DateTime( date('Y/m/t', strtotime("$year-$month-01")) ));
$monthCode = str_pad(($month >= 10 ? $month - 9 : $month + 3), 3, '0', STR_PAD_LEFT);
$sheetName = "{$year}年{$month}月";
$yearFolderName = "{$year}年";

$client = init_google_client([
    'credentials_path' => $basePath . '/../client_secrets.json',
    'token_path'       => $basePath . '/../token.json',
    'scopes' => [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/spreadsheets'
    ],
    'app_name'         => 'Drive Role Manager',
]);

$driveService = new Google_Service_Drive($client);
$sheetsService = new Google_Service_Sheets($client);

// 年フォルダ取得
$parentFolderId = '1nD5lxVyyOIpfJ4B949ArDWNL7ka9xJVt';
$yearFolderId = null;

$folders = $driveService->files->listFiles([
    'q' => "'$parentFolderId' in parents and mimeType = 'application/vnd.google-apps.folder'",
]);

foreach ($folders as $folder) {
    if ($folder->getName() === $yearFolderName) {
        $yearFolderId = $folder->getId();
        break;
    }
}

if (!$yearFolderId) {
    die("年フォルダが見つかりません");
}

// スプレッドシート取得
$spreadsheetId = null;
$files = $driveService->files->listFiles([
    'q' => "'$yearFolderId' in parents and mimeType = 'application/vnd.google-apps.spreadsheet'",
]);

foreach ($files as $file) {
    if ($file->getName() === $sheetName) {
        $spreadsheetId = $file->getId();
        break;
    }
}

if (!$spreadsheetId) {
    die("スプレッドシートが見つかりません");
}

$ss_name = '月集計';
if ($year == 2025 && $month == 10) {
  $ss_name = '月集計 のコピー';
}
$response = $sheetsService->spreadsheets_values->get($spreadsheetId, $ss_name . '!C4:I');

$rows = $response->getValues();

// Excelテンプレート準備
$templatePath = __DIR__ . "/template.xlsx";
$outputPath = $outputDir . "/仕分_{$year}年{$month}月.xlsx";

if (file_exists($outputPath)) {
    unlink($outputPath);
}
copy($templatePath, $outputPath);

$spreadsheet = IOFactory::load($outputPath);
$sheet = $spreadsheet->getActiveSheet();

// DB検索関数
function findCode($genba, $kouji, $wpdb, $month) {
    $results = $wpdb->get_results("SELECT * FROM sw_genba_master ORDER BY id DESC");
    foreach ($results as $row) {
        if ($row->name === $genba && $row->subject === $kouji) return ['code' => $row->code, 'd_code' => $row->d_code];
    }
/*
    foreach ($results as $row) {
        if ($row->name === $genba) return ['code' => $row->code, 'd_code' => $row->d_code];
    }
*/
    $commonGenba = "共通原価（{$month}月）";
    foreach ($results as $row) {
        if ($row->name === $commonGenba && $row->subject === "共通原価") return ['code' => $row->code, 'd_code' => $row->d_code, 'is_common' => true];
    }
    return null;
}

function parseAmount($value) {
    if ($value === null) return 0;

    // 全角→半角に正規化（例：全角カンマ、全角数字）
    $value = mb_convert_kana($value, 'n');

    // 括弧のマイナスを処理 (例: "(1,234)" -> -1234)
    $isParenNegative = preg_match('/^\s*\(.*\)\s*$/', $value) === 1;

    // 数字・小数点・マイナス以外を除去
    $clean = preg_replace('/[^0-9\.\-]/', '', $value);

    // 空なら0
    if ($clean === '' || $clean === '-' || $clean === '.') return 0;

    $num = (float)$clean;
    if ($isParenNegative) $num = -$num;

    return $num;
}

// 書き込み処理
$rowIndex = 9;
$loopCount = 1;
$total = 0;
$commonCode = null;
$commonTotal = 0;

foreach ($rows as $row) {
    list($genba, $kouji, , , $roumuhi, $keihi) = array_pad($row, 6, '');
    if (empty($genba)) break;

    $codeInfo = findCode($genba, $kouji, $wpdb, $month);
    if (!$codeInfo) continue;

    $code = $codeInfo['code'];
    $d_code = $codeInfo['d_code'];
    $isCommon = !empty($codeInfo['is_common']);

    $amount = parseAmount($roumuhi);
    $total += $amount; // ここでエラー

    if ($isCommon) {
        $commonCode = $code;
        $commonTotal += (float)str_replace(',', '', $roumuhi);
        continue;
    }

    $bikou = '';
    if( $kouji != '' ) {
        $bikou = mb_convert_kana("{$genba}：{$kouji}", "k", "UTF-8");
    } else {
        $bikou = mb_convert_kana("{$genba}", "k", "UTF-8");
    }

    // 書き込み
    $sheet->setCellValue("B{$rowIndex}", "1");
    $sheet->setCellValueExplicit("C{$rowIndex}", $monthEnd, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->getStyle("C{$rowIndex}")->getNumberFormat()->setFormatCode('yyyy/m/d');
    $sheet->setCellValue("D{$rowIndex}", "1");
    $sheet->setCellValue("E{$rowIndex}", $loopCount);
    $sheet->setCellValue("F{$rowIndex}", "001");
    $sheet->setCellValue("G{$rowIndex}", "170");
    $sheet->setCellValue("H{$rowIndex}", "8");
    $sheet->setCellValue("I{$rowIndex}", "      1641");
    $sheet->setCellValue("J{$rowIndex}", "1641");
    $sheet->setCellValue("K{$rowIndex}", $code);
    $sheet->setCellValue("L{$rowIndex}", $d_code);
    $sheet->setCellValue("R{$rowIndex}", "39001");
    $sheet->setCellValue("T{$rowIndex}", "1");
    $sheet->setCellValue("U{$rowIndex}", "         0");
    $sheet->setCellValue("Z{$rowIndex}", $roumuhi);
    $sheet->setCellValue("AA{$rowIndex}", "0");
    $sheet->setCellValue("AG{$rowIndex}", "1");
    $sheet->setCellValue("AH{$rowIndex}", "1");
    $sheet->setCellValue("AI{$rowIndex}", "0");
    $sheet->setCellValue("AJ{$rowIndex}", "0");
    $sheet->setCellValue("AK{$rowIndex}", $monthEnd);
    $sheet->setCellValue("AK{$rowIndex}", "{$month}月労務費({$bikou})");
    $sheet->setCellValue("DA{$rowIndex}", "0");
    $sheet->setCellValue("DB{$rowIndex}", "0");
    $sheet->setCellValue("DC{$rowIndex}", "0");
    $sheet->setCellValue("DD{$rowIndex}", "0");
    $sheet->setCellValue("DE{$rowIndex}", "0");
    $sheet->setCellValue("DF{$rowIndex}", "0000000000");
    $sheet->setCellValue("DG{$rowIndex}", "000");
    $sheet->setCellValue("EB{$rowIndex}", "0");
    $sheet->setCellValue("EC{$rowIndex}", "1");
    $sheet->setCellValue("ED{$rowIndex}", "0");
    $sheet->setCellValue("EE{$rowIndex}", "0");
    $sheet->setCellValue("EF{$rowIndex}", $monthEnd);
    $sheet->setCellValue("EG{$rowIndex}", "{$month}月労務費({$bikou})");
    $sheet->setCellValue("GW{$rowIndex}", "9");

    $rowIndex++;
    $loopCount++;
}

// 共通原価行の追加
if ($commonCode && $commonTotal > 0) {

    $sheet->setCellValue("B{$rowIndex}", "1");
    $sheet->setCellValueExplicit("C{$rowIndex}", $monthEnd, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->getStyle("C{$rowIndex}")->getNumberFormat()->setFormatCode('yyyy/m/d');
    $sheet->setCellValue("D{$rowIndex}", "1");
    $sheet->setCellValue("E{$rowIndex}", $loopCount);
    $sheet->setCellValue("F{$rowIndex}", "001");
    $sheet->setCellValue("G{$rowIndex}", "170");
    $sheet->setCellValue("H{$rowIndex}", "8");
    $sheet->setCellValue("I{$rowIndex}", "      1641");
    $sheet->setCellValue("J{$rowIndex}", "1641");
    $sheet->setCellValue("K{$rowIndex}", $genba_code);
    $sheet->setCellValue("L{$rowIndex}", '013');
    $sheet->setCellValue("R{$rowIndex}", "39001");
    $sheet->setCellValue("T{$rowIndex}", "1");
    $sheet->setCellValue("U{$rowIndex}", "         0");
    $sheet->setCellValue("Z{$rowIndex}", $commonTotal);
    $sheet->setCellValue("AA{$rowIndex}", "0");
    $sheet->setCellValue("AG{$rowIndex}", "1");
    $sheet->setCellValue("AH{$rowIndex}", "1");
    $sheet->setCellValue("AI{$rowIndex}", "0");
    $sheet->setCellValue("AJ{$rowIndex}", "0");
    $sheet->setCellValue("AK{$rowIndex}", $monthEnd);
    $sheet->setCellValue("AK{$rowIndex}", "{$month}月労務費");
    $sheet->setCellValue("DA{$rowIndex}", "0");
    $sheet->setCellValue("DB{$rowIndex}", "0");
    $sheet->setCellValue("DC{$rowIndex}", "0");
    $sheet->setCellValue("DD{$rowIndex}", "0");
    $sheet->setCellValue("DE{$rowIndex}", "0");
    $sheet->setCellValue("DF{$rowIndex}", "0000000000");
    $sheet->setCellValue("DG{$rowIndex}", "000");
    $sheet->setCellValue("EB{$rowIndex}", "0");
    $sheet->setCellValue("EC{$rowIndex}", "1");
    $sheet->setCellValue("ED{$rowIndex}", "0");
    $sheet->setCellValue("EE{$rowIndex}", "0");
    $sheet->setCellValue("EF{$rowIndex}", $monthEnd);
    $sheet->setCellValue("EG{$rowIndex}", "{$month}月労務費");
    $sheet->setCellValue("GW{$rowIndex}", "9");
    $rowIndex++;
    $loopCount++;
}

$sheet->setCellValue("B{$rowIndex}", "1");
$sheet->setCellValueExplicit("C{$rowIndex}", $monthEnd, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
$sheet->getStyle("C{$rowIndex}")->getNumberFormat()->setFormatCode('yyyy/m/d');
$sheet->setCellValue("D{$rowIndex}", "1");
$sheet->setCellValue("E{$rowIndex}", $loopCount);
$sheet->setCellValue("F{$rowIndex}", "0");
$sheet->setCellValue("G{$rowIndex}", "0");
$sheet->setCellValue("H{$rowIndex}", "");
$sheet->setCellValue("I{$rowIndex}", "         0");
$sheet->setCellValue("J{$rowIndex}", "0");
$sheet->setCellValue("K{$rowIndex}", "0");
$sheet->setCellValue("L{$rowIndex}", "000");
$sheet->setCellValue("R{$rowIndex}", "00000");
$sheet->setCellValue("T{$rowIndex}", "0");
$sheet->setCellValue("U{$rowIndex}", "         0");
$sheet->setCellValue("Z{$rowIndex}", "0");
$sheet->setCellValue("AA{$rowIndex}", "0");
$sheet->setCellValue("AG{$rowIndex}", "0");
$sheet->setCellValue("AH{$rowIndex}", "1");
$sheet->setCellValue("AI{$rowIndex}", "0");
$sheet->setCellValue("AJ{$rowIndex}", "0");
$sheet->setCellValue("AK{$rowIndex}", $monthEnd);
$sheet->setCellValue("AK{$rowIndex}", "{$month}月労務費");
$sheet->setCellValue("DA{$rowIndex}", "001");
$sheet->setCellValue("DB{$rowIndex}", "170");
$sheet->setCellValue("DC{$rowIndex}", "8");
$sheet->setCellValue("DD{$rowIndex}", "      1641");
$sheet->setCellValue("DE{$rowIndex}", "1641");
$sheet->setCellValue("DF{$rowIndex}", $genba_code);
$sheet->setCellValue("DG{$rowIndex}", $monthCode);
$sheet->setCellValue("DM{$rowIndex}", "39001");
$sheet->setCellValue("DO{$rowIndex}", "1");
$sheet->setCellValue("DP{$rowIndex}", "         0");
$sheet->setCellValue("DU{$rowIndex}", $total);
$sheet->setCellValue("DV{$rowIndex}", "0");
$sheet->setCellValue("EB{$rowIndex}", "1");
$sheet->setCellValue("EC{$rowIndex}", "1");
$sheet->setCellValue("ED{$rowIndex}", "0");
$sheet->setCellValue("EE{$rowIndex}", "0");
$sheet->setCellValue("EF{$rowIndex}", $monthEnd);
$sheet->setCellValue("EG{$rowIndex}", "{$month}月労務費");
$sheet->setCellValue("GW{$rowIndex}", "9");
$rowIndex++;
$loopCount++;


$total = 0;
$commonCode = null;
$commonTotal = 0;

foreach ($rows as $row) {
    list($genba, $kouji, , , $roumuhi, $keihi) = array_pad($row, 6, '');
    if (empty($genba)) break;

    $codeInfo = findCode($genba, $kouji, $wpdb, $month);
    if (!$codeInfo) continue;

    $code = $codeInfo['code'];
    $d_code = $codeInfo['d_code'];
    $isCommon = !empty($codeInfo['is_common']);

    $total += (float)str_replace(',', '', $keihi);

    if ($isCommon) {
        $commonCode = $code;
        $commonTotal += (float)str_replace(',', '', $keihi);
        continue;
    }

    $bikou = '';
    if( $kouji != '' ) {
        $bikou = mb_convert_kana("{$genba}：{$kouji}", "k", "UTF-8");
    } else {
        $bikou = mb_convert_kana("{$genba}", "k", "UTF-8");
    }

    // 書き込み
    $sheet->setCellValue("B{$rowIndex}", "1");
    $sheet->setCellValueExplicit("C{$rowIndex}", $monthEnd, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->getStyle("C{$rowIndex}")->getNumberFormat()->setFormatCode('yyyy/m/d');
    $sheet->setCellValue("D{$rowIndex}", "2");
    $sheet->setCellValue("E{$rowIndex}", $loopCount);
    $sheet->setCellValue("F{$rowIndex}", "001");
    $sheet->setCellValue("G{$rowIndex}", "170");
    $sheet->setCellValue("H{$rowIndex}", "8");
    $sheet->setCellValue("I{$rowIndex}", "      1672");
    $sheet->setCellValue("J{$rowIndex}", "1672");
    $sheet->setCellValue("K{$rowIndex}", $code);
    $sheet->setCellValue("L{$rowIndex}", $d_code);
    $sheet->setCellValue("R{$rowIndex}", "39002");
    $sheet->setCellValue("T{$rowIndex}", "1");
    $sheet->setCellValue("U{$rowIndex}", "         0");
    $sheet->setCellValue("Z{$rowIndex}", $keihi);
    $sheet->setCellValue("AA{$rowIndex}", "0");
    $sheet->setCellValue("AG{$rowIndex}", "1");
    $sheet->setCellValue("AH{$rowIndex}", "1");
    $sheet->setCellValue("AI{$rowIndex}", "0");
    $sheet->setCellValue("AJ{$rowIndex}", "0");
    $sheet->setCellValue("AK{$rowIndex}", $monthEnd);
    $sheet->setCellValue("AK{$rowIndex}", "{$month}月経費({$bikou})");
    $sheet->setCellValue("DA{$rowIndex}", "0");
    $sheet->setCellValue("DB{$rowIndex}", "0");
    $sheet->setCellValue("DC{$rowIndex}", "0");
    $sheet->setCellValue("DD{$rowIndex}", "0");
    $sheet->setCellValue("DE{$rowIndex}", "0");
    $sheet->setCellValue("DF{$rowIndex}", "0000000000");
    $sheet->setCellValue("DG{$rowIndex}", "000");
    $sheet->setCellValue("EB{$rowIndex}", "0");
    $sheet->setCellValue("EC{$rowIndex}", "1");
    $sheet->setCellValue("ED{$rowIndex}", "0");
    $sheet->setCellValue("EE{$rowIndex}", "0");
    $sheet->setCellValue("EF{$rowIndex}", $monthEnd);
    $sheet->setCellValue("EG{$rowIndex}", "{$month}月経費({$bikou})");
    $sheet->setCellValue("GW{$rowIndex}", "9");

    $rowIndex++;
    $loopCount++;
}

// 共通原価行の追加
if ($commonCode && $commonTotal > 0) {

    $sheet->setCellValue("B{$rowIndex}", "1");
    $sheet->setCellValueExplicit("C{$rowIndex}", $monthEnd, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
    $sheet->getStyle("C{$rowIndex}")->getNumberFormat()->setFormatCode('yyyy/m/d');
    $sheet->setCellValue("D{$rowIndex}", "2");
    $sheet->setCellValue("E{$rowIndex}", $loopCount);
    $sheet->setCellValue("F{$rowIndex}", "001");
    $sheet->setCellValue("G{$rowIndex}", "170");
    $sheet->setCellValue("H{$rowIndex}", "8");
    $sheet->setCellValue("I{$rowIndex}", "      1672");
    $sheet->setCellValue("J{$rowIndex}", "1672");
    $sheet->setCellValue("K{$rowIndex}", $genba_code);
    $sheet->setCellValue("L{$rowIndex}", '013');
    $sheet->setCellValue("R{$rowIndex}", "39002");
    $sheet->setCellValue("T{$rowIndex}", "1");
    $sheet->setCellValue("U{$rowIndex}", "         0");
    $sheet->setCellValue("Z{$rowIndex}", $commonTotal);
    $sheet->setCellValue("AA{$rowIndex}", "0");
    $sheet->setCellValue("AG{$rowIndex}", "1");
    $sheet->setCellValue("AH{$rowIndex}", "1");
    $sheet->setCellValue("AI{$rowIndex}", "0");
    $sheet->setCellValue("AJ{$rowIndex}", "0");
    $sheet->setCellValue("AK{$rowIndex}", $monthEnd);
    $sheet->setCellValue("AK{$rowIndex}", "{$month}月経費");
    $sheet->setCellValue("DA{$rowIndex}", "0");
    $sheet->setCellValue("DB{$rowIndex}", "0");
    $sheet->setCellValue("DC{$rowIndex}", "0");
    $sheet->setCellValue("DD{$rowIndex}", "0");
    $sheet->setCellValue("DE{$rowIndex}", "0");
    $sheet->setCellValue("DF{$rowIndex}", "0000000000");
    $sheet->setCellValue("DG{$rowIndex}", "000");
    $sheet->setCellValue("EB{$rowIndex}", "0");
    $sheet->setCellValue("EC{$rowIndex}", "1");
    $sheet->setCellValue("ED{$rowIndex}", "0");
    $sheet->setCellValue("EE{$rowIndex}", "0");
    $sheet->setCellValue("EF{$rowIndex}", $monthEnd);
    $sheet->setCellValue("EG{$rowIndex}", "{$month}月労務費");
    $sheet->setCellValue("GW{$rowIndex}", "9");
    $rowIndex++;
    $loopCount++;
}

$sheet->setCellValue("B{$rowIndex}", "1");
$sheet->setCellValueExplicit("C{$rowIndex}", $monthEnd, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
$sheet->getStyle("C{$rowIndex}")->getNumberFormat()->setFormatCode('yyyy/m/d');
$sheet->setCellValue("D{$rowIndex}", "2");
$sheet->setCellValue("E{$rowIndex}", $loopCount);
$sheet->setCellValue("F{$rowIndex}", "0");
$sheet->setCellValue("G{$rowIndex}", "0");
$sheet->setCellValue("H{$rowIndex}", "");
$sheet->setCellValue("I{$rowIndex}", "         0");
$sheet->setCellValue("J{$rowIndex}", "0");
$sheet->setCellValue("K{$rowIndex}", "0");
$sheet->setCellValue("L{$rowIndex}", "000");
$sheet->setCellValue("R{$rowIndex}", "00000");
$sheet->setCellValue("T{$rowIndex}", "0");
$sheet->setCellValue("U{$rowIndex}", "         0");
$sheet->setCellValue("Z{$rowIndex}", "0");
$sheet->setCellValue("AA{$rowIndex}", "0");
$sheet->setCellValue("AG{$rowIndex}", "0");
$sheet->setCellValue("AH{$rowIndex}", "1");
$sheet->setCellValue("AI{$rowIndex}", "0");
$sheet->setCellValue("AJ{$rowIndex}", "0");
$sheet->setCellValue("AK{$rowIndex}", $monthEnd);
$sheet->setCellValue("AK{$rowIndex}", "{$month}月経費");
$sheet->setCellValue("DA{$rowIndex}", "001");
$sheet->setCellValue("DB{$rowIndex}", "170");
$sheet->setCellValue("DC{$rowIndex}", "8");
$sheet->setCellValue("DD{$rowIndex}", "      1672");
$sheet->setCellValue("DE{$rowIndex}", "1672");
$sheet->setCellValue("DF{$rowIndex}", $genba_code);
$sheet->setCellValue("DG{$rowIndex}", $monthCode);
$sheet->setCellValue("DM{$rowIndex}", "39002");
$sheet->setCellValue("DO{$rowIndex}", "1");
$sheet->setCellValue("DP{$rowIndex}", "         0");
$sheet->setCellValue("DU{$rowIndex}", $total);
$sheet->setCellValue("DV{$rowIndex}", "0");
$sheet->setCellValue("EB{$rowIndex}", "1");
$sheet->setCellValue("EC{$rowIndex}", "1");
$sheet->setCellValue("ED{$rowIndex}", "0");
$sheet->setCellValue("EE{$rowIndex}", "0");
$sheet->setCellValue("EF{$rowIndex}", $monthEnd);
$sheet->setCellValue("EG{$rowIndex}", "{$month}月経費");
$sheet->setCellValue("GW{$rowIndex}", "9");
$rowIndex++;
$loopCount++;

// Excel保存
$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($outputPath);

sw_log('shiwake.php',"PROCES.S向けExcelファイル「{$year}年{$month}月.xlsx」を生成しました。");

header('Content-Type: application/json');
echo json_encode([
  'success' => true,
  'message' => "PROCES.S向けExcelファイル {$year}年{$month}月.xlsx を生成しました。<br><a href='/post/output/仕分_{$year}年{$month}月.xlsx'>ダウンロード</a>",
]);
