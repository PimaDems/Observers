// Auto-submit filter controls (CSP forbids inline handlers).
document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
  el.addEventListener('change', function () { el.form.submit(); });
});
