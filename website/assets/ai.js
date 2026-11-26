/* 食光菜谱 · 前台 AI 小功能（分享文案） */
(function () {
  'use strict';

  function toast(msg) {
    if (window.SG_TOAST) { window.SG_TOAST(msg); return; }
    var t = document.querySelector('.toast');
    if (t) { t.textContent = msg; t.classList.add('show'); setTimeout(function () { t.classList.remove('show'); }, 2200); }
  }

  function copy(t) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(t).catch(fallback);
    }
    fallback();
    return Promise.resolve();
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      ta.remove();
    }
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ai-copy]');
    if (!b || b.dataset.busy === '1') return;
    e.preventDefault();
    var id = b.getAttribute('data-ai-copy');
    if (!id) return;
    var old = b.innerHTML;
    b.dataset.busy = '1';
    b.innerHTML = '生成中…';
    var fd = new FormData();
    fd.append('do', 'copy');
    fd.append('recipe_id', id);
    fd.append('_token', window.SG_CSRF || '');
    fetch((window.SG_API || 'api/') + 'ai-text.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { toast(j.msg || '文案生成失败'); return; }
        return copy(j.text).then(function () { toast('文案已生成并复制'); });
      })
      .catch(function () { toast('文案生成失败，检查 AI 服务'); })
      .finally(function () { b.dataset.busy = '0'; b.innerHTML = old; if (window.SG_HYDRATE) window.SG_HYDRATE(b); });
  });
})();
