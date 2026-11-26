<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$totalRecipes = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '`', [], 0);
$published    = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE status = "published"', [], 0);
$drafts       = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE status = "draft"', [], 0);
$totalViews   = (int) fetch_val('SELECT COALESCE(SUM(views),0) FROM `' . T('recipes') . '`', [], 0);
$totalFavs    = (int) fetch_val('SELECT COALESCE(SUM(fav_count),0) FROM `' . T('recipes') . '`', [], 0);
$pending      = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '` WHERE status = "pending"', [], 0);
$users        = (int) fetch_val('SELECT COUNT(*) FROM `' . T('users') . '`', [], 0);
$pendingRc    = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE status = "pending"', [], 0);

/* 近 7 天新增 */
$days = [];
$max  = 1;
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $c = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE DATE(created_at) = ?', [$d], 0);
    $days[] = ['date' => $d, 'label' => date('j', strtotime($d)) . ' ' . ['日', '一', '二', '三', '四', '五', '六'][(int) date('w', strtotime($d))], 'count' => $c];
    $max = max($max, $c);
}
$weekNew = array_sum(array_column($days, 'count'));

$latest = fetch_all('SELECT r.*, c.name AS cat_name FROM `' . T('recipes') . '` r LEFT JOIN `' . T('categories') . '` c ON c.id = r.category_id ORDER BY r.id DESC LIMIT 6');
$pendingList = fetch_all('SELECT * FROM `' . T('comments') . '` WHERE status = "pending" ORDER BY id DESC LIMIT 3');

sg_admin_head('仪表盘', 'admin-dashboard', '后台仪表盘');
?>
        <div class="page-head">
          <h1>仪表盘</h1>
          <span class="sub desk-only">数据更新于 <?= date('Y-m-d H:i') ?></span>
          <div class="actions">
            <a class="btn" href="dashboard.php"><span class="ico" data-ico="refresh"></span>刷新</a>
            <a class="btn primary" href="recipe-edit.php"><span class="ico" data-ico="plus"></span>新增菜谱</a>
          </div>
        </div>

        <?= flash_render() ?>

<?php if ($pendingRc || $pending): ?>
        <div class="todo-strip">
<?php if ($pendingRc): ?>
          <a class="todo" href="recipes.php?status=pending"><span class="ico" data-ico="book"></span><b><?= $pendingRc ?> 道投稿菜谱等审核</b><span class="go">去审核<span class="ico" data-ico="chev"></span></span></a>
<?php endif; ?>
<?php if ($pending): ?>
          <a class="todo" href="comments.php?status=pending"><span class="ico" data-ico="comment"></span><b><?= $pending ?> 条评论等审核</b><span class="go">去处理<span class="ico" data-ico="chev"></span></span></a>
<?php endif; ?>
        </div>
<?php endif; ?>

        <section class="hub-panel">
          <div class="hub-panel-head"><h2>数据概览</h2><span>站点当前内容量</span><span style="margin-left:auto">更新于 <?= date('m-d H:i') ?></span></div>
          <div class="admin-stat-grid">
            <div class="stat"><div class="k"><span class="ico" data-ico="book"></span>菜谱总数</div><div class="v"><?= number_format($totalRecipes) ?><small>道</small></div><div class="t">已发布 <?= $published ?> · 草稿 <?= $drafts ?></div></div>
            <div class="stat"><div class="k"><span class="ico" data-ico="eye"></span>累计浏览</div><div class="v"><?= number_format($totalViews) ?></div><div class="t">含详情页访问量</div></div>
            <div class="stat"><div class="k"><span class="ico" data-ico="heart"></span>收藏总数</div><div class="v"><?= number_format($totalFavs) ?></div><div class="t">注册用户 <?= $users ?> 人</div></div>
            <div class="stat"><div class="k"><span class="ico" data-ico="comment"></span>待审核评论</div><div class="v"><?= $pending ?></div><div class="t <?= $pending ? 'down' : '' ?>"><?= $pending ? '需要人工处理' : '暂无待审评论' ?></div></div>
          </div>
        </section>

        <section class="hub-panel">
          <div class="hub-panel-head"><h2>功能入口</h2><span>每个功能单独进入一页</span></div>
          <div class="hub-grid">
            <a class="hub-tile" href="recipes.php"><span class="hub-tile-ic"><span class="ico" data-ico="book"></span></span><div><b>菜谱管理</b><span><?= $published ?> 道已发布</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="categories.php"><span class="hub-tile-ic"><span class="ico" data-ico="folder"></span></span><div><b>分类管理</b><span>分类与图标</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="comments.php"><span class="hub-tile-ic"><span class="ico" data-ico="comment"></span></span><div><b>评论审核<?php if ($pending): ?><i class="hub-count"><?= $pending ?></i><?php endif; ?></b><span>待审核评论</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="users.php"><span class="hub-tile-ic"><span class="ico" data-ico="users"></span></span><div><b>用户管理</b><span><?= $users ?> 位注册用户</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="media.php"><span class="hub-tile-ic"><span class="ico" data-ico="image"></span></span><div><b>媒体库</b><span>封面与图片</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="ingredients.php"><span class="hub-tile-ic"><span class="ico" data-ico="leaf"></span></span><div><b>食材图库</b><span>配图与别名</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="settings.php"><span class="hub-tile-ic"><span class="ico" data-ico="settings"></span></span><div><b>系统设置</b><span>站点与 AI</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="backup.php"><span class="hub-tile-ic"><span class="ico" data-ico="database"></span></span><div><b>数据备份</b><span>导出数据库</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="sync.php"><span class="hub-tile-ic"><span class="ico" data-ico="upload"></span></span><div><b>云端同步</b><span>本地推到云端</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="reset.php"><span class="hub-tile-ic"><span class="ico" data-ico="trash"></span></span><div><b>数据清理</b><span>清空旧内容</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="site-doctor.php"><span class="hub-tile-ic"><span class="ico" data-ico="shield"></span></span><div><b>网站诊断</b><span>检查功能配置</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
            <a class="hub-tile" href="../index.php" target="_blank"><span class="hub-tile-ic"><span class="ico" data-ico="eye"></span></span><div><b>查看前台</b><span>打开站点首页</span></div><span class="hub-go"><span class="ico" data-ico="chev"></span></span></a>
          </div>
        </section>

        <div class="admin-cols" style="margin-top:16px">
          <div class="card">
            <div class="card-head"><h3>近 7 天新增菜谱</h3><span class="hint">单位：道</span>
              <div class="right"><span class="badge live">已发布 <?= $published ?></span><span class="badge draft">草稿 <?= $drafts ?></span></div>
            </div>
            <div class="barchart">
<?php foreach ($days as $d): ?>
              <div class="col"><i<?= $d['date'] === date('Y-m-d') ? ' class="alt"' : '' ?> style="height:<?= max(6, round($d['count'] / $max * 88)) ?>%"></i><span><?= e($d['label']) ?></span></div>
<?php endforeach; ?>
            </div>
            <div style="display:flex;align-items:center;gap:22px;margin-top:14px;padding-top:13px;border-top:1px dashed var(--line-2);font-size:11.5px;color:var(--muted)">
              <span>本周共新增 <b style="color:var(--ink);font-size:13px"><?= $weekNew ?></b> 道</span>
              <span>已发布 <b style="color:var(--green);font-size:13px"><?= $published ?></b></span>
              <span>草稿 <b style="color:#96702A;font-size:13px"><?= $drafts ?></b></span>
              <a class="arrow-link" href="recipes.php" style="margin-left:auto;color:var(--brand)">查看全部<span class="ico" data-ico="chev"></span></a>
            </div>
          </div>

          <div class="card">
            <div class="card-head"><h3>站点状态</h3><span class="hint">运行环境</span></div>
            <div class="meta-box">
              <div class="meta-row"><span class="ico" data-ico="database"></span><span>数据库</span><b style="color:var(--green)"><?= e($GLOBALS['SG_CFG']['db']['name'] ?? 'MySQL') ?> · 已连接</b></div>
              <div class="meta-row"><span class="ico" data-ico="folder"></span><span>表前缀</span><b><?= e($GLOBALS['SG_CFG']['db']['prefix'] ?? 'sg_') ?></b></div>
              <div class="meta-row"><span class="ico" data-ico="clock"></span><span>站点时区</span><b><?= e(setting('site_timezone', 'Asia/Shanghai')) ?></b></div>
              <div class="meta-row"><span class="ico" data-ico="shield"></span><span>后台版本</span><b>v<?= SG_VERSION ?></b></div>
            </div>
            <a class="btn block" style="margin-top:14px" href="site-doctor.php"><span class="ico" data-ico="shield"></span>运行网站诊断</a>
          </div>
        </div>

        <?php if ($pendingList): ?>
        <div class="section-head"><h2>待审核评论</h2><a class="more" href="comments.php">全部评论<span class="ico" data-ico="chev"></span></a></div>
        <div class="card">
<?php foreach ($pendingList as $c): ?>
          <div class="cmt">
            <span class="avatar"><?= e(mb_substr($c['nickname'], 0, 1)) ?></span>
            <div class="bd">
              <div class="top"><b><?= e($c['nickname']) ?></b><span class="badge draft">待审核</span><span class="when"><?= e(time_ago($c['created_at'])) ?></span></div>
              <p><?= e($c['content']) ?></p>
            </div>
            <a class="btn sm" href="comments.php?status=pending">处理</a>
          </div>
<?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="section-head"><h2>最新菜谱</h2><a class="more" href="recipes.php">全部菜谱<span class="ico" data-ico="chev"></span></a></div>
        <div class="table-wrap">
          <table class="tb">
            <thead><tr><th>菜谱</th><th>分类</th><th>状态</th><th>浏览</th><th>更新时间</th><th style="width:96px">操作</th></tr></thead>
            <tbody>
<?php foreach ($latest as $r): ?>
              <tr>
                <td><div class="tb-title"><img class="tb-thumb" src="<?= e(image_url($r['cover'])) ?>" alt=""><div><b><?= e($r['title']) ?></b><span>ID <?= (int) $r['id'] ?> · <?= e($r['author_name'] ?: '—') ?></span></div></div></td>
                <td><?= e($r['cat_name'] ?? '未分类') ?></td>
                <td><?= badge_status($r['status']) ?></td>
                <td><?= number_format((int) $r['views']) ?></td>
                <td><?= e(time_ago($r['updated_at'])) ?></td>
                <td><div class="row-act">
                  <a class="iconbtn" href="recipe-edit.php?id=<?= (int) $r['id'] ?>" title="编辑"><span class="ico" data-ico="edit"></span></a>
                  <a class="iconbtn" href="../recipe.php?id=<?= (int) $r['id'] ?>" target="_blank" title="预览"><span class="ico" data-ico="eye"></span></a>
                </div></td>
              </tr>
<?php endforeach; ?>
<?php if (!$latest): ?>
              <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--muted)">还没有菜谱，<a href="recipe-edit.php" style="color:var(--brand)">创建第一道菜</a></td></tr>
<?php endif; ?>
            </tbody>
          </table>
        </div>
<?php
sg_admin_foot();
