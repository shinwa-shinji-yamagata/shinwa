<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php'; // PhpSpreadsheetのautoload

$current_user = wp_get_current_user();
$allowed_roles = ['keiri', 'administrator'];
$can_upload = array_intersect($allowed_roles, $current_user->roles);

if (empty($can_upload)) {
  die('この操作は許可されていません。');
}

use PhpOffice\PhpSpreadsheet\IOFactory;

global $wpdb;

$message = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excel_file'])) {
  $message = "Excelファイルが見つかりません。";
}

// ひらがな → ローマ字変換関数（簡易版）
function hiraToRomaji($str) {
    $map = [
        "きゃ"=>"kya","きゅ"=>"kyu","きょ"=>"kyo",
        "しゃ"=>"sha","しゅ"=>"shu","しょ"=>"sho",
        "ちゃ"=>"cha","ちゅ"=>"chu","ちょ"=>"cho",
        "にゃ"=>"nya","にゅ"=>"nyu","にょ"=>"nyo",
        "ひゃ"=>"hya","ひゅ"=>"hyu","ひょ"=>"hyo",
        "みゃ"=>"mya","みゅ"=>"myu","みょ"=>"myo",
        "りゃ"=>"rya","りゅ"=>"ryu","りょ"=>"ryo",
        "ぎゃ"=>"gya","ぎゅ"=>"gyu","ぎょ"=>"gyo",
        "じゃ"=>"ja", "じゅ"=>"ju", "じょ"=>"jo",
        "びゃ"=>"bya","びゅ"=>"byu","びょ"=>"byo",
        "ぴゃ"=>"pya","ぴゅ"=>"pyu","ぴょ"=>"pyo",
        "ん"=>"n","あ"=>"a","い"=>"i","う"=>"u","え"=>"e","お"=>"o",
        "か"=>"ka","き"=>"ki","く"=>"ku","け"=>"ke","こ"=>"ko",
        "さ"=>"sa","し"=>"shi","す"=>"su","せ"=>"se","そ"=>"so",
        "た"=>"ta","ち"=>"chi","つ"=>"tsu","て"=>"te","と"=>"to",
        "な"=>"na","に"=>"ni","ぬ"=>"nu","ね"=>"ne","の"=>"no",
        "は"=>"ha","ひ"=>"hi","ふ"=>"fu","へ"=>"he","ほ"=>"ho",
        "ま"=>"ma","み"=>"mi","む"=>"mu","め"=>"me","も"=>"mo",
        "や"=>"ya","ゆ"=>"yu","よ"=>"yo",
        "ら"=>"ra","り"=>"ri","る"=>"ru","れ"=>"re","ろ"=>"ro",
        "わ"=>"wa","を"=>"o","が"=>"ga","ぎ"=>"gi","ぐ"=>"gu","げ"=>"ge","ご"=>"go",
        "ざ"=>"za","じ"=>"ji","ず"=>"zu","ぜ"=>"ze","ぞ"=>"zo",
        "だ"=>"da","ぢ"=>"ji","づ"=>"zu","で"=>"de","ど"=>"do",
        "ば"=>"ba","び"=>"bi","ぶ"=>"bu","べ"=>"be","ぼ"=>"bo",
        "ぱ"=>"pa","ぴ"=>"pi","ぷ"=>"pu","ぺ"=>"pe","ぽ"=>"po",
        "ー"=>"-", "　"=>" ", " "=>" "
    ];
    // 外来語対応の追加マッピング
    $map += [
        "うぃ" => "wi", "うぇ" => "we", "うぉ" => "wo",
        "しぇ" => "she", "じぇ" => "je",
        "ちぇ" => "che", "てぃ" => "ti", "でぃ" => "di",
        "とぅ" => "tu", "どぅ" => "du",
        "ふぁ" => "fa", "ふぃ" => "fi", "ふぇ" => "fe", "ふぉ" => "fo",
        "ヴぁ" => "va", "ヴぃ" => "vi", "ヴ" => "vu", "ヴぇ" => "ve", "ヴぉ" => "vo",
        "くぁ" => "kwa", "ぐぁ" => "gwa",
        "ぁ" => "a", "ぃ" => "i", "ぅ" => "u", "ぇ" => "e", "ぉ" => "o",
        "ゃ" => "ya", "ゅ" => "yu", "ょ" => "yo"
    ];

    $romaji = "";
    $i = 0;
    $len = mb_strlen($str);

    while ($i < $len) {
        $chunk2 = mb_substr($str, $i, 2);
        $chunk1 = mb_substr($str, $i, 1);

        // 促音「っ」
        if ($chunk1 === "っ") {
            $next = mb_substr($str, $i + 1, 1);
            $nextRomaji = isset($map[$next]) ? $map[$next] : "";
            $romaji .= $nextRomaji ? substr($nextRomaji, 0, 1) : "";
            $i++;
            continue;
        }

        // 2文字マッチ（きゃ、しゃなど）
        if (isset($map[$chunk2])) {
            $romaji .= $map[$chunk2];
            $i += 2;
            continue;
        }

        // 1文字マッチ
        if (isset($map[$chunk1])) {
            // 「ん」の後処理
            if ($chunk1 === "ん") {
                $next = mb_substr($str, $i + 1, 1);
                if (in_array($next, ["あ","い","う","え","お","や","ゆ","よ"])) {
                    $romaji .= "n'";
                } else {
                    $romaji .= "n";
                }
            } else {
                $romaji .= $map[$chunk1];
            }
        } else {
            $romaji .= $chunk1;
        }
        $i++;
    }

    return $romaji;
}

$tmp_path = $_FILES['excel_file']['tmp_name'];

$inserted = 0;

try {
  if( $message != '' ) {
    throw new Exception($message);
  }
  $spreadsheet = IOFactory::load($tmp_path);
  $sheet = $spreadsheet->getActiveSheet();

  $code = trim((string)$sheet->getCell("D1")->getValue());
  $d_code = trim((string)$sheet->getCell("E1")->getValue());
  $name = trim((string)$sheet->getCell("G1")->getValue());
  $subject = trim((string)$sheet->getCell("H1")->getValue());
  $goukei = trim((string)$sheet->getCell("A2")->getValue());
  if( $code != '工事ｺｰﾄﾞ' || $d_code != '工事詳細ｺｰﾄﾞ' || $name != '現場名' || $subject != '工事件名' || $goukei != '合計') {
    $message = "Excelの形式が正しくありません";
    throw new Exception($message);
  }

  $table_name = 'sw_genba_master';
  $skipped = 0;

  for ($row = 3; ; $row++) {
    $code = trim((string)$sheet->getCell("D{$row}")->getValue());
    $d_code = trim((string)$sheet->getCell("E{$row}")->getValue());
    $name = trim((string)$sheet->getCell("G{$row}")->getValue());
    $subject = trim((string)$sheet->getCell("H{$row}")->getValue());

    if ($code === '') {
      break; // B列が空なら終了
    }

    // すでに登録されているか確認
    $exists = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE code = %s AND d_code = %s AND name = %s AND subject = %s",
        $code, $d_code, $name, $subject
      )
    );

    if ($exists > 0) {
      $skipped++;
      continue;
    }

    // INSERT
    $wpdb->insert($table_name, [
      'code' => $code,
      'd_code' => $d_code,
      'name' => $name,
      'subject' => $subject,
    ]);

    $inserted++;
  }

  // Igoの読み込み
  $base_dir = __DIR__;
  require_once $base_dir . '/../igo/lib/Igo.php';
  $igo = new Igo($base_dir . "/../igo/ipadic", "UTF-8");
  // sw_genba_masterテーブルから name を取得
  $results = $wpdb->get_results("SELECT id, name FROM sw_genba_master WHERE furigana = '' OR romaji = '' OR furigana IS NULL OR romaji IS NULL", ARRAY_A);

  foreach ($results as $row) {
      $id = $row['id'];
      $name = $row['name'];

      // Igoで形態素解析
      $tokens = $igo->parse($name);
      $kana = "";
      foreach ($tokens as $token) {
          $feature = explode(",", $token->feature);
          $kana .= isset($feature[7]) ? $feature[7] : $token->surface;
      }

      // カタカナ → ひらがな
      $hiragana = mb_convert_kana($kana, "c", "utf-8");

      // ひらがな → ローマ字
      $romaji = hiraToRomaji($hiragana);

      // UPDATE実行
      $wpdb->update(
          'sw_genba_master',
          ['furigana' => $hiragana, 'romaji' => $romaji],
          ['id' => $id],
          ['%s', '%s'],
          ['%d']
      );
  }

  echo "{$inserted} 件を登録しました。";
  sw_log("insert_genba_master.php","{$table_name} に {$inserted} 件登録しました。");

} catch (Exception $e) {
  $message = "Excelの読み込み中にエラーが発生しました : " . esc_html($e->getMessage());
}

$page_no = isset($_POST['page_no']) ? (int)$_POST['page_no'] : 1;
if( $message == '' ) {
  $_SESSION['upload_result'] = "{$inserted} 件を登録しました。";
} else {
  $_SESSION['upload_result'] = "$message";
}

header('Location: ' . site_url("/genba_master/?page_no={$page_no}"));
exit;
