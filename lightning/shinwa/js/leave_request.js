// shinwa/js/leave_request.js

document.addEventListener('DOMContentLoaded', function () {
  const halfAm     = document.getElementById('am');
  const halfPm     = document.getElementById('pm');
  const form       = document.getElementById('swl-request-form');
  const submitBtn  = document.getElementById('swl-submit-btn');
  const startInput = document.getElementById('swl-start-date');
  const endInput   = document.getElementById('swl-end-date');

  // 半休はどちらか一方のみ（Safari 対策で click を使用）
  if (halfAm && halfPm) {
    halfAm.addEventListener('click', function () {
      if (halfAm.checked) halfPm.checked = false;
    });
    halfPm.addEventListener('click', function () {
      if (halfPm.checked) halfAm.checked = false;
    });
  }

  if (form && submitBtn) {
    form.addEventListener('submit', function (e) {

      const type = form.querySelector('input[name="type"]:checked');
      const s = startInput ? startInput.value : '';
      const eDate = endInput ? endInput.value : '';
      const halfChecked = (halfAm && halfAm.checked) || (halfPm && halfPm.checked);

      // 種別
      if (!type) {
        alert('種別を選択してください。');
        e.preventDefault();
        return;
      }

      // 期日チェック（Safari 対策：length で判定）
      if (s.length === 0 || eDate.length === 0) {
        alert('期日を指定してください。');
        e.preventDefault();
        return;
      }

      // 半休の場合は1日だけ
      if (halfChecked && s !== eDate) {
        alert('半休の場合は1日だけ選択してください。');
        e.preventDefault();
        return;
      }

      // 確認ダイアログ
      const msg = window.SWL_REQUEST_CONFIG.confirmMessage || '申請しますか？';
      if (!confirm(msg)) {
        e.preventDefault();
        return;
      }

      // 多重送信防止
      submitBtn.disabled = true;
    });
  }
});
