<?php
global $wpdb;

error_log('セッションから取得: ' . ($_SESSION['upload_result'] ?? 'なし'));
if (isset($_SESSION['upload_result'])) {
  echo "<div class='upload-result'><strong>更新結果:</strong><br>" . esc_html($_SESSION['upload_result']) . "</div>";
  unset($_SESSION['upload_result']);
}

$current_user = wp_get_current_user();
$allowed_roles = ['keiri', 'administrator'];
$can_upload = array_intersect($allowed_roles, $current_user->roles);
$is_allowed = !empty($can_upload);

// ページ番号（初期値）
$page = isset($_GET['page_no']) ? max(1, (int)$_GET['page_no']) : 1;
?>

<div class="genba-container">
  <p><strong>【現場名一覧のExcel取得手順】</strong><br>
     &nbsp;&nbsp;PROCES.Sの画面から「原価・発注」タブ→「工事情報照会」→「工事集計単位：工事詳細別」にチェック→「F5：抽出」→「F9：Excel出力」<br>
  <div class="upload-area <?php echo $is_allowed ? '' : 'disabled'; ?>">
    <form method="post" enctype="multipart/form-data" action="<?php echo site_url('/post/insert_genba_master.php'); ?>">
      <input type="hidden" name="page" id="page-input" value="1">
      <input type="hidden" name="page_no" value="<?php echo $page; ?>">
      <div class="mt-4">
        <div class="d-flex align-items-center gap-3">
          <!-- アップロードエリア -->
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
            <div id="file-list"></div>
          </div>
          <!-- ボタンを縦中央に -->
          <div class="d-flex align-items-center" style="height: 100%;">
            <button id="upload-button" class="btn btn-primary">現場マスタ更新</button>
          </div>
        </div>
        <div id="upload-result" class="mt-3 text-center"></div>
      </div>
    </form>
    <?php echo $is_allowed ? '' : '<div style="font-size: 20px;">マスタ更新の実行権限がありません</div>'; ?>
    <form method="post" id="pagination-form">
      <input type="hidden" name="page" id="page-input" value="<?php echo $page; ?>">
    </form>
  </div>

  <div class="genba-table-area">
    <h3 style="margin-bottom:0;">現場マスタ</h3>

    <!-- 検索フォーム -->
    <div class="search-area">
      <div class="filter-box">
        <input type="text" id="genba-search" placeholder="現場名で検索">
        <button id="clear-btn">クリア</button>
      </div>
      <div id="search-suggestions" style="display:none;"></div>
    </div>

    <!-- 一覧＋ページングは部分テンプレートに統一 -->
    <div id="genba-list-area">
      <?php include __DIR__ . '/genba_list_partial.php'; ?>
    </div>
  </div>
</div>

<style>
.filter-box input {
  font-size: 1.2em;  /* 入力文字も小さめ */
  padding: 0.5em;    /* ボックスもコンパクトに */
}
.genba-table-area div,
.genba-table-area table,
.genba-table-area th,
.genba-table-area td {
  font-size: 12px;
}
.genba-container {
  width: 100vw;
  padding: 2em;
  box-sizing: border-box;
  font-family: 'Segoe UI', sans-serif;
  background: #fff;
}
.mt-4, .my-4 {
    width: 80%;
    max-width: 900px;
}
#upload-area {
  padding: 10px 30px 0 30px;
  background-color: #f8f8ff;
}
#upload-area.dragover {
  background-color: #f0f8ff;
  box-shadow: 0 0 10px rgba(0,123,255,0.3);
}
.upload-area form {
  display: flex;
  align-items: center;
  gap: 1em;
  margin-bottom: 0.5em;
  flex-wrap: wrap;
}
.drop-zone {
  display: inline-block;
  padding: 1em 2em;
  background: #f0f0f0;
  border: 2px dashed #ccc;
  border-radius: 8px;
  white-space: nowrap;
}
.drop-zone input[type="file"] {
  display: none;
}
.drop-zone.drag-over {
  border: 4px dashed #75A9FF !important;
  background-color: #E6FFE9 !important;
}
.upload-area button {
  padding: 1.0em 1.5em;
  font-size: 16px;
  background: #168dc2;
  color: #fff;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  white-space: nowrap;
}
.upload-area button:hover {
  background: #005f8d;
}
.upload-result {
  margin-bottom: 2em;
  padding: 1em;
  background: #e6f7ff;
  border-left: 4px solid #0073aa;
}
.genba-table-area h2 {
  margin-bottom: 0;
}
.log-table {
  border-top: none;
  border-bottom: none;
  width: 100%;
  border-collapse: collapse;
  overflow-x: auto;
  display: block;
}
.log-table th, .log-table td {
  padding: 2px 8px;
  border-bottom: 1px solid #ddd;
  text-align: left;
}
.log-table th {
  background-color: #f5f5f5;
}
.log-table tr:hover {
  background-color: #f0f8ff;
}
.log-table th:nth-child(1),
.log-table td:nth-child(1),
.log-table th:nth-child(2),
.log-table td:nth-child(2) {
  white-space: nowrap;
}
.drop-zone.dragging {
  background-color: #e0f7fa !important;
}
.pagination {
  margin: 0 0 1em 0;
  display: flex;
  justify-content: flex-start; /* 左寄せ */
  flex-wrap: wrap;
  gap: 6px;
}
.pagination a {
  display: inline-block;
  min-width: 28px;
  padding: 6px 10px;
  background: #f9f9f9;
  color: #333;
  text-decoration: none;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 13px;
  text-align: center;
  cursor: pointer;
}
.pagination a.active {
  background: #0073aa;
  color: #fff;
  border-color: #0073aa;
  font-weight: bold;
}
.pagination a.nav {
  background: #eee;
}
.pagination a:hover {
  background-color: #e0f0ff;
  color: #0073aa;
  border-color: #0073aa;
}
.upload-area button:disabled {
  background: #999;
  color: #eee;
}
/* 候補リストの見た目をプルダウン風に */
#search-suggestions {
  position: absolute;
  background: #fff;
  border: 1px solid #ccc;
  width: 300px;
  max-height: 200px;
  overflow-y: auto;
  z-index: 1000;
}
#search-suggestions .suggestion {
  padding: 5px;
  cursor: pointer;
}
#search-suggestions .suggestion.active {
  background: #e0e0e0;
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
jQuery(function($) {
  let timer = null;
  let selectedIndex = -1;

  // 初期表示（空検索で1ページ目）
  applyFilter('', 1);

  // ファイル選択時にファイル名を表示
  $('input[type="file"][name="excel_file"]').on('change', function() {
    const file = this.files[0];
    if (file) {
      $('.file-name').text(file.name);
    } else {
      $('.file-name').text('');
    }
  });

  // ドロップゾーンの要素取得
  const dropZone = $('#upload-area');
  const fileInput = $('input[type="file"][name="excel_file"]');

  // ページ全体でデフォルト動作を防ぐ
  $(document).on('dragover drop', function(e) {
    e.preventDefault();
  });

  // ドラッグオーバー時にハイライト
  dropZone.on('dragover', function(e) {
    e.preventDefault();
    e.stopPropagation();
    dropZone.addClass('drag-over');
  });

  // ドラッグリーブ時にハイライト解除
  dropZone.on('dragleave', function(e) {
    e.preventDefault();
    e.stopPropagation();
    dropZone.removeClass('drag-over');
  });

  // ドロップ時の処理
  dropZone.on('drop', function(e) {
    e.preventDefault();
    e.stopPropagation();
    dropZone.removeClass('drag-over');

    const files = e.originalEvent.dataTransfer.files;
    if (files.length > 0) {
      fileInput[0].files = files; // input要素にファイルをセット
      $('.file-name').text(files[0].name); // ファイル名を表示
    }
  });

  $('.upload-area form').on('submit', function() {
    var $button = $('#upload-button');
    $button.prop('disabled', true);
    $button.text('マスタ更新中...'); // 任意でボタンの文言を変更
    $button.css('cursor', 'not-allowed');
  });

  // オートコンプリート入力
  $('#genba-search').on('input', function() {
    clearTimeout(timer);
    const query = $(this).val();

    if (query.length < 2) {
      $('#search-suggestions').empty();
      selectedIndex = -1;
      return;
    }

    timer = setTimeout(function() {
      $.ajax({
        url: '/api/autocomplete_db_only.php',
        type: 'GET',
        dataType: 'json',
        data: { query },
        success: function(response) {
          if (!Array.isArray(response)) return;
          const html = response.map((item, idx) =>
            `<div class="suggestion" data-index="${idx}">${item}</div>`
          ).join('');
          $('#search-suggestions').css('display', 'block');
          $('#search-suggestions').html(html);
          selectedIndex = -1;
        },
        error: function() {
          $('#search-suggestions').css('display', 'none');
          $('#search-suggestions').empty();
          selectedIndex = -1;
        }
      });
    }, 250);
  });

  // 候補クリック
  $(document).on('click', '#search-suggestions .suggestion', function() {
    const value = $(this).text();
    $('#genba-search').val(value);
    $('#search-suggestions').css('display', 'none');
    $('#search-suggestions').empty();
    selectedIndex = -1;
    applyFilter(value, 1);
  });

  // キーボード操作
  $('#genba-search').on('keydown', function(e) {
    const suggestions = $('#search-suggestions .suggestion');

    if (e.key === 'Enter') {
      e.preventDefault();
      let value = (suggestions.length && selectedIndex >= 0)
        ? suggestions.eq(selectedIndex).text()
        : $('#genba-search').val();
      $('#genba-search').val(value);
      $('#search-suggestions').css('display', 'none');
      $('#search-suggestions').empty();
      selectedIndex = -1;
      applyFilter(value, 1);
    }

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
  });

  // ページングリンククリック
  $(document).on('click', '.pagination a.page-link', function(e) {
    e.preventDefault();
    const page = $(this).data('page');
    const value = $('#genba-search').val();
    applyFilter(value, page);
  });

  // クリアボタン
  $('#clear-btn').on('click', function() {
    $('#genba-search').val('');
    $('#search-suggestions').css('display', 'none');
    $('#search-suggestions').empty();
    applyFilter('', 1);
  });

  // DB再検索してDOM更新
  function applyFilter(value, page = 1) {
    $.ajax({
      url: '/wp-admin/admin-ajax.php',
      type: 'GET',
      dataType: 'json',
      data: {
        action: 'filter_genba',
        value: value,
        page_no: page
      },
      success: function(response) {
        if (!response.rows || !Array.isArray(response.rows)) return;

        const esc = s => String(s ?? '').replace(/[&<>"']/g, m => (
          {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
        ));

        const html = response.rows.map(item => `
          <tr>
            <td>${esc(item.code)}</td>
            <td>${esc(item.d_code)}</td>
            <td>${esc(item.name)}</td>
            <td>${esc(item.subject)}</td>
          </tr>
        `).join('');

        $('.log-table tbody').html(html);

        const perPage = 50;
        const totalPages = Math.ceil(response.total / perPage);
        renderPaging(page, totalPages);
      },
      error: function(xhr) {
        console.error('[filter] error:', xhr.responseText);
      }
    });
  }

  // ページング描画
  function renderPaging(currentPage, totalPages) {
    let pagingHtml = '';

    const maxVisible = 9;
    const half = Math.floor(maxVisible / 2);

    let start = currentPage - half;
    let end = currentPage + half;

    // 範囲調整（端に寄ったとき）
    if (start < 1) {
      end += (1 - start);
      start = 1;
    }
    if (end > totalPages) {
      start -= (end - totalPages);
      end = totalPages;
    }

    // 最終調整（範囲外補正）
    start = Math.max(1, start);
    end = Math.min(totalPages, end);

    // 最初へ <<
    if (currentPage > 1) {
      pagingHtml += `<a href="#" class="page-link" data-page="1">&laquo;</a>`;
      pagingHtml += `<a href="#" class="page-link" data-page="${currentPage - 1}">&lt;</a>`;
    }

    // 中央のページ番号
    for (let i = start; i <= end; i++) {
      const active = (i === currentPage) ? 'active' : '';
      pagingHtml += `<a href="#" class="page-link ${active}" data-page="${i}">${i}</a>`;
    }

    // 最後へ >>
    if (currentPage < totalPages) {
      pagingHtml += `<a href="#" class="page-link" data-page="${currentPage + 1}">&gt;</a>`;
      pagingHtml += `<a href="#" class="page-link" data-page="${totalPages}">&raquo;</a>`;
    }

    $('.pagination').html(pagingHtml);
  }
});
</script>
