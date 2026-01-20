<?php
// ページリンク生成関数
function page_link($label, $target, $current_page, $class = '') {
    $active = $target == $current_page ? 'active' : '';
    return "<a href='#' class='$class $active' data-page='$target'>$label</a>";
}

// 必要な変数は genba_list_partial.php 側から渡される想定
// $total_pages, $page が存在している前提
$last_page = $total_pages;
$current_page = $page;

// 最大表示件数
$max_numbers = 8;

// 「<<」「<」
if ($current_page > 1) {
    echo page_link('<<', 1, $current_page, 'nav');
    echo page_link('<', $current_page - 1, $current_page, 'nav');
}

// 数字リンク（最大8件）
$half = floor($max_numbers / 2);
$start = max(1, $current_page - $half);
$end   = min($last_page, $start + $max_numbers - 1);

// 調整：最後の方で件数が不足する場合
if ($end - $start + 1 < $max_numbers) {
    $start = max(1, $end - $max_numbers + 1);
}

for ($i = $start; $i <= $end; $i++) {
    echo page_link($i, $i, $current_page);
}

// 「>」「>>」
if ($current_page < $last_page) {
    echo page_link('>', $current_page + 1, $current_page, 'nav');
    echo page_link('>>', $last_page, $current_page, 'nav');
}
?>
