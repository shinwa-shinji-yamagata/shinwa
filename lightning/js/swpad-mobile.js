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
        response.items.forEach(function(item) {
          targetList.append('<li><a href="/swpad/' + item.id + '/">' + item.name + '</a></li>');
        });

        targetPagination.empty();
        for (var i = 1; i <= response.total_pages; i++) {
          targetPagination.append('<button class="genba-page-btn" data-page="' + i + '">' + i + '</button>');
        }
      }
    });
  }

  // ページ読み込み時に強制実行（テスト用）
  const mobileList = $('#genba-list-mobile');
  const mobilePagination = $('#genba-pagination-mobile');
  if (mobileList.length && mobileList.children().length === 0) {
    loadGenbaTo(mobileList, mobilePagination);
  }

  // ページング対応
  $(document).on('click', '.genba-page-btn', function() {
    var page = $(this).data('page');
    var list = $(this).closest('div').prev('ul');
    var pagination = $(this).closest('div');
    loadGenbaTo(list, pagination, page);
  });
});
