<?php
session_start();

$totalPages = count($_SESSION['uploaded_files'] ?? []);
$currentPage = ($_SESSION['current_index'] ?? 0) + 1;
if( isset($_GET['back']) && $_GET['back'] == 1 ) {
    $currentPage--;
}
?>

<p style="font-weight: bold; margin-bottom: 1em;">
    <?= $currentPage ?> / <?= $totalPages ?>  ページ
</p>

<?php
if (isset($_GET['back']) && isset($_SESSION['current_index'])) {
    $_SESSION['current_index'] = max(0, $_SESSION['current_index'] - 1);
}
if (
    empty($_SESSION['uploaded_files']) ||
    !isset($_SESSION['current_index']) ||
    !isset($_SESSION['output_path']) ||
    !isset($_SESSION['write_row'])
) {
    echo "セッション情報が見つかりません。最初からやり直してください。";
    exit;
}

$files = $_SESSION['uploaded_files'];
$currentIndex = $_SESSION['current_index'];
$fileInfo = $files[$currentIndex] ?? null;

if (!$fileInfo || !file_exists($fileInfo['path'])) {
    echo "現在のファイルが見つかりません。";
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../wp-load.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// 入力データをセッションに保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['seikyu_data'])) {
        $_SESSION['seikyu_data'] = [];
    }

    // 🔽 ここで必要なすべての入力を保存する
    $_SESSION['seikyu_data'][$currentIndex] = [
        'data' => $_POST['data'] ?? [],
        'vendor_name' => $_POST['vendor_name'] ?? [],
        'vendor_code' => $_POST['vendor_code'] ?? [],
        'genba_name' => $_POST['genba_name'] ?? [],
        'genba_code' => $_POST['genba_code'] ?? [],
    ];

    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'next') {
            $_SESSION['current_index']++;
        } elseif ($_POST['action'] === 'back') {
            $_SESSION['current_index'] = max(0, $_SESSION['current_index'] - 1);
        }
    }

    // 最後のファイルを超えたら書き込みへ
    if ($_SESSION['current_index'] >= count($files)) {
        header('Location: /post/seikyu_write.php');
        exit;
    }

    // リロードして次のファイルを読み込む
    header('Location: seikyu_process.php');
    exit;
}

// 現在のファイルを読み込み
$spreadsheet = IOFactory::load($fileInfo['path']);
$sheet = $spreadsheet->getActiveSheet();

$formatType = 'pattern2'; // デフォルト
$j1 = trim((string)$sheet->getCell('J1')->getValue());

if ($j1 === '請　求　書') {
    $formatType = 'pattern1';
}

// 列の定義（パターンごとに切り替え）
$columns = [];

switch ($formatType) {
    case 'pattern1':
        $columns = [
            'vendor' => 'AA3',
            'date' => 'B',
            'genba' => 'F',
            'kouji' => 'R',
            'amount' => 'AC',
        ];
        break;
    case 'pattern2':
    default:
        $columns = [
            'vendor' => '',   // 未定
            'date' => 'E',    // 仮
            'genba' => '',    // 未定
            'kouji' => '',    // 未定
            'amount' => '',   // 未定
        ];
        break;
}

$vendorName = $columns['vendor'] ? trim((string)$sheet->getCell($columns['vendor'])->getValue()) : '';
$rows = [];
$hasCommon = false;

for ($row = 15; $row < 115; $row++) {
    $dateVal = $columns['date'] ? trim((string)$sheet->getCell("{$columns['date']}{$row}")->getValue()) : '';
    $amountVal = $columns['amount'] ? trim((string)$sheet->getCell("{$columns['amount']}{$row}")->getValue()) : '';

    if ($dateVal === '日付' || $amountVal === '' || $amountVal === null) continue;
    if (!$columns['genba'] || !$columns['kouji']) continue; // パターン2はまだ未定なのでスキップ

    $genba = trim((string)$sheet->getCell("{$columns['genba']}{$row}")->getValue());
    $kouji = trim((string)$sheet->getCell("{$columns['kouji']}{$row}")->getValue());

    // 日付の整形
    if (is_numeric($dateVal)) {
        $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateVal)->format('Y/m/d');
    } else {
        $date = date('Y/m/d', strtotime($dateVal));
    }

    $amount = (float)str_replace(',', '', $amountVal);

    $excludeWords = ['交通費', '材費', '雑費', '経費'];
    $isExcluded = false;
    foreach ($excludeWords as $word) {
        if (mb_strpos($genba, $word) !== false || mb_strpos($kouji, $word) !== false) {
            $isExcluded = true;
            $hasCommon = true;
            break;
        }
    }

    $rows[] = [
        'vendor_name' => $vendorName,
        'date' => $date,
        'genba' => $genba,
        'kouji' => $kouji,
        'amount' => $amount,
        'is_excluded' => $isExcluded,
    ];
}

// 総金額（除外行を除く）
$total = array_sum(array_map(function ($r) {
    return empty($r['is_excluded']) ? $r['amount'] : 0;
}, $rows));

// パーセント計算
foreach ($rows as &$r) {
    if (!empty($r['is_excluded'])) {
        $r['percent'] = 0;
    } else {
        $r['percent'] = $total > 0 ? round(($r['amount'] / $total) * 100, 2) : 0;
    }
}
unset($r);

// 共通費の合計
$commonTotal = array_sum(array_map(function ($r) {
    return !empty($r['is_excluded']) ? $r['amount'] : 0;
}, $rows));

// 各行に対して分配額を計算（percentベース）
$distributedSum = 0;
$lastIndex = null;

foreach ($rows as $i => $r) {
    if (!empty($r['is_excluded'])) {
        continue;
    }

    $percent = $r['percent'] ?? 0;
    $share = round($commonTotal * ($percent / 100));
    if( $hasCommon && $share > 0 ) {
        $rows[$i]['amount'] = rtrim((string)$r['amount']) . '+' . $share;
    } else {
        $rows[$i]['amount'] = rtrim((string)$r['amount']);
    }
    $distributedSum += $share;
    $lastIndex = $i; // 最後に分配した行を記録
}

// 端数調整（合計がズレてたら最後の行で調整）
$diff = $commonTotal - $distributedSum;
if ($diff !== 0 && $lastIndex !== null) {
    // 元の金額と +金額 を分離
    $original = $rows[$lastIndex]['amount'];
    if (preg_match('/^(\d+)(\+\d+)?$/', $original, $matches)) {
        $base = $matches[1];
        $plus = isset($matches[2]) ? (int)substr($matches[2], 1) : 0;
        $plus += $diff;
        if( $hasCommon && $plus > 0 ) {
            $rows[$lastIndex]['amount'] = $base . '+' . $plus;
        } else {
            $rows[$lastIndex]['amount'] = $base;
        }
    }
}

// 金額に +分配 を追記（最初の1行だけ）
$usedGenba = [];
foreach ($rows as $i => $r) {
    $genba = $r['genba'];
    if (empty($r['is_excluded']) && isset($distributed[$genba]) && !isset($usedGenba[$genba])) {
        $plus = $distributed[$genba];
        if ($plus > 0) {
            $rows[$i]['amount'] = rtrim((string)$r['amount']) . '+' . $plus;
        }
        $usedGenba[$genba] = true;
    }
}

// 入力復元（戻る時）
$previousData = $_SESSION['seikyu_data'][$currentIndex] ?? [];

$results = [[
    'vendor_name' => $vendorName,
    'rows' => $rows,
    'serial_file' => $currentIndex + 1,
    'previous_data' => $previousData,
]];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>請求書データ確認</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .entry-block { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 8px; background: #fdfdfd; }
        .entry-block h3 { margin-top: 0; }
        .entry-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; }
        .entry-row label { width: 100px; font-weight: bold; }
        .entry-row input, .entry-row select { flex: 1; padding: 5px; }
        .skip-box { margin-bottom: 10px; }
        .write-btn { padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

<h2>請求書データ確認</h2>

<style>
.entry-row {
  display: flex;
  flex-wrap: nowrap; /* 折り返さないようにする */
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
  font-size: 14px;
}

.entry-label {
  font-weight: normal;
  font-size: 13px;
  width: 120px;
  flex-shrink: 0;
}

.autocomplete-wrapper {
  position: relative;
  flex-grow: 1;
  display: flex;
  max-width: 300px; /* 必要に応じて調整 */
}

.autocomplete-wrapper input[type="text"] {
  width: 240px;
  max-width: 100%;
  padding: 4px 8px;
  font-size: 13px;
  border: 1px solid #ccc;
  border-radius: 6px;
  box-sizing: border-box;
}

.suggest-box {
  width: 240px !important;
}

.entry-row input[type="text"],
.entry-row select {
  flex: 0 0 auto;
  width: 240px;
  max-width: 100%;
  padding: 4px 8px;
  font-size: 13px;
  border: 1px solid #ccc;
  border-radius: 6px;
  box-sizing: border-box;
}

.entry-row span {
    font-size: 13px;
}

.entry-block {
    border: 1px solid #ccc;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
    background: #fdfdfd;
}

.entry-section.grayout {
    background-color: #e0e0e0;
}

/* テキストボックスの親に position: relative を指定 */
.autocomplete-wrapper {
  position: relative;
  display: inline-block;
  width: 100%;
}

/* 候補ボックスをその下に表示 */
.suggest-box {
  position: absolute;
  top: 100%;
  left: 0;
  background: #fff;
  border: 1px solid #ccc;
  border-radius: 4px;
  max-height: 200px;
  overflow-y: auto;
  width: 200px;
  z-index: 1000;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  font-size: 13px;
}

.suggestion.selected {
  background-color: #cceeff;
}

.suggestion {
  padding: 6px 10px;
  cursor: pointer;
}

.suggestion:hover {
  background-color: #e6f7ff;
}

.write-btn:disabled {
  cursor: not-allowed !important;
  opacity: 0.6;
}

  .btn {
    background-color: #2196f3;
    color: white;
    padding: 10px 20px;
    font-size: 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: background 0.3s ease, transform 0.2s ease;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
  }

  .btn:hover {
    background-color: #1976d2;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
  }

  .btn-secondary {
    background-color: #f57c00;
    color: white;
  }

  .btn-secondary:hover {
    background-color: #e65100;
  }

  .button-row {
    display: flex;
    gap: 10px;
    justify-content: flex-start;
    margin-top: 20px;
  }
</style>

<form method="post" action="seikyu_process.php">
<?php foreach ($results as $fileIndex => $fileData): ?>
    <?php
        $previous = $fileData['previous_data'] ?? [];
        $prevData = $previous['data'] ?? [];
        $prevVendorName = $previous['vendor_name'] ?? [];
        $prevVendorCode = $previous['vendor_code'] ?? [];
        $prevGenbaName = $previous['genba_name'] ?? [];
        $prevGenbaCode = $previous['genba_code'] ?? [];
    ?>
    <div class="entry-block">
        <div class="entry-row">
            <span class="entry-label">業者名：</span>
            <span><?= htmlspecialchars($fileData['vendor_name']) ?></span>
        </div>

        <!-- 業者名検索ボックス -->
        <div class="entry-row">
          <label class="entry-label">業者名（検索）</label>
          <div class="autocomplete-wrapper">
            <input type="text" name="vendor_name[<?= $fileIndex ?>]" class="vendor-autocomplete"
                   value="<?= htmlspecialchars($prevVendorName[$fileIndex] ?? '') ?>" autocomplete="off">
            <input type="hidden" name="vendor_code[<?= $fileIndex ?>]" class="vendor-code"
                   value="<?= htmlspecialchars($prevVendorCode[$fileIndex] ?? '') ?>">
          </div>
        </div>

        <?php foreach ($fileData['rows'] as $rowIndex => $row): ?>
            <?php
              $skipWords = ['交通費', '雑材費', '雑費', '経費'];
              $shouldSkip = false;
              foreach ($skipWords as $word) {
                  if (mb_strpos($row['genba'], $word) !== false || mb_strpos($row['kouji'], $word) !== false) {
                      $shouldSkip = true;
                      break;
                  }
              }

              $prev = $prevData[$fileIndex][$rowIndex] ?? [];
            ?>
            <div class="entry-section" style="border-top: 1px solid #ddd; margin-top: 10px; padding-top: 10px;">
                <div class="entry-row">
                    <label>
                        <input type="checkbox"
                               class="skip-checkbox"
                               name="data[<?= $fileIndex ?>][<?= $rowIndex ?>][skip]"
                               value="1"
                               <?= !empty($prev['skip']) || $shouldSkip ? 'checked' : '' ?>>
                        スキップ
                    </label>
                </div>

                <div class="entry-content">
                    <div class="entry-row">
                        <span class="entry-label">日付：</span>
                        <span><?= htmlspecialchars($row['date']) ?></span>
                        <input type="hidden" name="data[<?= $fileIndex ?>][<?= $rowIndex ?>][date]" value="<?= htmlspecialchars($row['date']) ?>">
                    </div>

                    <div class="entry-row">
                        <span class="entry-label">現場名：</span>
                        <span><?= htmlspecialchars($row['genba']) ?></span>
                    </div>

                    <div class="entry-row">
                        <span class="entry-label">工事名：</span>
                        <span><?= htmlspecialchars($row['kouji']) ?></span>
                    </div>

                    <div class="entry-row">
                        <label class="entry-label">科目コード</label>
                        <select name="data[<?= $fileIndex ?>][<?= $rowIndex ?>][kamoku]">
                            <option value="610" <?= (isset($prev['kamoku']) && $prev['kamoku'] === '610') ? 'selected' : '' ?>>610：材料費</option>
                            <option value="620" <?= (isset($prev['kamoku']) && $prev['kamoku'] === '620') ? 'selected' : '' ?>>620：外注費</option>
                        </select>
                    </div>

                    <div class="entry-row">
                        <label class="entry-label">現場名（検索）</label>
                        <div class="autocomplete-wrapper">
                            <input type="text" name="genba_name[<?= $fileIndex ?>][<?= $rowIndex ?>]" class="genba-autocomplete"
                                   value="<?= htmlspecialchars($prevGenbaName[$fileIndex][$rowIndex] ?? '') ?>" autocomplete="off">
                            <input type="hidden" name="genba_code[<?= $fileIndex ?>][<?= $rowIndex ?>]" class="genba-code"
                                   value="<?= htmlspecialchars($prevGenbaCode[$fileIndex][$rowIndex] ?? '') ?>">
                        </div>
                    </div>

                    <div class="entry-row">
                        <label class="entry-label">金額</label>
                        <input type="text" name="data[<?= $fileIndex ?>][<?= $rowIndex ?>][amount]"
                               value="<?= htmlspecialchars($prev['amount'] ?? $row['amount']) ?>">
                        <span>（<?= $row['percent'] ?>%）</span>
                    </div>

                    <div class="entry-row">
                        <label class="entry-label">税率</label>
                        <input type="text" name="data[<?= $fileIndex ?>][<?= $rowIndex ?>][tax]"
                               value="<?= htmlspecialchars($prev['tax'] ?? '10') ?>" style="width: 60px;"> %
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

    <input type="hidden" name="step" value="<?= $_SESSION['current_index'] ?>">
    <div class="button-row" style="margin-top: 20px;">
        <?php if ($_SESSION['current_index'] > 0): ?>
            <button type="submit" name="action" value="back" class="btn btn-secondary">戻る</button>
        <?php endif; ?>

        <?php if ($currentPage === $totalPages): ?>
            <button type="submit" name="action" value="next" class="btn btn-download write-btn">Excelに書き込み</button>
        <?php else: ?>
            <button type="submit" name="action" value="next" class="btn">次へ</button>
        <?php endif; ?>
    </div>
</form>

</body>
</html>

<script>
jQuery(function($) {
  function setupAutocomplete(selector, actionName, hiddenSelector) {
    $(document).on('input', selector, function () {
      const $input = $(this);
      const query = $input.val();
      if (query.length < 1) return;

      $.get('/wp-admin/admin-ajax.php', {
        action: actionName,
        query: query
      }, function (data) {
        const suggestions = Array.isArray(data) ? data : [];
        let $wrapper = $input.closest('.autocomplete-wrapper');
        let $suggestBox = $wrapper.find('.suggest-box');

        if (!$suggestBox.length) {
          $suggestBox = $('<div class="suggest-box"></div>');
          $wrapper.append($suggestBox);
        }

        const html = suggestions.map((item, i) =>
          `<div class="suggestion" data-index="${i}">${item}</div>`
        ).join('');
        $suggestBox.html(html).show();
        $suggestBox.data('selectedIndex', -1);
      }, 'json');
    });

    // キーボード操作
    $(document).on('keydown', selector, function (e) {
      const $input = $(this);
      const $wrapper = $input.closest('.autocomplete-wrapper');
      const $suggestBox = $wrapper.find('.suggest-box');
      const $items = $suggestBox.find('.suggestion');
      let selectedIndex = $suggestBox.data('selectedIndex') ?? -1;

      if (!$items.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = (selectedIndex + 1) % $items.length;
        $suggestBox.data('selectedIndex', selectedIndex);
        $items.removeClass('selected');
        $items.eq(selectedIndex).addClass('selected');
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = (selectedIndex - 1 + $items.length) % $items.length;
        $suggestBox.data('selectedIndex', selectedIndex);
        $items.removeClass('selected');
        $items.eq(selectedIndex).addClass('selected');
      } else if (e.key === 'Enter') {
        const $selected = $items.eq(selectedIndex);
        if ($selected.length) {
          e.preventDefault();
          const value = $selected.text();
          $input.val(value);
          $suggestBox.hide();

          // hiddenにcodeをセット
          const $hidden = $wrapper.find(hiddenSelector);
          const match = value.match(/\((\d+)\)$/);
          if (match) {
            $hidden.val(match[1]);
          } else {
            $hidden.val('');
          }
        }
      }
    });

    // マウスクリックで選択
    $(document).on('click', '.suggestion', function () {
      const value = $(this).text();
      const $wrapper = $(this).closest('.autocomplete-wrapper');
      const $input = $wrapper.find(selector);
      const $hidden = $wrapper.find(hiddenSelector);

      $input.val(value);
      $wrapper.find('.suggest-box').hide();

      const match = value.match(/\((\d+)\)$/);
      if (match) {
        $hidden.val(match[1]);
      } else {
        $hidden.val('');
      }
    });

    function extractCodeFromInput($input, hiddenSelector) {
      const value = $input.val();
      const match = value.match(/\((\d+)\)$/);
      const $hidden = $input.closest('.autocomplete-wrapper').find(hiddenSelector);
      if (match) {
        $hidden.val(match[1]);
      } else {
        $hidden.val('');
      }
    }

    // 入力履歴などで選ばれたときにもコードを抽出
    $(document).on('change', '.vendor-autocomplete', function () {
      extractCodeFromInput($(this), '.vendor-code');
    });

    $(document).on('change', '.genba-autocomplete', function () {
      extractCodeFromInput($(this), '.genba-code');
    });


    // フォーカス外れたら非表示
    $(document).on('click', function (e) {
      if (!$(e.target).closest('.autocomplete-wrapper').length) {
        $('.suggest-box').hide();
      }
    });
  }

  $('form').on('submit', function () {
    const $btn = $('.write-btn');

    // フォーム全体に禁止カーソル（これは即時でOK）
    $(this).css('cursor', 'not-allowed');

    // ボタンが存在する場合のみ処理（最終ページだけ）
    if ($btn.length) {
      // 少し遅らせてボタンを無効化（送信処理を邪魔しない）
      setTimeout(() => {
        $btn.prop('disabled', true);
        $btn.css({
          'cursor': 'not-allowed',
          'opacity': '0.6'
        });
        $btn.text('書き込み中...');
      }, 10);
    }
  });

  // 業者名オートコンプリート
  setupAutocomplete('.vendor-autocomplete', 'autocomplete_gyousya_v2', '.vendor-code');

  // 現場名オートコンプリート
  setupAutocomplete('.genba-autocomplete', 'autocomplete_genba', '.genba-code');
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.entry-section').forEach(function (section) {
        const checkbox = section.querySelector('.skip-checkbox');
        const content = section.querySelector('.entry-content');

        function updateGrayout() {
            if (checkbox.checked) {
                section.classList.add('grayout');
            } else {
                section.classList.remove('grayout');
            }
        }

        checkbox.addEventListener('change', updateGrayout);
        updateGrayout(); // 初期状態も反映
    });
});

</script>
