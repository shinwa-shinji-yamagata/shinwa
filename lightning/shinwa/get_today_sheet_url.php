<?php
global $wpdb;

$url = $wpdb->get_var("SELECT url from sw_today_sheet WHERE id = 1");

echo "<a href='$url' target='_blank'>日毎管理表（今日）</a>";

?>
