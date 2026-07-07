<?php
global $wpdb;
$table = "sw_kanribu_nippou";

$year  = isset($_GET['year'])  ? sanitize_text_field($_GET['year'])  : null;
$month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : null;

echo '<style>

/* ▼ 共通ボタンデザイン（年・月・名前） ▼ */
.btn-year,
.btn-month,
.btn-name {
    display: inline-block;
    padding: 14px 0;
    margin: 8px;
    width: 180px;           /* PCでは適度な横幅 */
    text-align: center;
    border-radius: 8px;
    font-size: 18px;
    box-sizing: border-box;
}

/* ▼ 年一覧・月一覧のコンテナ ▼ */
.year-grid,
.month-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;   /* 中央寄せで右端切れ防止 */
    max-width: 900px;
    margin: 20px auto;
}

/* ▼ 名前一覧（PCは3列） ▼ */
.name-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    max-width: 600px;
    margin: 20px auto;
}

/* PCではボタン幅を1.5倍に拡張 */
@media (min-width: 601px) {
    .btn-year,
    .btn-month,
    .btn-name {
        width: 270px !important; /* ← 180px × 1.5 */
    }

    .year-grid,
    .month-grid {
        justify-content: center; /* 中央寄せで右端切れ防止 */
    }
}

/* ▼ スマホ時（幅600px以下）は全部縦並びにする ▼ */
@media (max-width: 600px) {

    .btn-year,
    .btn-month,
    .btn-name {
        width: 100%;        /* 右端切れ防止 */
        margin: 6px 0;
        font-size: 20px;
        padding: 18px 0;
    }

    .year-grid,
    .month-grid {
        display: block;     /* 縦並び */
        width: 100%;
        padding: 0 10px;
    }

    .name-grid {
        grid-template-columns: 1fr; /* 名前も縦一列 */
        width: 100%;
        padding: 0 10px;
    }
}

/* ▼ 共通ボタン（年・月・名前） ▼ */
.btn-year,
.btn-month,
.btn-name {
    display: inline-block;
    padding: 14px 0;
    margin: 8px;
    width: 270px;              /* PCでは1.5倍の横幅 */
    text-align: center;
    border-radius: 8px;
    font-size: 18px;
    box-sizing: border-box;
}

/* ▼ 年・月一覧のコンテナ（折り返し可能にする） ▼ */
.year-grid,
.month-grid {
    display: flex;
    flex-wrap: wrap;           /* ← 横幅が狭い時に自動で折り返す */
    gap: 12px;
    justify-content: center;   /* 中央寄せで右端切れ防止 */
    max-width: 100%;
    margin: 20px auto;
    padding: 0 10px;           /* 画面が狭い時の余白確保 */
}

/* ▼ 名前一覧（PCは3列） ▼ */
.name-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    max-width: 900px;
    margin: 20px auto;
    padding: 0 10px;
}

/* ▼ スマホ時（幅600px以下）は全部縦並びにする ▼ */
@media (max-width: 600px) {

    .btn-year,
    .btn-month,
    .btn-name {
        width: 100% !important; /* ← 右端切れ完全防止 */
        margin: 6px 0;
        font-size: 20px;
        padding: 18px 0;
    }

    .year-grid,
    .month-grid {
        display: block;
        width: 100%;
    }

    .name-grid {
        grid-template-columns: 1fr;
        width: 100%;
    }
}

.name-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 12px;
    max-width: 900px;
    margin: 20px auto;
    padding: 0 10px;
}
.btn-name {
    display: block;
    text-align: center;
    padding: 14px 0;
    border-radius: 8px;
    font-size: 18px;
}
@media (max-width: 600px) {
    .name-grid {
        grid-template-columns: 1fr;
    }
    .btn-name {
        width: 100%;
        font-size: 20px;
        padding: 18px 0;
    }
}

</style>';

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
    echo "<div class='year-grid'>";
    foreach ($years as $y) {
        echo "<a class='btn-year' href='?year=" . urlencode($y) . "'>$y</a>";
    }
    echo "</div>";
    return;
}

if ($year && !$month) {
    // ② 年月の選択
    $months = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT month FROM $table WHERE year = %s ORDER BY month DESC
    ", $year));
    echo "<div class='month-grid'>";
    foreach ($months as $m) {
        echo "<a class='btn-month' href='?year=" . urlencode($year) . "&month=" . urlencode($m) . "'>$m</a>";
    }
    echo "</div>";
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

// ③ 名前の選択
$order = ["黒田","稲垣","栗山","栗原","時田","門脇"];

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
