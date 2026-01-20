<?php

$base_dir = __DIR__;

require_once $base_dir . '/lib/Igo.php';
$igo = new Igo($base_dir . "/ipadic", "UTF-8");
$text = "漢字がひらがなやカタカナになります。";
$result = $igo->parse($text);
$str = "";
foreach($result as $value){
     $feature = explode(",", $value->feature);
     $str .= isset($feature[7]) ? $feature[7] : $value->surface;
}

echo mb_convert_kana($str, "c", "utf-8")."<br>";
echo mb_convert_kana($str, "C", "utf-8") . "\n";

?>