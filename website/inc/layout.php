<?php
/**
 * 食光菜谱 · 布局外壳
 * 桌面端 = 软件窗口（标题栏 / 侧边栏 / 状态栏，无最小化最大化关闭）
 * 移动端 = App（状态栏 / 顶栏 / 底部标签栏）
 */

function sg_shell_open(string $title, string $page, string $pagename, string $base, bool $admin): void
{
    $GLOBALS['SG_PAGE'] = $page;
    $GLOBALS['SG_AUTH_PAGE'] = in_array($page, ['login', 'register', 'admin-login', 'login-2fa', 'forgot-password', 'reset-password', 'qr-login'], true);
    $authPage = (bool) $GLOBALS['SG_AUTH_PAGE'];
    $frontTopbar = !$admin && !$authPage && !in_array($page, ['detail', 'cook'], true);
    $siteName = setting('site_name', '食光菜谱');
    $siteSub  = $siteName;
    $u        = current_user();
    ?>
<!DOCTYPE html>
<html lang="zh-CN" translate="no" class="<?= sg_is_mobile_ua() ? 'ua-mobile' : 'desk' ?><?= $admin ? ' admin-shell' : '' ?>">
<head>
<meta charset="UTF-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#221E1A">
<script>
/* 按设备 + 宽度 + 屏幕方向切换：手机 / 平板 / 桌面，平板横竖屏都不锁滚动 */
(function () {
  var mq = window.matchMedia('(max-width: 900px)');
  var orient = window.matchMedia('(orientation: portrait)');
  function sync() {
    var ua = navigator.userAgent || '';
    var phone = /iPhone|iPod|Windows Phone|IEMobile|Opera Mini|Android.*Mobile|BlackBerry|BB10/.test(ua);
    var ipad = /iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    var androidTablet = /Android/.test(ua) && !/Mobile/.test(ua);
    var coarse = window.matchMedia('(pointer: coarse)').matches;
    var touchTablet = !phone && navigator.maxTouchPoints > 1 && coarse
      && Math.min(screen.width, screen.height) >= 600
      && Math.max(screen.width, screen.height) >= 900;
    var tablet = ipad || androidTablet || touchTablet;
    /* 横屏平板走真正的桌面分支：侧栏、桌面顶栏、隐藏底部 Tab。
       竖屏平板仍用平板 App 外壳，避免 1024 竖屏被侧栏挤窄。 */
    var tabletDesktop = tablet && !orient.matches
      && Math.max(window.innerWidth, window.innerHeight) >= 1024;
    var mobile = (mq.matches || phone || tablet) && !tabletDesktop;
    var root = document.documentElement;
    root.classList.toggle('ua-mobile', mobile);
    root.classList.toggle('desk', !mobile);
    root.dataset.view = tabletDesktop ? 'desktop' : (tablet ? 'tablet' : (mobile ? 'phone' : 'desktop'));
    root.dataset.device = tablet ? 'tablet' : (phone ? 'phone' : 'desktop');
    root.dataset.layout = tabletDesktop ? 'desktop' : (mobile ? 'mobile' : 'desktop');
    root.dataset.orientation = orient.matches ? 'portrait' : 'landscape';
  }
  sync();
  if (mq.addEventListener) mq.addEventListener('change', sync);
  else if (mq.addListener) mq.addListener(sync);
  if (orient.addEventListener) orient.addEventListener('change', sync);
  else if (orient.addListener) orient.addListener(sync);
  window.addEventListener('resize', sync);
})();
</script>
<script>try{if('scrollRestoration' in history)history.scrollRestoration='manual';}catch(e){}</script>
<title><?= e($title) ?> · <?= e($siteName) ?><?= $admin ? ' · 管理后台' : '' ?></title>
<link rel="stylesheet" href="<?= e($base) ?>assets/base.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="<?= e($base) ?>assets/app-extra.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="<?= e($base) ?>assets/ua-mobile.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="<?= e($base) ?>assets/admin-mobile.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="<?= e($base) ?>assets/desk.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="<?= e($base) ?>assets/refresh.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="<?= e($base) ?>assets/tablet.css?v=<?= SG_VERSION ?>">
<?php
$sgBrand = (string) setting('theme_primary', '#C8502B');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $sgBrand)) { $sgBrand = '#C8502B'; }
?>
<style>:root{--brand:<?= $sgBrand ?>;--brand-2:<?= sg_color_adjust($sgBrand, -0.16) ?>;--brand-soft:<?= sg_color_adjust($sgBrand, 0.88) ?>;}</style>
</head>
<body class="<?= sg_is_mobile_ua() ? 'ua-mobile' : '' ?>" data-page="<?= e($page) ?>" data-pagename="<?= e($pagename) ?>">
<div class="window">




  <div class="appbody">
    <?php if (!$authPage && $admin): ?>
    <aside class="sidebar desk-only">
      <?php
      $pendingCmt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '` WHERE status = "pending"', [], 0);
      $recipeCnt  = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '`', [], 0);
      $catCnt     = (int) fetch_val('SELECT COUNT(*) FROM `' . T('categories') . '`', [], 0);
      $openTk     = sg_security_ready() ? (int) fetch_val('SELECT COUNT(*) FROM `' . T('support_tickets') . '` WHERE status = ?', ['open'], 0) : 0;
      $a          = current_admin() ?: ['username' => 'admin', 'display_name' => '管理员', 'role' => 'super'];
      $navItems   = [
          ['dashboard',  'chart',    '仪表盘',        ''],
          ['recipes',    'book',     '菜谱管理',      $recipeCnt],
          ['categories', 'folder',   '分类管理',      $catCnt],
          ['media',      'image',    '媒体库',        ''],
          ['ingredients','leaf',     '食材图库',      ''],
          ['users',      'users',    '用户管理',      ''],
          ['comments',   'comment',  '评论审核',      $pendingCmt ?: ''],
          ['support',    'comment',  '客服工单',      $openTk ?: ''],
          ['app-downloads', 'down',  'App 下载',      ''],
          ['app-versions',  'refresh', '客户端更新',    ''],
          ['settings',   'settings', '系统设置',      ''],
          ['reset',      'trash',    '数据清理',      ''],
          ['backup',     'database', '数据备份',      ''],
          ['source-backup', 'down',  '源码备份',      ''],
          ['update',     'refresh',  '更新系统',      ''],
          ['sync',       'upload',   '云端同步',      ''],
          ['logout',     'logout',   '退出登录',      ''],
      ];
      ?>
      <div class="nav-cap">运营</div>
      <?php foreach ($navItems as $i => $n):
          if ($i === 5): ?><div class="nav-cap">社区</div><?php endif; ?>
      <?php if ($i === 8): ?><div class="nav-cap">系统</div><?php endif; ?>
      <a class="nav-item<?= $page === 'admin-' . $n[0] ? ' on' : '' ?>" href="<?= e($n[0]) ?>.php"><span class="ico" data-ico="<?= e($n[1]) ?>"></span><?= e($n[2]) ?><?php if ($n[3] !== ''): ?><span class="nav-count"><?= (int) $n[3] ?></span><?php endif; ?></a>
      <?php endforeach; ?>
      <div class="side-foot">
        <a class="side-user" href="more.php" title="更多功能与当前账号">
          <span class="avatar" style="background:linear-gradient(150deg,#E9D8C4,#CDB99C)"><?= e(mb_substr($a['display_name'] ?: $a['username'], 0, 1)) ?></span>
          <div><b><?= e($a['display_name'] ?: $a['username']) ?></b><span><?= $a['role'] === 'super' ? '超级管理员' : '管理员' ?></span></div>
          <span class="side-go" aria-hidden="true"><span class="ico" data-ico="chev"></span></span>
        </a>
        <div class="side-ver"><span>管理后台</span><span>v<?= SG_VERSION ?></span></div>
      </div>
    </aside>
    <?php elseif (!$authPage): ?>
    <aside class="sidebar desk-only">
      <?php
      $favCnt = $u ? (int) fetch_val('SELECT COUNT(*) FROM `' . T('favorites') . '` WHERE user_id = ?', [(int) $u['id']], 0) : 0;
      ?>
      <div class="nav-cap">浏览</div>
      <a class="nav-item<?= $page === 'home' ? ' on' : '' ?>" href="<?= e($base) ?>index.php"><span class="ico" data-ico="home"></span>首页</a>
      <a class="nav-item<?= $page === 'recipes' ? ' on' : '' ?>" href="<?= e($base) ?>recipes.php"><span class="ico" data-ico="book"></span>菜谱库</a>
      <a class="nav-item<?= $page === 'search' ? ' on' : '' ?>" href="<?= e($base) ?>search.php"><span class="ico" data-ico="search"></span>搜索</a>
      <a class="nav-item<?= $page === 'tips' ? ' on' : '' ?>" href="<?= e($base) ?>tips.php"><span class="ico" data-ico="chef"></span>新手手册</a>
      <div class="nav-cap">我的厨房</div>
      <?php
      $onFav  = $page === 'user-favorites';
      $onHis  = $page === 'user-history';
      $onMe   = in_array($page, ['user', 'user-recipes'], true);
      $onSet  = in_array($page, ['user-settings', 'user-profile', 'user-password', 'user-account', 'user-security'], true);
      ?>
      <a class="nav-item<?= $onFav ? ' on' : '' ?>" href="<?= e($base) ?>user-favorites.php"><span class="ico" data-ico="heart"></span>我的收藏<?php if ($favCnt): ?><span class="nav-count"><?= $favCnt ?></span><?php endif; ?></a>
      <a class="nav-item<?= $onHis ? ' on' : '' ?>" href="<?= e($base) ?>user-history.php"><span class="ico" data-ico="clock"></span>浏览历史</a>
      <a class="nav-item<?= $onMe ? ' on' : '' ?>" href="<?= e($base) ?>user.php"><span class="ico" data-ico="user"></span>个人中心</a>
      <a class="nav-item<?= $onSet ? ' on' : '' ?>" href="<?= e($base) ?>user-settings.php"><span class="ico" data-ico="settings"></span>账号设置</a>
      <a class="nav-item<?= $page === 'shopping' ? ' on' : '' ?>" href="<?= e($base) ?>shopping.php"><span class="ico" data-ico="list"></span>采购清单</a>
      <a class="nav-item<?= $page === 'submit' ? ' on' : '' ?>" href="<?= e($base) ?>submit.php"><span class="ico" data-ico="chef"></span>投稿菜谱</a>
      <div class="nav-cap">更多</div>
      <div class="side-foot">
        <a class="side-user" href="<?= e($base) ?><?= $u ? 'user.php' : 'login.php' ?>" title="<?= $u ? '进入个人中心' : '登录或注册' ?>">
          <?php if ($u): ?>
          <span class="avatar"><?= e(mb_substr($u['display_name'] ?: $u['username'], 0, 1)) ?></span>
          <div><b><?= e($u['display_name'] ?: $u['username']) ?></b><span>家庭主厨</span></div>
          <?php else: ?>
          <span class="avatar" style="background:linear-gradient(150deg,#D9D2C6,#BDB3A2)">客</span>
          <div><b>未登录</b><span><span style="color:var(--brand)">登录 / 注册 →</span></span></div>
          <?php endif; ?>
        </a>
        <div class="side-ver"><span>前台站点</span><span>v<?= SG_VERSION ?></span></div>
      </div>
    </aside>
    <?php endif; ?>

    <main class="main<?= ($GLOBALS['SG_PAGE'] ?? '') === 'detail' ? ' has-detail-actions' : '' ?>">
      <div class="page">
        <?= flash_render() ?>
<?php
}

function sg_shell_close(string $base, bool $admin): void
{
    $authPage  = !empty($GLOBALS['SG_AUTH_PAGE']);
    $recipesCnt = 0;
    $dbName     = $GLOBALS['SG_CFG']['db']['name'] ?? '';
    /* 只有后台状态栏用得到，前台不再查询/显示这些运行信息 */
    if (!$authPage && $admin) {
        $recipesCnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE status = "published"', [], 0);
    }
    ?>
      </div>
    </main>
  </div>

  <?php if (!$authPage && $admin): ?>
  <!-- 状态栏只留给后台：前台不暴露数据库名、PHP 版本等运行信息 -->
  <div class="statusbar desk-only">
    <span class="dot"></span><span>后台就绪</span>
    <span>MySQL 已连接 · <?= e($dbName) ?></span>
    <span>共 <?= number_format($recipesCnt) ?> 道菜谱</span>
    <span class="sb-right"><span>PHP <?= e(PHP_VERSION) ?> · MySQL</span><span>v<?= SG_VERSION ?></span></span>
  </div>
  <?php endif; ?>

  <?php if (!$authPage && $admin && current_admin()): ?>
  <nav class="tabbar admin-tabbar mob-only">
    <?php
    $pg = $GLOBALS['SG_PAGE'] ?? '';
    $pendingCmtNav = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '` WHERE status = "pending"', [], 0);
    $mobMore = in_array($pg, ['admin-users', 'admin-media', 'admin-ingredients', 'admin-backup', 'admin-update', 'admin-settings', 'admin-more', 'admin-support', 'admin-app-downloads', 'admin-app-versions', 'admin-source-backup'], true);
    if ($pg === 'admin-edit') { $pg = 'admin-recipes'; }
    ?>
    <a class="<?= $pg === 'admin-dashboard' ? 'on' : '' ?>" href="dashboard.php"><span class="ico" data-ico="chart"></span>仪表盘</a>
    <a class="<?= $pg === 'admin-recipes' ? 'on' : '' ?>" href="recipes.php"><span class="ico" data-ico="book"></span>菜谱</a>
    <a class="<?= $pg === 'admin-categories' ? 'on' : '' ?>" href="categories.php"><span class="ico" data-ico="folder"></span>分类</a>
    <a class="<?= $pg === 'admin-comments' ? 'on' : '' ?>" href="comments.php"><span class="ico" data-ico="comment"></span>评论<?php if ($pendingCmtNav): ?><i class="tb-dot"><?= $pendingCmtNav > 99 ? '99+' : $pendingCmtNav ?></i><?php endif; ?></a>
    <a class="<?= $mobMore ? 'on' : '' ?>" href="more.php"><span class="ico" data-ico="grid"></span>更多</a>
  </nav>
  <?php endif; ?>

  <?php if (!$admin && !in_array(($GLOBALS['SG_PAGE'] ?? ''), ['detail', 'login', 'register', 'login-2fa', 'forgot-password', 'reset-password'], true)): ?>
  <nav class="tabbar mob-only">
    <?php
    $pg    = $GLOBALS['SG_PAGE'] ?? '';
    $isU   = in_array($pg, ['user', 'user-favorites', 'user-history', 'user-recipes', 'user-settings', 'user-profile', 'user-password', 'user-account', 'user-security'], true);
    $onMe  = $isU || in_array($pg, ['login', 'register'], true);
    ?>
    <a class="<?= $pg === 'home' ? 'on' : '' ?>" href="<?= e($base) ?>index.php"><span class="ico" data-ico="home"></span>首页</a>
    <a class="<?= $pg === 'recipes' ? 'on' : '' ?>" href="<?= e($base) ?>recipes.php"><span class="ico" data-ico="book"></span>菜谱库</a>
    <span class="tab-scan-slot" aria-hidden="true"></span>
    <button class="tab-scan" type="button" data-scan-open aria-label="扫一扫" title="扫一扫"><span class="ico" data-ico="scan"></span></button>
    <a class="<?= $pg === 'search' ? 'on' : '' ?>" href="<?= e($base) ?>search.php"><span class="ico" data-ico="search"></span>搜索</a>
    <a class="<?= $onMe ? 'on' : '' ?>" href="<?= e($base) ?>user.php"><span class="ico" data-ico="user"></span>我的</a>
  </nav>

  <div class="scan-overlay" id="scanOverlay" hidden data-jsqr="<?= e($base) ?>asset-js.php?f=jsQR.js&v=<?= SG_VERSION ?>" data-jsqr-worker="<?= e($base) ?>asset-jsqr-worker.php?v=<?= SG_VERSION ?>">
    <div class="scan-mask" data-scan-close></div>
    <section class="scan-box" role="dialog" aria-modal="true" aria-labelledby="scanTitle">
      <div class="scan-head">
        <b id="scanTitle">扫一扫</b>
        <button class="scan-close" type="button" data-scan-close aria-label="关闭"><span class="ico" data-ico="x"></span></button>
      </div>
      <div class="scan-stage">
        <video id="scanVideo" playsinline webkit-playsinline muted autoplay></video>
        <div class="scan-frame" aria-hidden="true"></div>
        <div class="scan-loading" id="scanLoading">正在打开相机…</div>
      </div>
      <p class="scan-tip" id="scanTip" aria-live="polite">把电脑上的登录二维码放进框内</p>
      <div class="scan-actions">
        <button class="scan-gallery" id="scanGallery" type="button">从相册选择二维码</button>
      </div>
      <input type="file" id="scanFile" accept="image/*" hidden>
    </section>
  </div>
  <?php endif; ?>
</div>
<script>
window.SG_API = '<?= e($base) ?>api/';
window.SG_CSRF = '<?= e(csrf_token()) ?>';
window.SG_LOGIN = '<?= e($base) ?>login.php';
<?php $sgUserNow = current_user(); ?>
window.SG_USER = <?= $admin || $sgUserNow ? 'true' : 'false' ?>;
window.SG_UID = <?= (int) ($sgUserNow['id'] ?? 0) ?>;
</script>
<script>
(function () {
  /* 刷新前先套用折叠状态，再恢复滚动位置，避免布局变高变矮造成跳动 */
  try {
    var sc = document.querySelector('[data-shop-card]');
    if (sc) {
      if (localStorage.getItem('sg_shop_fold') === '0') sc.classList.remove('is-collapsed');
      else sc.classList.add('is-collapsed');
    }
  } catch (e) {}
  var t = 'navigate';
  try {
    var n = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
    if (n && n.type) t = n.type;
    else if (performance.navigation) t = performance.navigation.type === 1 ? 'reload' : (performance.navigation.type === 2 ? 'back_forward' : 'navigate');
  } catch (e) {}
  if ((t === 'reload' || t === 'back_forward') && !location.hash) {
    try {
      var key = 'sg_scroll:' + location.pathname + location.search;
      var s = JSON.parse(sessionStorage.getItem(key) || 'null');
      if (s) {
        var main = document.querySelector('.main');
        if (main && s.m) main.scrollTop = Number(s.m) || 0;
        if (s.y) window.scrollTo(0, Number(s.y) || 0);
      }
    } catch (e) {}
  }
})();
</script>
<?php
$sgAudio = welcome_audio_file();
$sgAudioSrc = '';
$sgAudioProxy = '';
if (!$admin && !$authPage && $sgAudio !== '') {
    $sgAudioSrc = sg_audio_embed_mode() ? audio_data_uri($sgAudio) : '';
    if ($sgAudioSrc === '') {
        $sgAudioProxy = $base . 'audio.php?slot=' . (current_user() ? 'member' : 'guest') . '&v=' . md5($sgAudio);
        $sgAudioSrc = $sgAudioProxy;
    }
}
if (!$admin && !$authPage && $sgAudio !== ''):
?>
<audio id="sg-welcome-audio" class="sg-audio" src="<?= e($sgAudioSrc) ?>" preload="none" playsinline
       data-proxy="<?= e($sgAudioProxy) ?>" data-key="sg_welcome_audio:<?= md5((current_user() ? 'member' : 'guest') . ':' . $sgAudio) ?>"></audio>
<?php endif; ?>
<?php /* 主机对 /assets/*.js 和 fetch 会做人机验证；内联脚本才能稳定执行 */ ?>
<?php if (empty($admin)): ?>
<?php /* jsQR 源码以 text/plain 保留，既在主线程执行，也用于创建扫码 Worker，避免再被主机拦截一次 */ ?>
<script id="sgJsQrSrc" type="text/plain"><?php readfile(__DIR__ . '/../assets/jsQR.js'); ?></script>
<script>window.eval(document.getElementById('sgJsQrSrc').textContent);</script>
<?php endif; ?>
<script><?php readfile(__DIR__ . '/../assets/app.js'); ?></script>
</body>
</html>
<?php
}

function sg_head(string $title, string $page, string $pagename, string $base = ''): void
{
    $GLOBALS['SG_BASE'] = $base;
    sg_shell_open($title, $page, $pagename, $base, false);
}

function sg_foot(string $base = ''): void
{
    sg_shell_close($base, false);
}

function sg_admin_head(string $title, string $page, string $pagename): void
{
    $GLOBALS['SG_BASE'] = '../';
    sg_shell_open($title, $page, $pagename, '../', true);
}

function sg_admin_foot(): void
{
    sg_shell_close('../', true);
}
