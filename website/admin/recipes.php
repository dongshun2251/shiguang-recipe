<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$kw     = trim(get('kw'));
$status = get('status');
$page   = max(1, get_int('page', 1));
$per    = 12;

/* ---------- 写操作 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do  = post('do');
    $id  = post_int('id');
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));

    $cleanup = function (array $list): int {
        if (!$list) { return 0; }
        $in = implode(',', $list);
        q('DELETE FROM `' . T('recipes') . '` WHERE id IN (' . $in . ')');
        foreach (['recipe_ingredients' => 'recipe_id', 'recipe_steps' => 'recipe_id', 'recipe_tags' => 'recipe_id', 'comments' => 'recipe_id', 'favorites' => 'recipe_id'] as $tbl => $col) {
            q('DELETE FROM `' . T($tbl) . '` WHERE ' . $col . ' IN (' . $in . ')');
        }
        return count($list);
    };

    if ($do === 'delete' && $id) {
        $cleanup([$id]);
        flash('菜谱已删除（食材、步骤、评论、收藏一并清理）');
    } elseif ($do === 'status' && $id) {
        $new = post('new_status') === 'published' ? 'published' : 'draft';
        db_update(T('recipes'), [
            'status'       => $new,
            'published_at' => $new === 'published' ? date('Y-m-d H:i:s') : null,
            'updated_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
        flash($new === 'published' ? '菜谱已发布' : '菜谱已转为草稿');
    } elseif ($do === 'feature' && $id) {
        $cur = (int) fetch_val('SELECT is_featured FROM `' . T('recipes') . '` WHERE id = ?', [$id], 0);
        db_update(T('recipes'), ['is_featured' => $cur ? 0 : 1], 'id = ?', [$id]);
        flash($cur ? '已取消首页推荐' : '已设为首页推荐');
    } elseif ($do === 'batch' && $ids) {
        $act = post('batch_action');
        $in  = implode(',', $ids);
        $now = date('Y-m-d H:i:s');
        if ($act === 'publish') {
            q('UPDATE `' . T('recipes') . '` SET status = "published", published_at = COALESCE(published_at, "' . $now . '"), updated_at = "' . $now . '" WHERE id IN (' . $in . ')');
            flash('已把 ' . count($ids) . ' 道菜谱设为已发布');
        } elseif ($act === 'draft') {
            q('UPDATE `' . T('recipes') . '` SET status = "draft", updated_at = "' . $now . '" WHERE id IN (' . $in . ')');
            flash('已把 ' . count($ids) . ' 道菜谱转为草稿');
        } elseif ($act === 'feature') {
            q('UPDATE `' . T('recipes') . '` SET is_featured = 1 WHERE id IN (' . $in . ')');
            flash('已推荐 ' . count($ids) . ' 道菜谱到首页');
        } elseif ($act === 'delete') {
            flash('已删除 ' . $cleanup($ids) . ' 道菜谱');
        }
    }

    $back = array_filter([
        'kw'     => trim((string) post('kw')),
        'status' => (string) post('status'),
        'page'   => post_int('page') ?: null,
    ], function ($v) { return $v !== '' && $v !== null; });
    redirect('recipes.php' . ($back ? '?' . http_build_query($back) : ''));
}

/* ---------- 列表 ---------- */
$w = [];
$p = [];
if ($kw !== '') {
    $w[] = '(r.title LIKE ? OR r.author_name LIKE ?)';
    $p[] = '%' . $kw . '%';
    $p[] = '%' . $kw . '%';
}
if (in_array($status, ['published', 'draft', 'pending'], true)) {
    $w[] = 'r.status = ?';
    $p[] = $status;
}
$where = $w ? implode(' AND ', $w) : '1';
$total = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` r WHERE ' . $where, $p, 0);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$rows  = fetch_all('SELECT r.*, c.name AS cat_name FROM `' . T('recipes') . '` r LEFT JOIN `' . T('categories') . '` c ON c.id = r.category_id
                    WHERE ' . $where . ' ORDER BY r.id DESC LIMIT ' . (($page - 1) * $per) . ', ' . $per, $p);

$pendingRc = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE status = "pending"', [], 0);
$allRc     = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '`', [], 0);
$backQs    = http_build_query(array_filter(['kw' => $kw, 'status' => $status, 'page' => $page > 1 ? $page : null]));

sg_admin_head('菜谱管理', 'admin-recipes', '菜谱管理');
?>
        <div class="page-head">
          <h1>菜谱管理</h1>
          <span class="sub desk-only">共 <?= $total ?> 道 · 支持按状态与关键词筛选</span>
<?php if ($allRc > 0 || $kw !== ''): ?>
          <div class="actions">
            <form class="search-field head-search" method="get" action="recipes.php">
              <span class="ico" data-ico="search"></span>
              <input name="kw" value="<?= e($kw) ?>" placeholder="搜索菜名或作者">
              <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
            </form>
          </div>
<?php endif; ?>
        </div>

        <?= flash_render() ?>

<?php if ($pendingRc && $status !== 'pending'): ?>
        <a class="notice-bar" href="recipes.php?status=pending">
          <span class="ico" data-ico="alert"></span>
          <span>有 <b><?= $pendingRc ?></b> 道用户投稿等待审核</span>
          <span class="go">去审核<span class="ico" data-ico="chev"></span></span>
        </a>
<?php endif; ?>

        <div class="rc-toolbar">
          <div class="chips">
            <a class="chip<?= $status === '' ? ' on' : '' ?>" href="recipes.php<?= $kw !== '' ? '?kw=' . urlencode($kw) : '' ?>">全部</a>
            <a class="chip<?= $status === 'published' ? ' on' : '' ?>" href="recipes.php?status=published<?= $kw !== '' ? '&kw=' . urlencode($kw) : '' ?>">已发布</a>
            <a class="chip<?= $status === 'draft' ? ' on' : '' ?>" href="recipes.php?status=draft<?= $kw !== '' ? '&kw=' . urlencode($kw) : '' ?>">草稿</a>
            <a class="chip<?= $status === 'pending' ? ' on' : '' ?>" href="recipes.php?status=pending<?= $kw !== '' ? '&kw=' . urlencode($kw) : '' ?>">待审投稿 <?= $pendingRc ?: '' ?></a>
          </div>
          <button class="rc-sel-toggle" type="button" data-rc-toggle>批量</button>
        </div>

        <form method="post" id="bulkf" class="bulkbar">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="batch">
          <input type="hidden" name="kw" value="<?= e($kw) ?>">
          <input type="hidden" name="status" value="<?= e($status) ?>">
          <input type="hidden" name="page" value="<?= $page ?>">
          <span class="checkbox" data-check-all><span class="ico" data-ico="check"></span></span>
          <span class="bulk-txt">全选</span>
          <span class="desk-only bulk-cnt">共 <b><?= $total ?></b> 道</span>
          <div class="bulk-acts">
            <button class="btn sm" type="submit" name="batch_action" value="publish"><span class="ico" data-ico="check"></span>发布</button>
            <button class="btn sm" type="submit" name="batch_action" value="draft">转草稿</button>
            <button class="btn sm" type="submit" name="batch_action" value="feature"><span class="ico" data-ico="star"></span>推荐</button>
            <button class="btn sm danger" type="submit" name="batch_action" value="delete"><span class="ico" data-ico="trash"></span>删除</button>
          </div>
        </form>

        <div class="table-wrap">
          <table class="tb">
            <thead><tr><th style="width:34px"></th><th>菜谱</th><th class="col-hide">分类</th><th class="col-hide">状态</th><th class="col-hide">收藏</th><th class="col-hide">浏览</th><th class="col-hide">更新时间</th><th style="width:156px">操作</th></tr></thead>
            <tbody>
<?php foreach ($rows as $r): ?>
              <tr>
                <td><input class="row-check" type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="bulkf" aria-label="选择 <?= e($r['title']) ?>"></td>
                <td>
                  <div class="tb-title">
                    <img class="tb-thumb" src="<?= e(image_url($r['cover'])) ?>" alt="">
                    <div>
                      <b><a href="recipe-edit.php?id=<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a><?= $r['is_featured'] ? ' <span class="badge hot">推荐</span>' : '' ?></b>
                      <span>ID <?= (int) $r['id'] ?> · <?= e($r['author_name'] ?: '—') ?> · <?= recipe_total_minutes($r) ?> 分钟 · <?= $r['status'] === 'published' ? '已发布' : ($r['status'] === 'draft' ? '草稿' : '待审核') ?></span>
                    </div>
                  </div>
                </td>
                <td class="col-hide"><?= e($r['cat_name'] ?? '未分类') ?></td>
                <td class="col-hide"><?= badge_status($r['status']) ?></td>
                <td class="col-hide"><?= (int) $r['fav_count'] ?></td>
                <td class="col-hide"><?= number_format((int) $r['views']) ?></td>
                <td class="col-hide"><?= e(time_ago($r['updated_at'])) ?></td>
                <td>
                  <div class="row-act">
                    <div class="ra-main">
                    <a class="iconbtn" href="recipe-edit.php?id=<?= (int) $r['id'] ?>" title="编辑"><span class="ico" data-ico="edit"></span></a>
<?php
                    $nextStatus = $r['status'] === 'published' ? 'draft' : 'published';
                    $statusLabel = $r['status'] === 'pending' ? '通过' : ($r['status'] === 'published' ? '转草稿' : '发布');
                    $statusCls   = $r['status'] === 'published' ? '' : 'primary';
                    ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="status">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <input type="hidden" name="new_status" value="<?= $nextStatus ?>">
                      <input type="hidden" name="kw" value="<?= e($kw) ?>">
                      <input type="hidden" name="status" value="<?= e($status) ?>">
                      <input type="hidden" name="page" value="<?= $page ?>">
                      <button class="btn sm <?= $statusCls ?>" type="submit" title="<?= $statusLabel ?>"><?= $statusLabel ?></button>
                    </form>
                    </div>
                    <div class="ra-end">
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="feature">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <input type="hidden" name="kw" value="<?= e($kw) ?>">
                      <input type="hidden" name="status" value="<?= e($status) ?>">
                      <input type="hidden" name="page" value="<?= $page ?>">
                      <button class="iconbtn" type="submit" title="<?= $r['is_featured'] ? '取消推荐' : '设为推荐' ?>"><span class="ico" data-ico="star"></span></button>
                    </form>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <input type="hidden" name="kw" value="<?= e($kw) ?>">
                      <input type="hidden" name="status" value="<?= e($status) ?>">
                      <input type="hidden" name="page" value="<?= $page ?>">
                      <button class="iconbtn" type="submit" data-confirm="删除「<?= e($r['title']) ?>」后不可恢复，确定吗？" title="删除" style="color:#B04624"><span class="ico" data-ico="trash"></span></button>
                    </form>
                    </div>
                  </div>
                </td>
              </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
              <tr class="tb-empty"><td colspan="8" style="text-align:center;padding:36px;color:var(--muted)"><?= $kw !== '' ? '没有匹配「' . e($kw) . '」的菜谱' : '还没有菜谱' ?>，<a class="arrow-link" href="recipe-edit.php" style="color:var(--brand)">去新增<span class="ico" data-ico="chev"></span></a></td></tr>
<?php endif; ?>
            </tbody>
          </table>
        </div>
<?php if ($rows): ?>
        <div class="sel-note">已勾选 <b data-sel-count>0</b> 道 · 用上方按钮批量发布 / 转草稿 / 推荐 / 删除</div>
<?php endif; ?>
        <?= pager($page, $total, $per, 'recipes.php?' . $backQs) ?>
<script>
(function () {
  var form = document.getElementById('bulkf');
  if (!form) return;
  var boxes = document.querySelectorAll('input[name="ids[]"][form="bulkf"]');
  var countEl = document.querySelector('[data-sel-count]');
  function sync() {
    var n = 0;
    boxes.forEach(function (b) { if (b.checked) n++; });
    if (countEl) countEl.textContent = n;
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  var tg = document.querySelector('[data-rc-toggle]');
  if (tg) tg.addEventListener('click', function () {
    var on = document.body.classList.toggle('rc-selecting');
    tg.textContent = on ? '完成' : '批量';
    if (!on) {
      boxes.forEach(function (b) { b.checked = false; });
      document.querySelectorAll('[data-check-all]').forEach(function (m) { m.classList.remove('on'); });
      sync();
    }
  });
  form.addEventListener('submit', function (e) {
    var n = 0;
    boxes.forEach(function (b) { if (b.checked) n++; });
    if (n === 0) {
      e.preventDefault();
      if (window.SG_TOAST) SG_TOAST('先勾选要处理的菜谱（左侧小方框）');
      return;
    }
    var act = e.submitter ? e.submitter.value : '';
    if (act === 'delete' && !window.confirm('删除选中的 ' + n + ' 道菜谱？不可恢复。')) {
      e.preventDefault();
    } else if ((act === 'publish' || act === 'draft' || act === 'feature') && !window.confirm('对选中的 ' + n + ' 道菜谱执行该操作？')) {
      e.preventDefault();
    }
  });
})();
</script>
<?php
sg_admin_foot();
