/* 食光菜谱 · 烹饪模式（独立页面 cook.php） */
(function () {
  'use strict';
  var box = document.getElementById('sg-cook-data');
  if (!box) return;
  var data;
  try { data = JSON.parse(box.textContent); } catch (e) { return; }
  var steps = data.steps || [];
  if (!steps.length) return;

  var i = 0, timer = null, left = 0;
  var no = document.getElementById('cookNo');
  var tot = document.getElementById('cookTotal');
  var title = document.getElementById('cookStepTitle');
  var body = document.getElementById('cookStepBody');
  var photos = document.getElementById('cookStepPhotos');
  var bar = document.getElementById('cookBar');
  var tbox = document.getElementById('cookTimerBox');
  var clock = document.getElementById('cookClock');
  var toggle = document.getElementById('cookToggle');
  var reset = document.getElementById('cookReset');
  var prev = document.getElementById('cookPrev');
  var next = document.getElementById('cookNext');
  var done = document.getElementById('cookDone');
  var dots = [].slice.call(document.querySelectorAll('#cookDots button'));
  var lis = [].slice.call(document.querySelectorAll('.cook-li'));

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function parseSec(d) {
    if (!d) return 0;
    var m = String(d).match(/(\d+(?:\.\d+)?)/g);
    if (!m) return 0;
    var v = parseFloat(m[0]);
    if (/小时|h/i.test(d)) return Math.round(v * 3600);
    return Math.round(v * 60);
  }
  function render() {
    var s = steps[i];
    no.textContent = pad(i + 1);
    tot.textContent = '/ ' + pad(steps.length);
    title.textContent = s.t;
    body.innerHTML = (s.c || '').replace(/\n/g, '<br>');
    if (photos) {
      if (s.p && s.p.length) {
        photos.innerHTML = s.p.map(function (u) { return '<img src="' + u + '" alt="" loading="lazy">'; }).join('');
        photos.hidden = false;
      } else {
        photos.innerHTML = '';
        photos.hidden = true;
      }
    }
    bar.style.width = (100 * (i + 1) / steps.length) + '%';
    dots.forEach(function (b, k) { b.classList.toggle('on', k === i); });
    lis.forEach(function (b, k) { b.classList.toggle('on', k === i); });
    prev.disabled = i === 0;
    var last = i === steps.length - 1;
    next.hidden = last;
    done.hidden = !last;
    stop();
    var sec = parseSec(s.d);
    tbox.hidden = sec <= 0;
    if (sec > 0 && data.auto && !window.__sgCookStarted) {
      window.__sgCookStarted = true;
      start(sec);
    } else if (sec > 0) {
      left = sec;
      showClock();
    }
  }
  function showClock() {
    clock.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
  }
  function start(sec) {
    if (typeof sec === 'number') left = sec;
    toggle.textContent = '暂停';
    clearInterval(timer);
    timer = setInterval(function () {
      left--;
      if (left <= 0) {
        left = 0; showClock(); stop();
        if (window.SG_TOAST) SG_TOAST('这一步的时间到了');
        if (navigator.vibrate) navigator.vibrate([80, 40, 80]);
        return;
      }
      showClock();
    }, 1000);
    showClock();
  }
  function stop() {
    clearInterval(timer); timer = null;
    toggle.textContent = '开始计时';
  }
  toggle.addEventListener('click', function () {
    if (timer) { stop(); return; }
    if (left <= 0) left = parseSec(steps[i].d);
    if (left > 0) start();
  });
  reset.addEventListener('click', function () { stop(); left = parseSec(steps[i].d); showClock(); });
  prev.addEventListener('click', function () { if (i > 0) { i--; render(); } });
  next.addEventListener('click', function () { if (i < steps.length - 1) { i++; render(); } });
  dots.forEach(function (b) { b.addEventListener('click', function () { i = +b.getAttribute('data-jump'); render(); }); });

  render();
})();
