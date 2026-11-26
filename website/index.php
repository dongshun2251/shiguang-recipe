<?php
require_once __DIR__ . '/inc/bootstrap.php';

$hot = fetch_all('SELECT * FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY fav_count DESC, views DESC LIMIT 4');

/* 首页主推轮播：先放编辑精选，再补热门和新上架，最多 5 张 */
$heroFeatured = fetch_all('SELECT * FROM `' . T('recipes') . '` WHERE status = "published" AND is_featured = 1 ORDER BY published_at DESC');
$heroPool = [];
$heroSeen = [];
/* 今日种子只随日期变化：同一天刷新顺序稳定，第二天自动换一批/换顺序 */
$heroDaySeed = (int) date('Ymd');
usort($heroFeatured, function ($a, $b) use ($heroDaySeed) {
    $ha = hexdec(substr(hash('sha1', $heroDaySeed . '-' . $a['id']), 0, 8));
    $hb = hexdec(substr(hash('sha1', $heroDaySeed . '-' . $b['id']), 0, 8));
    return $ha === $hb ? ((int) $b['id'] <=> (int) $a['id']) : ($ha <=> $hb);
});
foreach (array_slice($heroFeatured, 0, 5) as $hr) {
    $hr['hero_label'] = '今日主推 · 编辑精选';
    $heroPool[] = $hr;
    $heroSeen[(int) $hr['id']] = true;
}
/* 没有设置首页推荐时，才用热门和新上架补位，避免主推区空白 */
if (!$heroPool) {
    foreach ($hot as $hr) {
        if (isset($heroSeen[(int) $hr['id']])) { continue; }
        $hr['hero_label'] = '本周热门 · 大家都在做';
        $heroPool[] = $hr;
        $heroSeen[(int) $hr['id']] = true;
        if (count($heroPool) >= 5) { break; }
    }
}
if (!$heroPool) {
    $latest = fetch_all('SELECT * FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY published_at DESC, id DESC LIMIT 5');
    foreach ($latest as $hr) {
        if (isset($heroSeen[(int) $hr['id']])) { continue; }
        $hr['hero_label'] = '今日推荐 · 新上桌';
        $heroPool[] = $hr;
        $heroSeen[(int) $hr['id']] = true;
        if (count($heroPool) >= 5) { break; }
    }
}
$featured = $heroPool[0] ?? null;
$wseed = get_int('w');
$weekSwap = get_int('swap');

/* 一周菜单：记住这一组 5 道菜，换掉其中一道时保留其余 4 道 */
$storedSeed = (int) ($_SESSION['home_week_seed'] ?? 0);
$weekIds = array_values(array_filter(array_map('intval', (array) ($_SESSION['home_week_ids'] ?? []))));
$weekSeedChanged = $wseed > 0 ? ($storedSeed !== $wseed) : ($storedSeed > 0);
if ($weekSeedChanged) {
    $weekIds = [];
}
if ($wseed > 0) { $_SESSION['home_week_seed'] = $wseed; } else { $_SESSION['home_week_seed'] = 0; }

if (!$weekIds) {
    if ($wseed > 0) {
        $rows = fetch_all('SELECT id FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY RAND(' . $wseed . ') LIMIT 5');
    } else {
        $rows = fetch_all('SELECT id FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY published_at DESC, id DESC LIMIT 5');
    }
    foreach ($rows as $row) { $weekIds[] = (int) $row['id']; }
    $weekIds = array_values(array_unique($weekIds));
    $_SESSION['home_week_ids'] = $weekIds;
}

/* 单道菜「换一道」：只替换被点的那一道，其余菜和采购清单保持不动 */
if ($weekSwap > 0) {
    $swapPos = array_search($weekSwap, $weekIds, true);
    if ($swapPos !== false) {
        array_splice($weekIds, $swapPos, 1);
        $excludeIds = array_merge($weekIds, [$weekSwap]);
        $notIds = implode(',', array_map('intval', array_unique($excludeIds)));
        $where = 'status = "published"' . ($notIds !== '' ? ' AND id NOT IN (' . $notIds . ')' : '');
        $replacement = fetch_one('SELECT * FROM `' . T('recipes') . '` WHERE ' . $where . ' ORDER BY RAND() LIMIT 1');
        if ($replacement) {
            array_splice($weekIds, $swapPos, 0, [(int) $replacement['id']]);
        }
        $_SESSION['home_week_ids'] = $weekIds;
    }
}

$week = [];
foreach ($weekIds as $wid) {
    $wr = fetch_one('SELECT * FROM `' . T('recipes') . '` WHERE id = ? AND status = "published"', [$wid]);
    if ($wr) { $week[] = $wr; }
}
/* 有菜谱被下架时，清掉失效 ID，避免下次换菜仍带着它 */
if (count($week) !== count($weekIds)) {
    $_SESSION['home_week_ids'] = array_map(function ($r) { return (int) $r['id']; }, $week);
}
sg_categories_ensure_bg();
$cats = fetch_all('SELECT c.*, (SELECT COUNT(*) FROM `' . T('recipes') . '` r WHERE r.category_id = c.id AND r.status = "published") AS cnt,
                   (SELECT r.cover FROM `' . T('recipes') . '` r WHERE r.category_id = c.id AND r.status = "published" AND r.cover <> "" ORDER BY r.is_featured DESC, r.published_at DESC, r.id DESC LIMIT 1) AS first_cover
                   FROM `' . T('categories') . '` c WHERE c.status = 1 ORDER BY c.sort ASC, c.id ASC LIMIT 10');
$total = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE status = "published"', [], 0);
$ingredients = $featured ? recipe_ingredients((int) $featured['id']) : [];
$tips = $featured ? array_values(array_filter(array_map('trim', preg_split('/\n+/', (string) $featured['tips'])))) : [];
$weekdays = ['周一', '周二', '周三', '周四', '周五', '周末'];

/* 本周采购清单：合并「一周菜单」里各道菜的食材，同名同单位相加 */
$shopping = [];
foreach ($week as $wr) {
    foreach (recipe_ingredients((int) $wr['id']) as $ing) {
        $nm = trim((string) $ing['name']);
        if ($nm === '') { continue; }
        $key = mb_strtolower(preg_replace('/\s+/u', '', $nm));
        if (!isset($shopping[$key])) {
            $shopping[$key] = ['name' => $nm, 'qty' => [], 'from' => []];
        }
        $qty = trim((string) $ing['quantity']);
        if ($qty !== '') { $shopping[$key]['qty'][] = $qty; }
        if (!in_array($wr['title'], $shopping[$key]['from'], true)) {
            $shopping[$key]['from'][] = $wr['title'];
        }
    }
}
/* 数量合并：同单位相加，不同单位或非数字用「+」连接 */
$shoppingMerged = [];
foreach ($shopping as $row) {
    $nums = []; $other = [];
    foreach ($row['qty'] as $q) {
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(.*)$/u', $q, $m)) {
            $unit = trim($m[2]);
            $nums[$unit] = ($nums[$unit] ?? 0) + (float) $m[1];
        } else {
            $other[] = $q;
        }
    }
    $parts = [];
    foreach ($nums as $unit => $v) {
        $parts[] = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . ($unit === '' ? '' : ' ' . $unit);
    }
    foreach ($other as $o) { $parts[] = $o; }
    $row['qtyText'] = implode(' + ', $parts);
    $shoppingMerged[] = $row;
}
$shopCount = count($shoppingMerged);

function sg_hero_slide(array $r, bool $active = false, bool $clone = false): void {
    $label = (string) ($r['hero_label'] ?? '今日推荐 · 厨房热门');
    $tab = $clone ? ' tabindex="-1"' : '';
?>
          <div class="hero-slide<?= $active ? ' active' : '' ?>"<?= $clone ? ' aria-hidden="true"' : '' ?>>
            <img src="<?= e(image_url($r['cover'])) ?>" alt="<?= e($r['title']) ?>">
            <div class="hero-in">
              <div class="hero-eyebrow"><?= e($label) ?></div>
              <h1><?= e($r['title']) ?></h1>
              <p><?= e($r['summary']) ?></p>
              <div class="hero-meta">
                <span><span class="ico" data-ico="clock"></span><?= recipe_total_minutes($r) ?> 分钟</span>
                <span><span class="ico" data-ico="chef"></span><?= e($r['difficulty']) ?></span>
                <span><span class="ico" data-ico="flame"></span><?= count(recipe_steps((int) $r['id'])) ?> 个步骤</span>
              </div>
              <div class="hero-actions">
                <a class="btn primary" href="<?= e(recipe_url($r)) ?>"<?= $tab ?>><span class="ico" data-ico="utensils"></span>查看做法</a>
                <button class="btn light" type="button" data-fav="<?= (int) $r['id'] ?>"<?= $tab ?>><span class="ico" data-ico="heart"></span>收藏这道菜</button>
              </div>
            </div>
          </div>
<?php
}
/* 首屏推荐：优先选全站评论点赞最高的菜；再取这道菜点赞前二 */
$wmFeature = $featured ?: ($hot[0] ?? null);
$wmWhy = '目前还没有足够的厨友点赞评价，先由编辑部推荐这道菜。';
$wmQuotes = [];
$wmTopCmt = fetch_one('SELECT c.recipe_id, c.nickname, c.content,
        (SELECT COUNT(*) FROM `' . T('comment_likes') . '` l WHERE l.comment_id = c.id) AS like_count
    FROM `' . T('comments') . '` c
    JOIN `' . T('recipes') . '` r ON r.id = c.recipe_id
    WHERE c.status = "approved" AND c.user_id > 0 AND c.content <> "" AND r.status = "published"
    ORDER BY like_count DESC, c.id DESC LIMIT 1');
if ($wmTopCmt && (int) $wmTopCmt['like_count'] > 0) {
    $wmPick = fetch_one('SELECT * FROM `' . T('recipes') . '` WHERE id = ? AND status = "published"', [(int) $wmTopCmt['recipe_id']]);
    if ($wmPick) {
        $wmFeature = $wmPick;
        $wmWhy = '这道菜的厨友评价被点赞最多，所以今天推荐它。';
        $wmQuotes = fetch_all('SELECT c.id, c.nickname, c.content,
                (SELECT COUNT(*) FROM `' . T('comment_likes') . '` l WHERE l.comment_id = c.id) AS like_count
            FROM `' . T('comments') . '` c
            WHERE c.recipe_id = ? AND c.status = "approved" AND c.user_id > 0 AND c.content <> ""
            ORDER BY like_count DESC, c.id DESC LIMIT 2', [(int) $wmFeature['id']]);
    }
}

/* 首页播报：公告 / 今日推荐 / 系统更新（评论独立到下方评论栏） */
$tickerItems = [];
$homeNotice = trim((string) setting('home_notice', '欢迎来到食光菜谱，今天也要好好吃饭。'));
$noticeLink = trim((string) setting('home_notice_link', ''));
if ($noticeLink === '') { $noticeLink = 'notice.php'; }
if (setting('home_notice_on', '1') === '1' && $homeNotice !== '') {
    $tickerItems[] = ['type' => '公告', 'tone' => 'notice', 'text' => $homeNotice, 'url' => $noticeLink];
}
if ($wmFeature) {
    $tickerItems[] = [
        'type' => '推荐', 'tone' => 'recommend',
        'text' => '今日主推：' . $wmFeature['title'] . ' · ' . recipe_total_minutes($wmFeature) . ' 分钟 · ' . $wmFeature['difficulty'],
        'url' => recipe_url($wmFeature),
    ];
}
$updateText = trim((string) setting('home_update_text', '首页空数据时补上空状态卡片，不再只剩标题和一大片空白。'));
$updateText = trim(preg_replace('/\s+/u', ' ', $updateText));
$updateVersion = trim((string) setting('home_update_version', 'v' . SG_VERSION));
if ($updateVersion === '') {
    $updateVersion = 'v' . SG_VERSION;
} elseif (!preg_match('/^v/i', $updateVersion)) {
    $updateVersion = 'v' . $updateVersion;
}
if (setting('home_update_on', '1') === '1' && $updateText !== '') {
    $tickerItems[] = [
        'type' => '更新', 'tone' => 'sys',
        'text' => $updateVersion . ' 已更新：' . $updateText,
        'url' => 'updates.php',
    ];
}
$btHtml = '';
$btIndex = 0;
foreach ($tickerItems as $item) {
    $tag = '<em class="ht-tag ht-' . e($item['tone']) . '">' . e($item['type']) . '</em>';
    $body = '<span>' . e($item['text']) . '</span>';
    $cls = 'ht-item bt-item' . ($btIndex === 0 ? ' on' : '');
    if (!empty($item['url'])) {
        $btHtml .= '<a class="' . $cls . '" href="' . e($item['url']) . '">' . $tag . $body . '</a>';
    } else {
        $btHtml .= '<span class="' . $cls . '">' . $tag . $body . '</span>';
    }
    $btIndex++;
}

/* 厨友评论栏：只取注册用户（user_id > 0）且已通过的评论，一行循环显示 */
$homeComments = fetch_all('SELECT c.id, c.nickname, c.content, c.recipe_id, r.title,
        (SELECT COUNT(*) FROM `' . T('comment_likes') . '` l WHERE l.comment_id = c.id) AS like_count
    FROM `' . T('comments') . '` c
    JOIN `' . T('recipes') . '` r ON r.id = c.recipe_id
    WHERE c.status = "approved" AND c.user_id > 0 AND c.content <> "" AND r.status = "published"
    ORDER BY c.id DESC');
$commentTickerHtml = '';
foreach ($homeComments as $c) {
    $ctText = trim(preg_replace('/\s+/u', ' ', (string) $c['content']));
    $ctText = mb_substr($ctText, 0, 52, 'UTF-8') . (mb_strlen($ctText, 'UTF-8') > 52 ? '…' : '');
    $commentTickerHtml .= '<a class="ht-item ct-item" href="' . e(recipe_url(['id' => (int) $c['recipe_id']]) . '#comments') . '">'
        . '<b class="ct-name">' . e($c['nickname'] ?: '厨友') . '</b>'
        . '<span class="ct-text">' . e($ctText) . '</span>'
        . '<span class="ct-like"><span class="ico" data-ico="heart"></span>' . (int) $c['like_count'] . '</span>'
        . '</a>';
}
if ($commentTickerHtml === '') {
    $commentTickerHtml = '<span class="ct-empty">还没有注册用户评论，来做第一个分享的人。</span>';
}
$ctDuration = max(36, min(120, 24 + count($homeComments) * 4));

$wmNotice = $homeNotice !== '' ? $homeNotice : ($tickerItems[0]['text'] ?? '');

sg_head('首页', 'home', '首页');
?>
<?php if ($btHtml !== ''): ?>
        <div class="home-ticker broadcast-ticker" data-bt-ticker data-bt-dwell="5" role="region" aria-label="首页播报">
          <span class="ht-label"><span class="ico" data-ico="info"></span>播报</span>
          <div class="bt-stack" data-bt-stack>
            <?= $btHtml ?>
          </div>
        </div>
<?php endif; ?>
        <div class="home-ticker comment-ticker<?= $homeComments ? '' : ' is-empty' ?>" role="region" aria-label="厨友评论" style="--ct-duration:<?= $ctDuration ?>s">
          <span class="ht-label"><span class="ico" data-ico="comment"></span>评论</span>
          <div class="ht-marquee">
            <div class="ht-track">
              <div class="ht-group"><?= $commentTickerHtml ?></div>
              <div class="ht-group" aria-hidden="true"><?= $commentTickerHtml ?></div>
            </div>
          </div>
        </div>

        <div class="welcome-modal" id="welcomeModal" hidden aria-labelledby="wmTitle">
          <div class="wm-backdrop"></div>
          <div class="wm-card" role="dialog" aria-modal="true">
            <button type="button" class="wm-close" data-wm-close aria-label="关闭">×</button>
<?php $wmAudio = welcome_audio_file(); ?>
            <div class="wm-eyebrow">SHIGUANG · 每天好好吃饭</div>
            <h2 id="wmTitle" class="wm-title<?= $wmAudio !== '' ? ' wm-has-audio' : '' ?>"<?= $wmAudio !== '' ? ' role="button" tabindex="0" aria-label="播放欢迎音乐" aria-pressed="false" data-wm-audio="1" title="点击播放欢迎音乐"' : '' ?>><span class="wm-title-text">欢迎来到食光菜谱<?php if ($wmAudio !== ''): ?><span class="wm-audio-ico" aria-hidden="true" data-ico="volume"></span><?php endif; ?></span></h2>
            <p class="wm-sub">不用纠结点什么，今天照着做就好。</p>
<?php if ($wmFeature): ?>
            <a class="wm-feature" href="<?= e(recipe_url($wmFeature)) ?>">
              <img src="<?= e(image_url($wmFeature['cover'])) ?>" alt="<?= e($wmFeature['title']) ?>">
              <div class="wm-feature-main">
                <span>今日推荐</span>
                <b><?= e($wmFeature['title']) ?></b>
                <em><?= recipe_total_minutes($wmFeature) ?> 分钟 · <?= e($wmFeature['difficulty']) ?> · <?= count(recipe_steps((int) $wmFeature['id'])) ?> 个步骤</em>
              </div>
              <span class="wm-arrow">›</span>
            </a>
            <div class="wm-hot wm-why">
              <div class="wm-hot-head"><span>为什么推荐</span><em><?= $wmQuotes ? '厨友点赞最高的评价' : '编辑部推荐' ?></em></div>
              <p class="wm-why-reason"><?= e($wmWhy) ?></p>
<?php if ($wmQuotes): ?>
<?php foreach ($wmQuotes as $i => $h): ?>
<?php
  $hText = trim(preg_replace('/\s+/u', ' ', (string) $h['content']));
  $hText = mb_substr($hText, 0, 72, 'UTF-8') . (mb_strlen($hText, 'UTF-8') > 72 ? '…' : '');
?>
              <a class="wm-hot-item" href="<?= e(recipe_url($wmFeature) . '#comments') ?>">
                <span class="wm-hot-rank"><?= $i + 1 ?></span>
                <div class="wm-hot-main">
                  <div class="wm-hot-top"><b><?= e($h['nickname'] ?: '厨友') ?></b><span class="wm-hot-like"><span class="ico" data-ico="heart"></span><?= (int) $h['like_count'] ?></span></div>
                  <p><?= e($hText) ?></p>
                </div>
              </a>
<?php endforeach; ?>
<?php endif; ?>
            </div>
<?php endif; ?>
            <div class="wm-links">
              <a href="recipes.php"><span class="ico" data-ico="book"></span><b>菜谱库</b><em>按分类找菜</em></a>
              <a href="#week"><span class="ico" data-ico="list"></span><b>本周菜单</b><em><?= count($week) ?> 道已配好</em></a>
              <a href="tips.php"><span class="ico" data-ico="chef"></span><b>新手手册</b><em>第一次下厨</em></a>
            </div>
<?php if ($wmNotice !== ''): ?>
            <div class="wm-notice"><span class="ico" data-ico="info"></span><span><?= e($wmNotice) ?></span></div>
<?php endif; ?>
            <div class="wm-actions">
              <label class="wm-no-today"><input type="checkbox" id="wmNoToday"> 今日不提醒</label>
              <button type="button" class="btn primary wm-start" id="wmStart">开始做饭</button>
            </div>
          </div>
        </div>

        <div class="home-top">
<div class="page-head">
          <h1>今天想做点什么？</h1>
          <span class="sub desk-only"><?= number_format($total) ?> 道家常菜谱 · 每周更新</span>
          <div class="actions">
            <form class="search-field desk-only" action="search.php" method="get">
              <span class="ico" data-ico="search"></span>
              <input name="q" placeholder="搜索菜名或食材" autocomplete="off" data-live-search>
            </form>
            <a class="btn primary desk-only" href="recipes.php"><span class="ico" data-ico="book"></span>浏览菜谱库</a>
          </div>
          <form class="search-field mob-only home-search" id="homeSearch" action="search.php" method="get">
            <span class="ico" data-ico="search"></span>
            <input name="q" placeholder="搜菜名、食材" autocomplete="off" data-live-search>
          </form>
        </div>

<?php if ($heroPool): ?>
        <section class="hero hero-carousel" id="heroCarousel" data-count="<?= count($heroPool) ?>" aria-roledescription="carousel" aria-label="今日主推">
          <div class="hero-track" id="heroTrack">
<?php foreach ($heroPool as $i => $r): sg_hero_slide($r, $i === 0); endforeach; ?>
<?php if (count($heroPool) > 1): sg_hero_slide($heroPool[0], false, true); endif; ?>
          </div>
<?php if (count($heroPool) > 1): ?>
          <div class="hero-dots" aria-label="切换主推">
<?php foreach ($heroPool as $i => $r): ?>
            <button type="button" class="hero-dot<?= $i === 0 ? ' on' : '' ?>" data-slide="<?= $i ?>" aria-label="第 <?= $i + 1 ?> 张：<?= e($r['title']) ?>"></button>
<?php endforeach; ?>
          </div>
<?php endif; ?>
        </section>
        <script>
        (function () {
          var hero = document.getElementById('heroCarousel');
          if (!hero) return;
          var track = document.getElementById('heroTrack');
          var dots = Array.prototype.slice.call(hero.querySelectorAll('.hero-dot'));
          var count = parseInt(hero.getAttribute('data-count'), 10) || 0;
          if (count < 2 || !track) return;
          var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          var index = 0, timer = null;
          function show(i, animate) {
            track.style.transition = animate ? 'transform .72s cubic-bezier(.4,0,.2,1)' : 'none';
            track.style.transform = 'translateX(-' + (i * 100) + '%)';
            dots.forEach(function (dot, j) { dot.classList.toggle('on', j === (i % count)); });
            index = i;
          }
          function tick() {
            var next = index + 1;
            if (next > count) { show(0, false); next = 1; }
            show(next, true);
            if (next === count) {
              window.setTimeout(function () { show(0, false); }, 760);
            }
          }
          function stop() { if (timer) { window.clearInterval(timer); timer = null; } }
          function start() { stop(); if (reduce) return; timer = window.setInterval(tick, 5200); }
          dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
              stop();
              show(parseInt(dot.getAttribute('data-slide'), 10) || 0, true);
              start();
            });
          });
          hero.addEventListener('pointerenter', stop);
          hero.addEventListener('pointerleave', start);
          hero.addEventListener('touchstart', stop, { passive: true });
          hero.addEventListener('touchend', start, { passive: true });
          document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stop(); } else { start(); }
          });
          start();
        })();
        </script>
<?php endif; ?>

        <section class="hub-panel home-cats" aria-labelledby="homeCatsTitle">
          <div class="hub-panel-head"><h2 id="homeCatsTitle">按分类找菜</h2><span>想吃什么，从这里开始</span><a class="more" href="recipes.php">全部菜谱<span class="ico" data-ico="chev"></span></a></div>
<?php if (!$cats): ?>
          <div class="home-empty">
            <span class="ico" data-ico="folder"></span>
            <div><b>还没有分类</b><span><?= current_admin() ? '先去后台「分类管理」添加分类，首页才会出现入口。' : '还没有分类，添加后这里会出现入口。' ?></span></div>
            <?php if (current_admin()): ?><a class="home-empty-go" href="admin/categories.php">去添加<span class="ico" data-ico="chev"></span></a><?php endif; ?>
          </div>
<?php else: ?>
          <div class="cat-strip" id="cats">
<?php foreach ($cats as $c): ?>
<?php
  $catBg = trim((string) ($c['bg'] ?? '')) ?: trim((string) ($c['first_cover'] ?? ''));
  $catBgUrl = $catBg !== '' ? image_url($catBg) : '';
?>
            <a class="cat-tile<?= $catBgUrl !== '' ? ' has-bg' : '' ?>" href="<?= e(cat_url($c['slug'])) ?>"<?= $catBgUrl !== '' ? ' style="background-image:url(\'' . e($catBgUrl) . '\')"' : '' ?>><?= sg_cat_icon((string) $c['icon']) ?><b><?= e($c['name']) ?></b><span><?= (int) $c['cnt'] ?> 道</span></a>
<?php endforeach; ?>
          </div>
<?php endif; ?>
        </section>

        <div class="section-head">
          <h2>本周热门</h2><span class="hint desk-only">按收藏与浏览量排序</span>
        </div>

<?php if (!$hot): ?>
        <div class="home-empty">
          <span class="ico" data-ico="book"></span>
          <div><b>还没有菜谱</b><span>发布第一道菜后，这里会自动出现热门推荐。</span></div>
          <?php if (current_admin()): ?><a class="home-empty-go" href="admin/recipe-edit.php">去发布<span class="ico" data-ico="chev"></span></a><?php endif; ?>
        </div>
<?php else: ?>
        <div class="rgrid">
<?php foreach ($hot as $r): recipe_card($r); endforeach; ?>
        </div>
<?php endif; ?>

        <div class="admin-cols" style="margin-top:8px">
          <div>
            <?php $nextSeed = ($wseed > 0 ? $wseed : (int) date('Ymd')) + 7; ?>
            <div class="section-head" id="week"><h2>一周菜单 · 照着做</h2><a class="more" href="index.php?w=<?= $nextSeed ?>#week"><span class="ico" data-ico="refresh"></span>换一组</a></div>
            <div class="listcard">
<?php if (!$week): ?>
              <div class="home-empty home-empty-flat">
                <span class="ico" data-ico="list"></span>
                <div><b>一周菜单还没生成</b><span>有菜谱后，这里会自动排出一周 5 道菜。</span></div>
                <?php if (current_admin()): ?><a class="home-empty-go" href="admin/recipe-edit.php">去发布<span class="ico" data-ico="chev"></span></a><?php endif; ?>
              </div>
<?php else: ?>
<?php foreach ($week as $i => $r): ?>
              <div class="lrow">
                <a class="lrow-main" href="<?= e(recipe_url($r)) ?>">
                  <span class="n<?= $i === 0 ? ' top' : '' ?>"><?= sprintf('%02d', $i + 1) ?></span>
                  <img src="<?= e(image_url($r['cover'])) ?>" alt="">
                  <div class="m"><b><?= e($r['title']) ?></b><span><?= $weekdays[$i] ?? '本周' ?> · <?= e($r['difficulty']) ?> · <?= recipe_total_minutes($r) ?> 分钟</span></div>
                  <span class="t"><?= number_format((float) $r['rating'], 1) ?></span>
                </a>
                <a class="week-swap" href="index.php?<?= $wseed > 0 ? 'w=' . $wseed . '&amp;' : '' ?>swap=<?= (int) $r['id'] ?>#week" title="换一道：<?= e($r['title']) ?>" aria-label="换一道：<?= e($r['title']) ?>">换一道</a>
              </div>
<?php endforeach; ?>
<?php endif; ?>
            </div>
          </div>
          <div>
            <div class="section-head"><h2>厨房备忘</h2><a class="more" href="shopping.php">采购清单<span class="ico" data-ico="chev"></span></a></div>
            <div class="card shop-card is-collapsed" data-shop-card>
              <div class="card-head shop-head">
                <h3>本周采购清单</h3>
                <div class="shop-head-side">
                  <p class="shop-live"><b>随 <?= count($week) ?> 道菜实时更新</b><span>换菜或改食材后自动汇总</span></p>
<?php if ($shoppingMerged): ?>
                  <button class="shop-toggle" type="button" data-shop-toggle aria-controls="shopBody" aria-expanded="false"><span data-shop-toggle-text>展开</span><span class="ico" data-ico="chev"></span></button>
<?php endif; ?>
                </div>
              </div>
              <div class="shop-body" id="shopBody">
                <div class="ing-grid" style="grid-template-columns:1fr">
<?php foreach ($shoppingMerged as $it): ?>
                  <div class="ing-row shop-row">
                    <span class="nm"><?= e($it['name']) ?>
<?php if (count($it['from']) > 1): ?>
                      <em class="from"><?= e(implode(' · ', array_slice($it['from'], 0, 2))) ?><?= count($it['from']) > 2 ? ' 等' : '' ?></em>
<?php endif; ?>
                    </span><span class="dt"></span><span class="qt"><?= e($it['qtyText'] !== '' ? $it['qtyText'] : '适量') ?></span>
                  </div>
<?php endforeach; ?>
<?php if (!$shoppingMerged): ?>
                  <p style="margin:0;font-size:12px;color:var(--muted)">暂无食材数据，先去后台给菜谱填上食材清单</p>
<?php endif; ?>
                </div>
<?php
                $copyText = '本周采购清单（' . count($week) . ' 道菜）' . "\n";
                foreach ($shoppingMerged as $it) {
                    $copyText .= '· ' . $it['name'] . ' ' . ($it['qtyText'] !== '' ? $it['qtyText'] : '适量') . "\n";
                }
?>
              </div>
              <div class="shop-copy">
                <button class="btn block soft" type="button" data-copy="<?= e(trim($copyText)) ?>"<?= $shoppingMerged ? '' : ' disabled' ?>><span class="ico" data-ico="list"></span>复制采购清单</button>
              </div>
<?php if ($shoppingMerged): ?>
              <button class="shop-fold-fab" type="button" data-shop-toggle aria-controls="shopBody" aria-expanded="false" aria-label="展开本周采购清单"><span class="ico" data-ico="down"></span></button>
<?php endif; ?>
            </div>
            <div class="card tips-card" style="margin-top:14px;background:#FBF6EE">
              <div class="card-head"><h3>新手小贴士</h3><a class="more" href="<?= e($GLOBALS['SG_BASE']) ?>tips.php">完整手册<span class="ico" data-ico="chev"></span></a></div>
              <div class="tips">
<?php foreach (sg_home_cooking_tips() as $ht): ?>
                <div class="tip<?= !empty($ht['warn']) ? ' warn' : '' ?>"><span class="ico" data-ico="<?= !empty($ht['warn']) ? 'alert' : 'info' ?>"></span><span><em class="tip-cat"><?= e($ht['cat']) ?></em><?= e($ht['text']) ?></span></div>
<?php endforeach; ?>
<?php if ($tips): ?>
                <div class="tip tip-recipe"><span class="ico" data-ico="chef"></span><span><em class="tip-cat">这道菜</em><?= e($tips[0]) ?></span></div>
<?php endif; ?>
              </div>
              <p class="tip-more">火候、调味、下锅顺序、厨房安全和翻车补救，都在完整手册里。</p>
            </div>
          </div>
        </div>
<?php
sg_foot();
