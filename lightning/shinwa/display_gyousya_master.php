<?php
global $wpdb;

$current_user = wp_get_current_user();
$allowed_roles = ['keiri', 'administrator'];
$can_upload = array_intersect($allowed_roles, $current_user->roles);
$is_allowed = !empty($can_upload);

if (isset($_SESSION['upload_result'])) {
  echo "<div class='upload-result'><strong>更新結果:</strong><br>" . esc_html($_SESSION['upload_result']) . "</div>";
  unset($_SESSION['upload_result']);
}
?>
<div style="margin-bottom:20px;">
<b>【現場名一覧のExcel取得手順】</b><br>
  PROCES.Sの画面から「マスタ」タブ→「取引情報照会」→「F5：抽出」→「F9：Excel出力」
</div>
<div class="gyousya-container">
  <div class="upload-area <?php echo $is_allowed ? '' : 'disabled'; ?>">
    <form method="post" enctype="multipart/form-data" action="<?php echo site_url('/post/insert_gyousya_master.php'); ?>">
      <div class="d-flex align-items-center gap-3">
        <!-- ドラッグ＆ドロップエリア -->
        <div id="upload-area" class="flex-grow-1 drop-zone">
          <p>ここにExcelファイルをドラッグ＆ドロップ</p>
          <div class="deco-file">
            <span style="margin-right:15px;">または</span>
            <label>
              ＋ファイルを選択
              <input type="file" name="excel_file" accept=".xlsx,.xls" required <?php echo $is_allowed ? '' : 'disabled'; ?>>
            </label>
          </div>
          <div class="file-name" style="margin-top:20px;"></div>
        </div>
        <div class="d-flex align-items-center">
          <button id="upload-button" class="btn btn-primary">業者マスタ更新</button>
        </div>
      </div>
    </form>
    <?php echo $is_allowed ? '' : '<div style="font-size: 20px;">マスタ更新の実行権限がありません</div>'; ?>
  </div>

  <div class="gyousya-search mt-4">
    <div style="display: flex; gap: 10px; align-items: center;">
      <input type="text" id="gyousya-search" placeholder="業者名で検索" style="flex: 1;">
      <button id="clear-btn" type="button">クリア</button>
    </div>
    <div id="gyousya-suggestions" style="display:none;"></div>
  </div>

  <div class="gyousya-table-area mt-3">
    <table class="log-table">
      <thead>
        <tr>
          <th>業者コード</th>
          <th>業者名</th>
          <th>業者名2</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>

<style>
#upload-button {
  font-size: 18px;
}
#gyousya-suggestions {
  position: absolute;
  background-color: #fff;
  border: 1px solid #ccc;
  border-radius: 4px;
  max-height: 200px;
  overflow-y: auto;
  width: 100%;
  z-index: 1000;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  margin-top: 2px;
  font-size: 12px;
}

#gyousya-suggestions .suggestion {
  padding: 6px 10px;
  cursor: pointer;
}

#gyousya-suggestions .suggestion:hover,
#gyousya-suggestions .suggestion.active {
  background-color: #e6f7ff;
}
#clear-btn {
  font-size: 16px;
}
.upload-result {
  margin-bottom: 2em;
  padding: 1em;
  background: #e6f7ff;
  border-left: 4px solid #0073aa;
}
table thead th {
  background-color: #f2f2f2;
  color: #333;
  font-weight: bold;
  border-bottom: 1px solid #ccc;
}
table td, table th {
    padding: .1rem 1rem;
    font-size: 12px !important;
}
table tbody tr:hover {
  background-color: #f0f8ff;
}
</style>

<script>
jQuery(function($) {
  const dropZone = $('#upload-area');
  const fileInput = $('input[type="file"]');

  let selectedIndex = -1;

  $(document).on('dragover drop', e => e.preventDefault());

  dropZone.on('dragover', e => {
    e.preventDefault();
    dropZone.addClass('drag-over');
  }).on('dragleave drop', e => {
    e.preventDefault();
    dropZone.removeClass('drag-over');
  });

  dropZone.on('drop', function(e) {
    const files = e.originalEvent.dataTransfer.files;
    if (files.length > 0) {
      fileInput[0].files = files;
      $('.file-name').text(files[0].name);
    }
  });

  fileInput.on('change', function() {
    const file = this.files[0];
    $('.file-name').text(file ? file.name : '');
  });

  $('#gyousya-search').on('input', function() {
    const query = $(this).val();
    if (query.length < 1) return;

    $.get('/wp-admin/admin-ajax.php', {
      action: 'autocomplete_gyousya',
      query: query
    }, function(data) {
      const suggestions = Array.isArray(data) ? data : [];
      const html = suggestions.map(item =>
        `<div class="suggestion">${item}</div>`
      ).join('');
      $('#gyousya-suggestions').html(html).show();
    }, 'json');
  });

  $('#gyousya-search').on('keydown', function(e) {
    const suggestions = $('#gyousya-suggestions .suggestion');

    if (e.key === 'ArrowDown' && suggestions.length) {
      e.preventDefault();
      selectedIndex = (selectedIndex + 1) % suggestions.length;
      suggestions.removeClass('active');
      suggestions.eq(selectedIndex).addClass('active');
    }

    if (e.key === 'ArrowUp' && suggestions.length) {
      e.preventDefault();
      selectedIndex = (selectedIndex - 1 + suggestions.length) % suggestions.length;
      suggestions.removeClass('active');
      suggestions.eq(selectedIndex).addClass('active');
    }

    if (e.key === 'Enter') {
      e.preventDefault();
      if (selectedIndex >= 0 && suggestions.length) {
        const value = suggestions.eq(selectedIndex).text();
        $('#gyousya-search').val(value);
        $('#gyousya-suggestions').hide().empty();
        selectedIndex = -1;
        filterGyousya(value);
      } else {
        const value = $('#gyousya-search').val();
        $('#gyousya-suggestions').hide().empty();
        selectedIndex = -1;
        filterGyousya(value);
      }
    }
  });

  $(document).on('click', '.suggestion', function() {
    const value = $(this).text();
    $('#gyousya-search').val(value);
    $('#gyousya-suggestions').hide().empty();
    filterGyousya(value);
  });

  function filterGyousya(keyword) {
    $.get('/wp-admin/admin-ajax.php', {
      action: 'filter_gyousya',
      keyword: keyword
    }, function(data) {
      const rows = Array.isArray(data) ? data : [];
      const html = rows.map(row => `
        <tr>
          <td>${row.code}</td>
          <td>${row.name}</td>
          <td>${row.name2}</td>
        </tr>
      `).join('');
      $('.log-table tbody').html(html);
    }, 'json');
  }

  $('#clear-btn').on('click', function() {
    $('#gyousya-search').val('');
    $('#gyousya-suggestions').hide().empty();
    filterGyousya('');
  });

  filterGyousya('');
});
</script>
