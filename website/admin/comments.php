<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = post('do');
    $id = post_int('id');

    if ($do === 'approve' && $id) {
        db_update(T('comments'), ['status' => 'approved'], 'id = ?', [$id]);
        flash('评论已通过');
    } elseif ($do === 'reject' && $id) {
        db_update(T('comments'), ['status' => 'pending'], 'id = ?', [$id]);
        flash('评论已转为待审核');
    } elseif ($do === 'delete' && $id) {
        q('DELETE FROM `' . T('comments') . '` WHERE id = ?', [$id]);
        flash('评论已删除');
    } elseif ($do === 'batch') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $act = post('batch_action');
        if ($ids && in_array($act, ['approve', 'delete'], true)) {
            $in = implode(',', $ids);
            if ($act === 'approve') {
                q('UPDATE `' . T('comments') . '` SET status = "approved" WHERE id IN (' . $in . ')');
            } else {
                q('DELETE FROM `' . T('comments') . '` WHERE id IN (' . $in . ')');
            }
            flash('已处理 ' . count($ids) . ' 条评论');
        }
    }
    redirect('comments.php?' . http_build_query(array_filter(['status' => (string) post('status'), 'kw' => trim((string) post('kw'))])));
}

$status = get('status', '');
$kw     = trim(get('kw'));
$page   = max(1, get_int('page', 1));
$per    = 12;

$w = [];
$p = [];
if (in_array($status, ['pending', 'approved'], true)) {
    $w[] = 'c.status = ?';
    $p[] = $status;
}
if ($kw !== '') {
    $w[] = '(c.content LIKE ? OR c.nickname LIKE ?)';
    $p[] = '%' . $kw . '%';
    $p[] = '%' . $kw . '%';
}
$where = $w ? implode(' AND ', $w) : '1';
$total = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '` c WHERE ' . $where, $p, 0);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$hasAvatar = sg_has_column('users', 'avatar');
$rows  = fetch_all('SELECT c.*, r.title AS recipe_title, r.cover AS recipe_cover'
                    . ($hasAvatar ? ', u.avatar AS user_avatar' : '') . ',
                    (SELECT COUNT(*) FROM `' . T('comments') . '` x WHERE x.status = "pending") AS pending_total
                    FROM `' . T('comments') . '` c LEFT JOIN `' . T('recipes') . '` r ON r.id = c.recipe_id'
                    . ($hasAvatar ? ' LEFT JOIN `' . T('users') . '` u ON u.id = c.user_id' : '')
                    . ' WHERE ' . $where . ' ORDER BY c.id DESC LIMIT ' . (($page - 1) * $per) . ', ' . $per, $p);
$pendingCount = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '` WHERE status = "pending"', [], 0);
$approvedCount = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '` WHERE status = "approved"', [], 0);

/* AI 预审当前页评论 */
$aiMap = $_SESSION['sg_cmt_ai'] ?? [];
if (get('ai_review') === '1') {
    if (!ai_enabled()) {
        flash('未启用本地 AI，请到系统设置配置', 'err');
    } elseif (!$rows) {
        flash('当前页没有评论可审');
    } else {
        [$ok, $map, $err] = ai_moderate_comments($rows);
        if ($ok) {
            foreach ($map as $cid => $it) { $aiMap[$cid] = $it; }
            $_SESSION['sg_cmt_ai'] = $aiMap;
            $bad = 0;
            foreach ($map as $it) { if ($it['flag'] !== 'ok') { $bad++; } }
            flash('AI 预审完成：' . count($map) . ' 条中标记 ' . $bad . ' 条可疑');
        } else {
            flash($err ?: 'AI 预审失败', 'err');
        }
    }
    redirect('comments.php?' . http_build_query(array_filter(['status' => $status, 'kw' => $kw])));
}

sg_admin_head('评论审核', 'admin-comments', '评论审核');
?>
        <div class="page-head">
          <h1>评论审核</h1>
          <span class="sub desk-only">待审核 <?= $pendingCount ?> 条 · 已通过 <?= $approvedCount ?> 条</span>
          <div class="actions">
            <?php if (ai_enabled()): ?>
            <a class="btn sm" href="comments.php?<?= e(http_build_query(array_filter(['status' => $status, 'kw' => $kw, 'ai_review' => '1'])))?>"><span class="ico" data-ico="bolt"></span>AI 预审本页</a>
            <?php endif; ?>
            <form class="search-field desk-only" method="get" action="comments.php" style="height:34px">
              <span class="ico" data-ico="search"></span>
              <input name="kw" value="<?= e($kw) ?>" placeholder="搜索评论内容或昵称">
            </form>
          </div>
        </div>

        <?= flash_render() ?>

        <div class="cmt-toolbar">
          <div class="chips">
            <a class="chip<?= $status === '' ? ' on' : '' ?>" href="comments.php">全部</a>
            <a class="chip<?= $status === 'pending' ? ' on' : '' ?>" href="comments.php?status=pending">待审核 <?= $pendingCount ? '(' . $pendingCount . ')' : '' ?></a>
            <a class="chip<?= $status === 'approved' ? ' on' : '' ?>" href="comments.php?status=approved">已通过</a>
          </div>
          <button class="cmt-sel-toggle" type="button" data-batch-toggle>批量</button>
        </div>

        <form method="post" id="batchForm">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="batch">
          <input type="hidden" name="status" value="<?= e($status) ?>">
          <input type="hidden" name="kw" value="<?= e($kw) ?>">
          <div class="table-wrap">
            <div class="bulkbar">
              <span class="checkbox" data-check-all><span class="ico" data-ico="check"></span></span>
              <span>全选</span>
              <span class="desk-only">共 <b><?= $total ?></b> 条</span>
              <div style="margin-left:auto;display:flex;gap:8px">
                <button class="btn sm" type="submit" name="batch_action" value="approve"><span class="ico" data-ico="check"></span>批量通过</button>
                <button class="btn sm" type="submit" name="batch_action" value="delete" data-confirm="确定删除选中的评论？"><span class="ico" data-ico="trash"></span>删除</button>
              </div>
            </div>

            <div class="cmt-list">
<?php foreach ($rows as $c): ?>
              <div class="cmt">
                <div class="bd">
                  <div class="top">
                    <input class="cmt-check" type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>">
                    <?= sg_avatar((string) ($c['user_avatar'] ?? ''), (string) $c['nickname'], 'top-avatar') ?>
                    <b><?= e($c['nickname']) ?></b>
                    <span class="badge <?= $c['status'] === 'approved' ? 'live' : 'draft' ?>"><?= $c['status'] === 'approved' ? '已通过' : '待审核' ?></span>
                    <span class="when cmt-when"><?= e(time_ago($c['created_at'])) ?></span>
                    <span class="when cmt-ip">· <?= e($c['ip']) ?></span>
<?php
                  $ai = $aiMap[(int) $c['id']] ?? null;
                  if ($ai && $ai['flag'] !== 'ok'):
                    $aiLabel = ['spam' => '疑似灌水', 'ad' => '疑似广告', 'abuse' => '疑似辱骂'][$ai['flag']] ?? '可疑';
?>
                    <span class="badge" style="background:#FBEDE7;color:#B4471F;border-color:#F0D2C3"><?= e($aiLabel) ?><?= $ai['reason'] !== '' ? '：' . e($ai['reason']) : '' ?></span>
<?php endif; ?>
                  </div>
                  <p><?= nl2br(e($c['content'])) ?></p>
<?php if ($c['recipe_title']): ?>
                  <a class="ctx" href="../recipe.php?id=<?= (int) $c['recipe_id'] ?>" target="_blank" style="text-decoration:none;color:inherit">
                    <img src="<?= e(image_url($c['recipe_cover'])) ?>" alt="">
                    <b><?= e($c['recipe_title']) ?></b>
                    <span class="arrow-link" style="margin-left:auto;font-size:11px;color:var(--brand)">查看菜谱<span class="ico" data-ico="chev"></span></span>
                  </a>
<?php endif; ?>
                  <div class="cmt-act">
                    <span class="cmt-no">#<?= (int) $c['id'] ?></span>
                    <span class="cmt-when2"><?= e(time_ago($c['created_at'])) ?></span>
<?php if ($c['status'] === 'pending'): ?>
                    <button class="btn sm primary" type="submit" form="act<?= (int) $c['id'] ?>" name="do" value="approve"><span class="ico" data-ico="check"></span>通过</button>
<?php else: ?>
                    <button class="btn sm" type="submit" form="act<?= (int) $c['id'] ?>" name="do" value="reject">转待审</button>
<?php endif; ?>
                    <button class="btn sm cmt-del" type="submit" form="act<?= (int) $c['id'] ?>" name="do" value="delete" data-confirm="确定删除这条评论？" title="删除"><span class="ico" data-ico="trash"></span><span class="cmt-del-t">删除</span></button>
                  </div>
                </div>
              </div>
<?php endforeach; ?>
<?php if (!$rows): ?>
              <div class="empty cmt-empty"><div class="ico" data-ico="comment"></div><b>没有符合条件的评论</b><p>切换筛选条件看看。</p></div>
<?php endif; ?>
            </div>
          </div>
        </form>
<?php foreach ($rows as $c): ?>
        <form method="post" id="act<?= (int) $c['id'] ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="hidden" name="kw" value="<?= e($kw) ?>"></form>
<?php endforeach; ?>
        <?= pager($page, $total, $per, 'comments.php?' . http_build_query(array_filter(['status' => $status, 'kw' => $kw]))) ?>
<script>
document.addEventListener('click', function (e) {
  var tg = e.target.closest('[data-batch-toggle]');
  if (tg) {
    var on = document.body.classList.toggle('cmt-selecting');
    tg.textContent = on ? '完成' : '批量';
    if (!on) {
      document.querySelectorAll('#batchForm input[name="ids[]"]').forEach(function (c) { c.checked = false; });
      var box = document.querySelector('[data-check-all]');
      if (box) box.classList.remove('on');
    }
    return;
  }
});
</script>
<?php
sg_admin_foot();
