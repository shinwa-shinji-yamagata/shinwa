jQuery(function($) {
  $('#site-search').autocomplete({
    source: function(request, response) {
      $.ajax({
        url: swpad_ajax.ajax_url,
        dataType: 'json',
        data: {
          action: 'swpad_search',
          term: request.term
        },
        success: function(data) {
          response(data);
        }
      });
    },
    minLength: 0,
    select: function(event, ui) {
      window.location.href = ui.item.value;
    }
  });
  // フォーカス時に空文字で強制検索 
  $('#site-search').on('focus', function () { 
    $(this).autocomplete('search', ''); 
  });
});
