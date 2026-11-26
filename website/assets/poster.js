/* 食光菜谱 · 菜谱长图生成
   把详情页数据画成一张竖版分享图（1080 宽），无需服务端渲染。 */
(function () {
  'use strict';

  var W = 1080;
  var PAD = 64;
  var CREAM = '#FBF6EA';
  var INK = '#2A211B';
  var MUTED = '#7A6A5C';
  var BRAND = (function () {
    try {
      var v = getComputedStyle(document.documentElement).getPropertyValue('--brand');
      return v && v.trim() ? v.trim() : '#D0502E';
    } catch (e) { return '#D0502E'; }
  })();
  var LINE = 'rgba(42,33,27,.14)';

  var FONT = '"PingFang SC","Hiragino Sans GB","Noto Sans CJK SC","Microsoft YaHei",sans-serif';
  var SERIF = '"Songti SC","Noto Serif CJK SC","Source Han Serif SC",serif';

  function $(s, r) { return (r || document).querySelector(s); }

  function loadImage(src) {
    return new Promise(function (resolve) {
      if (!src) return resolve(null);
      var img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function () { resolve(img); };
      img.onerror = function () { resolve(null); };
      img.src = src;
    });
  }

  function mbFirst(s) {
    s = String(s || '');
    return s.slice(0, 1);
  }

  function ellipsis(ctx, text, maxW) {
    text = String(text == null ? '' : text);
    if (ctx.measureText(text).width <= maxW) return text;
    var cur = '';
    for (var i = 0; i < text.length; i++) {
      if (ctx.measureText(cur + text[i] + '…').width > maxW) break;
      cur += text[i];
    }
    return cur + '…';
  }

  function roundRect(ctx, x, y, w, h, r) {    r = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  /* 逐字折行，中文友好；返回行数组 */
  function wrap(ctx, text, maxW) {
    var lines = [];
    var cur = '';
    var s = String(text == null ? '' : text);
    for (var i = 0; i < s.length; i++) {
      var ch = s[i];
      if (ch === '\n') { lines.push(cur); cur = ''; continue; }
      var t = cur + ch;
      if (ctx.measureText(t).width > maxW && cur !== '') {
        lines.push(cur);
        cur = ch;
      } else {
        cur = t;
      }
    }
    if (cur !== '') lines.push(cur);
    return lines;
  }

  function drawCover(ctx, img, x, y, w, h) {
    roundRect(ctx, x, y, w, h, 18);
    ctx.save();
    ctx.clip();
    if (img) {
      var iw = img.naturalWidth || img.width;
      var ih = img.naturalHeight || img.height;
      var scale = Math.max(w / iw, h / ih);
      var dw = iw * scale, dh = ih * scale;
      ctx.drawImage(img, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
    } else {
      ctx.fillStyle = '#EADFCB';
      ctx.fillRect(x, y, w, h);
    }
    ctx.restore();
  }

  function drawPoster(data, coverImg, ingImgs) {
    ingImgs = ingImgs || [];
    var tmp = document.createElement('canvas');
    tmp.width = W;
    tmp.height = 8000;
    var c = tmp.getContext('2d');
    var innerW = W - PAD * 2;

    /* 底色与边框先画，裁切时一起带走 */
    c.fillStyle = CREAM;
    c.fillRect(0, 0, W, tmp.height);
    c.strokeStyle = 'rgba(42,33,27,.10)';
    c.lineWidth = 2;
    c.strokeRect(20, 20, W - 40, tmp.height - 40);

    var y = PAD;

    /* ---- 品牌行 ---- */
    c.textBaseline = 'middle';
    c.fillStyle = BRAND;
    c.font = '700 26px ' + SERIF;
    c.fillText(data.brand || '食光菜谱', PAD, y + 14);
    var brandW = c.measureText(data.brand || '食光菜谱').width;
    c.fillStyle = MUTED;
    c.font = '400 22px ' + FONT;
    var metaLine = data.steps.length + ' 步 · 共 ' + (data.ingredients.length) + ' 种食材';
    c.fillText(metaLine, PAD + brandW + 20, y + 14);
    c.strokeStyle = LINE;
    c.beginPath();
    c.moveTo(PAD, y + 44);
    c.lineTo(W - PAD, y + 44);
    c.stroke();

    y += 60;
    c.textBaseline = 'alphabetic';

    /* ---- 标题 ---- */
    var titleSize = 88;
    c.fillStyle = INK;
    c.font = '700 ' + titleSize + 'px ' + SERIF;
    wrap(c, data.title, innerW).forEach(function (ln) {
      y += titleSize;
      c.fillText(ln, PAD, y);
      y += titleSize * 0.18;
    });

    /* ---- 摘要 ---- */
    if (data.summary) {
      y += 22;
      c.fillStyle = MUTED;
      c.font = '400 30px ' + FONT;
      wrap(c, data.summary, innerW).forEach(function (ln) {
        y += 44;
        c.fillText(ln, PAD, y);
      });
    }

    /* ---- 主图 ---- */
    if (coverImg) {
      y += 36;
      drawCover(c, coverImg, PAD, y, innerW, 620);
      y += 620;
    }

    /* ---- 食材 ---- */
    y += 56;
    c.fillStyle = INK;
    c.font = '700 36px ' + SERIF;
    c.fillText('食材', PAD, y + 6);
    var labW = c.measureText('食材').width;
    if (data.servings) {
      c.fillStyle = MUTED;
      c.font = '400 24px ' + FONT;
      c.fillText('· ' + data.servings, PAD + labW + 14, y + 4);
    }
    y += 46;

    var gap = 16;
    var chipW = (innerW - gap) / 2;
    var ingRowH = 96;
    data.ingredients.forEach(function (ing, i) {
      var col = i % 2;
      var row = Math.floor(i / 2);
      var x = PAD + col * (chipW + gap);
      var cy = y + row * (ingRowH + gap);
      c.fillStyle = '#FFFFFF';
      roundRect(c, x, cy, chipW, ingRowH, 12);
      c.fill();
      c.strokeStyle = 'rgba(42,33,27,.10)';
      c.lineWidth = 1.5;
      c.stroke();

      /* 圆形食材图（无图用首字圆底） */
      var img = ingImgs[i] || null;
      var ccx = x + 18 + 32, ccy = cy + 16 + 32;
      c.save();
      c.beginPath();
      c.arc(ccx, ccy, 32, 0, Math.PI * 2);
      c.clip();
      if (img) {
        var iw = img.naturalWidth || img.width, ih = img.naturalHeight || img.height;
        var sc = Math.max(64 / iw, 64 / ih);
        c.drawImage(img, ccx - iw * sc / 2, ccy - ih * sc / 2, iw * sc, ih * sc);
      } else {
        c.fillStyle = '#EFE6D6';
        c.fillRect(ccx - 32, ccy - 32, 64, 64);
      }
      c.restore();
      if (!img) {
        c.fillStyle = '#A98F6F';
        c.font = '700 26px ' + SERIF;
        c.textAlign = 'center';
        c.textBaseline = 'middle';
        c.fillText(mbFirst(ing.name), ccx, ccy + 1);
        c.textAlign = 'left';
      }

      var qtyText = ing.qty || '';
      var tx = x + 100;
      var tw = chipW - 100 - 18;
      c.textBaseline = 'alphabetic';
      c.fillStyle = INK;
      c.font = '500 27px ' + FONT;
      c.fillText(ellipsis(c, ing.name, tw), tx, cy + 40);
      if (qtyText) {
        c.fillStyle = MUTED;
        c.font = '400 23px ' + FONT;
        c.fillText(ellipsis(c, qtyText, tw), tx, cy + 72);
      }
    });
    c.textBaseline = 'alphabetic';
    y += Math.max(1, Math.ceil(data.ingredients.length / 2)) * (ingRowH + gap);

    /* ---- 步骤 ---- */
    y += 44;
    c.fillStyle = INK;
    c.font = '700 36px ' + SERIF;
    c.fillText('步骤', PAD, y + 6);
    y += 50;

    data.steps.forEach(function (s, i) {
      var cy = y;
      c.fillStyle = BRAND;
      c.beginPath();
      c.arc(PAD + 27, cy + 27, 27, 0, Math.PI * 2);
      c.fill();
      c.fillStyle = '#FFF';
      c.font = '700 26px ' + FONT;
      c.textAlign = 'center';
      c.textBaseline = 'middle';
      c.fillText(String(i + 1), PAD + 27, cy + 29);
      c.textAlign = 'left';
      c.textBaseline = 'alphabetic';

      var tx = PAD + 78;
      var tw = innerW - 78;
      var ty = cy;
      if (s.title) {
        c.fillStyle = INK;
        c.font = '600 32px ' + FONT;
        c.fillText(s.title, tx, ty + 36);
        ty += 56;
      } else {
        ty += 12;
      }
      c.fillStyle = '#4A3F36';
      c.font = '400 28px ' + FONT;
      wrap(c, s.content || '', tw).forEach(function (ln) {
        ty += 44;
        c.fillText(ln, tx, ty);
      });
      if (s.duration) {
        ty += 32;
        c.fillStyle = MUTED;
        c.font = '400 24px ' + FONT;
        c.fillText('约 ' + s.duration, tx, ty);
        ty += 2;
      }
      y = ty + 40;
    });

    /* ---- 页脚 ---- */
    y += 30;
    c.strokeStyle = LINE;
    c.beginPath();
    c.moveTo(PAD, y);
    c.lineTo(W - PAD, y);
    c.stroke();
    c.fillStyle = INK;
    c.font = '700 30px ' + SERIF;
    c.textAlign = 'center';
    c.fillText(data.slogan || '跟着做，一学就会', W / 2, y + 62);
    c.fillStyle = MUTED;
    c.font = '400 22px ' + FONT;
    c.fillText(data.siteName || '', W / 2, y + 104);
    c.textAlign = 'left';

    var H = Math.round(y + 104 + PAD);

    /* ---- 裁剪成实际高度 ---- */
    var canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = CREAM;
    ctx.fillRect(0, 0, W, H);
    ctx.drawImage(tmp, 0, 0, W, H, 0, 0, W, H);
    ctx.strokeStyle = 'rgba(42,33,27,.10)';
    ctx.lineWidth = 2;
    ctx.strokeRect(20, 20, W - 40, H - 40);

    window.__sgPoster = canvas;
    return canvas;
  }

  function shareText(data) {
    var lines = [];
    lines.push('【' + data.title + '】');
    if (data.summary) lines.push(data.summary);
    if (data.servings) lines.push('份量：' + data.servings);
    if (data.ingredients && data.ingredients.length) {
      lines.push('食材：' + data.ingredients.map(function (i) {
        return i.name + (i.qty ? ' ' + i.qty : '');
      }).join('、'));
    }
    if (data.steps && data.steps.length) {
      lines.push('做法：');
      data.steps.forEach(function (s, i) {
        var head = s.title ? s.title + '：' : '';
        lines.push((i + 1) + '. ' + head + (s.content || ''));
      });
    }
    if (data.siteName) lines.push('—— 来自 ' + data.siteName);
    return lines.join('\n');
  }

  function copyText(t) {
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = t;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      ta.remove();
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(t).catch(fallback);
    }
    fallback();
    return Promise.resolve();
  }

  function save(canvas, data) {
    canvas.toBlob(function (blob) {
      if (!blob) return;
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = '食光菜谱-' + (new Date().toISOString().slice(0, 10)) + '.png';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
      copyText(shareText(data)).then(function () {
        if (window.SG_TOAST) window.SG_TOAST('长图已保存，分享文案已复制');
      });
    }, 'image/png');
  }

  function run() {
    var el = document.getElementById('sg-poster-data');
    if (!el) return;
    var data;
    try { data = JSON.parse(el.textContent); } catch (e) { return; }
    var btn = document.querySelector('[data-poster]');
    var oldTxt = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '生成中…'; }

    var ingSrc = (data.ingredients || []).map(function (i) { return i.image || ''; });
    Promise.all([loadImage(data.cover)].concat(ingSrc.map(loadImage))).then(function (imgs) {
      var cover = imgs[0];
      var ingImgs = imgs.slice(1);
      try {
        save(drawPoster(data, cover, ingImgs), data);
      } catch (e) {
        if (window.SG_TOAST) window.SG_TOAST('长图生成失败，请刷新后重试');
        else console.warn(e);
      }
      if (btn) { btn.disabled = false; btn.innerHTML = oldTxt; if (window.SG_HYDRATE) window.SG_HYDRATE(); }
    });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-poster]');
    if (!b) return;
    e.preventDefault();
    run();
  });
})();
