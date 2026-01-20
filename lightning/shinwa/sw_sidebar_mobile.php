<?php
$current_url = $_SERVER['REQUEST_URI'];

if ( strpos( $current_url, 'swpad' ) !== false ) { ?>
  <script>
  var swpad_ajax = {
    ajax_url: "<?php echo admin_url('admin-ajax.php'); ?>"
  };
  </script>

  <div id="genba-sidebar-mobile">
    <h3>現場一覧</h3>
    <ul id="genba-list-mobile"></ul>
    <div id="genba-pagination-mobile"></div>
  </div>

  <script>
  jQuery(function($) {
    function loadGenbaTo(targetList, targetPagination, page = 1) {
      $.ajax({
        url: swpad_ajax.ajax_url,
        method: 'GET',
        data: {
          action: 'swpad_genba_list',
          page: page
        },
        success: function(response) {
          targetList.empty();
          response.items.forEach(item => {
            targetList.append(`<li><a href="/swpad/${item.id}/">${item.name}</a></li>`);
          });

          targetPagination.empty();
          for (let i = 1; i <= response.total_pages; i++) {
            targetPagination.append(`<button class="genba-page-btn" data-page="${i}">${i}</button>`);
          }
        }
      });
    }

    $('#global-nav').on('shown.bs.collapse', function () {
      const mobileList = $('#genba-list-mobile');
      const mobilePagination = $('#genba-pagination-mobile');
      if (mobileList.length && mobileList.children().length === 0) {
        loadGenbaTo(mobileList, mobilePagination);
      }
    });

    $(document).on('click', '.genba-page-btn', function() {
      const page = $(this).data('page');
      const list = $(this).closest('div').prev('ul');
      const pagination = $(this).closest('div');
      loadGenbaTo(list, pagination, page);
    });
  });
  </script>
<?php
} else {
  // 天気情報（OpenWeatherMap）
  $weather_api_key = '400be1f0ffda561dc5e36e9cbf9bbbbb';
  $city = 'Ota,JP';
  $weather_url = "https://api.openweathermap.org/data/2.5/weather?q={$city}&units=metric&lang=ja&appid={$weather_api_key}";

  $weather_response = wp_remote_get($weather_url);
  if (!is_wp_error($weather_response)) {
    $weather_data = json_decode(wp_remote_retrieve_body($weather_response), true);
    if (isset($weather_data['weather'][0]['description'])) {
      echo '<div class="sidebar-weather">';
      echo '<h3>🌀 今日の天気（大田区）</h3>';
      echo '<p>天気：' . esc_html($weather_data['weather'][0]['description']) . '</p>';
      echo '<p>気温：' . esc_html($weather_data['main']['temp']) . '℃</p>';
      echo '</div>';
    }
  }

  // ニュース情報（NewsAPI）
  $news_api_key = 'bc9fbf39146415f91ec72b63a306abc';
  $news_url = "https://newsapi.org/v2/top-headlines?country=jp&language=ja&pageSize=3&apiKey={$news_api_key}";

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