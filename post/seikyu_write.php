<?php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../wp-load.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date;

if (
    empty($_SESSION['output_path']) ||
    !isset($_SESSION['write_row']) ||
    empty($_SESSION['seikyu_data'])
) {
    echo "セッション情報が見つかりません。最初からやり直してください。";
    exit;
}

$outputPath = $_SESSION['output_path'];
$writeRow = (int)$_SESSION['write_row']; // 例: 9
$seikyuData = $_SESSION['seikyu_data'];

if (!file_exists($outputPath)) {
    echo "出力ファイルが見つかりません。";
    exit;
}

function evaluateAmount($input) {
    $safe = preg_replace('/[^0-9\+\-\*\/\.\(\)]/', '', $input);
    $result = @eval("return {$safe};");
    return is_numeric($result) ? (float)$result : 0;
}

$spreadsheet = IOFactory::load($outputPath);
$sheet = $spreadsheet->getActiveSheet();

$rowNo = $writeRow;
$fileSerial = 1;

foreach ($seikyuData as $fileIndex => $input) {
    $entries = $input['data'][0] ?? []; // ← ここが超重要ポイント！
    $vendorCode = $input['vendor_code'][0] ?? '';
    $genbaCodeList = $input['genba_code'][0] ?? [];

    $grouped = [];

    foreach ($entries as $rowIndex => $entry) {
        if (!empty($entry['skip'])) continue;

        $genbaCodeRaw = $genbaCodeList[$rowIndex] ?? '';
        if (!preg_match('/\d+/', $genbaCodeRaw, $match2)) continue;
        $genbaCode = $match2[0];

        $key = $vendorCode . '_' . $genbaCode;

        $rawAmount = $entry['amount'] ?? '';
        $amount = evaluateAmount($rawAmount);
        $date   = $entry['date'] ?? '';
        $kamoku = $entry['kamoku'] ?? '';
        $tax    = $entry['tax'] ?? '';

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'gyousya_code' => $vendorCode,
                'genba_code'   => $genbaCode,
                'amount'       => 0,
                'date'         => '',
                'kamoku'       => '',
                'tax'          => '',
            ];
        }

        $grouped[$key]['amount'] += $amount;
        $grouped[$key]['date']    = $date;
        $grouped[$key]['kamoku']  = $kamoku;
        $grouped[$key]['tax']     = $tax;
    }

    $lineSerial = 1;

    foreach ($grouped as $entry) {
        $kamoku = $entry['kamoku'];
        $kamokuCode = ($kamoku == '610') ? '1610' : '1620';
        $indentCode = '      ' . $kamokuCode;
        $date = $entry['date'];
        $taxRate = (float)$entry['tax'];
        $taxAmount = $entry['amount'] * ($taxRate / 100);

        // 1. PHPのDateTimeオブジェクトに変換
        $dateObj = new DateTime($date);
        // 2. Excelのシリアル値に変換
        $excelDate = Date::PHPToExcel($dateObj);

        $sheet->setCellValue("B{$rowNo}", 1);
        $sheet->setCellValueExplicit("C{$rowNo}", $excelDate, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->getStyle("C{$rowNo}") ->getNumberFormat() ->setFormatCode('yyyy/m/d');
        $sheet->setCellValue("D{$rowNo}", $fileSerial);
        $sheet->setCellValue("E{$rowNo}", $lineSerial);
        $sheet->setCellValue("F{$rowNo}", '001');
        $sheet->setCellValue("G{$rowNo}", $kamoku);
        $sheet->setCellValue("H{$rowNo}", 8);
        $sheet->setCellValue("I{$rowNo}", $indentCode);
        $sheet->setCellValue("J{$rowNo}", $kamokuCode);
        $sheet->setCellValue("K{$rowNo}", $entry['genba_code']);
        $sheet->setCellValue("L{$rowNo}", '001');
        $sheet->setCellValue("R{$rowNo}", $entry['gyousya_code']);
        $sheet->setCellValue("Z{$rowNo}", $entry['amount']);
        $sheet->setCellValue("AA{$rowNo}", $taxAmount);
        $sheet->setCellValue("AG{$rowNo}", 2);
        $sheet->setCellValue("AH{$rowNo}", 2);
        $sheet->setCellValue("AI{$rowNo}", $taxRate);
        $sheet->setCellValue("AJ{$rowNo}", 50);
        $sheet->setCellValue("AK{$rowNo}", $date);
        $sheet->setCellValue("AL{$rowNo}", '');
        $sheet->setCellValue("DA{$rowNo}", '001');
        $sheet->setCellValue("DB{$rowNo}", '301');
        $sheet->setCellValue("DC{$rowNo}", 2);
        $sheet->setCellValue("DD{$rowNo}", $entry['gyousya_code']);
        $sheet->setCellValue("DE{$rowNo}", 0);
        $sheet->setCellValue("DF{$rowNo}", $entry['genba_code']);
        $sheet->setCellValue("DG{$rowNo}", '001');
        $sheet->setCellValue("DM{$rowNo}", $entry['gyousya_code']);
        $sheet->setCellValue("DU{$rowNo}", $entry['amount'] + $taxAmount);
        $sheet->setCellValue("DV{$rowNo}", 0);
        $sheet->setCellValue("EB{$rowNo}", 2);
        $sheet->setCellValue("EC{$rowNo}", 1);
        $sheet->setCellValue("ED{$rowNo}", 0);
        $sheet->setCellValue("EE{$rowNo}", 50);
        $sheet->setCellValue("EF{$rowNo}", $date);
        $sheet->setCellValue("GW{$rowNo}", 9);

        $rowNo++;
        $lineSerial++;
    }

    $fileSerial++;
}

$_SESSION['write_row'] = $rowNo;

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($outputPath);

$downloadUrl = htmlspecialchars(str_replace($_SERVER['DOCUMENT_ROOT'], '', $outputPath));

$filename = basename($outputPath);
sw_log('seikyu_write.php',"PROCES.S向けExcelファイル「{$filename}」を生成しました。");

?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>請求書書き込み完了</title>
  <style>
    body {
      font-family: sans-serif;
      padding: 2em;
    }

    .button-row {
      display: flex;
      gap: 12px;
      margin-top: 20px;
    }

    .btn {
      display: inline-block;
      padding: 10px 20px;
      background-color: #0078D4;
      color: #fff;
      text-decoration: none;
      border-radius: 6px;
      font-weight: bold;
      transition: background-color 0.2s ease;
    }

    .btn:hover {
      background-color: #005ea2;
    }

    .btn-secondary {
      background-color: #f0a500;
    }

    .btn-secondary:hover {
      background-color: #d48806;
    }
    .btn-download {
      background-color: #0078D4;
    }
    .btn-download:hover {
      background-color: #005ea2;
    }

    .btn-back {
      background-color: #28a745;
    }
    .btn-back:hover {
      background-color: #218838;
    }

    .btn-reset {
      background-color: #f0a500;
    }
    .btn-reset:hover {
      background-color: #d48806;
    }
  </style>
</head>
<body>
  <h2>すべてのデータを書き込みました！</h2>
  <div class="button-row">
    <a href="<?= $downloadUrl ?>" target="_blank" class="btn btn-download" onclick="setTimeout(() => { fetch('/post/seikyu_reset.php'); }, 3000);">Excelをダウンロード</a>
    <a href="/post/seikyu_process.php?back=1" class="btn btn-back" id="back-button">戻る</a>
    <a href="/seikyu/" class="btn btn-reset" onclick="resetAndGoBack()">最初に戻る</a>
  </div>

<script>
  const downloadBtn = document.querySelector('.btn-download');
  const backBtn = document.getElementById('back-button');

  downloadBtn.addEventListener('click', () => {
    // 3秒後にセッション破棄
    setTimeout(() => {
      fetch('/post/seikyu_reset.php');
    }, 3000);

    // 即座に「戻る」ボタンを非表示にする
    if (backBtn) {
      backBtn.style.display = 'none';
    }
  });

  function resetAndGoBack() {
      fetch('/post/seikyu_reset.php', { method: 'POST' })
          .then(() => {
              window.location.href = '/seikyu/';
          });
  }
</script>


</body>
</html>
