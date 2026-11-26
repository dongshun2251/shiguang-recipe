<?php
/**
 * 食光菜谱 · App 下载页
 * 独立于网站外壳的落地页；卡片由后台「App 下载」维护。
 */
require_once __DIR__ . '/inc/bootstrap.php';

$siteName = setting('site_name', '食光菜谱');
$siteSub  = setting('site_sub', '家常菜谱库');
$u        = current_user();
$items    = sg_app_downloads(true);
$entry    = setting('app_download_entry', 'site');

$siteHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$siteHost = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', $siteHost);
if ($siteHost === '') { $siteHost = '你的域名'; }
$heroTitle = trim((string) setting('app_download_title', '把食光，装进口袋。'));
if ($heroTitle === '') { $heroTitle = '把食光，装进口袋。'; }
$heroSub = trim((string) setting('app_download_sub', '手机、平板和电脑，同一本家常菜谱。'));
if ($heroSub === '') { $heroSub = '手机、平板和电脑，同一本家常菜谱。'; }
$heroNote = trim((string) setting('app_download_note', '私人小厨房 · 熟人自用，不上架应用商店。'));
if ($heroNote === '') { $heroNote = '私人小厨房 · 熟人自用，不上架应用商店。'; }

$brand = (string) setting('theme_primary', '#C8502B');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand)) { $brand = '#C8502B'; }

/* 大标题默认在中文逗号处断成两行，避免“装进口袋”最后一个字被挤下去 */
$heroTitleLine1 = $heroTitle;
$heroTitleLine2 = '';
$heroParts = preg_split('/[，,]/u', $heroTitle, 2);
if (count($heroParts) === 2) {
    $heroTitleLine1 = rtrim($heroParts[0]) . '，';
    $heroTitleLine2 = ltrim($heroParts[1]);
}

$androidCount = 0;
$windowsCount = 0;
foreach ($items as $it) {
    if (($it['platform'] ?? '') === 'android') { $androidCount++; }
    if (($it['platform'] ?? '') === 'windows') { $windowsCount++; }
}

$ico = static function (string $name): string {
    $paths = [
        'down' => '<path d="M12 4.5v15"/><path d="M6 13.5l6 6 6-6"/>',
        'right' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'check' => '<path d="M4.5 12.5l5 5 10-11"/>',
        'shield' => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'phone' => '<rect x="6" y="2.5" width="12" height="19" rx="3"/><path d="M10 18.5h4"/>',
        'tablet' => '<rect x="3.5" y="2.5" width="17" height="19" rx="2.5"/><path d="M9 18.5h6"/>',
        'desk' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'sync' => '<path d="M20 11a8 8 0 0 0-13.6-4.6L3 9.5"/><path d="M3 4.5V9.5h5"/><path d="M4 13a8 8 0 0 0 13.6 4.6L21 14.5"/><path d="M21 19.5v-5h-5"/>',
        'spark' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
        'lock' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10"/>',
        'back' => '<path d="M15 5l-7 7 7 7"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c0-4 3.4-6.5 7.5-6.5s7.5 2.5 7.5 6.5"/>',
        'apple' => '<path d="M15.8 8.2c-1.8 0-2.8 1-3.8 1s-2.2-1-3.8-1c-3 0-5.2 2.5-5.2 6.2 0 4.2 3 8.6 5.4 8.6 1.2 0 2-.8 3.6-.8s2.2.8 3.4.8c2.2 0 4.8-3.5 5.4-6.2-2.7-1.1-3.3-4.6-1-6.2-1.2-1.6-3-2.4-4.8-2.4z"/><path d="M13.4 6.2c1.2-1.5 1.9-3.3 1.7-5-1.8.1-3.8 1.1-5 2.7-.9 1.2-1.4 2.6-1.2 4 1.8.1 3.6-.9 4.5-1.7z"/>',
    ];
    $p = $paths[$name] ?? $paths['down'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
};

$platformIcon = static function (string $platform) use ($ico): string {
    if ($platform === 'windows' || $platform === 'mac') { return $ico('desk'); }
    if ($platform === 'ios') { return $ico('phone'); }
    if ($platform === 'tablet') { return $ico('tablet'); }
    if ($platform === 'web') { return $ico('sync'); }
    if ($platform === 'other') { return $ico('spark'); }
    return $ico('phone');
};
?>
<!DOCTYPE html>
<html lang="zh-CN" translate="no" class="dl-page<?= sg_is_mobile_ua() ? ' is-mobile' : '' ?>">
<head>
<meta charset="UTF-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($brand) ?>">
<title>下载 App · <?= e($siteName) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset('app-icon.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('app-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('download.css')) ?>?v=<?= SG_VERSION ?>">
<style>:root{--brand:<?= e($brand) ?>;--brand-2:<?= e(sg_color_adjust($brand, -0.18)) ?>;--brand-soft:<?= e(sg_color_adjust($brand, 0.90)) ?>;}</style>
</head>
<body>
<div class="dl-bg" aria-hidden="true"><span></span><span></span><span></span></div>

<header class="dl-top">
  <a class="dl-brand" href="<?= e(url('index.php?app=site')) ?>">
    <span class="dl-logo">食</span>
    <span class="dl-brand-txt"><b><?= e($siteName) ?></b><em><?= e($siteSub) ?> · APP</em></span>
  </a>
  <nav class="dl-nav">
    <a class="dl-nav-back" href="<?= e(url('index.php?app=site')) ?>"><span class="dl-ico"><?= $ico('back') ?></span>返回网站</a>
<?php if ($u): ?>
    <a class="dl-nav-user" href="<?= e(url('user.php')) ?>"><span class="dl-ico"><?= $ico('user') ?></span><?= e($u['display_name'] ?: $u['username']) ?></a>
<?php else: ?>
    <a class="dl-nav-user" href="<?= e(url('login.php')) ?>"><span class="dl-ico"><?= $ico('user') ?></span>登录</a>
<?php endif; ?>
  </nav>
</header>

<main class="dl-main">
  <section class="dl-hero">
    <div class="dl-hero-copy">
      <div class="dl-eyebrow"><span class="dl-dot"></span>SHIGUANG APP · 私人小厨房</div>
      <h1><?= e($heroTitleLine1) ?><?php if ($heroTitleLine2 !== ''): ?><br><em><?= e($heroTitleLine2) ?></em><?php endif; ?></h1>
      <p class="dl-lead"><?= e($heroSub) ?></p>
      <div class="dl-chips">
        <span><span class="dl-ico"><?= $ico('phone') ?></span>Android APK</span>
        <span><span class="dl-ico"><?= $ico('desk') ?></span>Windows 桌面版</span>
        <span><span class="dl-ico"><?= $ico('sync') ?></span>同一个数据库</span>
      </div>
      <div class="dl-hero-actions">
        <a class="dl-btn dl-btn-primary" href="#downloads"><span class="dl-ico"><?= $ico('down') ?></span>选择我的设备</a>
        <a class="dl-btn dl-btn-ghost" href="<?= e(url('index.php?app=site')) ?>">先进网站看看<span class="dl-ico"><?= $ico('right') ?></span></a>
      </div>
      <div class="dl-trust"><span class="dl-ico"><?= $ico('lock') ?></span><?= e($heroNote) ?></div>
    </div>

    <div class="dl-stage" aria-hidden="true">
      <div class="dl-orbit o1"></div><div class="dl-orbit o2"></div>
      <div class="dl-desktop">
        <div class="dl-winbar"><span class="dl-mini-logo">食</span><b>食光菜谱</b><span class="dl-window-dots"><i></i><i></i><i></i></span></div>
        <div class="dl-winbody">
          <div class="dl-win-side">
            <span class="dl-side-on">首页</span><span>菜谱库</span><span>搜索</span><span>我的厨房</span>
          </div>
          <div class="dl-win-main">
            <div class="dl-win-hero"><small>今天想做点什么？</small><b>家常菜，也要<br>好好吃饭。</b><span class="dl-pill">浏览菜谱库</span></div>
            <div class="dl-win-cards"><div><span>本周热门</span><b>番茄炒蛋</b></div><div><span>新手友好</span><b>蒜香排骨</b></div></div>
          </div>
        </div>
      </div>
      <div class="dl-phone">
        <div class="dl-notch"></div>
        <div class="dl-phone-screen">
          <div class="dl-ph-top"><span class="dl-mini-logo">食</span><b>食光菜谱</b><span class="dl-ph-dot"></span></div>
          <div class="dl-ph-hero">今天想做<br><em>点什么？</em></div>
          <div class="dl-ph-search">搜索菜名或食材<span></span></div>
          <div class="dl-ph-card"><div class="dl-ph-thumb"></div><div><b>番茄炒蛋</b><small>15 分钟 · 新手友好</small></div><span>›</span></div>
          <div class="dl-ph-card"><div class="dl-ph-thumb warm"></div><div><b>香菇青菜</b><small>10 分钟 · 清淡</small></div><span>›</span></div>
          <div class="dl-ph-nav"><span>首页</span><span>菜谱库</span><span>搜索</span><span>我的</span></div>
        </div>
      </div>
      <div class="dl-stage-note"><span class="dl-ico"><?= $ico('sync') ?></span><b>同一个账号</b><small>手机、平板和电脑，菜谱实时同步</small></div>
    </div>
  </section>

  <section class="dl-downloads" id="downloads">
    <div class="dl-section-head">
      <div><div class="dl-eyebrow">DOWNLOAD</div><h2>把你的厨房，<em>装进设备</em></h2></div>
      <p>安装包是独立的浏览器外壳，网站和数据库仍然在 <b><?= e($siteHost) ?></b>。</p>
    </div>

<?php if (!$items): ?>
    <div class="dl-empty">
      <span class="dl-empty-ico"><?= $ico('down') ?></span>
      <div><b>安装包正在准备中</b><p>管理员还没有发布下载卡片。到后台「App 下载」填写 APK / EXE 的网盘或 CDN 直链后，这里会自动出现。</p>
<?php if (current_admin()): ?><a class="dl-btn dl-btn-ghost" href="<?= e(url('admin/app-downloads.php')) ?>">去后台添加<span class="dl-ico"><?= $ico('right') ?></span></a><?php endif; ?>
      </div>
    </div>
<?php else: ?>
    <div class="dl-grid">
<?php foreach ($items as $it):
    $meta = sg_app_platform_meta((string) $it['platform']);
    $href = sg_app_download_href((string) $it['url']);
    $isExternal = (bool) preg_match('#^https?://#i', trim((string) $it['url']));
?>
      <article class="dl-card is-<?= e((string) $it['platform']) ?>">
        <div class="dl-card-top">
          <span class="dl-platform-ico"><?= $platformIcon((string) $it['platform']) ?></span>
          <span class="dl-platform-tag"><?= e($meta['short']) ?></span>
          <?php if ($it['ver'] !== ''): ?><span class="dl-ver">v<?= e(preg_replace('/^v/i', '', (string) $it['ver'])) ?></span><?php endif; ?>
        </div>
        <h3><?= e($it['title']) ?></h3>
        <p><?= e($it['note'] !== '' ? $it['note'] : $meta['hint']) ?></p>
        <div class="dl-card-meta">
          <span><?= e($meta['label']) ?></span>
          <?php if ($it['size'] !== ''): ?><span><?= e($it['size']) ?></span><?php endif; ?>
          <?php if ($isExternal): ?><span>外部链接</span><?php endif; ?>
        </div>
        <?php if (($it['app_link'] ?? '') !== ''): ?>
        <div class="dl-card-actions">
          <button type="button" class="dl-btn dl-card-btn dl-open-app"
                  data-app-link="<?= e((string) $it['app_link']) ?>"
                  data-download="<?= e($href) ?>">
            <span class="dl-ico"><?= $ico('right') ?></span>打开 App
          </button>
          <?php if ($href !== ''): ?>
          <a class="dl-btn dl-btn-ghost dl-card-dl" href="<?= e($href) ?>"<?= $isExternal ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
            <span class="dl-ico"><?= $ico('down') ?></span>下载安装包
          </a>
          <?php endif; ?>
        </div>
        <?php if ($href !== ''): ?><p class="dl-card-hint">已安装会直接打开；没安装会自动转到下载。</p><?php endif; ?>
        <?php elseif ($href !== ''): ?>
        <a class="dl-btn dl-card-btn" href="<?= e($href) ?>"<?= $isExternal ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
          <span class="dl-ico"><?= $ico('down') ?></span><?= $isExternal ? '打开下载链接' : '下载安装包' ?>
        </a>
        <?php else: ?>
        <span class="dl-btn dl-card-btn is-disabled">链接还没配置</span>
        <?php endif; ?>
      </article>
<?php endforeach; ?>
    </div>
<?php endif; ?>
  </section>

  <section class="dl-points">
    <div class="dl-point"><span class="dl-point-no">01</span><span class="dl-ico"><?= $ico('sync') ?></span><h3>同一套数据</h3><p>手机、电脑打开的是同一个站，账号、收藏、采购清单都在服务器上，不在安装包里。</p></div>
    <div class="dl-point"><span class="dl-point-no">02</span><span class="dl-ico"><?= $ico('phone') ?></span><h3>手机电脑同时在线</h3><p>一台设备登录不会把另一台踢掉；默认最多 5 台，超了自动退最久没用的那台。</p></div>
    <div class="dl-point"><span class="dl-point-no">03</span><span class="dl-ico"><?= $ico('shield') ?></span><h3>只给熟人用</h3><p>不上架应用商店，安装包直接发；网站改版后 App 自动显示新版，不用重新发安装包。</p></div>
  </section>

  <section class="dl-how">
    <div class="dl-section-head"><div><div class="dl-eyebrow">HOW TO</div><h2>三步就能开饭</h2></div><p>装好后，后续只需要正常登录。</p></div>
    <div class="dl-steps">
      <div class="dl-step"><b>01</b><h3>下载安装包</h3><p>选上面的安装卡片。手机点 APK，电脑点 EXE。</p></div>
      <div class="dl-step"><b>02</b><h3>允许安装</h3><p>安卓允许“未知来源”；Windows 点“更多信息 → 仍要运行”。私人安装包没有买商业签名。</p></div>
      <div class="dl-step"><b>03</b><h3>登录同一个账号</h3><p>打开 App，用网站原来的用户名和密码登录，菜谱和收藏会自动出现。</p></div>
    </div>
  </section>

  <section class="dl-faq">
    <div class="dl-section-head"><div><div class="dl-eyebrow">QUESTIONS</div><h2>常见问题</h2></div></div>
    <div class="dl-faq-list">
      <details open><summary>安装时提示“未知来源 / 已保护你的电脑”，安全吗？<span>+</span></summary><p>安全。两个安装包都只包含浏览器外壳、图标和站点地址，没有内嵌数据库。因为没上架商店、也没买商业代码签名，系统会按惯例提示；确认文件是你发的再安装即可。</p></details>
      <details><summary>网站更新后，App 要重新安装吗？<span>+</span></summary><p>不用。App 打开的是线上网站，页面和菜谱更新会自动生效。只有换域名、换图标或改 App 名字时才需要重新打包。</p></details>
      <details><summary>为什么 Windows 安装包比 APK 大很多？<span>+</span></summary><p>Windows 版用 Electron 自带浏览器内核，约 74 MB；APK 借用手机系统 WebView，只有 118 KB。两者都不是把 PHP/MySQL 塞进安装包。</p></details>
      <details><summary>iPhone 能装吗？<span>+</span></summary><p>这次的 APK 只适用于 Android。iPhone 可以先用 Safari 打开网站，点分享里的“添加到主屏幕”；要做真正的 iOS 安装包，需要苹果开发者账号和 TestFlight / App Store 分发。</p></details>
    </div>
  </section>
</main>

<footer class="dl-foot">
  <div class="dl-foot-brand"><span class="dl-logo">食</span><span><?= e($siteName) ?> · <?= e($siteSub) ?></span></div>
  <div class="dl-foot-note"><?= e($heroNote) ?></div>
  <div class="dl-foot-links"><a href="<?= e(url('index.php?app=site')) ?>">返回网站</a><a href="<?= e(url('updates.php')) ?>">版本更新</a><a href="<?= e(url('support.php')) ?>">帮助与客服</a></div>
</footer>
<script>
(function () {
  var buttons = document.querySelectorAll('[data-app-link]');
  Array.prototype.forEach.call(buttons, function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      var appLink = (btn.getAttribute('data-app-link') || '').trim();
      var download = (btn.getAttribute('data-download') || '').trim();
      if (!appLink) {
        if (download) { window.location.href = download; }
        return;
      }
      /* http(s) 通用链接由系统接管，不做下载兜底，避免误触 */
      if (/^https?:\/\//i.test(appLink)) {
        window.location.href = appLink;
        return;
      }
      if (btn.getAttribute('data-opening') === '1') { return; }
      btn.setAttribute('data-opening', '1');
      btn.classList.add('is-opening');
      var settled = false;
      var timer = null;
      var onVisibility = function () {
        if (document.hidden) { finish(); }
      };
      var onBlur = function () {
        /* 桌面端 App 被拉起来时窗口会失焦；稍等一下再看是否仍在前台 */
        window.setTimeout(function () {
          if (!document.hidden) { finish(); }
        }, 240);
      };
      function finish() {
        settled = true;
        if (timer) { window.clearTimeout(timer); }
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('blur', onBlur);
        btn.removeAttribute('data-opening');
        btn.classList.remove('is-opening');
      }
      document.addEventListener('visibilitychange', onVisibility);
      window.addEventListener('blur', onBlur);
      timer = window.setTimeout(function () {
        if (settled || document.hidden) { return; }
        finish();
        if (download) { window.location.href = download; }
      }, 1800);
      try {
        window.location.href = appLink;
      } catch (err) {
        finish();
        if (download) { window.location.href = download; }
      }
    });
  });
})();
</script>
</body>
</html>
