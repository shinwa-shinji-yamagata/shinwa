<?php
global $wpdb;
$table = "sw_kanribu_nippou";

$year  = isset($_GET['year'])  ? sanitize_text_field($_GET['year'])  : null;
$month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : null;

echo "<h2>管理部作業日報</h2>";

/* ▼ パンくずリスト ▼ */
echo "<div>";
echo "<a href='?'>TOP</a>";

if ($year) {
    echo " &gt; <a href='?year=" . urlencode($year) . "'>$year</a>";
}

if ($year && $month) {
    echo " &gt; <span style='font-size: 19px;'>$month</span>";
}

echo "</div>";
/* ▲ パンくずリスト ▲ */

if (!$year) {
    // ① 年の選択
    $years = $wpdb->get_col("SELECT DISTINCT year FROM $table ORDER BY year DESC");
    foreach ($years as $y) {
        echo "<a class='btn-year' href='?year=" . urlencode($y) . "'>$y</a>";
    }
    return;
}

if ($year && !$month) {
    // ② 年月の選択
    $months = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT month FROM $table WHERE year = %s ORDER BY month DESC
    ", $year));
    foreach ($months as $m) {
        echo "<a class='btn-month' href='?year=" . urlencode($year) . "&month=" . urlencode($m) . "'>$m</a>";
    }
    return;
}

// ③ 名前の選択
$order = ["黒田","稲垣","栗山","栗原","時田","門脇"];

$rows = $wpdb->get_results($wpdb->prepare("
    SELECT name, url FROM $table WHERE year = %s AND month = %s
", $year, $month), ARRAY_A);

$map = [];
foreach ($rows as $r) {
    $map[$r['name']] = $r['url'];
}

$classMap = [
    "黒田" => "btn-kuroda",
    "稲垣" => "btn-inagaki",
    "栗山" => "btn-kuriyama",
    "栗原" => "btn-kurihara",
    "時田" => "btn-tokita",
    "門脇" => "btn-kadowaki",
];

echo "<div class='name-grid'>";
foreach ($order as $name) {
    $class = $classMap[$name];

    if (isset($map[$name])) {
        echo "<a class='btn-name $class' href='" . esc_url($map[$name]) . "' target='_blank'>$name</a>";
    } else {
        echo "<div class='btn-name disabled $class'>$name</div>";
    }
}
echo "</div>";
?>
