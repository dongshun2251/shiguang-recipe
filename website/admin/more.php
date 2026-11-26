<?php
/**
 * 后台 · 更多（手机端底栏的第五格）
 * 把桌面侧栏里剩下的入口集中到这里，手机上不用记直链。
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$userCnt   = (int) fetch_val('SELECT COUNT(*) FROM `' . T('users') . '`', [], 0);
$cmtCnt    = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '` WHERE status = "pending"', [], 0);
$ingCnt    = (int) fetch_val('SELECT COUNT(*) FROM `' . T('ingredients') . '`', [], 0);
$mediaCnt  = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE cover <> ""', [], 0);
$openTk    = sg_security_ready() ? (int) fetch_val('SELECT COUNT(*) FROM `' . T('support_tickets') . '` WHERE status = ?', ['open'], 0) : 0;
$pendingRc = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE status = "pending"', [], 0);
$backups   = glob(SG_ROOT . '/config/backup/*.sql') ?: [];
$lastBk    = $backups ? date('Y-m-d H:i', max(array_map('filemtime', $backups))) : '';

$groups = [
    [
        'title' => '内容管理',
        'hint'  => '菜谱、分类、审核与素材',
        'items' => [
            ['recipes.php',       'book',     '菜谱管理',   '已发布与待审', ''],
            ['categories.php',    'folder',   '分类管理',   '分类与图标',           ''],
            ['recipes.php?status=pending', 'book', '待审投稿', $pendingRc ? $pendingRc . ' 道待处理' : '暂无投稿', $pendingRc],
            ['comments.php',      'comment',  '评论审核',   $cmtCnt ? $cmtCnt . ' 条待审' : '暂无待审', $cmtCnt],
            ['support.php',       'comment',  '客服工单',   $openTk ? $openTk . ' 条待处理' : '暂无待处理', $openTk],
            ['users.php',         'users',    '用户管理',   $userCnt . ' 位注册用户',      ''],
            ['media.php',         'image',    '媒体库',     $mediaCnt . ' 张封面',     ''],
            ['ingredients.php',   'leaf',     '食材图库',   $ingCnt . ' 种配图',       ''],
        ],
    ],
    [
        'title' => '系统工具',
        'hint'  => '设置、同步、备份与诊断',
        'items' => [
            ['app-downloads.php','down',    'App 下载',   '安装包与下载链接',      ''],
            ['app-versions.php','refresh',  '客户端更新', '三端版本与更新包地址',  ''],
            ['settings.php',    'settings', '系统设置',   '站点与 AI',             ''],
            ['sync.php',        'upload',   '云端同步',   '本地内容推到 InfinityFree',    ''],
            ['backup.php',      'database', '数据备份',   '导出数据库', $lastBk ? 0 : 1],
            ['source-backup.php','down',    '源码备份',   '打包整站文件下载',      ''],
            ['update.php',      'refresh',  '更新系统',   '上传 ZIP 覆盖程序', ''],
            ['reset.php',       'trash',    '数据清理',   '清空旧内容',      ''],
            ['site-doctor.php', 'shield',   '网站诊断',   '功能与配置',  ''],
        ],
    ],
    [
        'title' => '站点与账号',
        'hint'  => '前台入口和当前管理员',
        'items' => [
            ['../index.php', 'home',   '查看前台', '看用户看到的样子', ''],
            ['logout.php',   'logout', '退出登录', '当前管理员账号',   ''],
        ],
    ],
];
?>
<?php sg_admin_head('更多', 'admin-more', '更多功能'); ?>

        <div class="page-head">
          <h1>更多功能</h1>
          <span class="sub desk-only">手机上不常用的入口都收在这里</span>
          <div class="actions">
            <a class="btn sm more-back" href="dashboard.php"><span class="ico" data-ico="back"></span>回仪表盘</a>
          </div>
        </div>

        <?= flash_render() ?>

<?php if ($cmtCnt || $pendingRc): ?>
        <div class="card" style="background:#FBF6EE;border:1px dashed #DCC9AE;margin-bottom:14px">
          <div class="card-head" style="padding:0;border:0;margin-bottom:10px"><h3>待办</h3><span class="hint">处理完就不用惦记了</span></div>
          <div class="todo-list">
<?php if ($pendingRc): ?>
            <a class="todo" href="recipes.php?status=pending"><span class="ico" data-ico="book"></span><b><?= $pendingRc ?> 道投稿菜谱等审核</b><span class="go">去处理<span class="ico" data-ico="chev"></span></span></a>
<?php endif; ?>
<?php if ($cmtCnt): ?>
            <a class="todo" href="comments.php?status=pending"><span class="ico" data-ico="comment"></span><b><?= $cmtCnt ?> 条评论等审核</b><span class="go">去处理<span class="ico" data-ico="chev"></span></span></a>
<?php endif; ?>
          </div>
        </div>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
        <section class="hub-panel">
          <div class="hub-panel-head"><h2><?= e($group['title']) ?></h2><span><?= e($group['hint']) ?></span></div>
          <div class="hub-grid">
<?php foreach ($group['items'] as $it): ?>
            <a class="hub-tile" href="<?= e($it[0]) ?>">
              <span class="hub-tile-ic"><span class="ico" data-ico="<?= e($it[1]) ?>"></span></span>
              <div><b><?= e($it[2]) ?><?php if (!empty($it[4])): ?><i class="hub-count"><?= (int) $it[4] ?></i><?php endif; ?></b><span><?= e($it[3]) ?></span></div>
              <span class="hub-go"><span class="ico" data-ico="chev"></span></span>
            </a>
<?php endforeach; ?>
          </div>
        </section>
<?php endforeach; ?>

        <div class="card" style="margin-top:14px">
          <div class="card-head"><h3>当前账号</h3><span class="hint">v<?= SG_VERSION ?></span></div>
          <div class="meta-box">
            <div class="meta-row"><span class="ico" data-ico="shield"></span><span>管理员</span><b><?= e(current_admin()['display_name'] ?? '管理员') ?> · <?= (current_admin()['role'] ?? '') === 'super' ? '超级管理员' : '管理员' ?></b></div>
            <div class="meta-row"><span class="ico" data-ico="clock"></span><span>登录时间</span><b><?= e(time_ago(current_admin()['last_login_at'] ?? date('Y-m-d H:i:s'))) ?></b></div>
            <div class="meta-row"><span class="ico" data-ico="database"></span><span>数据库</span><b><?= e($GLOBALS['SG_CFG']['db']['name'] ?? 'MySQL') ?> · 已连接</b></div>
          </div>
        </div>

<?php sg_admin_foot(); ?>
