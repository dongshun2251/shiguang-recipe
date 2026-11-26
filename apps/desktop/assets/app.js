/* 食光菜谱 · 交互脚本：图标注入 / 收藏 / 通用组件 */
(function () {
  'use strict';

  var API = (window.SG_API || 'api/').replace(/\/$/, '') + '/';

  /* InfinityFree 对 fetch/XHR 做人机验证，只有页面导航能过。
     把扫码状态/刷新请求改成隐藏 iframe 导航，既保留 JSON，又能绕过拦截。 */
  function sgParseForm(body) {
    var out = {};
    (body || '').split('&').forEach(function (kv) {
      if (!kv) return;
      var p = kv.split('=');
      out[decodeURIComponent(p[0])] = decodeURIComponent((p[1] || '').replace(/\+/g, ' '));
    });
    return out;
  }

  function sgIframeFetch(url, init) {
    init = init || {};
    return new Promise(function (resolve, reject) {
      var f = document.createElement('iframe');
      f.style.display = 'none';
      f.setAttribute('aria-hidden', 'true');
      var done = false;
      var timer = setTimeout(function () {
        if (done) return;
        done = true;
        f.remove();
        reject(new Error('iframe timeout'));
      }, 12000);
      function finish() {
        if (done) return;
        done = true;
        clearTimeout(timer);
        try {
          var text = (f.contentDocument && f.contentDocument.body) ? f.contentDocument.body.innerText : '';
          var data = JSON.parse(text);
          resolve({
            ok: true,
            status: 200,
            json: function () { return Promise.resolve(data); },
            text: function () { return Promise.resolve(text); }
          });
        } catch (e) {
          reject(e);
        } finally {
          f.remove();
        }
      }
      f.onload = function () { setTimeout(finish, 40); };
      if ((init.method || 'GET').toUpperCase() === 'POST') {
        var fields = sgParseForm(init.body);
        var inputs = '';
        Object.keys(fields).forEach(function (k) {
          inputs += '<input type="hidden" name="' + k.replace(/"/g, '&quot;') + '" value="' +
            String(fields[k]).replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '">';
        });
        f.srcdoc = '<!doctype html><html><body><form method="post" action="' +
          url.replace(/"/g, '&quot;') + '">' + inputs +
          '</form><script>document.forms[0].submit()<\/script></body></html>';
        document.body.appendChild(f);
      } else {
        f.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
        document.body.appendChild(f);
      }
    });
  }

  if (!window.__sgIframeFetch) {
    window.__sgIframeFetch = sgIframeFetch;
    var sgNativeFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : ((input && input.url) || '');
      if (url && url.indexOf('/qr-status.php') >= 0) return sgIframeFetch(url, init);
      return sgNativeFetch(input, init);
    };
  }

  var ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/><path d="M9.5 21v-6h5v6"/>',
    book: '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H19v18H5.5A1.5 1.5 0 0 1 4 19.5z"/><path d="M8 3v18"/><path d="M11.5 8h4"/>',
    grid: '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
    search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.5 15.5 21 21"/>',
    heart: '<path d="M12 20s-8-4.6-8-10a4.6 4.6 0 0 1 8-2.9A4.6 4.6 0 0 1 20 10c0 5.4-8 10-8 10z"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c0-4 3.4-6.5 7.5-6.5s7.5 2.5 7.5 6.5"/>',
    clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    chef: '<path d="M7 11a3.2 3.2 0 0 1-1-6.2A3.6 3.6 0 0 1 12 3.6 3.6 3.6 0 0 1 18 4.8a3.2 3.2 0 0 1-1 6.2"/><path d="M7 11h10v9H7z"/><path d="M7 15h10"/>',
    chart: '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M21 20V7"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
    edit: '<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="M14.5 5.5 18.5 9.5"/>',
    folder: '<path d="M3.5 6.5a2 2 0 0 1 2-2H9l2 2.5h7.5a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
    users: '<circle cx="9" cy="8" r="3.4"/><path d="M3 20c0-3.4 2.7-5.6 6-5.6s6 2.2 6 5.6"/><path d="M16 5.2A3.4 3.4 0 0 1 16 11"/><path d="M17.5 14.6c2.1.7 3.5 2.4 3.5 5.4"/>',
    comment: '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v9a1.5 1.5 0 0 1-1.5 1.5H10l-5 4V5.5z"/><path d="M8 9h8M8 12h5"/>',
    settings: '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.2 5.2l2.1 2.1M16.7 16.7l2.1 2.1M18.8 5.2l-2.1 2.1M7.3 16.7l-2.1 2.1"/>',
    expand: '<path d="M4 9V4h5"/><path d="M20 15v5h-5"/><path d="M4 4l6 6"/><path d="M20 20l-6-6"/>',
    shrink: '<path d="M9 4v5H4"/><path d="M15 20v-5h5"/><path d="M10 9L4 3"/><path d="M14 15l6 6"/>',
    star: '<path d="M12 3.2l2.7 5.6 6 .9-4.4 4.3 1 6.1-5.3-2.9-5.3 2.9 1-6.1L3.3 9.7l6-.9"/>',
    check: '<path d="M4.5 12.5l5 5 10-11"/>',
    plus: '<path d="M12 4.5v15M4.5 12h15"/>',
    right: '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
    left: '<path d="M19 12H5"/><path d="M11 6l-6 6 6 6"/>',
    back: '<path d="M15 5l-7 7 7 7"/>',
    flame: '<path d="M12 21c4 0 6.5-2.6 6.5-6 0-3-2-4.6-3.2-7-1 2-2 2.6-2.6 4.6C10 8 8 6 9 3c-1 3-3.5 5.4-3.5 9.5C5.5 17 8 21 12 21z"/>',
    leaf: '<path d="M20 4c0 9-5 13-11 13H5c0-8 6-13 15-13z"/><path d="M4 20c2-4 5-6 9-7"/>',
    share: '<circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="6" r="2.6"/><circle cx="18" cy="18" r="2.6"/><path d="M8.4 10.8 15.6 7.2M8.4 13.2l7.2 3.6"/>',
    print: '<path d="M7 8V4h10v4"/><rect x="3.5" y="8" width="17" height="8" rx="1.5"/><path d="M7 13h10v7H7z"/>',
    image: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M4 17l5-5 4 4 3-3 4 4"/>',
    tag: '<path d="M20.5 12.5 12 21 3 12V3h9l8.5 8.5z"/><circle cx="7.5" cy="7.5" r="1.6"/>',
    trash: '<path d="M4.5 7h15"/><path d="M8 7V4.5h8V7"/><path d="M6.5 7l1 13h9l1-13"/>',
    eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
    lock: '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10"/>',
    key: '<circle cx="8" cy="12" r="3.5"/><path d="M11.5 12H21M17 12v3M14 12v2"/>',
    logout: '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h9"/>',
    shield: '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
    database: '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.6 3.1 3 7 3s7-1.4 7-3V6"/><path d="M5 12v6c0 1.6 3.1 3 7 3s7-1.4 7-3v-6"/>',
    info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v.5M12 11.5V16"/>',
    alert: '<path d="M12 3.5 21 20H3z"/><path d="M12 9v5M12 17v.4"/>',
    refresh: '<path d="M20 11a8 8 0 0 0-13.6-4.6L3 9.5"/><path d="M3 4.5V9.5h5"/><path d="M4 13a8 8 0 0 0 13.6 4.6L21 14.5"/><path d="M21 19.5v-5h-5"/>',
    calendar: '<rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M3.5 10h17"/><path d="M8 3.5v4M16 3.5v4"/>',
    chev: '<path d="M9 5l7 7-7 7"/>',
    down: '<path d="M12 4.5v15"/><path d="M6 13.5l6 6 6-6"/>',
    scan: '<path d="M4 9V6a2 2 0 0 1 2-2h3"/><path d="M15 4h3a2 2 0 0 1 2 2v3"/><path d="M20 15v3a2 2 0 0 1-2 2h-3"/><path d="M9 20H6a2 2 0 0 1-2-2v-3"/><path d="M7.5 12h9"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    upload: '<path d="M12 20V5"/><path d="M6 11l6-6 6 6"/><path d="M4 20h16"/>',
    tag2: '<path d="M4 4h7l9 9-7 7-9-9z"/>',
    wand: '<path d="M4 20l10-10"/><path d="M15 4l1.5 3L20 8.5l-3.5 1.5L15 13l-1.5-3L10 8.5l3.5-1.5z"/>',
    utensils: '<path d="M6 3v7a2 2 0 0 0 4 0V3"/><path d="M8 12v9"/><path d="M17 3c-2 1.5-2 5 0 7v11"/>',
    rice: '<path d="M4 11h16l-1.5 9H5.5z"/><path d="M8 11c0-2.5 1.8-4 4-4s4 1.5 4 4"/>',
    box: '<path d="M3.5 8 12 3.5 20.5 8v8L12 20.5 3.5 16z"/><path d="M3.5 8l8.5 4.5L20.5 8M12 12.5v8"/>',
    filter: '<path d="M3.5 5h17l-6.5 8v6l-4 2v-8z"/>',
    more: '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
    wifi: '<path d="M3 9a13 13 0 0 1 18 0"/><path d="M6.5 12.5a9 9 0 0 1 11 0"/><path d="M10 16a4.5 4.5 0 0 1 4 0"/><circle cx="12" cy="19" r=".8"/>',
    play: '<path d="M8 5.5l10 6.5-10 6.5z"/>',
    music: '<path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="16.5" cy="16" r="2.5"/>',
    volume: '<path d="M4 9.5h4l5-4v13l-5-4H4z"/><path class="sg-snd sg-snd-1" d="M16 8.5a5 5 0 0 1 0 7"/><path class="sg-snd sg-snd-2" d="M18.8 6a8.5 8.5 0 0 1 0 12"/>',
    tablet: '<rect x="3.5" y="2.5" width="17" height="19" rx="2.5"/><path d="M9 18.5h6"/>',
    qr: '<rect x="3.5" y="3.5" width="6" height="6" rx="1"/><rect x="14.5" y="3.5" width="6" height="6" rx="1"/><rect x="3.5" y="14.5" width="6" height="6" rx="1"/><path d="M14.5 14.5h2v2h-2zM18.5 18.5h2v2h-2zM18.5 14.5h2M14.5 18.5h2"/>'
  };

  function svg(name) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" ' +
      'stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || ICONS.info) + '</svg>';
  }
  window.SG_ICON = svg;

  function hydrate(root) {
    (root || document).querySelectorAll('[data-ico]').forEach(function (el) {
      if (el.getAttribute('data-hydrated') === '1') return;
      el.innerHTML = svg(el.getAttribute('data-ico'));
      el.setAttribute('data-hydrated', '1');
    });
  }
  window.SG_HYDRATE = hydrate;

  /* ---------------- toast ---------------- */
  var toastEl, toastTimer;

  function toast(msg) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      document.body.appendChild(toastEl);
    }
    toastEl.innerHTML = '<span class="ico">' + svg('check') + '</span><span></span>';
    toastEl.querySelector('span:last-child').textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2300);
  }
  window.SG_TOAST = toast;

  /* ---------------- 收藏 ---------------- */
  function toggleFav(el) {
    var id = el.getAttribute('data-fav');
    if (!id) return;
    if (!window.SG_USER) {
      location.href = (window.SG_LOGIN || 'login.php') + '?next=' + encodeURIComponent(location.pathname + location.search);
      return;
    }
    var data = new URLSearchParams();
    data.append('recipe_id', id);
    data.append('token', window.SG_CSRF || '');
    fetch(API + 'favorite.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: data.toString()
    }).then(function (r) {
      if (!r.ok) throw new Error('http');
      return r.json();
    }).then(function (j) {
      if (j.ok) {
        el.classList.toggle('on', !!j.fav);
        el.setAttribute('aria-pressed', j.fav ? 'true' : 'false');
        var lbl = el.querySelector('.lbl');
        if (lbl) lbl.textContent = j.fav ? '已收藏' : '收藏';
        var num = el.closest('.rcard');
        if (num && j.count !== undefined) {
          var cell = num.querySelector('[data-favcount]');
          if (cell) cell.textContent = j.count + ' 收藏';
        }
        toast(j.fav ? '已加入我的收藏' : '已取消收藏');
      } else {
        toast(j.msg || '操作失败');
      }
    }).catch(function () { toast('操作失败，请稍后再试'); });
  }

  /* ---------------- 采购清单（localStorage，未登录也能用） ---------------- */
  var SHOP_KEY = 'sg_shopping';

  function shopRead() {
    try { return JSON.parse(localStorage.getItem(SHOP_KEY) || '[]') || []; } catch (e) { return []; }
  }
  function shopWrite(list) {
    try { localStorage.setItem(SHOP_KEY, JSON.stringify(list)); } catch (e) {}
  }
  function shopClicks(name, from) {
    if (!name) return 0;
    var list = shopRead();
    for (var i = 0; i < list.length; i++) {
      if (list[i].n === name && list[i].f === from) return Number(list[i].clicks) || 0;
    }
    return 0;
  }

  window.SG_SHOP = { read: shopRead, write: shopWrite, clicks: shopClicks };

  function shopBadgeText(n) { return n > 99 ? '99+' : String(n); }

  function shopMeta(tile) {
    if (!tile) return { name: '', qty: '', from: '' };
    var qt = tile.querySelector('.qt');
    return {
      name: tile.getAttribute('data-ing-name') || '',
      qty:  qt ? (qt.textContent || '').trim() : (tile.getAttribute('data-ing-qty') || '适量'),
      from: tile.getAttribute('data-ing-from') || ''
    };
  }

  function refreshShopBadge(tile) {
    if (!tile) return;
    var badge = tile.querySelector('[data-shop-count]');
    if (!badge) return;
    var meta = shopMeta(tile);
    var n = shopClicks(meta.name, meta.from);
    badge.textContent = shopBadgeText(n);
    badge.hidden = n < 1;
    if (n > 0) {
      badge.classList.remove('pop');
      void badge.offsetWidth;
      badge.classList.add('pop');
    }
  }

  function renderShopBadges() {
    var tiles = document.querySelectorAll('.ing-tile');
    for (var i = 0; i < tiles.length; i++) { refreshShopBadge(tiles[i]); }
  }

  var armedTile = null;
  var armedTimer = null;
  var pressTimer = null;
  var longPressFired = false;
  var shopAllPressTimer = null;
  var shopAllPressBtn = null;
  var shopAllLongFired = false;

  function cancelPressTimer() {
    if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
  }

  function cancelShopAllPress() {
    if (shopAllPressTimer) { clearTimeout(shopAllPressTimer); shopAllPressTimer = null; }
    if (shopAllPressBtn) { shopAllPressBtn.classList.remove('is-holding'); shopAllPressBtn = null; }
  }

  function clearShopEntry(tile) {
    var meta = shopMeta(tile);
    if (!meta.name) return;
    var list = shopRead();
    var next = [];
    var found = false;
    for (var i = 0; i < list.length; i++) {
      if (list[i].n === meta.name && list[i].f === meta.from) {
        found = true;
      } else {
        next.push(list[i]);
      }
    }
    if (!found) {
      toast('这个食材还不在采购清单里');
      return;
    }
    shopWrite(next);
    refreshShopBadge(tile);
    disarmIng();
    if (navigator.vibrate) { try { navigator.vibrate(15); } catch (e) {} }
    toast('已从采购清单移除「' + meta.name + '」');
  }

  function clearShopFromRecipe(recipeTitle, count) {
    var list = shopRead();
    var next = [];
    var removed = 0;
    for (var i = 0; i < list.length; i++) {
      if (recipeTitle && list[i].f === recipeTitle) {
        removed++;
      } else {
        next.push(list[i]);
      }
    }
    if (!removed) {
      toast('这道菜的食材还不在采购清单里');
      return;
    }
    shopWrite(next);
    renderShopBadges();
    disarmIng();
    if (navigator.vibrate) { try { navigator.vibrate(20); } catch (e) {} }
    toast('已移除这道菜的 ' + removed + ' 样食材');
  }

  function disarmIng() {
    if (armedTimer) { clearTimeout(armedTimer); armedTimer = null; }
    if (!armedTile) return;
    armedTile.classList.remove('confirming');
    var tip = armedTile.querySelector('.ing-confirm');
    if (tip) tip.remove();
    armedTile = null;
  }

  function armIng(tile) {
    disarmIng();
    armedTile = tile;
    tile.classList.add('confirming');
    var tip = tile.querySelector('.ing-confirm');
    if (!tip) {
      tip = document.createElement('span');
      tip.className = 'ing-confirm';
      tip.setAttribute('aria-live', 'polite');
      tile.appendChild(tip);
    }
    tip.textContent = '再点一下，加入清单';
    armedTimer = setTimeout(disarmIng, 2600);
  }

  function commitShop(tile) {
    var meta = shopMeta(tile);
    if (!meta.name) return;
    var cnt = shopAdd(meta.name, meta.qty, meta.from);
    refreshShopBadge(tile);
    disarmIng();
    toast('已加入采购清单（共 ' + cnt + ' 项）');
  }

  function handleIngTap(tile) {
    if (armedTile === tile) { commitShop(tile); return; }
    armIng(tile);
  }

  function shopAdd(name, qty, from) {
    if (!name) return;
    var list = shopRead();
    var hit = null;
    for (var i = 0; i < list.length; i++) {
      if (list[i].n === name && list[i].f === from) { hit = list[i]; break; }
    }
    if (hit) {
      hit.clicks = (Number(hit.clicks) || 0) + 1;
      if (!hit.done) {
        var a = String(hit.q).match(/^([0-9]+(?:\.[0-9]+)?)\s*(.*)$/);
        var b = String(qty).match(/^([0-9]+(?:\.[0-9]+)?)\s*(.*)$/);
        if (a && b && a[2] === b[2]) {
          var sum = parseFloat(a[1]) + parseFloat(b[1]);
          hit.q = (Math.round(sum * 100) / 100).toString().replace(/\.0$/, '') + (a[2] ? ' ' + a[2] : '');
        }
      }
      hit.done = false;
    } else {
      list.push({ n: name, q: qty || '适量', f: from || '', done: false, clicks: 1 });
    }
    shopWrite(list);
    return list.length;
  }

  /* ---------------- 全局事件 ---------------- */
  document.addEventListener('click', function (e) {



    var fav = e.target.closest('[data-fav]');
    if (fav) { e.preventDefault(); e.stopPropagation(); toggleFav(fav); return; }

    var allAdd = e.target.closest('[data-shop-all]');
    if (allAdd) {
      if (shopAllLongFired) {
        shopAllLongFired = false;
        e.preventDefault(); e.stopPropagation();
        return;
      }
      e.preventDefault(); e.stopPropagation();
      var allCard = allAdd.closest('.card');
      var allTiles = allCard ? allCard.querySelectorAll('.ing-tile') : [];
      var added = 0;
      for (var t = 0; t < allTiles.length; t++) {
        var tm = shopMeta(allTiles[t]);
        if (!tm.name) continue;
        shopAdd(tm.name, tm.qty, tm.from);
        refreshShopBadge(allTiles[t]);
        added++;
      }
      disarmIng();
      var total = shopRead().length;
      toast('已加入 ' + added + ' 种食材（清单共 ' + total + ' 项）');
      return;
    }

    /* 食材圆图也支持轻点加入；必须连点两下确认，防误触 */
    var ingIc = e.target.closest('.ing-ic');
    if (ingIc) {
      var tile = ingIc.closest('.ing-tile');
      if (tile) {
        if (longPressFired) {
          longPressFired = false;
          e.preventDefault(); e.stopPropagation();
          return;
        }
        var validTap = e.detail === 0 || (tapState && !tapState.moved && Date.now() - tapState.t < 650);
        tapState = null;
        if (!validTap) return;
        e.preventDefault(); e.stopPropagation();
        handleIngTap(tile);
        return;
      }
    }

    var sw = e.target.closest('.switch');
    if (sw && !sw.hasAttribute('data-keep')) { sw.classList.toggle('on'); }

    var t = e.target.closest('[data-toast]');
    if (t) { e.preventDefault(); toast(t.getAttribute('data-toast')); return; }

    var pick = e.target.closest('.pick');
    if (pick) {
      var cb = pick.querySelector('.checkbox');
      if (cb) cb.classList.toggle('on');
      pick.classList.toggle('on');
    }

    var plus = e.target.closest('[data-portion]');
    if (plus) {
      var box = plus.closest('.portion');
      var val = box.querySelector('span');
      var base = parseInt(box.getAttribute('data-base'), 10) || 2;
      var m = val.textContent.match(/(\d+)/);
      var cur = m ? parseInt(m[1], 10) : base;
      cur = plus.getAttribute('data-portion') === '+' ? Math.min(12, cur + 1) : Math.max(1, cur - 1);
      val.textContent = cur + ' 人份';
      /* 同步换算这道菜的食材数量：同单位按倍数缩放，非数字（适量/少许）原样保留 */
      var card = plus.closest('.card');
      var rows = card ? card.querySelectorAll('.qt[data-base]') : [];
      Array.prototype.forEach.call(rows, function (q) {
        var raw = q.getAttribute('data-base') || '';
        var mm = raw.match(/^([0-9]+(?:\.[0-9]+)?)\s*(.*)$/);
        if (!mm) { q.textContent = raw; return; }
        var n = parseFloat(mm[1]) * cur / base;
        var txt = (Math.round(n * 10) / 10).toString().replace(/\.0$/, '');
        q.textContent = txt + (mm[2] ? ' ' + mm[2] : '');
      });
    }

    var tab = e.target.closest('.tabs a');
    if (tab) {
      tab.parentNode.querySelectorAll('a').forEach(function (a) { a.classList.remove('on'); });
      tab.classList.add('on');
    }

    var ck = e.target.closest('.checkbox');
    if (ck && !ck.closest('.pick') && !ck.hasAttribute('data-check-all')) ck.classList.toggle('on');

    var cp = e.target.closest('[data-copy]');
    if (cp) {
      var text = cp.getAttribute('data-copy');
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { toast('已复制到剪贴板'); });
      else toast('请手动复制');
    }

    var ip = e.target.closest('[data-icon-pick]');
    if (ip) {
      var wrap = ip.closest('.icon-pick-wrap');
      var val = wrap ? wrap.querySelector('[data-icon-value]') : null;
      if (val) val.value = ip.getAttribute('data-icon-pick');
      if (wrap) {
        Array.prototype.forEach.call(wrap.querySelectorAll('.ip-btn'), function (x) {
          var on = x === ip;
          x.classList.toggle('on', on);
          x.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      }
      var card = ip.closest('.cat-mcard');
      var srcIco = ip.querySelector('.ico');
      var nowIco = card ? card.querySelector('.cm-icon-inline .ico, .cm-icons .icon-now .ico') : null;
      if (srcIco && nowIco) nowIco.replaceWith(srcIco.cloneNode(true));
      var nameEl = card ? card.querySelector('.cm-icon-name') : null;
      var nameTxt = ip.querySelector('small');
      if (nameEl && nameTxt) {
        nameEl.textContent = nameTxt.textContent;
        if (card) toast('已选「' + nameTxt.textContent + '」，点保存生效');
      }
    }

    var sh = e.target.closest('[data-share]');
    if (sh) {
      e.preventDefault();
      if (navigator.share) navigator.share({ title: document.title, url: location.href }).catch(function () {});
      else if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(function () { toast('链接已复制，可分享给朋友'); });
      else toast('请复制浏览器地址分享');
    }
  });


  /* 全局二次确认：以前 data-confirm 只是写在按钮上，没有任何脚本接管，点了直接执行 */
  var tapState = null;
  document.addEventListener('pointerdown', function (e) {
    var ic = e.target.closest('.ing-ic');
    tapState = ic ? { x: e.clientX, y: e.clientY, t: Date.now(), moved: false } : null;
    longPressFired = false;
    cancelPressTimer();

    var all = e.target.closest('[data-shop-all]');
    shopAllLongFired = false;
    cancelShopAllPress();
    if (all) {
      shopAllPressBtn = all;
      all.classList.add('is-holding');
      shopAllPressTimer = setTimeout(function () {
        shopAllPressTimer = null;
        if (!shopAllPressBtn) return;
        var card = shopAllPressBtn.closest('.card');
        var tile = card ? card.querySelector('.ing-tile[data-ing-from]') : null;
        var from = tile ? tile.getAttribute('data-ing-from') : '';
        shopAllLongFired = true;
        shopAllPressBtn.classList.remove('is-holding');
        shopAllPressBtn = null;
        if (navigator.vibrate) { try { navigator.vibrate(25); } catch (e) {} }
        clearShopFromRecipe(from);
      }, 700);
    }

    if (ic) {
      var pressTile = ic.closest('.ing-tile');
      pressTimer = setTimeout(function () {
        pressTimer = null;
        if (!tapState || tapState.moved || !pressTile) return;
        longPressFired = true;
        tapState = null;
        disarmIng();
        clearShopEntry(pressTile);
      }, 650);
    }
  }, true);
  document.addEventListener('pointermove', function (e) {
    if (!tapState) return;
    if (Math.abs(e.clientX - tapState.x) > 12 || Math.abs(e.clientY - tapState.y) > 12) {
      tapState.moved = true;
      cancelPressTimer();
      cancelShopAllPress();
    }
  }, true);
  document.addEventListener('pointerup', function () { cancelPressTimer(); cancelShopAllPress(); }, true);
  document.addEventListener('pointercancel', function () {
    cancelPressTimer();
    cancelShopAllPress();
    longPressFired = false;
    tapState = null;
  }, true);
  document.addEventListener('contextmenu', function (e) {
    if (e.target.closest('.ing-ic') || e.target.closest('[data-shop-all]')) e.preventDefault();
  });
  window.addEventListener('scroll', function () { cancelPressTimer(); cancelShopAllPress(); disarmIng(); }, { passive: true });
  document.addEventListener('click', function (e) {
    if (armedTile && !e.target.closest('.confirming')) disarmIng();
  }, false);

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var ic = e.target.closest('.ing-ic');
    if (!ic) return;
    var tile = ic.closest('.ing-tile');
    if (!tile) return;
    e.preventDefault();
    handleIngTap(tile);
  });

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el) return;
    if (!window.confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  }, true);

  function initCategoryTrain() {
    var strip = document.querySelector('.cat-strip');
    if (!strip || strip.dataset.train === '1') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (getComputedStyle(strip).display !== 'flex') return;
    if (strip.scrollWidth <= strip.clientWidth + 2) return;

    strip.dataset.train = '1';
    var originals = Array.prototype.slice.call(strip.children);
    var firstLeft = originals[0].getBoundingClientRect().left;
    for (var i = 0; i < originals.length; i++) {
      var copy = originals[i].cloneNode(true);
      copy.setAttribute('aria-hidden', 'true');
      copy.setAttribute('tabindex', '-1');
      strip.appendChild(copy);
    }
    var copy = strip.children[originals.length];
    var loopWidth = copy.getBoundingClientRect().left - firstLeft;
    if (loopWidth <= 0) return;

    var speed = 13;            /* px / 秒，慢火车节奏 */
    var last = 0;
    var pausedUntil = 0;
    var dragging = false;
    var raf = 0;
    var pos = strip.scrollLeft; /* 保留小数，避免 scrollLeft 取整后每帧都被吃掉 */

    function pause(ms) {
      pausedUntil = Math.max(pausedUntil, Date.now() + (ms || 0));
      if (ms > 0) {
        setTimeout(function () {
          pos = strip.scrollLeft;
          last = 0;
        }, ms + 30);
      }
    }
    function tick(ts) {
      if (!last) last = ts;
      var dt = Math.min(80, ts - last) / 1000;
      last = ts;
      if (!document.hidden && !dragging && Date.now() >= pausedUntil) {
        pos += speed * dt;
        if (pos >= loopWidth) { pos -= loopWidth; }
        strip.scrollLeft = pos;
      }
      raf = requestAnimationFrame(tick);
    }

    strip.addEventListener('pointerdown', function () {
      dragging = true;
      pos = strip.scrollLeft;
      pause(0);
    }, { passive: true });
    strip.addEventListener('pointerup', function () { dragging = false; pause(1600); }, { passive: true });
    strip.addEventListener('pointercancel', function () { dragging = false; pause(1200); }, { passive: true });
    strip.addEventListener('wheel', function () { pause(1800); }, { passive: true });
    strip.addEventListener('touchstart', function () {
      dragging = true;
      pos = strip.scrollLeft;
      pause(0);
    }, { passive: true });
    strip.addEventListener('touchend', function () { dragging = false; pause(1600); }, { passive: true });
    strip.addEventListener('focusin', function () { pause(0); }, true);
    strip.addEventListener('focusout', function () { pause(1200); }, true);
    document.addEventListener('visibilitychange', function () {
      last = 0;
      if (!document.hidden) { pos = strip.scrollLeft; pause(600); }
    });

    /* 手动滑到复制区时，也把位置拉回循环范围，避免看到重复卡片 */
    strip.addEventListener('scroll', function () {
      if (dragging) pos = strip.scrollLeft;
      if (strip.scrollLeft >= loopWidth) {
        strip.scrollLeft -= loopWidth;
        pos = strip.scrollLeft;
        last = 0;
      }
    }, { passive: true });

    raf = requestAnimationFrame(tick);
    window.addEventListener('beforeunload', function () {
      if (raf) cancelAnimationFrame(raf);
    });
  }


  /* 卡片简介只在文字放不下时滚动；放得下保持静止 */
  function initCardMarquee() {
    if (!document.documentElement.classList.contains('ua-mobile')) return;
    var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-marquee]'));
    nodes.forEach(function (el) {
      if (el.getAttribute('data-mq-ready') === '1') return;
      var html = el.innerHTML.trim();
      var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
      if (!text) return;

      var boxWidth = el.clientWidth;
      var textWidth = el.scrollWidth;
      if (textWidth <= boxWidth + 1) {
        el.setAttribute('data-mq-ready', '1');
        return;
      }

      var track = document.createElement('span');
      track.className = 'sg-mq-track';
      var copy1 = document.createElement('span');
      copy1.className = 'sg-mq-copy';
      copy1.innerHTML = html;
      var copy2 = document.createElement('span');
      copy2.className = 'sg-mq-copy';
      copy2.setAttribute('aria-hidden', 'true');
      copy2.innerHTML = html;
      track.appendChild(copy1);
      track.appendChild(copy2);

      var gap = 48;
      var shift = textWidth + gap;
      track.style.setProperty('--sg-mq-shift', '-' + shift + 'px');
      track.style.setProperty('--sg-mq-dur', Math.max(10, Math.min(45, shift / 22)).toFixed(1) + 's');
      el.innerHTML = '';
      el.appendChild(track);
      el.setAttribute('data-mq-ready', '1');
    });
  }


  /* 首屏欢迎卡：只有首页有；勾选“今日不提醒”后当天不再出现 */
  function initWelcome() {
    var modal = document.getElementById('welcomeModal');
    if (!modal) return;

    /* 今日不提醒按“当前设备 + 当前账号”隔离：
       游客只跟浏览器走；登录后键里带 uid，同一台设备换账号不会互相影响。
       换手机时 localStorage / Cookie 本来就是新的，不会继承旧设备的关闭状态。 */
    var uid = 0;
    try { uid = parseInt(window.SG_UID || 0, 10) || 0; } catch (e) {}
    var scope = uid > 0 ? '_u' + uid : '';
    var KEY = 'sg_welcome_off' + scope;
    /* 欢迎卡只按“当前标签页”记一次，不按账号分键。
       否则游客首页看过欢迎卡，登录后 uid 变了，同一标签页会再弹一次并播登录后音频。 */
    var SESSION_KEY = 'sg_welcome_shown_v2';
    var WIN_SHOWN = 'sgw2_seen=1';
    var WIN_OFF_PREFIX = 'sgoff' + scope + '=';

    /* 调试/预览用：?welcome=1 强制显示，不受今日不提醒、刷新、同站跳转限制 */
    var force = false;
    try {
      force = /(?:^|[?&])welcome=1(?:&|$)/.test(location.search);
    } catch (e) {}
    /* 从本站其他页面点“首页”进来时，即使 WebView 这一次没保住宿主页的
       sessionStorage / window.name，也能靠 referrer 认出这是站内跳转，
       不再把欢迎卡当成一次新的打开。冷启动、直接输网址、外站进来没有同站
       referrer，仍然正常展示。 */
    var internalNav = false;
    try {
      if (document.referrer) {
        internalNav = new URL(document.referrer).origin === location.origin;
      }
    } catch (e) {}
    /* 后台「站点体检」用同站 iframe 内嵌首页，这里不能当成普通站内跳转。 */
    var diagnostic = /(?:^|[?&])dx_silent=1(?:&|$)/.test(location.search);
    var d = new Date();
    var day = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

    function storageWorks(kind) {
      try {
        var st = window[kind];
        var probe = '__sg_probe__';
        st.setItem(probe, '1');
        st.removeItem(probe);
        return true;
      } catch (e) { return false; }
    }
    function cookieGet(name) {
      try {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
      } catch (e) { return ''; }
    }
    function cookieSet(name, value, expires) {
      try {
        var c = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';
        if (expires) c += '; expires=' + expires.toUTCString();
        if (location.protocol === 'https:') c += '; Secure';
        document.cookie = c;
        return true;
      } catch (e) { return false; }
    }
    function cookieDel(name) {
      try {
        document.cookie = name + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        return true;
      } catch (e) { return false; }
    }
    function cookieWorks() {
      var k = '__sg_cookie_probe__';
      if (!cookieSet(k, '1')) return false;
      var ok = cookieGet(k) === '1';
      cookieDel(k);
      return ok;
    }
    function winHas(token) {
      try { return ('|' + (window.name || '') + '|').indexOf('|' + token + '|') !== -1; } catch (e) { return false; }
    }
    function winSet(token) {
      try {
        if (winHas(token)) return;
        window.name = (window.name ? window.name + '|' : '') + token;
      } catch (e) {}
    }
    function winDel(token) {
      try {
        var parts = (window.name || '').split('|');
        window.name = parts.filter(function (p) { return p !== token; }).join('|');
      } catch (e) {}
    }
    function dayEnd() {
      var e = new Date();
      e.setHours(23, 59, 59, 999);
      return e;
    }

    var hasLS = storageWorks('localStorage');
    var hasSS = storageWorks('sessionStorage');
    var hasCookie = cookieWorks();

    function readOff() {
      var values = [];
      if (hasLS) {
        try { values.push(localStorage.getItem(KEY) || ''); } catch (e) {}
      }
      if (hasCookie) values.push(cookieGet(KEY));
      if (winHas(WIN_OFF_PREFIX + day)) values.push(day);
      for (var i = 0; i < values.length; i++) {
        if (values[i] === day) return day;
      }
      for (var j = 0; j < values.length; j++) {
        if (values[j]) return values[j];
      }
      return '';
    }
    function saveOff(on) {
      if (on) {
        if (hasLS) { try { localStorage.setItem(KEY, day); } catch (e) {} }
        if (hasCookie) cookieSet(KEY, day, dayEnd());
        winSet(WIN_OFF_PREFIX + day);
      } else {
        if (hasLS) {
          try { if (localStorage.getItem(KEY) === day) localStorage.removeItem(KEY); } catch (e) {}
        }
        if (hasCookie && cookieGet(KEY) === day) cookieDel(KEY);
        winDel(WIN_OFF_PREFIX + day);
      }
    }
    function hasShown() {
      if (hasSS) {
        try { if (sessionStorage.getItem(SESSION_KEY) === '1') return true; } catch (e) {}
      }
      return winHas(WIN_SHOWN);
    }
    function markShown() {
      if (hasSS) {
        try { sessionStorage.setItem(SESSION_KEY, '1'); } catch (e) {}
      }
      winSet(WIN_SHOWN);
    }

    /* 访问网站：新标签 / 无痕 / 直接打开没有“已显示”标记，100% 显示。
       刷新或后退：同一标签的 sessionStorage 或 window.name 标记还在，直接隐藏。
       不用 navigation.type：手机 WebView 首次打开经常误报 reload，反而会把自己藏掉。
       存储被浏览器禁用时，window.name / Cookie 兜底，“今日不提醒”刷新后仍然有效。 */
    if (!force && readOff() === day) return;

    var shouldMark = false;
    if (!force) {
      if (hasShown()) return;
      /* 站内返回首页：不重复弹，也不写“已显示”标记，
         这样下次真正冷启动/直接打开首页时欢迎卡仍会出现。 */
      if (internalNav && !diagnostic) return;
      shouldMark = true;
    }

    var noToday = document.getElementById('wmNoToday');
    var bodyOverflow = document.body.style.overflow;

    function close() {
      if (noToday && noToday.checked) saveOff(true);
      modal.classList.remove('open');
      document.body.classList.remove('sg-modal-lock');
      document.body.style.overflow = bodyOverflow;
      window.setTimeout(function () { modal.hidden = true; }, 240);
    }

    if (noToday) {
      noToday.addEventListener('change', function () { saveOff(noToday.checked); });
    }

    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-wm-close]')) { close(); return; }
      if (e.target.closest('a')) { close(); }
    });
    var start = document.getElementById('wmStart');
    if (start) start.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) close();
    });

    modal.hidden = false;
    /* 不把“可见”依赖在 requestAnimationFrame 上：后台标签页 / 手机 WebView
       可能不触发 rAF，卡会变成 hidden=false 但 opacity=0 的透明状态。 */
    modal.classList.add('open');
    if (start) start.focus();
    if (shouldMark) markShown();
    document.body.classList.add('sg-modal-lock');
    document.body.style.overflow = 'hidden';
  }


  /* 评论点赞：登录用户点一次赞一次，再点取消；数量实时更新 */
  function initCommentLikes() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-cmt-like]');
      if (!btn) return;
      e.preventDefault();
      if (!window.SG_USER) {
        location.href = (window.SG_LOGIN || 'login.php') + '?next=' + encodeURIComponent(location.pathname + location.search);
        return;
      }
      if (btn.getAttribute('data-busy') === '1') return;
      btn.setAttribute('data-busy', '1');
      var id = btn.getAttribute('data-cmt-like');
      var data = new URLSearchParams();
      data.append('comment_id', id);
      data.append('token', window.SG_CSRF || '');
      fetch(API + 'comment-like.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString()
      }).then(function (r) {
        return r.json();
      }).then(function (j) {
        if (j.ok) {
          btn.classList.toggle('on', !!j.liked);
          btn.setAttribute('aria-pressed', j.liked ? 'true' : 'false');
          btn.setAttribute('aria-label', '点赞，当前 ' + j.count + ' 个赞');
          var n = btn.querySelector('[data-like-count]');
          if (n) n.textContent = j.count;
        } else if (j.need_login) {
          location.href = (window.SG_LOGIN || 'login.php') + '?next=' + encodeURIComponent(location.pathname + location.search);
        } else {
          toast(j.msg || '点赞失败');
        }
      }).catch(function () {
        toast('点赞失败，请稍后再试');
      }).finally(function () {
        btn.removeAttribute('data-busy');
      });
    });
  }

  /* 本周采购清单：折叠 / 展开，状态跨刷新保持 */
  function initShopFold() {
    var card = document.querySelector('[data-shop-card]');
    if (!card) return;
    var btns = Array.prototype.slice.call(card.querySelectorAll('[data-shop-toggle]'));
    if (!btns.length) {
      card.classList.remove('is-collapsed');
      return;
    }
    var text = card.querySelector('[data-shop-toggle-text]');
    var KEY = 'sg_shop_fold';

    function setFold(collapsed, persist) {
      card.classList.toggle('is-collapsed', collapsed);
      btns.forEach(function (btn) {
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      });
      if (text) text.textContent = collapsed ? '展开' : '收起';
      if (persist) {
        try {
          localStorage.setItem(KEY, collapsed ? '1' : '0');
        } catch (e) {}
      }
    }

    var startCollapsed = true;
    try { startCollapsed = localStorage.getItem(KEY) !== '0'; } catch (e) {}
    setFold(startCollapsed, false);

    btns.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        setFold(!card.classList.contains('is-collapsed'), true);
      });
    });
  }

  /* 刷新 / 后退时恢复上一步的滚动位置；普通跳转仍从顶部开始 */
  function initScrollMemory() {
    var KEY = 'sg_scroll:' + location.pathname + location.search;
    var main = document.querySelector('.main');
    var docEl = document.documentElement;
    var saved = null;
    var navType = 'navigate';

    try {
      if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    } catch (e) {}
    try {
      var nav = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
      if (nav && nav.type) navType = nav.type;
      else if (performance.navigation) {
        navType = performance.navigation.type === 1 ? 'reload' : (performance.navigation.type === 2 ? 'back_forward' : 'navigate');
      }
    } catch (e) {}
    try { saved = JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) {}

    var allowRestore = (navType === 'reload' || navType === 'back_forward') && !location.hash && saved;
    var userMoved = false;

    function markUserMove() { userMoved = true; }
    ['wheel', 'touchstart', 'keydown'].forEach(function (evt) {
      window.addEventListener(evt, markUserMove, { passive: true });
    });

    function apply() {
      if (!allowRestore || userMoved) return;
      var y = Math.max(0, Number(saved.y) || 0);
      var m = Math.max(0, Number(saved.m) || 0);
      try {
        if (main && m) main.scrollTop = m;
        if (y) window.scrollTo(0, y);
      } catch (e) {}
    }

    /* 页面内容 / 图片陆续加载完后再校一次，避免被撑高后落错位置 */
    apply();
    requestAnimationFrame(apply);
    window.setTimeout(apply, 120);
    window.addEventListener('load', function () {
      apply();
      window.setTimeout(apply, 180);
      window.setTimeout(apply, 700);
    });

    function save() {
      var y = Math.max(0, window.pageYOffset || docEl.scrollTop || 0);
      var m = main ? Math.max(0, main.scrollTop) : 0;
      try { sessionStorage.setItem(KEY, JSON.stringify({ y: y, m: m })); } catch (e) {}
    }

    var ticking = false;
    function onScroll() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () { save(); ticking = false; });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    if (main) main.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('pagehide', save);
    window.addEventListener('beforeunload', save);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') save();
    });
  }

  /* 搜索框输入联想：1–10 个字，菜名优先，同时匹配食材 / 标签 */
  function initLiveSearch() {
    var inputs = document.querySelectorAll('.search-field input[name="q"][data-live-search]');
    if (!inputs.length) return;

    function cap10(s) {
      if (!s) return '';
      try {
        if (typeof Array.from === 'function') return Array.from(s).slice(0, 10).join('');
      } catch (e) {}
      return s.slice(0, 10);
    }

    Array.prototype.forEach.call(inputs, function (input, idx) {
      var form = input.closest('form');
      if (!form) return;
      var pop = document.createElement('div');
      pop.className = 'search-live-pop';
      pop.id = 'searchLivePop' + idx;
      pop.hidden = true;
      pop.setAttribute('role', 'listbox');
      pop.setAttribute('aria-label', '菜谱联想');
      form.appendChild(pop);
      form.classList.add('has-live-search');

      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('aria-controls', pop.id);
      input.setAttribute('autocomplete', 'off');

      var cache = {};
      var seq = 0;
      var timer = 0;
      var composing = false;
      var current = [];
      var active = -1;
      var lastQ = '';

      function fitHeight() {
        try {
          var vv = window.visualViewport;
          var vh = vv ? vv.height : window.innerHeight;
          var top = form.getBoundingClientRect().bottom + 7;
          var avail = vh - top - 8;
          pop.style.maxHeight = Math.max(140, Math.min(340, avail)) + 'px';
        } catch (e) {}
      }

      function open() {
        pop.hidden = false;
        form.classList.add('has-live');
        input.setAttribute('aria-expanded', 'true');
        fitHeight();
      }

      function hide() {
        pop.hidden = true;
        form.classList.remove('has-live');
        input.setAttribute('aria-expanded', 'false');
        active = -1;
      }

      function searchUrl(q) {
        var action = form.getAttribute('action') || 'search.php';
        return action + (action.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
      }

      function highlight(el, text, q) {
        var lower = text.toLowerCase();
        var ql = q.toLowerCase();
        var at = lower.indexOf(ql);
        if (at < 0) {
          el.textContent = text;
          return;
        }
        if (at > 0) el.appendChild(document.createTextNode(text.slice(0, at)));
        var mark = document.createElement('mark');
        mark.textContent = text.slice(at, at + q.length);
        el.appendChild(mark);
        if (at + q.length < text.length) {
          el.appendChild(document.createTextNode(text.slice(at + q.length)));
        }
      }

      function render(q, data) {
        current = [];
        active = -1;
        pop.innerHTML = '';

        var head = document.createElement('div');
        head.className = 'live-head';
        var label = document.createElement('span');
        label.appendChild(document.createTextNode('匹配「'));
        var qb = document.createElement('b');
        qb.textContent = q;
        label.appendChild(qb);
        label.appendChild(document.createTextNode('」'));
        head.appendChild(label);
        var count = document.createElement('em');
        count.textContent = data.total ? ('共 ' + data.total + ' 道') : '暂无匹配';
        if (data.capped) {
          count.className = 'note';
          count.textContent = '按前 10 个字匹配';
        }
        head.appendChild(count);
        pop.appendChild(head);

        if (!data.items || !data.items.length) {
          var empty = document.createElement('div');
          empty.className = 'live-empty';
          var eb = document.createElement('b');
          eb.textContent = '没有找到「' + q + '」相关菜谱';
          var es = document.createElement('span');
          es.textContent = '换个菜名或食材试试，也可以直接去搜索页看看。';
          empty.appendChild(eb);
          empty.appendChild(es);
          pop.appendChild(empty);
          var emptyFoot = document.createElement('a');
          emptyFoot.className = 'live-foot';
          emptyFoot.href = searchUrl(q);
          emptyFoot.textContent = '去搜索页看看';
          pop.appendChild(emptyFoot);
          open();
          return;
        }

        var list = document.createElement('div');
        list.className = 'live-list';
        data.items.forEach(function (it) {
          var a = document.createElement('a');
          a.className = 'live-item';
          a.href = it.url || '#';
          a.setAttribute('role', 'option');
          a.setAttribute('aria-label', it.title);

          var thumb = document.createElement('span');
          thumb.className = 'live-thumb';
          var fallback = document.createElement('b');
          fallback.textContent = (it.title || '菜').slice(0, 1);
          var img = document.createElement('img');
          img.src = it.cover || '';
          img.alt = '';
          img.loading = 'lazy';
          img.onerror = function () { this.style.display = 'none'; };
          thumb.appendChild(fallback);
          thumb.appendChild(img);

          var body = document.createElement('div');
          body.className = 'live-body';
          var title = document.createElement('div');
          title.className = 'live-title';
          highlight(title, it.title || '', q);
          var meta = document.createElement('div');
          meta.className = 'live-meta';
          var bits = [];
          if (it.minutes) bits.push(it.minutes + ' 分钟');
          if (it.difficulty) bits.push(it.difficulty);
          if (it.rating && Number(it.rating) > 0) bits.push('★ ' + it.rating);
          meta.textContent = bits.join(' · ');
          body.appendChild(title);
          body.appendChild(meta);

          var why = document.createElement('span');
          why.className = 'live-why';
          why.textContent = it.reason || '相关';

          a.appendChild(thumb);
          a.appendChild(body);
          a.appendChild(why);
          a.addEventListener('mouseenter', function () {
            var at = current.indexOf(a);
            if (at >= 0) setActive(at);
          });
          list.appendChild(a);
          current.push(a);
        });
        pop.appendChild(list);

        var foot = document.createElement('a');
        foot.className = 'live-foot';
        foot.href = searchUrl(q);
        if (data.total > data.items.length) {
          foot.textContent = '查看全部 ' + data.total + ' 道匹配（当前显示前 ' + data.items.length + ' 道）';
        } else {
          foot.textContent = '查看全部匹配结果';
        }
        pop.appendChild(foot);
        open();
      }

      function setActive(at) {
        if (!current.length) return;
        if (at < 0) at = current.length - 1;
        if (at >= current.length) at = 0;
        active = at;
        current.forEach(function (el, i) {
          el.classList.toggle('is-active', i === active);
        });
        current[active].scrollIntoView({ block: 'nearest' });
      }

      function showLoading(q) {
        current = [];
        active = -1;
        pop.innerHTML = '';
        var box = document.createElement('div');
        box.className = 'live-loading';
        box.textContent = '正在找与「' + q + '」相关的菜…';
        pop.appendChild(box);
        open();
      }

      function load(q) {
        if (!q) { hide(); return; }
        lastQ = q;
        if (cache[q]) {
          render(q, cache[q]);
          return;
        }
        showLoading(q);
        var my = ++seq;
        var url = API + 'search-suggest.php?q=' + encodeURIComponent(q);
        fetch(url, { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (my !== seq) return;
            if (!j || !j.ok) throw new Error('bad');
            cache[q] = j;
            if (input.value.trim() && cap10(input.value.trim()) === q) render(q, j);
          })
          .catch(function () {
            if (my !== seq) return;
            if (input.value.trim() && cap10(input.value.trim()) === q) {
              render(q, { total: 0, items: [], capped: false });
            }
          });
      }

      function schedule(delay) {
        window.clearTimeout(timer);
        var q = cap10(input.value.trim());
        if (!q) { hide(); return; }
        timer = window.setTimeout(function () { load(q); }, typeof delay === 'number' ? delay : 230);
      }

      input.addEventListener('compositionstart', function () { composing = true; });
      input.addEventListener('compositionend', function () {
        composing = false;
        schedule(0);
      });
      input.addEventListener('input', function () {
        if (composing) return;
        schedule();
      });
      input.addEventListener('focus', function () {
        if (input.value.trim()) schedule(0);
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { hide(); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          if (!current.length) return;
          e.preventDefault();
          setActive(e.key === 'ArrowDown' ? active + 1 : active - 1);
        } else if (e.key === 'Enter' && active >= 0 && current[active]) {
          e.preventDefault();
          location.href = current[active].href;
        }
      });
      form.addEventListener('submit', hide);
      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () {
          if (!pop.hidden) fitHeight();
        });
      }
      document.addEventListener('pointerdown', function (e) {
        if (!form.contains(e.target)) hide();
      });
      /* 页面滚动时不做隐藏：移动端键盘弹出本身也会触发滚动 */
    });
  }

  /* 代理音频先取成 Blob 再播：部分手机 WebView 直接播 audio.php 的 URL 会报 NOT SUPPORT，
     但同一批字节用 Blob 能正常解码，所以统一走这条更兼容的路径。 */
  var sgAudioBlobCache = new WeakMap();

  function sgAudioProxyOf(el) {
    /* 桌面 Electron 直接播放 audio.php，fetch 音频会被免费主机的人机验证挡成 HTML */
    if (/Electron/i.test(navigator.userAgent || '')) return '';
    return (el && el.getAttribute && el.getAttribute('data-proxy')) || '';
  }

  function sgAudioReadyUrl(el) {
    var rec = sgAudioBlobCache.get(el);
    return rec && rec.url ? rec.url : '';
  }

  function sgAudioPrepare(el) {
    var proxy = sgAudioProxyOf(el);
    if (!proxy) return Promise.resolve(null);
    var rec = sgAudioBlobCache.get(el);
    if (rec) {
      if (rec.url) return Promise.resolve(rec);
      if (rec.promise) return rec.promise;
    }
    rec = { url: '', type: '', promise: null, error: null };
    sgAudioBlobCache.set(el, rec);
    if (typeof fetch !== 'function') {
      rec.promise = Promise.reject(new Error('浏览器不支持 fetch'));
      return rec.promise;
    }
    rec.promise = fetch(proxy, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        var ct = (r.headers.get('content-type') || '').toLowerCase();
        if (!r.ok) throw new Error('HTTP ' + r.status);
        if (ct.indexOf('text/html') !== -1) throw new Error('主机返回的是 HTML 验证页');
        return r.arrayBuffer().then(function (buf) { return { buf: buf, type: ct }; });
      })
      .then(function (x) {
        var type = /^audio\//i.test(x.type) ? x.type.split(';')[0] : 'audio/mpeg';
        rec.type = type;
        rec.url = URL.createObjectURL(new Blob([x.buf], { type: type }));
        return rec;
      })
      .catch(function (err) {
        rec.error = err;
        sgAudioBlobCache.delete(el);
        throw err;
      });
    return rec.promise;
  }

  function sgAudioStart(el) {
    var ready = sgAudioReadyUrl(el);
    if (ready) {
      if (el.src !== ready) {
        el.src = ready;
        el.load();
      }
      try { return Promise.resolve(el.play()); } catch (e) { return Promise.reject(e); }
    }
    function useBlob(rec) {
      if (!rec || !rec.url) throw new Error('音频准备失败');
      if (el.src !== rec.url) {
        el.src = rec.url;
        el.load();
      }
      try { return el.play(); } catch (e) { return Promise.reject(e); }
    }
    return sgAudioPrepare(el).then(useBlob, function (err) {
      /* fetch 失败时退回直链，兼容少量“媒体直链能播、fetch 被拦”的环境 */
      var proxy = sgAudioProxyOf(el);
      if (proxy) {
        try {
          el.src = proxy;
          el.load();
          return el.play();
        } catch (e) {}
      }
      throw err;
    });
  }

  /* 欢迎音频：首次访问自动播放一次；没自动播放成功时，三个入口任意一个点一下可以出声 */
  function initWelcomeAudio() {
    var audio = document.getElementById('sg-welcome-audio');
    var title = document.getElementById('wmTitle');
    if (!audio) return;

    var key = audio.getAttribute('data-key') || 'sg_welcome_audio';
    var ANY_KEY = 'sg_welcome_audio_any_v1';
    var playedThisTab = false;
    var playedAnyWelcome = false;
    var silent = false;
    try {
      /* 按标签页记“已经自动播过”，不再用 localStorage 永久记住：
         新标签页 sessionStorage 是新的，会再自动播一次；同一标签刷新则不会重复。 */
      playedThisTab = sessionStorage.getItem(key + ':played') === '1';
      /* 同一标签页里游客欢迎音频播过之后，登录换账号也不再自动播登录后音频。
         用户主动点标题仍然可以重播。 */
      playedAnyWelcome = sessionStorage.getItem(ANY_KEY) === '1';
      silent = /(?:^|[?&])dx_silent=1(?:&|$)/.test(location.search);
    } catch (e) {}

    /* 自动播放每个标签页只尝试一次；用户主动点标题仍然可以重播。
       诊断页用 dx_silent=1 内嵌测试，不自动出声。
       欢迎卡没显示（例如今日不提醒、同标签刷新）时也不在后台自动响。 */
    var wmCard = document.getElementById('welcomeModal');
    var cardOpen = !!(wmCard && !wmCard.hidden);
    var autoAllowed = !playedThisTab && !playedAnyWelcome && !silent && cardOpen;

    var started = false;

    function mark() {
      playedThisTab = true;
      playedAnyWelcome = true;
      try {
        sessionStorage.setItem(key + ':played', '1');
        sessionStorage.setItem(ANY_KEY, '1');
      } catch (e) {}
    }
    function setPlaying(on) {
      if (!title) return;
      title.classList.toggle('is-playing', !!on);
      title.setAttribute('aria-pressed', on ? 'true' : 'false');
    }

    /* play 事件比 play() Promise 更早、更可靠：只要真正开始播放就置位，
       避免个别 WebView 的 play() Promise 一直不 resolve 时又被按钮重播。 */
    audio.addEventListener('play', function () {
      playedThisTab = true;
      playedAnyWelcome = true;
      try { sessionStorage.setItem(ANY_KEY, '1'); } catch (e) {}
    });
    audio.addEventListener('playing', function () {
      started = true;
      setPlaying(true);
      mark();
    });
    audio.addEventListener('pause', function () { setPlaying(false); });
    audio.addEventListener('ended', function () { setPlaying(false); });

    /* 自动只尝试一次；用户主动点标题可以重播/暂停 */
    function playNow() {
      /* 防止移动端一次点击触发多次/连点：上一次 play() 还没落定就不再调用 pause() */
      if (audio.getAttribute('data-play-pending') === '1') return;
      if (!audio.paused) {
        audio.pause();
        setPlaying(false);
        return;
      }
      if (audio.ended) {
        try { audio.currentTime = 0; } catch (e) {}
      }
      /* 有 data-proxy 时先取 Blob 再播，绕开 WebView 对 audio.php 直链的 NOT SUPPORT */
      if (sgAudioProxyOf(audio)) {
        var bp;
        try {
          audio.setAttribute('data-play-pending', '1');
          bp = sgAudioStart(audio);
        } catch (e) {
          audio.removeAttribute('data-play-pending');
          setPlaying(false);
          if (window.SG_TOAST) window.SG_TOAST('音频请求失败：' + (e && e.message ? e.message : '请刷新重试'));
          return;
        }
        bp.then(function () {
          audio.removeAttribute('data-play-pending');
          started = true;
          setPlaying(true);
          mark();
        }).catch(function (err) {
          audio.removeAttribute('data-play-pending');
          started = false;
          setPlaying(false);
          if (err && err.name === 'AbortError') return;
          if (window.SG_TOAST) {
            window.SG_TOAST(err && err.name === 'NotAllowedError'
              ? '浏览器拦截了音频播放，请再点一次标题'
              : '音频请求失败：' + (err && err.message ? err.message : '请刷新重试'));
          }
        });
        return;
      }
      var p;
      try {
        audio.setAttribute('data-play-pending', '1');
        p = audio.play();
      } catch (e) {
        audio.removeAttribute('data-play-pending');
        setPlaying(false);
        if (window.SG_TOAST) window.SG_TOAST('浏览器拦截了音频播放，请再点一次标题');
        return;
      }
      if (p && typeof p.then === 'function') {
        started = true;
        p.then(function () {
          audio.removeAttribute('data-play-pending');
          setPlaying(true);
          mark();
        }).catch(function (err) {
          audio.removeAttribute('data-play-pending');
          started = false;
          setPlaying(false);
          /* double-tap 造成的 AbortError 不提示，避免本来已经播着却弹失败 */
          if (err && err.name === 'AbortError') return;
          if (window.SG_TOAST) {
            window.SG_TOAST(err && err.name === 'NotAllowedError'
              ? '浏览器拦截了音频播放，请再点一次标题'
              : '音频播放失败，请刷新页面重试');
          }
        });
      } else {
        audio.removeAttribute('data-play-pending');
        started = true;
      }
    }

    function startAuto() {
      if (!autoAllowed || started) return;
      if (audio.ended) {
        try { audio.currentTime = 0; } catch (e) {}
      }
      playNow();
    }

    if (title && title.getAttribute('data-wm-audio') === '1') {
      title.addEventListener('click', function (e) {
        e.preventDefault();
        playNow();
      });
      title.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          playNow();
        }
      });
    }

    /* “开始做饭”和右上角关闭叉：只在还没听过时作为补启动入口。
       本标签页已经自动播过（playedThisTab=true）就不再重复；
       自动播放被浏览器拦掉时 playedThisTab 仍是 false，按钮依然可以补播。 */
    function playFromEntry() {
      if (playedThisTab) return;
      if (!audio.paused) return;
      playNow();
    }
    var wmStart = document.getElementById('wmStart');
    if (wmStart) {
      wmStart.addEventListener('click', playFromEntry);
    }
    var wmClose = document.querySelector('#welcomeModal .wm-close');
    if (wmClose) {
      wmClose.addEventListener('click', playFromEntry);
    }

    if (sgAudioProxyOf(audio)) {
      sgAudioPrepare(audio).catch(function () {});
    }
    window.setTimeout(function () { startAuto(); }, 0);
  }

  /* 头像：点头像 / 选择图片打开裁剪弹窗，只保留选图 + 裁剪 */
  function initAvatarCrop() {
    var modal = document.getElementById('avatarModal');
    var stage = document.getElementById('avStage');
    if (!modal || !stage) return;
    var img      = document.getElementById('avImg');
    var drop     = document.getElementById('avDrop');
    var tools    = document.getElementById('avTools');
    var zoom     = document.getElementById('avZoom');
    var resetBtn = document.getElementById('avReset');
    var saveBtn  = document.getElementById('avSave');
    var filePick = document.getElementById('avFile');
    var formInput = document.querySelector('[data-avatar-input]');
    var openers   = document.querySelectorAll('[data-avatar-open]');
    var bodyOverflow = '';
    var st = { url: '', w: 0, h: 0, base: 1, zoom: 1, x: 0, y: 0 };

    function stageSize() { return stage.clientWidth || 300; }

    function clampOffsets() {
      var S = stageSize();
      var dw = st.w * st.base * st.zoom;
      var dh = st.h * st.base * st.zoom;
      var maxX = Math.max(0, (dw - S) / 2);
      var maxY = Math.max(0, (dh - S) / 2);
      st.x = Math.min(maxX, Math.max(-maxX, st.x));
      st.y = Math.min(maxY, Math.max(-maxY, st.y));
    }

    function render() {
      if (!st.w) return;
      clampOffsets();
      var S = stageSize();
      var scale = st.base * st.zoom;
      var dw = st.w * scale;
      var dh = st.h * scale;
      img.style.width = dw + 'px';
      img.style.height = dh + 'px';
      img.style.left = ((S - dw) / 2 + st.x) + 'px';
      img.style.top = ((S - dh) / 2 + st.y) + 'px';
    }

    function resetView() {
      st.zoom = 1;
      st.x = 0;
      st.y = 0;
      if (zoom) zoom.value = '1';
      render();
    }

    function loadFile(file) {
      if (!file) return;
      if (!/^image\/(jpeg|png|webp)$/i.test(file.type || '')) {
        window.alert('请选择 JPG / PNG / WebP 图片');
        return;
      }
      if (file.size > 8 * 1024 * 1024) {
        window.alert('图片不能超过 8MB');
        return;
      }
      var url = URL.createObjectURL(file);
      var probe = new Image();
      probe.onload = function () {
        if (st.url) URL.revokeObjectURL(st.url);
        st.url = url;
        st.w = probe.naturalWidth;
        st.h = probe.naturalHeight;
        var S = stageSize();
        st.base = Math.max(S / st.w, S / st.h);
        img.src = url;
        img.hidden = false;
        if (drop) drop.hidden = true;
        if (tools) tools.hidden = false;
        if (saveBtn) saveBtn.disabled = false;
        resetView();
      };
      probe.onerror = function () {
        URL.revokeObjectURL(url);
        window.alert('图片读取失败，换一张试试');
      };
      probe.src = url;
    }

    function open() {
      bodyOverflow = document.body.style.overflow;
      modal.hidden = false;
      document.body.classList.add('sg-modal-lock');
      document.body.style.overflow = 'hidden';
      if (!st.w && img) { img.hidden = true; }
      if (st.w) render();
    }

    function close() {
      modal.hidden = true;
      document.body.classList.remove('sg-modal-lock');
      document.body.style.overflow = bodyOverflow;
    }

    openers.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        open();
      });
    });

    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-avatar-close]')) close();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) close();
    });

    if (filePick) {
      filePick.addEventListener('change', function () {
        loadFile(filePick.files && filePick.files[0]);
        filePick.value = '';
      });
    }

    if (zoom) {
      zoom.addEventListener('input', function () {
        st.zoom = Math.max(1, Math.min(3, parseFloat(zoom.value) || 1));
        render();
      });
    }

    if (resetBtn) resetBtn.addEventListener('click', resetView);

    var drag = null;
    stage.addEventListener('pointerdown', function (e) {
      if (!st.w) return;
      drag = { id: e.pointerId, x: e.clientX, y: e.clientY, ox: st.x, oy: st.y };
      stage.classList.add('is-drag');
      try { stage.setPointerCapture(e.pointerId); } catch (err) {}
      e.preventDefault();
    });
    stage.addEventListener('pointermove', function (e) {
      if (!drag || drag.id !== e.pointerId) return;
      st.x = drag.ox + (e.clientX - drag.x);
      st.y = drag.oy + (e.clientY - drag.y);
      render();
    });
    function endDrag(e) {
      if (!drag || (e && drag.id !== e.pointerId)) return;
      drag = null;
      stage.classList.remove('is-drag');
    }
    stage.addEventListener('pointerup', endDrag);
    stage.addEventListener('pointercancel', endDrag);
    stage.addEventListener('lostpointercapture', endDrag);

    stage.addEventListener('wheel', function (e) {
      if (!st.w) return;
      e.preventDefault();
      st.zoom = Math.max(1, Math.min(3, st.zoom - e.deltaY * 0.002));
      if (zoom) zoom.value = String(st.zoom);
      render();
    }, { passive: false });

    window.addEventListener('resize', function () {
      if (!st.w) return;
      var S = stageSize();
      st.base = Math.max(S / st.w, S / st.h);
      render();
    });

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        if (!st.w) return;
        var S = stageSize();
        var factor = 512 / S;
        var scale = st.base * st.zoom;
        var dw = st.w * scale;
        var dh = st.h * scale;
        var left = (S - dw) / 2 + st.x;
        var top = (S - dh) / 2 + st.y;
        var canvas = document.createElement('canvas');
        canvas.width = 512;
        canvas.height = 512;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, 512, 512);
        ctx.drawImage(img, left * factor, top * factor, dw * factor, dh * factor);

        canvas.toBlob(function (blob) {
          if (!blob) return;
          var name = 'avatar-' + Date.now() + '.jpg';
          var file = new File([blob], name, { type: 'image/jpeg' });

          if (formInput) {
            try {
              var dt = new DataTransfer();
              dt.items.add(file);
              formInput.files = dt.files;
            } catch (err) {
              /* 不支持 DataTransfer 时退回原图上传 */
              if (filePick && filePick.files && filePick.files[0]) {
                try { formInput.files = filePick.files; } catch (e2) {}
              }
            }
          }

          var preview = document.querySelector('.avatar-edit .avatar-lg');
          if (preview) {
            var pvImg = preview.querySelector('img');
            var pvUrl = URL.createObjectURL(blob);
            if (pvImg) {
              pvImg.src = pvUrl;
            } else {
              preview.innerHTML = '<img src="' + pvUrl + '" alt="">';
            }
          }
          var removeBox = document.querySelector('input[name="remove_avatar"]');
          if (removeBox) removeBox.checked = false;
          close();
        }, 'image/jpeg', 0.9);
      });
    }
  }

  function initSelectAll() {
    var masters = Array.prototype.slice.call(document.querySelectorAll('[data-check-all]'));
    if (!masters.length) return;
    function boxes() {
      return Array.prototype.slice.call(document.querySelectorAll('input[type="checkbox"][name="ids[]"]'));
    }
    function sync() {
      var list = boxes();
      var all = list.length > 0 && list.every(function (c) { return c.checked; });
      masters.forEach(function (m) {
        m.classList.toggle('on', all);
        m.setAttribute('aria-checked', all ? 'true' : 'false');
      });
    }
    masters.forEach(function (m) {
      m.setAttribute('role', 'checkbox');
      m.setAttribute('aria-checked', 'false');
      m.setAttribute('tabindex', '0');
      m.addEventListener('click', function () {
        var list = boxes();
        var on = !(list.length > 0 && list.every(function (c) { return c.checked; }));
        list.forEach(function (c) {
          if (c.checked === on) return;
          c.checked = on;
          c.dispatchEvent(new Event('change', { bubbles: true }));
        });
        sync();
      });
      m.addEventListener('keydown', function (e) {
        if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); m.click(); }
      });
    });
    document.addEventListener('change', function (e) {
      if (e.target && e.target.matches && e.target.matches('input[name="ids[]"]')) sync();
    });
    sync();
  }

  function initAudioPreview() {
    var plays = Array.prototype.slice.call(document.querySelectorAll('[data-audio-play]'));
    plays.forEach(function (btn) {
      var el = btn.parentNode.querySelector('[data-audio-el]');
      if (!el) return;
      var label = btn.querySelector('[data-audio-label]');
      btn.addEventListener('click', function () {
        /* 播放请求还没落定时忽略连点，避免 play() 立刻被 pause() 打断 */
        if (el.getAttribute('data-play-pending') === '1') return;
        if (!el.paused) { el.pause(); return; }
        plays.forEach(function (other) {
          var o = other.parentNode.querySelector('[data-audio-el]');
          if (o && o !== el && !o.paused) o.pause();
        });
        var pr;
        var proxy = sgAudioProxyOf(el);
        try {
          el.setAttribute('data-play-pending', '1');
          pr = proxy ? sgAudioStart(el) : el.play();
        } catch (err) {
          el.removeAttribute('data-play-pending');
          if (window.SG_TOAST) window.SG_TOAST('试听失败：' + (err && err.message ? err.message : '音频无法解码'));
          return;
        }
        if (pr && pr.then) pr.then(function () {
          el.removeAttribute('data-play-pending');
        }).catch(function (err) {
          el.removeAttribute('data-play-pending');
          if (err && err.name === 'AbortError') return;
          if (window.SG_TOAST) {
            window.SG_TOAST(err && err.name === 'NotAllowedError'
              ? '浏览器拦截了试听，请再点一次'
              : '试听失败：' + (err && err.message ? err.message : '音频无法解码'));
          }
        });
      });
      el.addEventListener('play', function () {
        el.removeAttribute('data-play-pending');
        btn.classList.add('is-playing');
        if (label) label.textContent = '暂停';
      });
      el.addEventListener('pause', function () {
        el.removeAttribute('data-play-pending');
        btn.classList.remove('is-playing');
        if (label) label.textContent = '试听';
      });
      el.addEventListener('ended', function () {
        btn.classList.remove('is-playing');
        if (label) label.textContent = '试听';
        el.currentTime = 0;
      });
      if (sgAudioProxyOf(el)) sgAudioPrepare(el).catch(function () {});
    });
  }

  function initBroadcastTicker() {
    var root = document.querySelector('[data-bt-ticker]');
    if (!root) return;
    var items = root.querySelectorAll('.bt-item');
    if (items.length < 2) return;
    var index = 0;
    var timer = null;
    var paused = false;

    function show(n) {
      if (n === index) return;
      var prev = items[index];
      prev.classList.remove('on');
      prev.classList.add('out');
      var next = items[n];
      next.classList.remove('out');
      next.classList.add('on');
      index = n;
      // 旧一条滑出顶部后静默归位到底部，下一轮再从下方进入。
      setTimeout(function () {
        items.forEach(function (el) {
          if (!el.classList.contains('out')) return;
          el.style.transition = 'none';
          el.classList.remove('out');
          void el.offsetHeight;
          el.style.transition = '';
        });
      }, 650);
    }
    function start() {
      if (timer) return;
      var dwell = parseFloat(root.getAttribute('data-bt-dwell') || '5') * 1000;
      var interval = dwell + 580;
      timer = setInterval(function () {
        if (!paused) show((index + 1) % items.length);
      }, Math.max(1500, interval));
    }
    root.addEventListener('mouseenter', function () { paused = true; });
    root.addEventListener('mouseleave', function () { paused = false; });
    root.addEventListener('focusin', function () { paused = true; });
    root.addEventListener('focusout', function () { paused = false; });
    start();
  }

  function initSideScrollHint() {
    var sidebars = document.querySelectorAll('.sidebar');
    if (!sidebars.length) return;

    Array.prototype.forEach.call(sidebars, function (sidebar) {
      var hint = sidebar.querySelector('.side-scroll-hint');
      if (!hint) return;
      var raf = 0;

      function sync() {
        raf = 0;
        var max = sidebar.scrollHeight - sidebar.clientHeight;
        var arrowH = hint.offsetHeight || 0;
        var canScroll = max > arrowH + 4;
        var atBottom = (max - sidebar.scrollTop) <= 4;
        hint.classList.toggle('is-hidden', !canScroll || atBottom);
      }

      function queue() {
        if (raf) return;
        raf = window.requestAnimationFrame(sync);
      }

      sidebar.addEventListener('scroll', queue, { passive: true });
      window.addEventListener('resize', queue, { passive: true });
      window.addEventListener('orientationchange', queue, { passive: true });
      window.addEventListener('load', queue, { once: true });
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(queue, function () {});
      queue();
    });
  }

  function initAuthFlip() {
    var card = document.querySelector('[data-auth-flip]');
    if (!card) return;
    var faces = card.querySelectorAll('[data-auth-face]');
    var tabs = card.querySelectorAll('[data-auth-go]');
    var timer = null;
    var stopped = false;

    function apply(face) {
      card.setAttribute('data-face', face);
      faces.forEach(function (f) {
        var on = f.getAttribute('data-auth-face') === face;
        f.setAttribute('aria-hidden', on ? 'false' : 'true');
        if (on) f.removeAttribute('inert');
        else f.setAttribute('inert', '');
      });
      if (face === 'qr' && window.SG_QR_PANEL) {
        setTimeout(function () { window.SG_QR_PANEL.init(); }, 60);
      }
    }

    function stop() {
      stopped = true;
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    }

    function next() {
      if (stopped) return;
      var active = document.activeElement;
      if (active && card.contains(active) && active.matches && active.matches('input,textarea,select')) {
        stop();
        return;
      }
      if (card.getAttribute('data-face') === 'qr') return;
      apply(card.getAttribute('data-face') === 'login' ? 'register' : 'login');
    }

    if (card.getAttribute('data-auto') === '1') {
      timer = setInterval(next, 7000);
    }

    card.addEventListener('pointerdown', stop, { passive: true });
    card.addEventListener('focusin', stop);
    card.addEventListener('keydown', stop);

    tabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        stop();
        apply(btn.getAttribute('data-auth-go'));
      });
    });

    apply(card.getAttribute('data-face'));
  }

  function initScanLogin() {
    var btn = document.querySelector('[data-scan-open]');
    var overlay = document.getElementById('scanOverlay');
    if (!btn || !overlay) return;
    var video = document.getElementById('scanVideo');
    var tip = document.getElementById('scanTip');
    var loading = document.getElementById('scanLoading');
    var file = document.getElementById('scanFile');
    var gallery = document.getElementById('scanGallery');
    if (!video || !tip || !loading || !file || !gallery) return;

    var stream = null;
    var timer = 0;
    var detector = null;
    var detectorFailed = false;
    var detecting = false;
    var handled = false;
    var open = false;
    var canvas = null;
    var ctx = null;
    var jsqrState = 0;
    var prevOverflow = '';

    function setTip(text, err) {
      tip.textContent = text;
      tip.classList.toggle('is-err', !!err);
    }
    function setLoading(show) {
      loading.hidden = !show;
    }
    /* 只用 jsQR：Android WebView 的 BarcodeDetector 在部分机型上会卡住 Promise，
       一旦 detect() 不返回，页面既解不出码又会严重掉帧，所以彻底不用原生检测器。 */
    function getDetector() { return null; }
    function loadJsQr(cb) {
      if (window.jsQR) { cb(null); return; }
      if (jsqrState === 3) { cb(new Error('load')); return; }
      if (jsqrState === 1) { cb(new Error('loading')); return; }
      jsqrState = 1;
      var s = document.createElement('script');
      s.async = true;
      s.src = overlay.getAttribute('data-jsqr') || '';
      s.onload = function () { jsqrState = 2; cb(null); };
      s.onerror = function () { jsqrState = 3; cb(new Error('load')); };
      document.head.appendChild(s);
    }
    function stopStream() {
      if (timer) { clearTimeout(timer); timer = 0; }
      detecting = false;
      if (stream) {
        try {
          stream.getTracks().forEach(function (track) { track.stop(); });
        } catch (e) {}
        stream = null;
      }
      try { video.pause(); } catch (e) {}
      try { video.srcObject = null; } catch (e) {}
    }
    function close() {
      if (!open) return;
      open = false;
      handled = true;
      stopStream();
      setLoading(false);
      overlay.hidden = true;
      document.body.style.overflow = prevOverflow;
    }
    function acceptQr(raw) {
      if (!open || handled) return;
      var text = String(raw || '').trim();
      if (!text) return;
      var u;
      try { u = new URL(text, location.href); } catch (e) {
        setTip('二维码内容无法识别，请扫本站的登录二维码', true);
        return;
      }
      if (u.host !== location.host) {
        setTip('这不是本站的二维码，已停止跳转', true);
        return;
      }
      if (!/\/qr-approve\.php$/.test(u.pathname)) {
        setTip('这不是登录二维码，请回到电脑上打开扫码登录', true);
        return;
      }
      if (!u.searchParams.get('t')) {
        setTip('登录二维码不完整，请在电脑上刷新后重扫', true);
        return;
      }
      handled = true;
      stopStream();
      setLoading(false);
      setTip('识别成功，正在打开确认页…');
      setTimeout(function () {
        location.href = location.origin + u.pathname + u.search + u.hash;
      }, 160);
    }
    function decodeCanvas(c, done) {
      loadJsQr(function (err) {
        if (err || !window.jsQR) { done(null); return; }
        var cctx = c.getContext('2d', {willReadFrequently: true});
        var img = cctx.getImageData(0, 0, c.width, c.height);
        var code = window.jsQR(img.data, img.width, img.height, {inversionAttempts: 'dontInvert'});
        done(code && code.data ? code.data : null);
      });
    }
    function detectCanvas(c, done) {
      decodeCanvas(c, done);
    }
    var scanFrame = 0;
    function fallbackTick() {
      if (!window.jsQR) {
        if (jsqrState === 0) {
          loadJsQr(function (err) {
            if (err && err.message !== 'loading' && !getDetector()) {
              setTip('扫码组件加载失败，请检查网络后重试', true);
            }
          });
        }
        return;
      }
      if (!video.videoWidth || !video.videoHeight) return;
      if (!canvas) {
        canvas = document.createElement('canvas');
        ctx = canvas.getContext('2d', {willReadFrequently: true});
      }
      /* 只看取景框中心的正方形再放大：手机离屏幕较远时二维码只占画面一小块，
         整帧缩到 360 宽会糊到解不出。中心裁 76% 后放大到 480，接近截图识别效果。 */
      var side = Math.min(video.videoWidth, video.videoHeight) * 0.76;
      var sx = (video.videoWidth - side) / 2;
      var sy = (video.videoHeight - side) / 2;
      canvas.width = 480;
      canvas.height = 480;
      try {
        ctx.drawImage(video, sx, sy, side, side, 0, 0, 480, 480);
      } catch (e) { return; }
      var img = ctx.getImageData(0, 0, 480, 480);
      scanFrame++;
      /* 大部分是深色码；每 4 帧试一次反色，兼顾屏幕反光/深色模式 */
      var code = window.jsQR(img.data, 480, 480, {
        inversionAttempts: (scanFrame % 4 === 0) ? 'attemptBoth' : 'dontInvert'
      });
      if (code && code.data) acceptQr(code.data);
    }
    function tick() {
      if (!open || !stream || handled) return;
      if (video.readyState < 2) {
        timer = setTimeout(tick, 180);
        return;
      }
      fallbackTick();
      timer = setTimeout(tick, 300);
    }
    function startCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setLoading(false);
        setTip('当前浏览器不支持相机扫码，可以点下面从相册选择二维码', true);
        return;
      }
      navigator.mediaDevices.getUserMedia({
        audio: false,
        video: {
          facingMode: {ideal: 'environment'},
          width: {ideal: 640},
          height: {ideal: 480},
          frameRate: {ideal: 24, max: 30},
          focusMode: {ideal: 'continuous'}
        }
      }).then(function (s) {
        if (!open) {
          try { s.getTracks().forEach(function (track) { track.stop(); }); } catch (e) {}
          return null;
        }
        stream = s;
        video.srcObject = s;
        var p = video.play();
        return p && p.then ? p.catch(function () {}) : null;
      }).then(function () {
        if (!open) return;
        setLoading(false);
        setTip('把电脑上的登录二维码放进框内，保持 1-2 秒');
        tick();
      }).catch(function (err) {
        if (!open) return;
        setLoading(false);
        var msg = '打不开相机，可以点下面从相册选择二维码';
        if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) {
          msg = '相机权限被拒绝，请在浏览器里允许相机，或从相册选择二维码';
        } else if (err && err.name === 'NotFoundError') {
          msg = '没有找到可用相机，请从相册选择二维码';
        }
        setTip(msg, true);
      });
    }
    function decodeFile(f) {
      if (!f) return;
      if (f.size && f.size > 12 * 1024 * 1024) {
        setTip('图片太大，请选择 12MB 以内的二维码截图', true);
        return;
      }
      var url = URL.createObjectURL(f);
      var img = new Image();
      img.onload = function () {
        var max = Math.max(img.naturalWidth, img.naturalHeight);
        var scale = Math.min(1, 900 / max);
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * scale));
        c.height = Math.max(1, Math.round(img.naturalHeight * scale));
        var cctx = c.getContext('2d', {willReadFrequently: true});
        cctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        detectCanvas(c, function (text) {
          if (text) acceptQr(text);
          else setTip('没识别到二维码，请换一张更清晰的电脑屏幕截图', true);
        });
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        setTip('这张图片打不开，请换一张二维码截图', true);
      };
      img.src = url;
    }
    function openScanner() {
      if (open) return;
      open = true;
      handled = false;
      detecting = false;
      prevOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      overlay.hidden = false;
      setLoading(true);
      setTip('把电脑上的登录二维码放进框内');
      loadJsQr(function (err) {
        if (err && err.message !== 'loading' && !getDetector()) {
          setTip('扫码组件加载失败，请检查网络后重试', true);
        }
      });
      startCamera();
    }

    btn.addEventListener('click', openScanner);
    Array.prototype.forEach.call(overlay.querySelectorAll('[data-scan-close]'), function (el) {
      el.addEventListener('click', close);
    });
    gallery.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () {
      decodeFile(file.files && file.files[0]);
      file.value = '';
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && open) close();
    });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden && open) close();
    });
    window.addEventListener('pagehide', stopStream);
    /* Android 桌面长按“扫一扫”快捷方式带 ?scan=1 直接打开相机 */
    try {
      if (new URLSearchParams(location.search).get('scan') === '1') {
        setTimeout(function () { openScanner(); }, 700);
      }
    } catch (e) {}
  }

  function init() {
    hydrate();
    initShopFold();
    initScrollMemory();
    initLiveSearch();
    renderShopBadges();
    initCategoryTrain();
    initCardMarquee();
    initBroadcastTicker();
    initSideScrollHint();
    initScanLogin();
    initWelcome();
    initWelcomeAudio();
    initAuthFlip();
    initCommentLikes();
    initAvatarCrop();
    initAudioPreview();
    initSelectAll();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
window.__sgAppLoaded = true;
