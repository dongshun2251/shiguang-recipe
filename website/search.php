<?php
require_once __DIR__ . '/inc/bootstrap.php';

$q      = trim(get('q'));
$page   = max(1, get_int('page', 1));
$per    = 8;
$rows   = [];
$total  = 0;
$took   = 0.0;
$recent = $_SESSION['search_history'] ?? [];

$cat    = get_int('cat');
$diff   = trim(get('diff'));
$bucket = trim(get('time'));
$sort   = trim(get('sort', 'hot'));
if (!in_array($sort, ['hot', 'new', 'fast'], true)) { $sort = 'hot'; }

if (get('clear') === '1') {
    unset($_SESSION['search_history']);
    redirect('search.php');
}

$hasFilter = $cat > 0 || $diff !== '' || $bucket !== '' || $sort !== 'hot';
$filterCount = ($cat > 0 ? 1 : 0) + ($diff !== '' ? 1 : 0) + ($bucket !== '' ? 1 : 0) + ($sort !== 'hot' ? 1 : 0);
$showList  = $q !== '' || $hasFilter;

/* AI 语义找菜：把自然语言解析成食材/耗时/难度条件 */
$aiWant = get('ai') === '1' && ai_enabled() && $q !== '';
$aiIntent = null;
$aiErr = '';
if ($aiWant) {
    [$aiOk, $aiData, $aiMsg] = ai_search_intent($q);
    if ($aiOk && is_array($aiData)) {
        $aiIntent = $aiData;
        $kw   = array_values(array_filter(array_map('trim', (array) ($aiData['keywords'] ?? []))));
        $ings = array_values(array_filter(array_map('trim', (array) ($aiData['ingredients'] ?? []))));
        if ($kw)   { $q = implode(' ', array_slice($kw, 0, 3)); }
        $maxMin = (int) ($aiData['max_minutes'] ?? 0);
        if ($maxMin > 0 && $bucket === '') {
            $bucket = $maxMin <= 15 ? '15' : ($maxMin <= 30 ? '30' : ($maxMin <= 60 ? '60' : ''));
        }
        $diffAi = (string) ($aiData['difficulty'] ?? '');
        if ($diffAi !== '' && $diff === '') {
            $map = ['初级' => '入门', '中级' => '初级', '高级' => '进阶', '入门' => '入门', '进阶' => '进阶'];
            $diff = $map[$diffAi] ?? '';
        }
        $catAi = (string) ($aiData['category'] ?? '');
        if ($catAi !== '' && $cat === 0) {
            foreach (fetch_all('SELECT id, name FROM `' . T('categories') . '` WHERE status = 1') as $cRow) {
                if (mb_strpos($cRow['name'], $catAi) !== false || mb_strpos($catAi, $cRow['name']) !== false) {
                    $cat = (int) $cRow['id'];
                    break;
                }
            }
        }
    } else {
        $aiErr = $aiMsg ?: 'AI 解析失败，已按关键词搜索';
        $aiWant = false;
    }
}

if ($showList) {
    $t0   = microtime(true);
    $conds  = ['r.status = "published"'];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $conds[] = '(r.title LIKE ? OR r.summary LIKE ? OR r.author_name LIKE ?
            OR EXISTS (SELECT 1 FROM `' . T('recipe_ingredients') . '` i WHERE i.recipe_id = r.id AND i.name LIKE ?)
            OR EXISTS (SELECT 1 FROM `' . T('tags') . '` tg JOIN `' . T('recipe_tags') . '` rt ON rt.tag_id = tg.id WHERE rt.recipe_id = r.id AND tg.name LIKE ?))';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($cat > 0) {
        $conds[] = 'r.category_id = ?';
        $params[] = $cat;
    }
    if ($diff !== '') {
        $conds[] = 'r.difficulty = ?';
        $params[] = $diff;
    }
    if ($bucket === '15') {
        $conds[] = '(r.prep_minutes + r.cook_minutes) <= 15';
    } elseif ($bucket === '30') {
        $conds[] = '(r.prep_minutes + r.cook_minutes) <= 30';
    } elseif ($bucket === '60') {
        $conds[] = '(r.prep_minutes + r.cook_minutes) <= 60';
    } elseif ($bucket === '60p') {
        $conds[] = '(r.prep_minutes + r.cook_minutes) > 60';
    }

    if ($sort === 'new') {
        $order = 'r.published_at DESC, r.id DESC';
    } elseif ($sort === 'fast') {
        $order = '(r.prep_minutes + r.cook_minutes) ASC, r.fav_count DESC';
    } else {
        $order = 'r.fav_count DESC, r.views DESC';
    }

    $where = implode(' AND ', $conds);
    $total = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` r WHERE ' . $where, $params, 0);
    $pages = max(1, (int) ceil($total / $per));
    $page  = min($page, $pages);
    $rows  = $total ? fetch_all('SELECT r.* FROM `' . T('recipes') . '` r WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . (($page - 1) * $per) . ', ' . $per, $params) : [];
    $took  = round((microtime(true) - $t0) * 1000);

    if ($q !== '') {
        $_SESSION['search_history'] = array_slice(array_values(array_unique(array_merge([$q], $recent))), 0, 6);
    }
}

/* 筛选用到的选项 */
$catsForFilter = fetch_all('SELECT id, name FROM `' . T('categories') . '` WHERE status = 1 ORDER BY sort ASC, id ASC');
$diffsForFilter = fetch_all('SELECT DISTINCT difficulty FROM `' . T('recipes') . '` WHERE status = "published" AND difficulty <> "" ORDER BY difficulty ASC');
$qsBase = array_filter(['q' => $q, 'cat' => $cat ?: null, 'diff' => $diff, 'time' => $bucket, 'sort' => $sort !== 'hot' ? $sort : null, 'ai' => $aiWant ? '1' : null], function ($v) { return $v !== null && $v !== ''; });

$hotTags = fetch_all('SELECT t.name, COUNT(*) c FROM `' . T('tags') . '` t JOIN `' . T('recipe_tags') . '` rt ON rt.tag_id = t.id GROUP BY t.name ORDER BY c DESC LIMIT 7');
$ingsHot = fetch_all('SELECT name, COUNT(*) c FROM `' . T('recipe_ingredients') . '` GROUP BY name ORDER BY c DESC LIMIT 6');

function search_hl(string $text, string $q): string
{
    if ($q === '') {
        return e($text);
    }
    $safe = e($text);
    return str_ireplace(e($q), '<mark class="hl">' . e($q) . '</mark>', $safe);
}

sg_head('搜索', 'search', '搜索菜谱');
?>
        <div class="page-head search-page-head">
          <h1>搜索菜谱</h1>
          <span class="sub desk-only">按菜名、食材、标签或一句话找菜</span>
        <div class="search-top">
          <form class="search-field search-main" method="get" action="search.php">
            <span class="ico" data-ico="search"></span>
            <input name="q" value="<?= e($q) ?>" placeholder="菜名、食材" autocomplete="off" data-live-search>
<?php if ($cat > 0): ?><input type="hidden" name="cat" value="<?= (int) $cat ?>"><?php endif; ?>
<?php if ($diff !== ''): ?><input type="hidden" name="diff" value="<?= e($diff) ?>"><?php endif; ?>
<?php if ($bucket !== ''): ?><input type="hidden" name="time" value="<?= e($bucket) ?>"><?php endif; ?>
<?php if ($sort !== 'hot'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
<?php if (ai_enabled() && $q !== ''): ?>
            <input type="hidden" name="ai" value="<?= $aiWant ? '1' : '0' ?>">
<?php endif; ?>
            <button class="btn primary sm" type="submit">搜索</button>
          </form>

          <div class="search-filter-wrap">
            <button type="button" class="filter-toggle<?= $filterCount ? ' on' : '' ?>" id="filterToggle" aria-expanded="false" aria-controls="filterPanel" title="筛选">
              <span class="ico" data-ico="filter"></span><span class="ft-label">筛选</span><?php if ($filterCount): ?><b class="fcount"><?= $filterCount ?></b><?php endif; ?>
            </button>
            <div class="filter-panel" id="filterPanel" hidden>
              <form method="get" action="search.php">
                <input type="hidden" name="q" value="<?= e($q) ?>">
<?php if ($aiWant): ?><input type="hidden" name="ai" value="1"><?php endif; ?>
                <div class="fp-grid">
                  <label class="fp-field"><span>分类</span>
                    <select name="cat">
                      <option value="">全部分类</option>
<?php foreach ($catsForFilter as $c): ?>
                      <option value="<?= (int) $c['id'] ?>"<?= $cat === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
<?php endforeach; ?>
                    </select>
                  </label>
                  <label class="fp-field"><span>难度</span>
                    <select name="diff">
                      <option value="">全部难度</option>
<?php foreach ($diffsForFilter as $d): ?>
                      <option value="<?= e($d['difficulty']) ?>"<?= $diff === $d['difficulty'] ? ' selected' : '' ?>><?= e($d['difficulty']) ?></option>
<?php endforeach; ?>
                    </select>
                  </label>
                  <label class="fp-field"><span>耗时</span>
                    <select name="time">
                      <option value="">不限耗时</option>
                      <option value="15"<?= $bucket === '15' ? ' selected' : '' ?>>15 分钟内</option>
                      <option value="30"<?= $bucket === '30' ? ' selected' : '' ?>>30 分钟内</option>
                      <option value="60"<?= $bucket === '60' ? ' selected' : '' ?>>1 小时内</option>
                      <option value="60p"<?= $bucket === '60p' ? ' selected' : '' ?>>1 小时以上</option>
                    </select>
                  </label>
                  <label class="fp-field"><span>排序</span>
                    <select name="sort">
                      <option value="hot"<?= $sort === 'hot' ? ' selected' : '' ?>>最热门</option>
                      <option value="new"<?= $sort === 'new' ? ' selected' : '' ?>>最新发布</option>
                      <option value="fast"<?= $sort === 'fast' ? ' selected' : '' ?>>最快上手</option>
                    </select>
                  </label>
                </div>
                <div class="fp-actions">
                  <button class="btn primary sm" type="submit">查看结果</button>
<?php if ($hasFilter): ?><a class="chip" href="search.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">清除筛选</a><?php endif; ?>
                </div>
              </form>
            </div>
          </div>
        </div>
        </div>

        <div class="search-suggest card is-collapsed" id="searchSuggest">
          <div class="suggest-grid">
            <section class="suggest-block">
              <div class="suggest-head"><h3>热门搜索</h3><span>点一下直接搜</span>
                <button type="button" class="suggest-toggle" id="searchSuggestToggle" data-suggest-toggle aria-expanded="false" aria-controls="searchSuggest" title="展开热门搜索和食材建议">
                  <span class="ico" data-ico="chev"></span>
                </button>
              </div>
              <div class="chips">
<?php foreach ($hotTags as $t): ?><a class="chip" href="search.php?q=<?= urlencode($t['name']) ?>"><?= e($t['name']) ?></a><?php endforeach; ?>
<?php if (!$hotTags): ?><span class="suggest-empty">暂无标签数据</span><?php endif; ?>
              </div>
            </section>
            <section class="suggest-block">
              <div class="suggest-head"><h3>按食材找菜</h3><span>家里有什么就搜什么</span></div>
              <div class="chips">
<?php foreach ($ingsHot as $i): ?><a class="chip" href="search.php?q=<?= urlencode($i['name']) ?>"><?= e($i['name']) ?></a><?php endforeach; ?>
<?php if (!$ingsHot): ?><span class="suggest-empty">暂无食材数据</span><?php endif; ?>
              </div>
            </section>
          </div>
<?php if ($recent): ?>
          <div class="suggest-recent">
            <b>最近搜索</b>
            <div class="chips">
<?php foreach ($recent as $kw): ?><a class="chip" href="search.php?q=<?= urlencode($kw) ?>"><?= e($kw) ?></a><?php endforeach; ?>
            </div>
            <a class="suggest-clear" href="search.php?clear=1" data-confirm="清空最近搜索记录？">清空</a>
          </div>
<?php endif; ?>
          <button type="button" class="suggest-peek" data-suggest-toggle aria-expanded="false" aria-controls="searchSuggest" aria-label="展开更多搜索建议">
            <span class="ico" data-ico="chev"></span>
          </button>
        </div>

<?php if (ai_enabled()): ?>
        <div class="ai-hint" style="display:flex;gap:10px;align-items:center;margin:-6px 0 12px;font-size:12px;color:var(--muted);flex-wrap:wrap">
          <span>AI 找菜：直接说「只有番茄和鸡蛋，20 分钟能做什么」</span>
<?php if ($q !== ''): ?>
          <a class="chip<?= $aiWant ? ' on' : '' ?>" href="search.php?q=<?= urlencode($q) ?>&ai=<?= $aiWant ? '0' : '1' ?>"><?= $aiWant ? '已用语义解析' : '用 AI 理解这句话' ?></a>
<?php endif; ?>
        </div>
<?php endif; ?>
<?php if ($aiErr !== ''): ?>
        <div class="alert" style="margin-bottom:12px"><span class="ico" data-ico="alert"></span><span><?= e($aiErr) ?></span></div>
<?php endif; ?>

        <div class="search-results">
            <div class="section-head" style="margin-top:6px">
              <h2><?= $showList ? '搜索结果' : '试试这些' ?></h2>
              <?php if ($showList): ?><span class="hint"><?= $aiWant && $aiIntent ? 'AI 理解为「' . e(implode('、', array_filter(array_merge((array) ($aiIntent['keywords'] ?? []), (array) ($aiIntent['ingredients'] ?? [])))) . '」· ') : ($q !== '' ? '关键词「' . e($q) . '」· ' : '') ?><?= $hasFilter || $aiWant ? '已筛选 · ' : '' ?>共 <?= $total ?> 道菜谱 · <?= $took ?> ms</span><?php else: ?><span class="hint">按用户评分优先 · 已避开首页 · 每次随机换一批</span><?php endif; ?>
            </div>

<?php if ($showList && $rows): ?>
            <div style="display:flex;flex-direction:column;gap:12px">
<?php foreach ($rows as $r): ?>
              <a class="sres" href="<?= e(recipe_url($r)) ?>">
                <img src="<?= e(image_url($r['cover'])) ?>" alt="">
                <div class="b">
                  <h3><?= search_hl($r['title'], $q) ?></h3>
                  <p data-marquee><?= search_hl($r['summary'], $q) ?></p>
                  <div class="f">
                    <span class="tag warm"><?= e(category_name((int) $r['category_id'])) ?></span>
                    <span><?= recipe_total_minutes($r) ?> 分钟</span>
                    <span><?= e($r['difficulty']) ?></span>
                    <span style="margin-left:auto"><?= (int) $r['fav_count'] ?> 收藏</span>
                  </div>
                </div>
              </a>
<?php endforeach; ?>
            </div>
            <?= pager($page, $total, $per, 'search.php?' . http_build_query($qsBase)) ?>

<?php elseif ($showList): ?>
            <div class="empty">
              <div class="ico" data-ico="search"></div>
              <b>没有符合条件的菜谱</b>
              <p>换个关键词试试，比如食材名或菜名中的两个字。</p>
            </div>
            <?php if ($hotTags): ?>
            <div class="card" style="margin-top:14px">
              <div class="card-head"><h3>热门标签</h3></div>
              <div class="chips">
<?php foreach ($hotTags as $t): ?><a class="chip" href="search.php?q=<?= urlencode($t['name']) ?>"><?= e($t['name']) ?></a><?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

<?php else: ?>
            <div class="rgrid" style="margin-top:4px">
<?php
            $excludeIds = sg_home_recipe_ids();
            $where = 'r.status = "published"';
            if ($excludeIds) {
                $where .= ' AND r.id NOT IN (' . implode(',', array_map('intval', $excludeIds)) . ')';
            }
            $latest = fetch_all('SELECT r.* FROM `' . T('recipes') . '` r WHERE ' . $where
                . ' ORDER BY CASE WHEN r.rating_count > 0'
                . ' THEN (r.rating * r.rating_count + 4.5 * 5) / (r.rating_count + 5)'
                . ' ELSE LEAST(r.rating, 4.6) * 0.8 END DESC,'
                . ' r.rating_count DESC, r.fav_count DESC, r.views DESC LIMIT 18');
            // 菜谱太少、避开首页后一道都不剩时，才用全站高分菜谱补足，避免推荐区空白
            if (!$latest && $excludeIds) {
                $latest = fetch_all('SELECT r.* FROM `' . T('recipes') . '` r WHERE r.status = "published"'
                    . ' ORDER BY CASE WHEN r.rating_count > 0'
                    . ' THEN (r.rating * r.rating_count + 4.5 * 5) / (r.rating_count + 5)'
                    . ' ELSE LEAST(r.rating, 4.6) * 0.8 END DESC,'
                    . ' r.rating_count DESC, r.fav_count DESC, r.views DESC LIMIT 8');
            }
            shuffle($latest);
            $latest = array_slice($latest, 0, 8);
            foreach ($latest as $r) {
                recipe_card($r);
            }
            ?>
            </div>
<?php endif; ?>
        </div>
<script>
(function () {
  var btn = document.getElementById('filterToggle');
  var panel = document.getElementById('filterPanel');
  if (!btn || !panel) { return; }
  function setOpen(open) {
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.classList.toggle('open', open);
  }
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    setOpen(panel.hidden);
  });
  panel.addEventListener('click', function (e) { e.stopPropagation(); });
  document.addEventListener('click', function () {
    if (!panel.hidden) { setOpen(false); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) { setOpen(false); btn.focus(); }
  });
})();
(function () {
  var box = document.getElementById('searchSuggest');
  var toggles = document.querySelectorAll('[data-suggest-toggle]');
  if (!box || !toggles.length) { return; }
  function setOpen(open) {
    box.classList.toggle('is-open', open);
    box.classList.toggle('is-collapsed', !open);
    toggles.forEach(function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
  }
  toggles.forEach(function (t) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      setOpen(!box.classList.contains('is-open'));
    });
  });
})();
</script>
<?php
sg_foot();
