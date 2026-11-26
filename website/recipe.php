<?php
require_once __DIR__ . '/inc/bootstrap.php';

$id = get_int('id');
$r  = $id ? fetch_one('SELECT * FROM `' . T('recipes') . '` WHERE id = ? AND status = "published"', [$id]) : null;

if (!$r) {
    http_response_code(404);
    sg_head('菜谱不存在', 'detail', '菜谱详情');
    ?>
        <div class="page-head"><h1>菜谱不存在</h1></div>
        <div class="empty"><div class="ico" data-ico="search"></div><b>这道菜谱可能已下架</b><p><a href="recipes.php" style="color:var(--brand)">返回菜谱库</a></p></div>
    <?php
    sg_foot();
    exit;
}

/* 浏览计数 & 历史 */
q('UPDATE `' . T('recipes') . '` SET views = views + 1 WHERE id = ?', [$id]);
history_add($id);

/* 提交评论 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'comment') {
    csrf_verify();
    $content = trim(post('content'));
    $u       = current_user();
    $nick    = $u ? ($u['display_name'] ?: $u['username']) : trim(post('nickname', '匿名用户'));
    if ($content === '') {
        flash('评论内容不能为空', 'err');
    } elseif (mb_strlen($content) > 500) {
        flash('评论最多 500 字', 'err');
    } else {
        $status = setting('comment_review', '1') === '1' ? 'pending' : 'approved';
        db_insert(T('comments'), [
            'recipe_id' => $id,
            'user_id'   => $u ? (int) $u['id'] : 0,
            'nickname'  => $nick !== '' ? $nick : '匿名用户',
            'content'   => $content,
            'status'    => $status,
            'ip'        => client_ip(),
        ]);
        flash($status === 'pending' ? '评论已提交，审核通过后显示' : '评论已发布，感谢分享');
    }
    redirect(recipe_url($r) . '#comments');
}

/* 用户打分（登录用户一人一票，可修改） */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'rate') {
    csrf_verify();
    $u     = current_user();
    $score = (int) post('score');
    if (!$u) {
        flash('登录后才能给菜谱打分', 'err');
    } elseif ($score < 1 || $score > 5) {
        flash('请选择 1–5 星', 'err');
    } else {
        $old = fetch_one('SELECT id FROM `' . T('ratings') . '` WHERE recipe_id = ? AND user_id = ?', [$id, (int) $u['id']]);
        if ($old) {
            db_update(T('ratings'), ['rating' => $score], 'id = ?', [(int) $old['id']]);
        } else {
            try {
                db_insert(T('ratings'), [
                    'recipe_id' => $id,
                    'user_id'   => (int) $u['id'],
                    'rating'    => $score,
                ]);
            } catch (Throwable $e) {
                // 并发提交时唯一键冲突，退化为更新
                if (strpos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
                q('UPDATE `' . T('ratings') . '` SET rating = ? WHERE recipe_id = ? AND user_id = ?', [$score, $id, (int) $u['id']]);
            }
        }
        sg_recipe_rating_refresh($id);
        flash('已提交评分：' . $score . ' 星');
    }
    redirect(recipe_url($r) . '#rate');
}

$cats      = recipe_tags($id);
$category  = category_name((int) $r['category_id']);
$ings      = ingredients_with_image(recipe_ingredients($id));
$steps     = recipe_steps($id);
$tips      = array_values(array_filter(array_map('trim', preg_split('/\n+/', (string) $r['tips']))));
$uNow      = current_user();
$cmtUid    = $uNow ? (int) $uNow['id'] : 0;
$cmtSql = 'SELECT c.*' . (sg_has_column('users', 'avatar') ? ', u.avatar AS user_avatar' : '') . ',
    (SELECT COUNT(*) FROM `' . T('comment_likes') . '` l WHERE l.comment_id = c.id) AS like_count,
    (SELECT COUNT(*) FROM `' . T('comment_likes') . '` l WHERE l.comment_id = c.id AND l.user_id = ?) AS liked
    FROM `' . T('comments') . '` c' . (sg_has_column('users', 'avatar') ? ' LEFT JOIN `' . T('users') . '` u ON u.id = c.user_id' : '') . '
    WHERE c.recipe_id = ? AND c.status = "approved" ORDER BY c.id DESC LIMIT 20';
$comments  = fetch_all($cmtSql, [$cmtUid, $id]);
$related   = fetch_all('SELECT * FROM `' . T('recipes') . '` WHERE status = "published" AND id <> ? AND category_id = ? ORDER BY fav_count DESC LIMIT 3', [$id, (int) $r['category_id']]);
if (!$related) {
    $related = fetch_all('SELECT * FROM `' . T('recipes') . '` WHERE status = "published" AND id <> ? ORDER BY fav_count DESC LIMIT 3', [$id]);
}
$fav    = is_fav($id);
$ratingStats = sg_recipe_rating_stats($id, $uNow ? (int) $uNow['id'] : 0);
$heroRating  = $ratingStats['count'] > 0 ? $ratingStats['avg'] : (float) $r['rating'];
/* 基准人份：取 servings 里第一个数字，'3–4 人份' => 3；没有数字按 2 */
$servBase = 2;
if (preg_match('/(\d+)/u', (string) $r['servings'], $mm)) { $servBase = max(1, (int) $mm[1]); }
$mins   = recipe_total_minutes($r);
$ingCount = count($ings);

sg_head($r['title'], 'detail', '菜谱详情');
?>
        <div class="mob-topbar-spacer"></div>
        <div class="mob-topbar">
          <a class="mt-back" href="recipes.php" aria-label="返回"><span class="ico" data-ico="back"></span></a>
          <span class="mt-title"><?= e($r['title']) ?></span>
          <span class="mt-sp" aria-hidden="true"></span>
        </div>
        <div class="page-head desk-only">
          <a class="btn sm" href="recipes.php"><span class="ico" data-ico="back"></span>返回菜谱库</a>
        </div>

        <section class="detail-hero">
          <img src="<?= e(image_url($r['cover'])) ?>" alt="<?= e($r['title']) ?>">
          <div class="hero-top">
            <button type="button" class="hero-fav fav<?= $fav ? ' on' : '' ?>" data-fav="<?= $id ?>" aria-pressed="<?= $fav ? 'true' : 'false' ?>" title="收藏"><span class="ico" data-ico="heart"></span></button>
          </div>
          <div class="hero-bot">
            <div class="kicker">
              <span class="tag"><?= e($category) ?></span>
<?php foreach (array_slice($cats, 0, 2) as $t): ?>
              <span class="tag"><?= e($t['name']) ?></span>
<?php endforeach; ?>
            </div>
            <h1><?= e($r['title']) ?></h1>
            <div class="hm">
              <span><span class="ico" data-ico="clock"></span>准备 <?= (int) $r['prep_minutes'] ?> 分钟 · 烹饪 <?= (int) $r['cook_minutes'] ?> 分钟</span>
              <span><span class="ico" data-ico="chef"></span><?= e($r['difficulty']) ?></span>
              <span><span class="ico" data-ico="star"></span><?= number_format($heroRating, 1) ?>（<?= $ratingStats['count'] > 0 ? $ratingStats['count'] . ' 人评分' : '编辑评分' ?>）</span>
              <span><span class="ico" data-ico="calendar"></span><?= e(fmt_date($r['updated_at'])) ?> 更新</span>
            </div>
          </div>
        </section>

        <?= flash_render() ?>

        <div class="detail-actions">
          <button class="btn<?= $fav ? ' on' : '' ?>" type="button" data-fav="<?= $id ?>" aria-pressed="<?= $fav ? 'true' : 'false' ?>"><span class="ico" data-ico="heart"></span><span class="lbl"><?= $fav ? '已收藏' : '收藏' ?></span></button>
          <a class="btn primary" href="cook.php?id=<?= $id ?>"><span class="ico" data-ico="flame"></span>开始烹饪</a>
        </div>

        <div class="detail-cols" style="margin-top:20px">
          <div>
            <div class="card">
              <div class="card-head"><h3>这道菜</h3><span class="hint">作者：<?= e($r['author_name'] ?: '食光编辑部') ?></span>
                <div class="right">
                  <button class="btn sm" type="button" data-share><span class="ico" data-ico="share"></span>分享</button>
                  <button class="btn sm" type="button" data-poster><span class="ico" data-ico="image"></span>生成菜谱长图</button>
                  <?php if (ai_enabled()): ?>
                  <button class="btn sm" type="button" data-ai-copy="<?= $id ?>"><span class="ico" data-ico="bolt"></span>AI 文案</button>
                  <?php endif; ?>
                  <button class="btn sm desk-only" type="button" onclick="window.print()"><span class="ico" data-ico="print"></span>打印</button>
                </div>
              </div>
              <p class="intro-text"><?= e($r['summary']) ?></p>
              <?php if (!empty($tips[0])): ?>
              <div class="quote-note">关键提示：<?= e($tips[0]) ?></div>
              <?php endif; ?>
            </div>

            <div class="card" style="margin-top:14px">
              <div class="card-head">
                <h3>食材清单</h3><span class="hint"><?= $ingCount ?> 种食材 · <?= e($r['servings']) ?></span>
                <div class="right"><span class="portion" data-base="<?= $servBase ?>"><button type="button" data-portion="-">−</button><span><?= $servBase ?> 人份</span><button type="button" data-portion="+">+</button></span></div>
              </div>
              <div class="ing-grid ing-tiles">
<?php foreach ($ings as $ing): ?>
                <div class="ing-tile" data-ing-name="<?= e($ing['name']) ?>" data-ing-qty="<?= e($ing['quantity']) ?>" data-ing-from="<?= e($r['title']) ?>">
                  <span class="ing-media">
                    <span class="ing-ic" role="button" tabindex="0" title="连点两下加入采购清单；长按移除清单里的这一样" aria-label="连点两下加入采购清单；长按移除清单里的这一样">
<?php if (!empty($ing['image'])): ?>
                      <img src="<?= e($ing['image']) ?>" alt="<?= e($ing['name']) ?>" loading="lazy">
<?php else: ?>
                      <i><?= e(mb_substr($ing['name'], 0, 1)) ?></i>
<?php endif; ?>
                    </span>
                    <span class="shop-count" data-shop-count title="已加入次数；长按圆图可从采购清单移除" aria-label="已加入次数；长按圆图可从采购清单移除" hidden></span>
                  </span>
                  <span class="nm"><?= e($ing['name']) ?></span>
                  <span class="qt" data-base="<?= e($ing['quantity']) ?>"><?= e($ing['quantity']) ?></span>
                </div>
<?php endforeach; ?>
<?php if (!$ings): ?>
                <p style="margin:0;font-size:12px;color:var(--muted)">暂未填写食材清单</p>
<?php endif; ?>
              </div>
<?php if ($ings): ?>
              <button class="btn shop-all" type="button" data-shop-all title="长按可移除这道菜已加入的食材"><span class="ico" data-ico="list"></span>把食材全部加入清单</button>
              <p class="shop-all-hint">按当前份量一次加入；只缺某样时，连点圆图两下单独补；长按圆图移除这一样，长按按钮移除这道菜的全部食材。</p>
<?php endif; ?>
            </div>

            <div class="card" style="margin-top:14px">
              <div class="card-head"><h3>制作步骤</h3><span class="hint">共 <?= count($steps) ?> 步 · 约 <?= $mins ?> 分钟</span>
              </div>
<?php if ($steps): ?>
              <div class="steps">
<?php foreach ($steps as $i => $s):
                  $sImgs = step_images($s);
                  $singleImg = count($sImgs) === 1 ? $sImgs[0] : '';
?>
                <div class="step">
                  <div class="num<?= $singleImg !== '' ? ' has-img' : '' ?>"<?= $singleImg !== '' ? ' style="background-image:url(\'' . e(image_url($singleImg)) . '\')"' : '' ?>><span><?= sprintf('%02d', $i + 1) ?></span></div>
                  <div class="body">
                    <b><?= e($s['title'] ?: '第 ' . ($i + 1) . ' 步') ?></b>
                    <p><?= nl2br(e($s['content'])) ?></p>
<?php if (!empty($s['duration'])): ?>
                    <span class="tm"><span class="ico" data-ico="clock"></span><?= e(step_duration_text((string) $s['duration'])) ?></span>
<?php endif; ?>
<?php if (count($sImgs) > 1): ?>
                    <div class="step-photos">
<?php foreach ($sImgs as $img): ?>
                      <img src="<?= e(image_url($img)) ?>" alt="<?= e($s['title'] ?: '第 ' . ($i + 1) . ' 步') ?>" loading="lazy">
<?php endforeach; ?>
                    </div>
<?php endif; ?>
                  </div>
                </div>
<?php endforeach; ?>
              </div>
<?php else: ?>
              <p style="margin:0;font-size:12.5px;color:var(--muted)">暂未填写制作步骤，可在后台补充。</p>
<?php endif; ?>
            </div>

<?php if ($tips): ?>
            <div class="card" style="margin-top:14px">
              <div class="card-head"><h3>制作提示</h3><span class="hint">新手最容易踩的坑</span></div>
              <div class="tips">
<?php foreach ($tips as $i => $tp): ?>
                <div class="tip<?= $i % 2 === 1 ? ' warn' : '' ?>"><span class="ico" data-ico="<?= $i % 2 === 1 ? 'alert' : 'info' ?>"></span><span><?= e($tp) ?></span></div>
<?php endforeach; ?>
              </div>
            </div>
<?php endif; ?>

            <div class="card rate-card" id="rate" style="margin-top:14px">
              <div class="card-head"><h3>给这道菜打分</h3><span class="hint"><?= $ratingStats['count'] > 0 ? '已有 ' . $ratingStats['count'] . ' 人打分 · 平均 ' . number_format($ratingStats['avg'], 1) . ' 分' : '还没有人打分' ?></span></div>
              <div class="rate-summary">
                <div class="rate-big<?= $ratingStats['count'] > 0 ? '' : ' is-empty' ?>">
<?php if ($ratingStats['count'] > 0): ?>
                  <?= number_format($ratingStats['avg'], 1) ?><small>/ 5</small>
<?php else: ?>
                  <span>暂无</span><small>评分</small>
<?php endif; ?>
                </div>
                <div class="rate-sum-body">
                  <div class="rate-stars-view" aria-hidden="true"><?php for ($i = 1; $i <= 5; $i++): ?><span class="<?= $ratingStats['count'] > 0 && $i <= round($ratingStats['avg']) ? 'on' : '' ?>">★</span><?php endfor; ?></div>
                  <b><?= $ratingStats['count'] > 0 ? '做过的平均分' : '等你来打第一分' ?></b>
                  <p>评分和评价高的菜谱，会在列表和推荐里优先展示。</p>
                </div>
              </div>
<?php if ($uNow): ?>
              <form method="post" class="rate-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="rate">
                <div class="rate-form-row">
                  <div class="rate-pick" id="rateStars" aria-label="选择评分">
<?php for ($i = 1; $i <= 5; $i++): ?>
                    <button type="button" data-score="<?= $i ?>" aria-label="<?= $i ?> 星" aria-pressed="false">★</button>
<?php endfor; ?>
                  </div>
                  <input type="hidden" name="score" id="rateScore" value="<?= (int) $ratingStats['mine'] ?>">
                  <span class="rate-pick-label" id="ratePickLabel"><?= $ratingStats['mine'] > 0 ? '你打了 ' . (int) $ratingStats['mine'] . ' 星，可重新选择' : '点击星星选择分数' ?></span>
                  <button class="btn primary sm" type="submit">提交评分</button>
                </div>
              </form>
<?php else: ?>
              <p class="rate-login"><a href="login.php">登录</a> 后就能给这道菜打分，一人一票，可以修改。</p>
<?php endif; ?>
            </div>

            <div class="card" style="margin-top:14px" id="comments">
              <div class="card-head"><h3>做过的人怎么说</h3><span class="hint"><?= count($comments) ?> 条评论</span></div>
<?php foreach ($comments as $c): ?>
              <div class="cmt">
                <?= sg_avatar((string) ($c['user_avatar'] ?? ''), (string) $c['nickname']) ?>
                <div class="bd">
                  <div class="top"><b><?= e($c['nickname']) ?></b><span class="when"><?= e(time_ago($c['created_at'])) ?></span></div>
                  <p><?= nl2br(e($c['content'])) ?></p>
                  <div class="cmt-foot">
                    <button type="button" class="cmt-like<?= (int) ($c['liked'] ?? 0) ? ' on' : '' ?>" data-cmt-like="<?= (int) $c['id'] ?>" aria-pressed="<?= (int) ($c['liked'] ?? 0) ? 'true' : 'false' ?>" aria-label="点赞，当前 <?= (int) ($c['like_count'] ?? 0) ?> 个赞">
                      <span class="ico" data-ico="heart"></span><span class="n" data-like-count><?= (int) ($c['like_count'] ?? 0) ?></span><span class="cmt-like-word">赞</span>
                    </button>
                  </div>
                </div>
              </div>
<?php endforeach; ?>
<?php if (!$comments): ?>
              <p style="margin:0 0 10px;font-size:12.5px;color:var(--muted)">还没有评论，来分享你的第一次成品吧。</p>
<?php endif; ?>

              <form method="post" style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--line-2)">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="comment">
<?php if (!current_user()): ?>
                <div class="field" style="margin-bottom:10px"><label>昵称</label><input class="input" name="nickname" placeholder="匿名用户" maxlength="20"></div>
<?php endif; ?>
                <div class="field"><label>写下你的做法心得</label><textarea class="textarea" name="content" placeholder="比如：番茄炒了多久出沙？火候有什么要注意的？" maxlength="500" required></textarea></div>
                <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
                  <span style="font-size:11px;color:var(--muted)"><?= setting('comment_review', '1') === '1' ? '评论先审后发' : '评论即时显示' ?></span>
                  <button class="btn primary sm" type="submit" style="margin-left:auto"><span class="ico" data-ico="comment"></span>发布评论</button>
                </div>
              </form>
            </div>
          </div>

          <aside>
            <div class="card">
              <div class="card-head"><h3>菜谱信息</h3></div>
              <div class="meta-box">
                <div class="meta-row"><span class="ico" data-ico="clock"></span><span>总耗时</span><b><?= $mins ?> 分钟</b></div>
                <div class="meta-row"><span class="ico" data-ico="chef"></span><span>难度</span><b><?= e($r['difficulty']) ?></b></div>
                <div class="meta-row"><span class="ico" data-ico="utensils"></span><span>份量</span><b><?= e($r['servings']) ?></b></div>
                <div class="meta-row"><span class="ico" data-ico="tag"></span><span>分类</span><b><?= e($category) ?></b></div>
                <div class="meta-row"><span class="ico" data-ico="eye"></span><span>浏览</span><b><?= number_format((int) $r['views']) ?></b></div>
                <div class="meta-row"><span class="ico" data-ico="heart"></span><span>收藏</span><b><?= (int) $r['fav_count'] ?></b></div>
              </div>
            </div>

<?php if (!empty($r['nutrition'])): ?>
            <div class="card" style="margin-top:14px">
              <div class="card-head"><h3>营养参考</h3><span class="hint">每份</span></div>
              <p style="margin:0;font-size:12.5px;color:var(--ink-2);line-height:1.8"><?= e($r['nutrition']) ?></p>
            </div>
<?php endif; ?>

            <div class="card" style="margin-top:14px">
              <div class="card-head"><h3>作者</h3></div>
              <div class="author-card">
                <span class="avatar"><?= e(mb_substr($r['author_name'] ?: '食', 0, 1)) ?></span>
                <div><b><?= e($r['author_name'] ?: '食光编辑部') ?></b><span>菜谱作者 · 已发布 <?= (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE author_name = ?', [$r['author_name']], 0) ?> 道菜谱</span></div>
              </div>
            </div>

<?php if ($related): ?>
            <div class="card" style="margin-top:14px">
              <div class="card-head"><h3>相关菜谱</h3><a class="more" href="recipes.php" style="margin-left:auto;font-size:11.5px;color:var(--brand)">更多</a></div>
              <div style="display:flex;flex-direction:column;gap:10px">
<?php foreach ($related as $rr): ?>
                <a class="lrow" href="<?= e(recipe_url($rr)) ?>" style="padding:4px 0"><img src="<?= e(image_url($rr['cover'])) ?>" alt=""><div class="m"><b><?= e($rr['title']) ?></b><span><?= recipe_total_minutes($rr) ?> 分钟 · <?= e($rr['difficulty']) ?></span></div><span class="t"><?= number_format((float) $rr['rating'], 1) ?></span></a>
<?php endforeach; ?>
              </div>
            </div>
<?php endif; ?>
          </aside>
        </div>

<?php
/* 长图数据：前端 Canvas 直接用，避免再请求接口 */
$posterData = [
    'title'       => $r['title'],
    'summary'     => (string) $r['summary'],
    'servings'    => (string) $r['servings'],
    'cover'       => image_url($r['cover']),
    'brand'       => '食光菜谱',
    'siteName'    => setting('site_name', '食光菜谱'),
    'slogan'      => '跟着做，一学就会',
    'ingredients' => array_map(function ($i) {
        return ['name' => $i['name'], 'qty' => (string) $i['quantity'], 'image' => (string) ($i['image'] ?? '')];
    }, $ings),
    'steps'       => array_map(function ($s, $i) {
        return [
            'title'    => (string) ($s['title'] ?: '第 ' . ($i + 1) . ' 步'),
            'content'  => trim(preg_replace('/\s+/u', ' ', (string) $s['content'])),
            'duration' => (string) $s['duration'],
        ];
    }, $steps, array_keys($steps)),
];
?>
<script type="application/json" id="sg-poster-data"><?= json_encode($posterData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
(function () {
  var wrap = document.getElementById('rateStars');
  if (!wrap) { return; }
  var input = document.getElementById('rateScore');
  var label = document.getElementById('ratePickLabel');
  var buttons = wrap.querySelectorAll('button[data-score]');
  function paint(v) {
    for (var i = 0; i < buttons.length; i++) {
      var on = Number(buttons[i].getAttribute('data-score')) <= v;
      buttons[i].classList.toggle('on', on);
      buttons[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    if (label) { label.textContent = v > 0 ? '已选 ' + v + ' 星' : '点击星星选择分数'; }
  }
  for (var i = 0; i < buttons.length; i++) {
    buttons[i].addEventListener('click', function () {
      var v = Number(this.getAttribute('data-score'));
      if (input) { input.value = v; }
      paint(v);
    });
  }
  paint(parseInt(input && input.value ? input.value : '0', 10) || 0);
})();
</script>
<script src="<?= e($GLOBALS['SG_BASE']) ?>assets/poster.js?v=<?= SG_VERSION ?>"></script>
<?php if (ai_enabled()): ?>
<script src="<?= e($GLOBALS['SG_BASE']) ?>assets/ai.js?v=<?= SG_VERSION ?>"></script>
<?php endif; ?>
<?php
sg_foot();
