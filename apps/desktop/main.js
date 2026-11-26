const { app, BrowserWindow, Menu, shell, session, dialog, nativeImage, protocol } = require('electron');
const path = require('path');
const fs = require('fs');

const SITE_URL = 'https://dongshun.gt.tc/';
const SITE_HOST = 'dongshun.gt.tc';
const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

/* 本地二维码库：站点 /assets/qrcode.min.js 会被 InfinityFree 的人机验证拦截，
   桌面端直接内置一份，避免扫码面出现空白框。 */
let QRCODE_LIB = '';
try {
  QRCODE_LIB = fs.readFileSync(path.join(__dirname, 'assets', 'qrcode.min.js'), 'utf8');
} catch (e) {
  QRCODE_LIB = '';
}
const QRCODE_LIB_JSON = JSON.stringify(QRCODE_LIB);
let APP_JS = '';
try { APP_JS = fs.readFileSync(path.join(__dirname, 'assets', 'app.js'), 'utf8'); } catch (e) { APP_JS = ''; }
let JSQR_JS = '';
try { JSQR_JS = fs.readFileSync(path.join(__dirname, 'assets', 'jsQR.js'), 'utf8'); } catch (e) { JSQR_JS = ''; }
const APP_JS_JSON = JSON.stringify(APP_JS);
const JSQR_JS_JSON = JSON.stringify(JSQR_JS);

/* 桌面端兜底：即使站点 app.js 因缓存/网络原因没有执行到 initAuthFlip，
   也保证“用手机扫码登录”按钮能翻到二维码面。二维码面板自身的 SG_QR_PANEL
   是内联脚本，不依赖 app.js；这里只负责切面和触发初始化。 */
const QR_FALLBACK_SCRIPT = `
(function () {
  if (window.__sgDesktopQrFallback) return;
  window.__sgDesktopQrFallback = true;

  var QRCODE_LIB = ${QRCODE_LIB_JSON};

  function ensureQrLibrary(cb) {
    if (window.QRCode) { cb(); return; }
    try { (0, eval)(QRCODE_LIB); } catch (e) {}
    if (window.QRCode) { cb(); return; }
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
    s.onload = cb;
    s.onerror = cb;
    document.head.appendChild(s);
  }

  function startQr() {
    ensureQrLibrary(function () {
      setTimeout(function () { initQrPanel(0); }, 60);
    });
  }

  function applyFace(name) {
    var card = document.querySelector('[data-auth-flip]');
    if (!card) return false;
    card.setAttribute('data-face', name);
    var faces = card.querySelectorAll('[data-auth-face]');
    Array.prototype.forEach.call(faces, function (face) {
      var on = face.getAttribute('data-auth-face') === name;
      face.setAttribute('aria-hidden', on ? 'false' : 'true');
      if (on) face.removeAttribute('inert');
      else face.setAttribute('inert', '');
    });
    return true;
  }

  function openQr() {
    if (!applyFace('qr')) return false;
    return true;
  }

  function initQrPanel(tries) {
    if (window.SG_QR_PANEL && typeof window.SG_QR_PANEL.init === 'function') {
      window.SG_QR_PANEL.init();
      return;
    }
    if (tries < 20) setTimeout(function () { initQrPanel(tries + 1); }, 120);
  }

  function bind() {
    var card = document.querySelector('[data-auth-flip]');
    if (!card) return;
    var tabs = card.querySelectorAll('[data-auth-go]');
    Array.prototype.forEach.call(tabs, function (btn) {
      if (btn.__sgDesktopQrBound) return;
      btn.__sgDesktopQrBound = true;
      btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-auth-go');
        if (!applyFace(target)) return;
        if (target === 'qr') startQr();
      }, true);
    });
  }

  bind();
  document.addEventListener('DOMContentLoaded', bind);
  window.addEventListener('load', bind);
  setTimeout(bind, 600);
  ensureQrLibrary(function () {});
})();
`;

/* 站点 app.js / jsQR.js 被 InfinityFree 人机验证拦截时，桌面端用内置脚本补上：
   图标、返回登录、刷新二维码、复制、扫一扫都恢复；同时修当前线上页面的
   采购清单复制按钮和侧边栏账号卡。 */
const LOCAL_APP_SCRIPT = `
(function () {
  if (window.__sgDesktopAppFallback) return;
  window.__sgDesktopAppFallback = true;

  function fixQrIcon() {
    var card = document.querySelector('[data-auth-flip]');
    if (!card) return;
    var ico = card.querySelector('.qr-ico .ico');
    if (ico && !ico.querySelector('svg')) {
      ico.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
        '<rect x="3.5" y="3.5" width="6" height="6" rx="1"/><rect x="14.5" y="3.5" width="6" height="6" rx="1"/>' +
        '<rect x="3.5" y="14.5" width="6" height="6" rx="1"/><path d="M14.5 14.5h2v2h-2zM18.5 18.5h2v2h-2zM18.5 14.5h2M14.5 18.5h2"/></svg>';
    }
  }

  function fixShopCopy() {
    var body = document.querySelector('.shop-body');
    var copy = document.querySelector('[data-copy]');
    if (!body || !copy || copy.parentNode !== body) return;
    body.parentNode.insertBefore(copy, body.nextSibling);
    copy.style.marginTop = '14px';
    copy.style.position = 'relative';
    copy.style.zIndex = '2';
  }

  function fixSideUser() {
    var side = document.querySelector('.side-user');
    if (!side || side.tagName !== 'DIV') return;
    side.style.cursor = 'pointer';
    side.addEventListener('click', function (e) {
      if (e.target && e.target.closest && e.target.closest('a')) return;
      location.href = window.SG_USER ? 'user.php' : 'login.php';
    });
  }

  function fixChrome() {
    Array.prototype.forEach.call(document.querySelectorAll('.titlebar, .front-topbar'), function (el) { el.remove(); });
    Array.prototype.forEach.call(document.querySelectorAll('.nav-item[href*="download.php"]'), function (el) { el.remove(); });
    Array.prototype.forEach.call(document.querySelectorAll('.side-scroll-hint'), function (el) { el.remove(); });
  }

  function fixQrLayout() {
    var btn = document.getElementById('qrRefreshBtn');
    var expired = document.getElementById('qrExpired');
    if (!btn || !expired) return;
    if (expired.firstElementChild !== btn) expired.insertBefore(btn, expired.firstElementChild);
    btn.classList.remove('qr-refresh-mini');
    var line = document.querySelector('.qr-status-line');
    if (line) {
      var status = document.getElementById('qrStatus');
      if (status) line.parentNode.insertBefore(status, line);
      line.remove();
    }
  }

  function fixAudio() {
    Array.prototype.forEach.call(document.querySelectorAll('#sg-welcome-audio, .sg-audio-probe'), function (el) {
      el.removeAttribute('data-proxy');
    });
  }

  function uiFixes() {
    fixQrIcon();
    fixQrLayout();
    fixAudio();
    fixShopCopy();
    fixSideUser();
    fixChrome();
  }

  if (!window.__sgAppLoaded) {
    try {
      if (!window.jsQR) (0, eval)(${JSQR_JS_JSON});
    } catch (e) {}
    try { (0, eval)(${APP_JS_JSON}); } catch (e) {}
  }
  uiFixes();
})();
`;


/* 桌面端音频：免费主机对 audio.php 的媒体请求会返回人机验证 HTML，
   即使直接交给 <audio> 也会播成“解析失败”。把音频打包进客户端，
   网络层把 audio.php 请求重定向到本地协议，彻底不经过主机。 */
protocol.registerSchemesAsPrivileged([{
  scheme: 'sg-audio',
  privileges: { standard: true, secure: true, supportFetchAPI: true, stream: true }
}]);

function registerAudioProtocol() {
  protocol.handle('sg-audio', (request) => {
    try {
      const u = new URL(request.url);
      const slot = u.pathname.indexOf('member') !== -1 ? 'member' : 'guest';
      const file = path.join(__dirname, 'assets', 'audio', 'welcome-' + slot + '.mp3');
      if (!fs.existsSync(file)) return new Response('not found', { status: 404 });
      const data = fs.readFileSync(file);
      return new Response(new Uint8Array(data), {
        status: 200,
        headers: {
          'Content-Type': 'audio/mpeg',
          'Content-Length': String(data.length),
          'Cache-Control': 'no-store'
        }
      });
    } catch (e) {
      return new Response('audio error', { status: 500 });
    }
  });
}

const UPDATE_URL = 'https://dongshun.gt.tc/version.php';
let updateChecked = false;

function versionNewer(latest, current) {
  const a = String(latest || '').replace(/^v/i, '').split('.').map(function (n) { return parseInt(n, 10) || 0; });
  const b = String(current || '').replace(/^v/i, '').split('.').map(function (n) { return parseInt(n, 10) || 0; });
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const x = a[i] || 0, y = b[i] || 0;
    if (x > y) return true;
    if (x < y) return false;
  }
  return false;
}

function checkUpdate() {
  if (updateChecked || !mainWindow || mainWindow.isDestroyed()) return;
  updateChecked = true;
  const current = app.getVersion();
  mainWindow.webContents.executeJavaScript(
    "(function(){if(!window.__sgIframeFetch)return null;return window.__sgIframeFetch('" + UPDATE_URL + "?t='+Date.now()).then(function(r){return r.json();});})()",
    true
  ).then(function (data) {
    if (!data || !data.desktop || !data.desktop.version) return;
    if (!versionNewer(data.desktop.version, current)) return;
    dialog.showMessageBox(mainWindow, {
      type: 'info',
      title: '发现新版本',
      message: '有新版本了，请更新',
      detail: '当前版本 ' + current + '，最新版本 ' + data.desktop.version + '。' + (data.desktop.notes ? '\n' + data.desktop.notes : ''),
      buttons: ['去下载', '稍后'],
      defaultId: 0,
      cancelId: 1,
      noLink: true
    }).then(function (result) {
      if (result.response === 0) shell.openExternal(data.desktop.url || 'https://dongshun.gt.tc/download.php');
    });
  }).catch(function () {});
}

let mainWindow = null;
let splashWindow = null;
let mainReady = false;
let splashStartedAt = Date.now();
let errorPageShown = false;

app.setAppUserModelId('com.shiguang.recipe.desktop');
app.setPath('userData', path.join(app.getPath('appData'), 'ShiguangRecipe'));
/* 下载页“打开 App”：Windows 安装后注册 shiguang:// */
app.setAsDefaultProtocolClient('shiguang');

const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  app.on('second-instance', (event, argv) => {
    const protocolArg = (argv || []).find((arg) => /^shiguang:\/\//i.test(arg));
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore();
      mainWindow.show();
      mainWindow.focus();
      if (!protocolArg) return;
      /* 已打开时再次通过 shiguang:// 唤起，回到站点首页 */
      if (mainWindow.webContents.getURL().startsWith('data:')) {
        mainWindow.loadURL(SITE_URL);
      }
    }
  });
}

function createSplash() {
  splashWindow = new BrowserWindow({
    width: 460,
    height: 330,
    frame: false,
    resizable: false,
    show: false,
    backgroundColor: '#B23F19',
    skipTaskbar: true,
    alwaysOnTop: true,
    webPreferences: { sandbox: true, contextIsolation: true, nodeIntegration: false }
  });
  splashWindow.loadFile(path.join(__dirname, 'splash.html'));
  splashWindow.once('ready-to-show', () => {
    if (!splashWindow) return;
    splashStartedAt = Date.now();
    splashWindow.show();
  });
  splashWindow.on('closed', () => { splashWindow = null; });
}

function closeSplash() {
  if (!splashWindow) return;
  const elapsed = Date.now() - splashStartedAt;
  const delay = Math.max(0, 1100 - elapsed);
  setTimeout(() => {
    if (splashWindow && !splashWindow.isDestroyed()) splashWindow.close();
    splashWindow = null;
  }, delay);
}

function createMainWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 860,
    minWidth: 900,
    minHeight: 600,
    show: false,
    backgroundColor: '#FFFDF9',
    title: ' ',
    icon: path.join(__dirname, 'build', 'icon.ico'),
    autoHideMenuBar: true,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      webviewTag: false,
      spellcheck: false,
      devTools: false
    }
  });

  mainWindow.setMenuBarVisibility(false);
  mainWindow.on('page-title-updated', (event) => {
    event.preventDefault();
    mainWindow.setTitle(' ');
  });

  const ses = mainWindow.webContents.session;
  ses.setUserAgent(CHROME_UA);
  /* 所有欢迎音频请求改走本地文件，避免主机人机验证把音频换成 HTML */
  ses.webRequest.onBeforeRequest({ urls: ['*://*/audio.php*'] }, (details, callback) => {
    let slot = 'guest';
    try { slot = new URL(details.url).searchParams.get('slot') === 'member' ? 'member' : 'guest'; } catch (e) {}
    callback({ redirectURL: 'sg-audio://welcome/' + slot + '.mp3' });
  });
  ses.setPermissionRequestHandler((wc, permission, callback) => {
    callback(false);
  });

  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (isSiteUrl(url)) {
      mainWindow.loadURL(url);
    } else if (/^https?:|^mailto:|^tel:/.test(url)) {
      shell.openExternal(url);
    }
    return { action: 'deny' };
  });

  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (!isSiteUrl(url)) {
      event.preventDefault();
      if (/^https?:|^mailto:|^tel:/.test(url)) shell.openExternal(url);
    }
  });

  mainWindow.webContents.on('did-start-loading', () => {
    errorPageShown = false;
  });

  mainWindow.webContents.on('did-finish-load', () => {
    mainReady = true;
    if (mainWindow && !mainWindow.isVisible()) {
      mainWindow.show();
      mainWindow.focus();
    }
    closeSplash();
    mainWindow.webContents.executeJavaScript(LOCAL_APP_SCRIPT, true).catch(() => {});
    mainWindow.webContents.executeJavaScript(QR_FALLBACK_SCRIPT, true).catch(() => {});
    setTimeout(checkUpdate, 1200);
  });

  mainWindow.webContents.on('did-fail-load', (event, code, desc, url, isMainFrame) => {
    if (!isMainFrame) return;
    if (code === -3) return;
    showErrorPage();
  });

  mainWindow.webContents.on('did-navigate-http-status', (event, statusCode, url, isMainFrame) => {
    if (isMainFrame && statusCode >= 500) showErrorPage();
  });

  mainWindow.once('ready-to-show', () => {
    setTimeout(() => {
      if (mainWindow && !mainWindow.isVisible() && !splashWindow) {
        mainWindow.show();
      }
    }, 1200);
  });

  mainWindow.on('closed', () => { mainWindow = null; });
  mainWindow.loadURL(SITE_URL);
}

function isSiteUrl(url) {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:' && parsed.hostname.toLowerCase() === SITE_HOST;
  } catch (e) {
    return false;
  }
}

function showErrorPage() {
  if (!mainWindow || errorPageShown) return;
  errorPageShown = true;
  const html = `
  <!doctype html><html><head><meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>暂时连不上 · 食光菜谱</title>
  <style>
    *{box-sizing:border-box} body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:#FFFDF9;color:#3A3029;font-family:"Microsoft YaHei","PingFang SC",system-ui,sans-serif;text-align:center;padding:32px}
    .box{max-width:380px}.logo{width:64px;height:64px;border-radius:16px;margin:0 auto 20px;background:linear-gradient(160deg,#E4673C,#B23F19);
    color:#fff;display:flex;align-items:center;justify-content:center;font-family:serif;font-size:32px;font-weight:700;box-shadow:0 8px 22px rgba(178,63,25,.24)}
    h1{font-size:22px;margin:0 0 10px}p{font-size:14px;line-height:1.9;color:#7C6E60;margin:0 0 24px}
    button{border:0;background:#C8502B;color:#fff;padding:12px 32px;border-radius:10px;font-size:14px;font-family:inherit;cursor:pointer}
    button:hover{background:#B23F19} .tip{margin-top:22px;font-size:12px;color:#B4A899}
  </style></head><body><div class="box"><div class="logo">食</div><h1>暂时连不上</h1>
  <p>请检查网络后再试。免费主机偶尔会打盹，等一会儿重新加载通常就好。</p>
  <button onclick="location.href='${SITE_URL}'">重新加载</button>
  <div class="tip">食光菜谱 · Windows 客户端</div></div></body></html>`;
  mainWindow.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html));
  if (!mainWindow.isVisible()) {
    mainWindow.show();
    closeSplash();
  }
}

app.whenReady().then(() => {
  registerAudioProtocol();
  Menu.setApplicationMenu(null);
  createSplash();
  setTimeout(() => {
    if (mainWindow && !mainWindow.isVisible()) mainWindow.show();
    closeSplash();
  }, 15000);
  createMainWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createMainWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
