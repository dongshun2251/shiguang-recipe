/* 食光菜谱 · 采购清单页 */
(function () {
  'use strict';
  var box = document.getElementById('shopList');
  if (!box) return;
  var KEY = 'sg_shopping';

  function read() {
    try { return JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; }
  }
  function write(list) {
    try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
  }
  var ZONE_KEY = 'sg_shop_zone';
  var ZONE_RULES = [
    ['蔬菜水果', ['菜','番茄','西红柿','土豆','萝卜','茄子','黄瓜','青椒','辣椒','洋葱','蒜','姜','葱','芹','豆角','西兰花','菠菜','白菜','生菜','南瓜','冬瓜','玉米','香菇','蘑菇','柠檬','苹果','香菜','韭','藕','山药']],
    ['肉蛋水产', ['肉','牛','猪','鸡','鸭','羊','排骨','五花','鱼','虾','蟹','贝','蛋','培根','香肠','火腿','牛腩']],
    ['米面主食', ['米','面','粉','馒','面包','饺子','馄饨','年糕','粉丝','淀粉','吐司','意面']],
    ['调料', ['盐','糖','酱','醋','料酒','蚝油','胡椒','花椒','八角','桂皮','香叶','孜然','味精','鸡精','油','豆瓣','辣椒粉','五香','香油','蜂蜜']],
    ['干货豆制', ['豆腐','豆干','腐竹','木耳','银耳','海带','紫菜','花生','芝麻','红枣','枸杞','干辣椒','香菇干','粉丝干']]
  ];
  function zoneOf(name) {
    var n = String(name || '');
    for (var i = 0; i < ZONE_RULES.length; i++) {
      var words = ZONE_RULES[i][1];
      for (var j = 0; j < words.length; j++) {
        if (n.indexOf(words[j]) !== -1) return ZONE_RULES[i][0];
      }
    }
    return '其他';
  }
  function zoneOn() { return localStorage.getItem(ZONE_KEY) === '1'; }

  function group(list) {
    var g = {}, order = [];
    list.forEach(function (it) {
      var k = zoneOn() ? zoneOf(it.n) : (it.f || '其他');
      if (!g[k]) { g[k] = []; order.push(k); }
      g[k].push(it);
    });
    return order.map(function (k) { return { from: k, items: g[k] }; });
  }

  function render() {
    var list = read();
    box.innerHTML = '';
    var left = list.filter(function (i) { return !i.done; }).length;
    document.getElementById('shopMeta').textContent = left + ' 项待买 · 共 ' + list.length + ' 项';
    document.getElementById('shopEmpty').hidden = list.length > 0;

    group(list).forEach(function (grp) {
      var wrap = document.createElement('div');
      wrap.className = 'shop-group';
      var h = document.createElement('div');
      h.className = 'shop-group-h';
      h.textContent = grp.from;
      wrap.appendChild(h);

      grp.items.forEach(function (it) {
        var row = document.createElement('div');
        row.className = 'shop-item' + (it.done ? ' done' : '');

        var ck = document.createElement('button');
        ck.type = 'button';
        ck.className = 'shop-ck' + (it.done ? ' on' : '');
        ck.setAttribute('aria-label', it.done ? '标记为未买' : '标记为已买');
        ck.innerHTML = '<span class="ico"></span>';
        ck.addEventListener('click', function () {
          var l = read();
          l.forEach(function (x) { if (x.n === it.n && x.f === it.f) { x.done = !x.done; } });
          write(l); render();
        });

        var nm = document.createElement('span');
        nm.className = 'shop-nm';
        nm.textContent = it.n;
        var q = document.createElement('span');
        q.className = 'shop-q';
        q.textContent = it.q;
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'shop-del';
        del.setAttribute('aria-label', '删除');
        del.textContent = '×';
        del.addEventListener('click', function () {
          write(read().filter(function (x) { return !(x.n === it.n && x.f === it.f); }));
          render();
        });

        row.appendChild(ck); row.appendChild(nm); row.appendChild(q); row.appendChild(del);
        wrap.appendChild(row);
      });
      box.appendChild(wrap);
    });
  }

  function copyText() {
    var list = read();
    var t = '采购清单\n';
    group(list).forEach(function (g) {
      t += '\n【' + g.from + '】\n';
      g.items.forEach(function (i) { t += (i.done ? '√ ' : '· ') + i.n + ' ' + i.q + '\n'; });
    });
    if (navigator.clipboard) {
      navigator.clipboard.writeText(t).then(function () {
        if (window.SG_TOAST) SG_TOAST('清单已复制');
      });
    } else if (window.SG_TOAST) {
      SG_TOAST('浏览器不支持复制，请手动选择文字');
    }
  }

  function saveImage() {
    var list = read();
    if (!list.length) { if (window.SG_TOAST) SG_TOAST('清单是空的'); return; }
    var lines = [];
    group(list).forEach(function (g) {
      lines.push('§' + g.from);
      g.items.forEach(function (i) { lines.push((i.done ? '√ ' : '· ') + i.n + '  ' + i.q); });
    });
    var W = 720, LH = 46, PAD = 44;
    var H = PAD * 2 + 86 + lines.length * LH;
    var cv = document.createElement('canvas');
    var dpr = 2;
    cv.width = W * dpr; cv.height = H * dpr;
    var ctx = cv.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.fillStyle = '#FFFDFA'; ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#C8502B'; ctx.fillRect(0, 0, W, 8);
    ctx.fillStyle = '#2A2119'; ctx.font = '700 34px serif';
    ctx.fillText('食光菜谱 · 采购清单', PAD, PAD + 36);
    ctx.fillStyle = '#8A8175'; ctx.font = '20px sans-serif';
    ctx.fillText(new Date().toLocaleDateString('zh-CN'), PAD + 6, PAD + 70);
    var y = PAD + 110;
    lines.forEach(function (ln) {
      if (ln.charAt(0) === '§') {
        ctx.fillStyle = '#C8502B'; ctx.font = '700 24px sans-serif';
        ctx.fillText('【' + ln.slice(1) + '】', PAD, y);
      } else {
        var done = ln.charAt(0) === '√';
        ctx.fillStyle = done ? '#B5AB9C' : '#2A2119';
        ctx.font = '24px sans-serif';
        ctx.fillText(ln, PAD + 6, y);
        if (done) {
          var w = ctx.measureText(ln).width;
          ctx.strokeStyle = '#B5AB9C'; ctx.beginPath();
          ctx.moveTo(PAD + 6, y - 8); ctx.lineTo(PAD + 6 + w, y - 8); ctx.stroke();
        }
      }
      y += LH;
    });
    var a = document.createElement('a');
    a.download = '采购清单-' + new Date().toISOString().slice(0, 10) + '.png';
    a.href = cv.toDataURL('image/png');
    a.click();
    if (window.SG_TOAST) SG_TOAST('已生成图片，请到下载目录查看');
  }

  var zoneBtn = document.getElementById('shopZone');
  function syncZone() {
    if (!zoneBtn) return;
    zoneBtn.classList.toggle('on', zoneOn());
    zoneBtn.textContent = zoneOn() ? '按菜名分组' : '按超市分区';
  }
  if (zoneBtn) {
    zoneBtn.addEventListener('click', function () {
      localStorage.setItem(ZONE_KEY, zoneOn() ? '0' : '1');
      syncZone(); render();
      if (window.SG_TOAST) SG_TOAST(zoneOn() ? '已按蔬菜/肉蛋/调料分区' : '已按菜名分组');
    });
  }
  syncZone();

  document.getElementById('shopCopy').addEventListener('click', copyText);
  document.getElementById('shopImg').addEventListener('click', saveImage);
  document.getElementById('shopClearDone').addEventListener('click', function () {
    write(read().filter(function (i) { return !i.done; }));
    render();
    if (window.SG_TOAST) SG_TOAST('已清掉买过的');
  });
  document.getElementById('shopClearAll').addEventListener('click', function () {
    if (!confirm('清空整份采购清单？')) return;
    write([]); render();
    if (window.SG_TOAST) SG_TOAST('清单已清空');
  });

  render();
})();
