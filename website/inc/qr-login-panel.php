<?php
/* 登录卡里的扫码面板。由 inc/auth-card.php 引入，不再单独占用一个二维码页面。 */
$qrPanelError = '';
$challenge = $_SESSION['sg_qr_challenge'] ?? null;
$qr = $challenge ? sg_qr_find((string) ($challenge['token'] ?? '')) : null;
$now = time();
$usable = $qr && in_array((string) $qr['status'], ['pending', 'approved'], true)
    && strtotime((string) $qr['expires_at']) > $now;

if (!$usable) {
    if ($qr && (string) $qr['status'] === 'pending') {
        q('UPDATE `' . T('qr_logins') . '` SET status = ? WHERE id = ? AND status = ?', ['expired', (int) $qr['id'], 'pending']);
    }
    try {
        $qr = sg_qr_create();
        $_SESSION['sg_qr_challenge'] = ['id' => (int) $qr['id'], 'token' => (string) $qr['token']];
    } catch (Throwable $e) {
        $qrPanelError = '生成二维码失败：' . $e->getMessage();
        $qr = null;
    }
}

$qrToken = (string) ($_SESSION['sg_qr_challenge']['token'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') $scheme = 'https';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
if (!preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) $host = 'localhost';
$qrApproveUrl = $qrToken !== '' ? ($scheme . '://' . $host . url('qr-approve.php?t=' . rawurlencode($qrToken))) : '';
$qrExpiresAt = $qr ? strtotime((string) $qr['expires_at']) : 0;
$qrLeft = max(0, $qrExpiresAt - time());
?>
<div class="auth-qr-panel">
  <div class="qr-head">
    <span class="qr-ico"><span class="ico" data-ico="qr"></span></span>
    <h2 class="auth-qr-title">手机扫码登录</h2>
    <p>用手机相机扫描下面的二维码，在手机上点确认，这台电脑就会直接登录。</p>
  </div>

  <?php if ($qrPanelError !== ''): ?><div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($qrPanelError) ?></span></div><?php endif; ?>

  <?php if ($qr): ?>
  <div class="qr-box<?= (string) $qr['status'] === 'approved' ? ' is-approved' : '' ?>" id="qrBox" data-url="<?= e($qrApproveUrl) ?>" data-status="<?= e(url('qr-status.php')) ?>" data-claim="<?= e(url('qr-login.php?claim=1')) ?>">
    <div class="qr-code" id="qrCode"></div>
    <div class="qr-overlay" id="qrOverlay"<?= (string) $qr['status'] === 'approved' ? '' : ' hidden' ?>>
      <span class="ico" data-ico="check"></span>
      <b>已确认</b>
      <small>正在进入电脑…</small>
    </div>
    <div class="qr-overlay qr-expired" id="qrExpired" hidden>
      <button class="qr-refresh-btn" id="qrRefreshBtn" type="button" aria-label="刷新二维码" title="刷新二维码">
        <span class="ico" data-ico="refresh"></span>
      </button>
      <b>二维码已失效</b>
      <small>点击圆圈刷新</small>
    </div>
  </div>
  <p class="qr-status" id="qrStatus" data-left="<?= (int) $qrLeft ?>">
    <?= (string) $qr['status'] === 'approved' ? '手机已确认，正在进入电脑…' : '等待手机确认 · 剩 ' . (int) $qrLeft . ' 秒' ?>
  </p>
  <?php /* 内联二维码库：InfinityFree 会对 /assets/*.js 返回人机验证 HTML，导致 QRCode 未定义 */ ?>
  <script><?php readfile(__DIR__ . '/../assets/qrcode.min.js'); ?></script>
  <script>
  (function () {
    var box = document.getElementById('qrBox');
    var code = document.getElementById('qrCode');
    var statusEl = document.getElementById('qrStatus');
    var overlay = document.getElementById('qrOverlay');
    var expired = document.getElementById('qrExpired');
    var refreshBtn = document.getElementById('qrRefreshBtn');
    if (!box || !code || !statusEl) return;


    function sgParseForm(body) {
      var out = {};
      (body || '').split('&').forEach(function (kv) {
        if (!kv) return;
        var p = kv.split('=');
        out[decodeURIComponent(p[0])] = decodeURIComponent((p[1] || '').replace(/\+/g, ' '));
      });
      return out;
    }
    function sgJsonFetch(url, init) {
      if (window.__sgIframeFetch) return window.__sgIframeFetch(url, init);
      init = init || {};
      return new Promise(function (resolve, reject) {
        var f = document.createElement('iframe');
        f.style.display = 'none';
        f.setAttribute('aria-hidden', 'true');
        var done = false;
        var timer = setTimeout(function () {
          if (done) return;
          done = true; f.remove(); reject(new Error('iframe timeout'));
        }, 12000);
        function finish() {
          if (done) return;
          done = true; clearTimeout(timer);
          try {
            var text = (f.contentDocument && f.contentDocument.body) ? f.contentDocument.body.innerText : '';
            var data = JSON.parse(text);
            resolve({ok: true, status: 200, json: function () { return Promise.resolve(data); }, text: function () { return Promise.resolve(text); }});
          } catch (e) { reject(e); } finally { f.remove(); }
        }
        f.onload = function () { setTimeout(finish, 40); };
        if ((init.method || 'GET').toUpperCase() === 'POST') {
          var fields = sgParseForm(init.body), inputs = '';
          Object.keys(fields).forEach(function (k) {
            inputs += '<input type="hidden" name="' + k.replace(/"/g, '&quot;') + '" value="' + String(fields[k]).replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '">';
          });
          f.srcdoc = '<!doctype html><html><body><form method="post" action="' + url.replace(/"/g, '&quot;') + '">' + inputs + '</form><script>document.forms[0].submit()<\/script></body></html>';
          document.body.appendChild(f);
        } else {
          f.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
          document.body.appendChild(f);
        }
      });
    }

    var started = false;
    var initTries = 0;
    var approved = box.classList.contains('is-approved');
    var left = parseInt(statusEl.getAttribute('data-left') || '0', 10);
    var timer = null;
    var busy = false;
    var face = box.closest('[data-auth-face]');

    function faceActive() {
      return !face || face.getAttribute('aria-hidden') === 'false';
    }

    function stopTimer() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    }

    function setStatus(text) {
      statusEl.textContent = text;
    }

    function drawCode() {
      if (!window.QRCode || !code.offsetWidth) return false;
      code.innerHTML = '';
      new QRCode(code, {
        text: box.getAttribute('data-url'),
        width: 196,
        height: 196,
        correctLevel: QRCode.CorrectLevel.M
      });
      return true;
    }

    function showExpired() {
      stopTimer();
      approved = false;
      box.classList.remove('is-approved');
      box.classList.add('is-expired');
      overlay.hidden = true;
      expired.hidden = false;
      setStatus('二维码已失效，请点击二维码上的刷新按钮');
    }

    var deadline = 0;
    var lastShownLeft = -1;
    var lastPollAt = 0;
    var polling = false;
    function startPolling() {
      stopTimer();
      deadline = Date.now() + Math.max(0, left) * 1000;
      lastShownLeft = left;
      lastPollAt = Date.now();
      /* 250ms 刷新一次显示，但只在整秒变化时改文字：
         定时器被系统拖慢时也不会一次跳两秒，更不会出现 50→48 的跳秒。 */
      timer = setInterval(function () {
        left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        /* 真被系统冻结了几秒，也只允许一秒一秒往下掉，不出现跳秒 */
        if (left > 0 && lastShownLeft > 0 && left < lastShownLeft - 1) left = lastShownLeft - 1;
        if (left <= 0) {
          showExpired();
          return;
        }
        if (left !== lastShownLeft) {
          lastShownLeft = left;
          setStatus('等待手机确认 · 剩 ' + left + ' 秒');
        }
        /* 状态请求保持 2 秒一次，不跟着 250ms 的显示刷新走 */
        if (Date.now() - lastPollAt < 2000 || polling) return;
        lastPollAt = Date.now();
        polling = true;
        sgJsonFetch(box.getAttribute('data-status') + '?t=' + Date.now(), {cache: 'no-store'})
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.status === 'approved') {
              stopTimer();
              approved = true;
              box.classList.remove('is-expired');
              box.classList.add('is-approved');
              overlay.hidden = false;
              expired.hidden = true;
              setStatus('手机已确认，正在进入电脑…');
              setTimeout(function () { location.href = box.getAttribute('data-claim'); }, 350);
            } else if (data.status === 'expired' || data.status === 'none' || data.status === 'consumed') {
              showExpired();
            }
          }).catch(function () {}).then(function () { polling = false; });
      }, 250);
    }

    function resetQr(url, seconds) {
      box.setAttribute('data-url', url);
      left = Math.max(30, parseInt(seconds || '120', 10));
      deadline = Date.now() + left * 1000;
      approved = false;
      busy = false;
      box.classList.remove('is-approved', 'is-expired', 'is-refreshing');
      overlay.hidden = true;
      expired.hidden = true;
      refreshBtn.disabled = false;
      drawCode();
      setStatus('等待手机确认 · 剩 ' + Math.max(0, left) + ' 秒');
      startPolling();
    }

    function refreshQr() {
      if (busy) return;
      busy = true;
      refreshBtn.disabled = true;
      box.classList.add('is-refreshing');
      setStatus('正在刷新二维码…');
      sgJsonFetch(box.getAttribute('data-status'), {
        method: 'POST',
        cache: 'no-store',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: 'refresh=1&_token=' + encodeURIComponent(window.SG_CSRF || '')
      })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function (data) {
          if (data.status !== 'pending' || !data.url) throw new Error('refresh failed');
          resetQr(data.url, data.expires_in);
        })
        .catch(function () {
          busy = false;
          refreshBtn.disabled = false;
          box.classList.remove('is-refreshing');
          showExpired();
          setStatus('刷新失败，请再点一次圆圈');
        });
    }

    function init() {
      if (started) return;
      if (!window.QRCode || !code.offsetWidth) {
        if (initTries++ < 30) setTimeout(init, 100);
        return;
      }
      started = true;
      if (approved) {
        box.classList.add('is-approved');
        overlay.hidden = false;
        expired.hidden = true;
        setStatus('手机已确认，正在进入电脑…');
        return;
      }
      if (!drawCode()) return;
      startPolling();
    }

    refreshBtn.addEventListener('click', refreshQr);
    window.SG_QR_PANEL = {init: init};

    /* 站点 app.js 被主机人机验证拦截时，登录/扫码翻面也要能用 */
    var authCard = document.querySelector('[data-auth-flip]');
    if (authCard) {
      var applyFace = function (name) {
        authCard.setAttribute('data-face', name);
        var faces = authCard.querySelectorAll('[data-auth-face]');
        Array.prototype.forEach.call(faces, function (item) {
          var on = item.getAttribute('data-auth-face') === name;
          item.setAttribute('aria-hidden', on ? 'false' : 'true');
          if (on) item.removeAttribute('inert');
          else item.setAttribute('inert', '');
        });
        if (name === 'qr') setTimeout(init, 80);
      };
      var tabs = authCard.querySelectorAll('[data-auth-go]');
      Array.prototype.forEach.call(tabs, function (btn) {
        if (btn.__sgQrFallbackBound) return;
        btn.__sgQrFallbackBound = true;
        btn.addEventListener('click', function () {
          applyFace(btn.getAttribute('data-auth-go'));
        });
      });
    }

    if (faceActive()) setTimeout(init, 60);
  })();
  </script>
  <?php else: ?>
  <p class="muted">二维码暂时无法生成，请稍后刷新登录页。</p>
  <?php endif; ?>

  <div class="qr-note">
    <span class="ico" data-ico="info"></span>
    <span>这不是验证器绑定码。手机需要已经登录本站，用相机扫码后确认；验证器 App 不能替本站确认登录。</span>
  </div>
  <p class="auth-help"><button type="button" class="auth-text-btn" data-auth-go="login">返回账号密码登录</button><span>·</span><a href="support.php">联系客服</a></p>
</div>
