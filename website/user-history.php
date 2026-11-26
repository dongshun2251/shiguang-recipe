<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (post('do') === 'clear_history') {
        unset($_SESSION['history']);
        flash('浏览历史已清空');
    }
    redirect('user-history.php');
}

$history = history_list(12);

sg_head('浏览历史', 'user-history', '浏览历史');
?>
        <div class="me-subhead">
          <a class="me-back" href="user.php" aria-label="返回个人中心"><span class="ico" data-ico="back"></span></a>
          <div><h1>浏览历史</h1><span>最近 <?= count($history) ?> 条 · 仅在当前浏览器记录</span></div>
          <?php if ($history): ?>
          <form method="post" class="me-subhead-act">
            <input type="hidden" name="do" value="clear_history"><?= csrf_field() ?>
            <button class="btn sm ghost" type="submit" data-confirm="确定清空浏览历史？"><span class="ico" data-ico="trash"></span>清空</button>
          </form>
          <?php endif; ?>
        </div>

<?php if ($history): ?>
        <div class="listcard">
<?php foreach ($history as $i => $r): ?>
          <a class="lrow" href="<?= e(recipe_url($r)) ?>">
            <span class="n<?= $i === 0 ? ' top' : '' ?>"><?= sprintf('%02d', $i + 1) ?></span>
            <img src="<?= e(image_url($r['cover'])) ?>" alt="">
            <div class="m"><b><?= e($r['title']) ?></b><span><?= e(category_name((int) $r['category_id'])) ?> · <?= e($r['difficulty']) ?></span></div>
            <span class="t"><?= recipe_total_minutes($r) ?> 分钟</span>
          </a>
<?php endforeach; ?>
        </div>
<?php else: ?>
        <div class="empty"><div class="ico" data-ico="clock"></div><b>暂时没有浏览记录</b><p>打开任意菜谱详情后会自动记录。</p></div>
<?php endif; ?>
<?php
sg_foot();
