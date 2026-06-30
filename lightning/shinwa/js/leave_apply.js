// shinwa/js/leave_apply.js

document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('swl-approve-form');
  const btn  = document.getElementById('swl-approve-btn');

  if (!form || !btn) return;

  form.addEventListener('submit', function (e) {
    const msg = window.SWL_APPLY_CONFIG && window.SWL_APPLY_CONFIG.confirmMessage
      ? window.SWL_APPLY_CONFIG.confirmMessage
      : '承認しますか？';

    if (!confirm(msg)) {
      e.preventDefault();
      return;
    }
    btn.disabled = true;
  });
});
