<?php
// WordPress環境でのDB接続
global $wpdb;
$table_name = $wpdb->prefix . 'aggregation_status';

// 現在の1か月前の年月を取得
$prevMonth = strtotime('-1 month');
$defaultYear = date('Y', $prevMonth);
$defaultMonth = date('m', $prevMonth);
$defaultDisplay = $defaultYear . '年' . $defaultMonth . '月';
$defaultRaw = $defaultYear . '-' . $defaultMonth;
$defaultValue = $defaultYear . '年' . $defaultMonth . '月';

$current_user = wp_get_current_user();
$allowed_roles = ['keiri', 'soumu', 'administrator'];
$can_upload = array_intersect($allowed_roles, $current_user->roles);
$is_allowed = !empty($can_upload);

// ボタンの活性状態とメッセージを決定
$buttonDisabled = (!$is_allowed) ? 'disabled' : '';
$statusMessage = '';
$statusMessage2 = '';
if (!$is_allowed) {
    $statusMessage = '権限がありません';
    $statusMessage2 = '権限がありません';
}
?>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
  <h3>【日毎現場表】→【人工】月次集計</h3>
  <form id="summaryForm1" class="summary-form">
    <div class="form-row">
      <input type="text" id="yearMonthDisplay1" autocomplete="off" value="<?= $defaultDisplay ?>" placeholder="年月を選択">
      <input type="hidden" id="yearMonth1" name="yearMonth" value="<?= $defaultRaw ?>">
      <button type="submit" id="submitBtn1" style="padding: 8px 40px;" <?= $buttonDisabled ?>>集計</button>
    </div>
    <div id="statusMessage1"><?= $statusMessage ?></div>
  </form>

  <h3 style="margin-top:60px; margin-bottom:0;">【人工】→【PROCES.S向けExcel】変換（仕分）</h3>
  <p>ここで生成されたExcelは、PROCES.Sの画面で「管理」→「ﾃﾞｰﾀｲﾝﾎﾟｰﾄ」→「対象テーブル：更新仕分データ」→「コード：ｼﾝﾜ ｼﾝﾜﾄﾘｺﾐ」→「開始」からインポートできます。</p>
  <form id="summaryForm2" class="summary-form">
    <div class="form-row">
      <input type="text" id="yearMonthDisplay2" autocomplete="off" value="<?= $defaultDisplay ?>" placeholder="年月を選択">
      <input type="text" id="genba_code2" name="genba_code2" style="max-width:170px;font-size:14px;padding-top:14px;padding-bottom:14px;border-radius: 0.5em;" value="" placeholder="共通原価の工事ｺｰﾄﾞ">
      <input type="hidden" id="yearMonth2" name="yearMonth" value="<?= $defaultRaw ?>">
      <button type="submit" id="submitBtn2" style="padding: 8px 40px;" <?= $buttonDisabled ?>>変換</button>
    </div>
    <div id="statusMessage2"><?= $statusMessage ?></div>
  </form>

<script>
jQuery(function($) {
  function setupDatePicker(displayId, hiddenId) {
    const prevMonthDate = new Date();
    prevMonthDate.setMonth(prevMonthDate.getMonth() - 1);
    $('#' + displayId).datepicker({
      changeMonth: true,
      changeYear: true,
      showButtonPanel: true,
      dateFormat: 'yy-mm',
      defaultDate: prevMonthDate,
      showMonthAfterYear: true,
      onClose: function(dateText, inst) {
        var month = $("#ui-datepicker-div .ui-datepicker-month :selected").val();
        var year = $("#ui-datepicker-div .ui-datepicker-year :selected").val();
        var formatted = year + '年' + ('0' + (parseInt(month) + 1)).slice(-2) + '月';
        var raw = year + '-' + ('0' + (parseInt(month) + 1)).slice(-2);
        $('#' + displayId).val(formatted);
        $('#' + hiddenId).val(raw);
      },
      beforeShow: function(input, inst) {
        setTimeout(() => inst.dpDiv.addClass('month-only'), 0);

        // 入力欄の位置を取得してピッカーを下に表示
        const inputOffset = $(input).offset();
        const inputHeight = $(input).outerHeight();
        setTimeout(() => {
          inst.dpDiv.css({
            top: inputOffset.top + inputHeight + 5 + 'px',
            left: inputOffset.left + 'px'
          });
        }, 0);

        var val = $('#' + displayId).val();
        var match = val.match(/^(\d{4})年(\d{2})月$/);
        if (match) {
          var year = parseInt(match[1], 10);
          var month = parseInt(match[2], 10) - 1;
          $(input).datepicker('option', 'defaultDate', new Date(year, month, 1));
        }

        setTimeout(() => {
          $('#ui-datepicker-div .ui-datepicker-year option').each(function() {
            $(this).text($(this).val() + '年');
          });
        }, 10);
      },
      onChangeMonthYear: function(year, month, inst) {
        setTimeout(() => {
          $('#ui-datepicker-div .ui-datepicker-year option').each(function() {
            $(this).text($(this).val() + '年');
          });
        }, 10);
      }
    });
  }

  function setupForm(formId, displayId, hiddenId, codeId, buttonId, messageId, postUrl) {
    setupDatePicker(displayId, hiddenId);
    $('#' + formId).on('submit', function(e) {
      e.preventDefault();
      $('#' + buttonId).prop('disabled', true);
      $('#' + messageId).css('color','initial');
      $('#' + messageId).text('集計中です...');
      const yearMonth = $('#' + hiddenId).val();
      const [year, month] = yearMonth.split('-');
      const code = $('#' + codeId).val();

      $.ajax({
        url: postUrl,
        method: 'POST',
        data: { year, month, code },
        dataType: 'json',
        success: function(response) {
          if (response.success) {
            $('#' + messageId).html(response.message);
          } else {
            $('#' + messageId).css('color','red');
            $('#' + messageId).text('エラー: ' + response.message);
          }
          $('#' + buttonId).prop('disabled', false);
        },
        error: function() {
          $('#' + messageId).css('color','red');
          $('#' + messageId).text('通信エラーが発生しました');
          $('#' + buttonId).prop('disabled', false);
        }
      });
    });
  }

  // 各フォームの初期化
  setupForm('summaryForm1', 'yearMonthDisplay1', 'yearMonth1', '', 'submitBtn1', 'statusMessage1', '/post/higoto_genba_summary.php');
  setupForm('summaryForm2', 'yearMonthDisplay2', 'yearMonth2', 'genba_code2', 'submitBtn2', 'statusMessage2', '/post/shiwake.php');
});

var ajaxurl = "<?= admin_url('admin-ajax.php'); ?>";

jQuery(document).ready(function($) {
    $.ajax({
        url: ajaxurl, // WordPressが提供するAjaxエンドポイント
        method: 'POST',
        data: {
            action: 'get_genba_codes'
        },
        success: function(response) {
            $('#genba_code2').autocomplete({
                source: response,
                minLength: 0
            }).focus(function () {
                $(this).autocomplete("search", "");
            });
        }
    });
});
</script>
<style>
.wp-block-heading {
  display: none;
}
</style>