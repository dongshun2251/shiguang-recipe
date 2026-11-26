<?php
require_once __DIR__ . '/inc/bootstrap.php';

$per    = max(4, (int) setting('per_page', '12'));
$page   = max(1, get_int('page', 1));
$catSlug = trim(get('cat'));
$diff   = trim(get('diff'));
$time   = trim(get('time'));
$sort   = get('sort', 'hot');

$cats = fetch_all('SELECT c.*, (SELECT COUNT(*) FROM `' . T('recipes') . '` r WHERE r.category_id = c.id AND r.status = "published") AS cnt
                   FROM `' . T('categories') . '` c WHERE c.status = 1 ORDER BY c.sort ASC, c.id ASC');

$w = ['r.status = "published"'];
$p = [];

if ($catSlug !== '') {
    $cat = fetch_one('SELECT * FROM `' . T('categories') . '` WHERE slug = ?', [$catSlug]);
    if ($cat) {
        $w[] = 'r.category_id = ?';
        $p[] = (int) $cat['id'];
    }
}
if (in_array($diff, ['入门', '初级', '进阶'], true)) {
    $w[] = 'r.difficulty = ?';
    $p[] = $diff;
}
if ($time === '15') {
    $w[] = '(r.prep_minutes + r.cook_minutes) <= 15';
} elseif ($time === '30') {
    $w[] = '(r.prep_minutes + r.cook_minutes) <= 30';
} elseif ($time === '60') {
    $w[] = '(r.prep_minutes + r.cook_minutes) <= 60';
}

$orders = [
    'hot'   => 'r.fav_count DESC, r.views DESC',
    'new'   => 'r.published_at DESC, r.id DESC',
    'quick' => '(r.prep_minutes + r.cook_minutes) ASC',
];
$order = $orders[$sort] ?? $orders['hot'];

$where = implode(' AND ', $w);
$total = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` r WHERE ' . $where, $p, 0);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$rows  = fetch_all('SELECT r.* FROM `' . T('recipes') . '` r WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . (($page - 1) * $per) . ', ' . $per, $p);

$qs = ['cat' => $catSlug, 'diff' => $diff, 'time' => $time, 'sort' => $sort];
$baseUrl = 'recipes.php?' . http_build_query(array_filter($qs));

sg_head('菜谱库', 'recipes', '菜谱库');
?>
        <div class="page-head">
          <h1>菜谱库</h1>
          <span class="sub desk-only">共 <?= number_format($total) ?> 道<?= $catSlug && $cat ? ' · ' . e($cat['name']) : '' ?></span>
          <div class="actions">
            <form class="search-field desk-only" action="search.php" method="get">
              <span class="ico" data-ico="search"></span>
              <input name="q" placeholder="搜索菜名或食材">
            </form>
            <a class="btn desk-only" href="recipes.php"><span class="ico" data-ico="refresh"></span>重置</a>
          </div>
        </div>

        <section class="recipe-filter" aria-labelledby="filterTitle">
          <div class="recipe-filter-head"><h2 id="filterTitle">筛选菜谱</h2><span>按分类、难度和时长挑一道</span></div>
<?php if ($cats): ?>
        <div class="chips cat-scroll" id="cats">
          <a class="chip<?= $catSlug === '' ? ' on' : '' ?>" href="recipes.php">全部</a>
<?php foreach ($cats as $c): ?>
          <a class="chip<?= $catSlug === $c['slug'] ? ' on' : '' ?>" href="<?= e(cat_url($c['slug'])) ?>"><?= e($c['name']) ?></a>
<?php endforeach; ?>
        </div>
        <script>
        (function () {
          var box = document.getElementById('cats');
          if (!box) return;
          var on = box.querySelector('.chip.on');
          if (on && box.scrollWidth > box.clientWidth) {
            box.scrollLeft = Math.max(0, on.offsetLeft - (box.clientWidth - on.offsetWidth) / 2);
          }
        })();
        </script>
<?php endif; ?>

        <form class="filterbar" method="get" action="recipes.php">
          <input type="hidden" name="cat" value="<?= e($catSlug) ?>">
          <span class="fl">难度
            <select name="diff" onchange="this.form.submit()">
              <option value="">全部</option>
<?php foreach (['入门', '初级', '进阶'] as $d): ?>
              <option value="<?= $d ?>"<?= $diff === $d ? ' selected' : '' ?>><?= $d ?></option>
<?php endforeach; ?>
            </select>
          </span>
          <span class="fl desk-only">时长
            <select name="time" onchange="this.form.submit()">
              <option value="">不限</option>
              <option value="15"<?= $time === '15' ? ' selected' : '' ?>>15 分钟内</option>
              <option value="30"<?= $time === '30' ? ' selected' : '' ?>>30 分钟内</option>
              <option value="60"<?= $time === '60' ? ' selected' : '' ?>>1 小时内</option>
            </select>
          </span>
          <div class="right">
            <span class="desk-only" style="font-size:11.5px;color:var(--muted)"><?= number_format($total) ?> 条结果</span>
            <span class="fl">排序
            <select class="select" name="sort" onchange="this.form.submit()">
              <option value="hot"<?= $sort === 'hot' ? ' selected' : '' ?>>最热门</option>
              <option value="new"<?= $sort === 'new' ? ' selected' : '' ?>>最新收录</option>
              <option value="quick"<?= $sort === 'quick' ? ' selected' : '' ?>>最省时间</option>
            </select>
            </span>
          </div>
        </form>
        </section>

        <div class="section-head">
          <h2>菜谱结果</h2>
          <span class="hint"><?= number_format($total) ?> 道符合条件</span>
        </div>

<?php if ($rows): ?>
        <div class="rgrid">
<?php foreach ($rows as $r): recipe_card($r); endforeach; ?>
        </div>
        <?= pager($page, $total, $per, $baseUrl) ?>
<?php else: ?>
        <div class="empty" style="margin-top:12px">
          <div class="ico" data-ico="search"></div>
          <b>没有找到符合条件的菜谱</b>
          <p>试试放宽难度或时长条件，或者<a href="recipes.php" style="color:var(--brand)">查看全部菜谱</a>。</p>
        </div>
<?php endif; ?>
<?php
sg_foot();
