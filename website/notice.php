<?php
require_once __DIR__ . '/inc/bootstrap.php';

$notice = trim((string) setting('home_notice', '欢迎来到食光菜谱，今天也要好好吃饭。'));

sg_head('公告详情', 'notice', '公告详情');
?>
        <div class="page-head">
          <h1>公告详情</h1>
          <span class="sub desk-only">首页播报条里的公告全文</span>
        </div>

<?php if ($notice !== ''): ?>
        <article class="update-card">
          <div class="update-meta"><span class="update-badge">公告</span></div>
          <p class="update-text"><?= nl2br(e($notice)) ?></p>
        </article>
<?php else: ?>
        <div class="alert"><span class="ico" data-ico="info"></span><span>后台还没有填写公告内容。</span></div>
<?php endif; ?>

        <p style="margin-top:14px"><a class="btn" href="index.php"><span class="ico" data-ico="home"></span>回首页</a></p>
<?php sg_foot(); ?>
