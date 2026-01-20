<?php
$current_url = $_SERVER['REQUEST_URI'];

if ( strpos( $current_url, 'swpad' ) !== false ) { ?>
  <div id="genba-sidebar">
    <h3>現場一覧</h3>
    <ul id="genba-list"></ul>
    <div id="genba-pagination"></div>
  </div>

  <?php
    global $wpdb;
    $page = 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $total = $wpdb->get_var("SELECT COUNT(*) FROM swpad_genba");
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, name FROM swpad_genba ORDER BY create_date DESC LIMIT %d OFFSET %d",
      $limit, $offset
    ));
  ?>
  <ul id="genba-list">
    <?php foreach( $rows as $row ) : ?>
      <li><a href="/swpad/<?= $row->id ?>/"><?= esc_html($row->name) ?></a></li>
    <?php endforeach; ?>
  </ul>

  <script>
    $(document).on('click', '.genba-page-btn', function() {
      const page = $(this).data('page');
      loadGenbaPage(page);
    });

    loadGenbaPage();
  });
  </script>
<?php
} else {
  // 天気情報（OpenWeatherMap）
  $weather_api_key = '400be1f0ffda561dc5e36e9cbf9bbbbb';
  $city = 'Ota,JP';
  $weather_url = "https://api.openweathermap.org/data/2.5/weather?q={$city}&units=metric&lang=ja&appid={$weather_api_key}";

  $weather_response = wp_remote_get($weather_url);
echo '<pre>';

echo '</pre>';
  if (!is_wp_error($weather_response)) {
    $weather_data = json_decode(wp_remote_retrieve_body($weather_response), true);
    if (isset($weather_data['weather'][0]['description'])) {
      echo '<div class="sidebar-weather">';
      echo '<h3 style="font-size:18px">🌀 今日の天気<br>　（大田区）</h3>';
      echo '<p>天気：' . esc_html($weather_data['weather'][0]['description']) . '</p>';
      echo '<p>気温：' . esc_html($weather_data['main']['temp']) . '℃</p>';
      echo '</div>';
    }
  }

  // ニュース情報（NewsAPI）
  $news_api_key = '4bc9fbf39146415f91ec72b63a306abc';
  $news_url = "https://newsapi.org/v2/top-headlines?country=jp&category=business&apiKey={$news_api_key}";

  $news_response = wp_remote_get($news_url);
  if (!is_wp_error($news_response)) {
    $news_data = json_decode(wp_remote_retrieve_body($news_response), true);
    if (!empty($news_data['articles'])) {
      echo '<div class="sidebar-news">';
      echo '<h3>📰 今日のニュース</h3><ul>';
      foreach ($news_data['articles'] as $article) {
        echo '<li><a href="' . esc_url($article['url']) . '" target="_blank">' . esc_html($article['title']) . '</a></li>';
      }
      echo '</ul></div>';
    }
  }
}
?>

