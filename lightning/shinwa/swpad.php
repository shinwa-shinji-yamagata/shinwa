<?php
global $wp_query;
$wp_query->is_404 = false;

$id  = get_query_var('id');
$fid = get_query_var('fid');

global $wpdb;
$genba = null;
if ($id) {
  $genba = $wpdb->get_row($wpdb->prepare("SELECT name FROM swpad_genba WHERE id = %d", $id));
}
if ($fid) {
  $folder = $wpdb->get_row($wpdb->prepare("SELECT name FROM swpad_genba_folder WHERE id = %d", $fid));
}
?>

<div class="container card-box">
  <div class="row">
    <!-- メインコンテンツ -->
    <main class="col-md-12">
      <?php if (empty($id) && empty($fid)) : ?>
      <!-- TOPページ：検索＋登録 -->
<div class="search-header d-flex align-items-center justify-content-between flex-wrap gap-3">
  <div class="search-left flex-grow-1" style="max-width: 600px;">
    <label for="site-search" class="form-label mb-1">現場名検索</label>
    <div class="position-relative">
      <input type="text" name="s" id="site-search" class="form-control" placeholder="現場名を入力..." autocomplete="off" />
      <span class="search-icon" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #888;">🔍</span>
    </div>
  </div>

  <div class="register-right">
    <a href="/swpad/swpad-regist" class="btn btn-register">＋ 新規登録</a>
  </div>
</div>
      <?php endif; ?>

      <div class="mt-4">
        <?php if ($id && !$fid && $genba): ?>
          <h4><a href="/swpad/">TOP</a> / <?= esc_html($genba->name) ?></h4>
        <?php elseif ($id && $fid && $genba): ?>
          <h4><a href="/swpad/">TOP</a> / <a href="/swpad/<?php echo $id ?>"><?= esc_html($genba->name) ?></a> / <?= esc_html($folder->name) ?></h4>
        <?php endif;?>
      <?php if ($id && !$fid && $genba): ?>
        <!-- フォルダ作成 -->

      <form id="folder-form" class="card p-4 shadow-sm mb-4">
        <div class="d-flex align-items-center gap-3">
          <label for="folder-name" class="form-label mb-0 me-2" style="min-width: 100px;">フォルダ名</label>
          <input type="text" id="folder-name" name="folder-name" class="form-control" style="max-width: 300px; margin-right:20px;" required>
          <button type="submit" class="btn btn-success" style="min-width: 140px;">＋ フォルダ作成</button>
        </div>
      </form>

        <div id="folder-result" class="text-center mb-4"></div>
      <?php endif; ?>

      <?php if ($id && $genba): ?>
        <!-- 写真アップロード（複数対応） -->
        <div class="mt-4">
          <div class="d-flex align-items-center gap-3">
            <!-- アップロードエリア -->

            <div id="upload-area" class="flex-grow-1">
              <p>ここにExcelファイルをドラッグ＆ドロップ</p>

              <div class="deco-file">
                <span style="margin-right:15px;">または</span>
                <label>
                  ＋ファイルを選択
                  <input type="file" id="upload-files" name="upload-files[]" multiple>
                </label>
              </div>

              <div id="file-list"></div>
            </div>

            <!-- ボタンを縦中央に -->
            <div class="d-flex align-items-center" style="height: 100%;">
              <button id="upload-button" class="btn btn-primary">アップロード</button>
            </div>


          </div>

          <div id="upload-result" class="mt-3 text-center"></div>
        </div>

        <?php
        echo '<div class="mt-5"><h5>写真一覧</h5>';
        $base_dir = get_theme_root() . '/swpad/tmb/' . sanitize_file_name($genba->name);
        if ($fid) {
          $folder_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM swpad_genba_folder WHERE id = %d", $fid));
          $base_dir .= '/' . sanitize_file_name($folder_name);
        }

        $web_path = '/wp-content/themes/swpad/tmb/' . sanitize_file_name($genba->name);
        if ($fid && $folder_name) {
          $web_path .= '/' . sanitize_file_name($folder_name);
        }

        if (file_exists($base_dir)) {
          $files = array_values(array_filter(scandir($base_dir), function($f) {
            return preg_match('/\.(jpg|jpeg|png|gif)$/i', $f);
          }));

          echo '<div class="swpad-thumbnails">';
          foreach ($files as $file) {
            echo '<div class="thumb-item">';
            echo '<img src="' . esc_url($web_path . '/' . $file) . '" />';
            echo '</div>';
          }
          echo '</div>';
        }

        if (!$fid) {
          $folders = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM swpad_genba_folder WHERE genba_id = %d", $id));
          echo '<div class="mt-5"><h5>フォルダ一覧</h5>';

          foreach ($folders as $folder) {
            $folder_link = "/swpad/" . $id . "/" . sanitize_file_name($folder->id);
            echo '<div class="mb-3">';
            echo '<div class="fordername"><a href="' . esc_url($folder_link) . '" class="fw-bold">・' . esc_html($folder->name) . '</a></div>';

            $tmb_path = '/wp-content/themes/swpad/tmb/' . sanitize_file_name($genba->name) . '/' . sanitize_file_name($folder->name);
            $tmb_dir = get_theme_root() . '/swpad/tmb/' . sanitize_file_name($genba->name) . '/' . sanitize_file_name($folder->name);
            if (file_exists($tmb_dir)) {
              $images = array_values(array_filter(scandir($tmb_dir), function($f) {
                return preg_match('/\.(jpg|jpeg|png|gif)$/i', $f);
              }));

              echo '<div class="row">';
              foreach ($images as $i => $img) {
                echo '<div class="col-md-2 mb-2"><img src="' . esc_url($tmb_path . '/' . $img) . '" class="img-thumbnail" /></div>';
                if (($i + 1) % 6 === 0) echo '</div><div class="row">';
              }
              echo '</div>';
            }

            echo '</div>';
          }
        }

        echo '</div>';
        ?>
      <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<script>
jQuery(function($) {
  // PHPの値をJavaScript変数に代入（安全な方法）
  const phpId = <?php echo intval($id); ?>;
  const phpFid = <?php echo $fid ? intval($fid) : 0; ?>;

  let selectedFiles = [];

  const $area = $('#upload-area');
  const $input = $('#upload-files');
  const $button = $('#upload-button');
  const $result = $('#upload-result');

  $area.on('click', function(e) {
    if ($(e.target).closest('label').length > 0) return;
    e.preventDefault();
  });

  $area.on('dragover', function(e) {
    e.preventDefault();
    $area.addClass('dragover');
  });

  $area.on('dragleave', function() {
    $area.removeClass('dragover');
  });

  $area.on('drop', function(e) {
    e.preventDefault();
    $area.removeClass('dragover');
    selectedFiles = Array.from(e.originalEvent.dataTransfer.files);
    $result.html(`<div>${selectedFiles.length} 件のファイルが選択されました</div>`);
  });

  $input.on('change', function() {
    selectedFiles = Array.from(this.files);
    $result.html(`<div>${selectedFiles.length} 件のファイルが選択されました</div>`);
  });


  $button.on('click', function() {
    if (selectedFiles.length === 0) {
      $result.html('<div class="text-danger">ファイルが選択されていません</div>');
      return;
    }

    $result.html('');
    selectedFiles.forEach(file => {
      const formData = new FormData();
      formData.append('action', 'swpad_upload_photo');
      formData.append('id', phpId);
      formData.append('fid', phpFid);
      formData.append('upload-file', file);

      $.ajax({
        url: swpad_ajax.ajax_url,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
          const msg = response.success ? `${file.name} → アップロード成功` : `${file.name} → 失敗`;
          $result.append(`<div>${msg}</div>`);
          location.reload();
        },
        error: function() {
          $result.append(`<div>${file.name} → 通信エラー</div>`);
        }
      });
    });

    selectedFiles = [];
  });

  $('#folder-form').on('submit', function(e) {
    e.preventDefault();
    const folderName = $('#folder-name').val();

    $.post(swpad_ajax.ajax_url, {
      action: 'swpad_create_folder',
      id: phpId,
      folder_name: folderName
    }, function(response) {
      const $result = $('#folder-result');
      if (response.success) {
        $result.text('「' + folderName + '」フォルダ作成完了！').removeClass('text-danger').addClass('text-success');
        $('#folder-name').val('');
        location.reload();
      } else {
        $result.text(response.data || '作成失敗').removeClass('text-success').addClass('text-danger');
      }
    }).fail(function() {
      $('#folder-result').text('通信エラー').removeClass('text-success').addClass('text-danger');
    });
  });
});
</script>
<style>
h4 a {
  font-size: 22px;
}
.fordername {
  font-size: 14px;
  margin-bottom: 10px;
  width: 100%;
  background-color: aliceblue;
}
.fordername a {
  color: indigo !important;
}
.swpad-thumbnails {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 20px;
  max-width: 100%;
}

.thumb-item {
  width: 100px;
  height: 100px;
  overflow: hidden;
  border: 1px solid #ccc;
  border-radius: 4px;
}

.thumb-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
</style>
