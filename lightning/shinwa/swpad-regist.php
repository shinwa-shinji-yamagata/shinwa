<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
      <h2 class="mb-4 text-center">現場名登録</h2>
      <form id="genba-form" class="card p-4 shadow-sm">
        <div class="mb-3">
          <div class="d-flex align-items-center justify-content-center">
            <label for="genba-name" class="form-label mb-0 me-2" style="min-width: 60px; font-size:16px;">現場名</label>
            <input type="text" id="genba-name" name="genba-name" class="form-control me-3" style="max-width: 300px; margin-right:20px;" required>
            <button type="submit" class="btn btn-primary" style="min-width: 120px;">登録する</button>
          </div>
        </div>
      </form>
      <div id="genba-result" class="mt-3 text-center"></div>
    </div>
  </div>
</div>

<script>
jQuery(function($) {
  $('#genba-form').on('submit', function(e) {
    e.preventDefault();
    const name = $('#genba-name').val();

    $.ajax({
      url: swpad_ajax.ajax_url,
      method: 'POST',
      data: {
        action: 'swpad_genba_insert',
        name: name
      },
      success: function(response) {
        $('#genba-result').text(response.success ? '現場名「' + name + '」を登録しました！' : '登録に失敗しました').addClass('text-success');
        $('#genba-name').val('');
        location.reload();
      },
      error: function() {
        $('#genba-result').text('通信エラーが発生しました').addClass('text-danger');
      }
    });
  });
});
</script>

<style>
/* gap-2 は Bootstrap 5 のユーティリティ。ない場合は以下で代用可能 */
.d-flex.gap-2 > * {
  margin-right: 0.5rem;
}
#genba-name {
  max-width: 300px; /* 必要に応じて幅を制限 */
}
</style>
