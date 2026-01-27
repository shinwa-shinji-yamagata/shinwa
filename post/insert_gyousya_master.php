<?php
session_start();
require_once __DIR__ . '/../wp-load.php';
global $wpdb;

require_once __DIR__ . '/../vendor/autoload.php'; // PhpSpreadsheet 読み込み

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_FILES['excel_file'])) {
  $_SESSION['upload_result'] = 'ファイルがアップロードされていません。';
  wp_redirect($_SERVER['HTTP_REFERER']);
  exit;
}

$file = $_FILES['excel_file']['tmp_name'];
$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();

$headerB = $sheet->getCell('B1')->getValue();
$headerG = $sheet->getCell('G1')->getValue();
$headerH = $sheet->getCell('H1')->getValue();

if ($headerB !== '取引先ｺｰﾄﾞ' || $headerG !== '正式名称' || $headerH !== '正式名称2') {
  $_SESSION['upload_result'] = 'Excelの形式が不正です。';
  wp_redirect($_SERVER['HTTP_REFERER']);
  exit;
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

$inserted = 0;
for ($row = 3; ; $row++) {
  $code = trim($sheet->getCell("B{$row}")->getValue());
  if (empty($code)) break;

  $name = trim($sheet->getCell("G{$row}")->getValue());
  $name2 = trim($sheet->getCell("H{$row}")->getValue());
  $name_ryaku = trim($sheet->getCell("I{$row}")->getValue());
  $name_kana = trim($sheet->getCell("J{$row}")->getValue());

  $name_zen_kana = mb_convert_kana($name_kana, 'KV', 'UTF-8');
  $name_hiragana = mb_convert_kana($name_zen_kana, 'c', 'UTF-8');
  $name_romaji = hiraToRomaji($name_hiragana);

  $exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM sw_gyousya_master WHERE code = %s", $code
  ));

  if ($exists) continue;

  $wpdb->insert('sw_gyousya_master', [
    'code' => $code,
    'name' => $name,
    'name2' => $name2,
    'name_ryaku' => $name_ryaku,
    'name_kana' => $name_kana,
    'name_zen_kana' => $name_zen_kana,
    'name_hiragana' => $name_hiragana,
    'name_romaji' => $name_romaji,
  ]);

  $inserted++;
}

$_SESSION['upload_result'] = "{$inserted} 件の業者を登録しました。";
sw_log("insert_gyousya_master.php","sw_gyousya_masterに{$inserted} 件の業者を登録しました。");
wp_redirect($_SERVER['HTTP_REFERER']);
exit;
