<?php
/**
 * 后台 · 数据清理
 * 只清内容数据，始终保留管理员(sg_admins)和站点设置(sg_settings)。
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$admin = current_admin();
if (($admin['role'] ?? '') !== 'super') {
    flash('只有超级管理员可以清理数据。', 'err');
    redirect('dashboard.php');
}

$cfg = @include SG_ROOT . '/config/config.php';
$prefix = is_array($cfg) ? ($cfg['db']['prefix'] ?? 'sg_') : 'sg_';

$groups = [
    'recipes' => [
        'label' => '菜谱内容',
        'desc'  => '菜谱、步骤、食材用量、标签关联，并连带评论/点赞/收藏/评分',
        'tables' => ['recipes', 'recipe_ingredients', 'recipe_steps', 'recipe_tags', 'comments', 'comment_likes', 'favorites', 'ratings'],
    ],
    'categories' => [
        'label' => '分类与图标',
        'desc'  => '分类和自定义分类图标；保留菜谱会失去分类归属',
        'tables' => ['categories', 'icons'],
    ],
    'ingredients' => [
        'label' => '食材图库',
        'desc'  => '全局食材配图字典，只影响配图匹配',
        'tables' => ['ingredients'],
    ],
    'tags' => [
        'label' => '标签',
        'desc'  => '标签和菜谱的标签关联',
        'tables' => ['tags', 'recipe_tags'],
    ],
    'comments' => [
        'label' => '评论与点赞',
        'desc'  => '所有菜谱评论和评论点赞',
        'tables' => ['comments', 'comment_likes'],
    ],
    'users' => [
        'label' => '前台用户',
        'desc'  => '注册用户、收藏、评分、评论点赞；保留评论正文',
        'tables' => ['users', 'favorites', 'ratings', 'comment_likes'],
    ],
    'sync_map' => [
        'label' => '同步映射',
        'desc'  => '本地与云端旧的 local_id / target_id 对应关系',
        'tables' => ['sync_map'],
    ],
    'home' => [
        'label' => '首页公告',
        'desc'  => '只清空公告文字并关闭；版本号、更新内容和历史版本保留',
        'tables' => [],
    ],
    'app_downloads' => [
        'label' => 'App 下载页',
        'desc'  => '下载页的安装卡片；本站上传的安装包也会一并删除，外链不受影响',
        'tables' => ['app_downloads'],
    ],
];

$imageDirs = [
    'uploads/recipes',
    'uploads/steps',
    'uploads/categories',
    'uploads/icons',
    'uploads/ingredients',
    'uploads/sync',
    'uploads/ai',
    'uploads/avatars',
    'uploads/apps',
];

/** 清理前自动导出 SQL（不包含 uploads 图片） */
function reset_backup_sql(PDO $pdo, string $prefix, string $dir): string
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('无法创建 config/backup 目录');
    }
    $file = $dir . '/before-reset-' . date('Ymd-His') . '.sql';
    $fh = fopen($file, 'wb');
    if (!$fh) {
        throw new RuntimeException('无法写入备份文件，请检查 config/backup 权限');
    }
    fwrite($fh, "-- 食光菜谱 · 清理前自动备份\n-- 生成时间：" . date('Y-m-d H:i:s') . "\n-- 表前缀：{$prefix}\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if (strpos($t, $prefix) !== 0) { continue; }
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_ASSOC);
        $ddl = $create['Create Table'] ?? '';
        if ($ddl === '') { continue; }
        fwrite($fh, "DROP TABLE IF EXISTS `{$t}`;\n{$ddl};\n\n");
        $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_map(function ($c) { return '`' . $c . '`'; }, array_keys($row));
            $vals = array_map(function ($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote((string) $v); }, array_values($row));
            fwrite($fh, 'INSERT INTO `' . $t . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return basename($file);
}

/** 只删内容目录里的文件，不删目录本身，也不碰 uploads/audio */
function reset_clear_uploads(string $root, array $dirs): int
{
    $count = 0;
    foreach ($dirs as $rel) {
        $dir = $root . '/' . ltrim($rel, '/');
        if (!is_dir($dir)) { continue; }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isFile() && @unlink($file->getPathname())) { $count++; }
        }
    }
    return $count;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $all = post('do') === 'clean_all';
    $selected = $all ? array_keys($groups) : array_values(array_intersect(array_keys($groups), (array) post('targets', [])));
    $withImages = $all || post('images') === '1';
    if (!$selected) {
        flash('没有选择要清理的内容。', 'err');
        redirect('reset.php');
    }
    if (post('confirm') !== '1') {
        flash('请先勾选“我已确认清空”。', 'err');
        redirect('reset.php');
    }

    try {
        $backup = reset_backup_sql(db(), $prefix, SG_ROOT . '/config/backup');

        $tables = [];
        foreach ($selected as $key) {
            foreach ($groups[$key]['tables'] as $t) { $tables[$t] = true; }
        }
        $existing = [];
        foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $existing[$t] = true; }
        $doneTables = 0;
        foreach (array_keys($tables) as $t) {
            $full = $prefix . $t;
            if (!isset($existing[$full])) { continue; }
            $sqlTable = '`' . str_replace('`', '', $full) . '`';
            try {
                q('TRUNCATE TABLE ' . $sqlTable);
            } catch (Throwable $te) {
                /* 个别虚拟主机不给 TRUNCATE 权限，退化为 DELETE，保证能清空 */
                q('DELETE FROM ' . $sqlTable);
            }
            $doneTables++;
        }
        if (in_array('home', $selected, true)) {
            setting_set('home_notice', '');
            setting_set('home_notice_on', '0');
            /* 版本号、更新内容和历史版本不参与清理，始终保留；缺失时补回当前版本说明 */
            if (trim((string) setting('home_update_version', '')) === '') {
                setting_set('home_update_version', 'v' . SG_VERSION);
            }
            if (trim((string) setting('home_update_text', '')) === '') {
                setting_set('home_update_text', '一键清空全部内容不再清空版本号和更新记录；版本历史继续保留。');
            }
        }
        $imgCount = 0;
        $imgErr = '';
        if ($withImages) {
            try { $imgCount = reset_clear_uploads(SG_ROOT, $imageDirs); }
            catch (Throwable $ie) { $imgErr = '；图片目录未清完：' . $ie->getMessage(); }
        }
        $summary = [];
        foreach ($selected as $key) { $summary[] = $groups[$key]['label']; }
        flash('已自动备份到 config/backup/' . $backup . '；已清理：' . implode('、', $summary) . '。共 ' . $doneTables . ' 张表' . ($withImages ? '，删除 ' . $imgCount . ' 个内容文件' : '') . '。管理员和站点设置保留。' . $imgErr, $imgErr === '' ? 'ok' : 'err');
    } catch (Throwable $ex) {
        flash('清理失败：' . $ex->getMessage(), 'err');
    }
    redirect('reset.php');
}

$userCnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('users') . '`', [], 0);
$catCnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('categories') . '`', [], 0);
$recCnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '`', [], 0);
$cmtCnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '`', [], 0);
$indCnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('ingredients') . '`', [], 0);
$admCnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('admins') . '`', [], 0);

sg_admin_head('数据清理', 'admin-reset', '数据清理');
?>
        <div class="page-head">
          <h1>数据清理</h1>
          <span class="sub desk-only">清内容，保留管理员和站点设置</span>
        </div>

        <?= flash_render() ?>

        <div class="alert err"><span class="ico" data-ico="alert"></span><span><b>不可恢复：</b>每次清理前会自动导出一份 SQL 到 <code>config/backup/</code>，但备份不含 uploads 图片。管理员账号和站点设置（含同步密钥、AI 配置）始终保留。</span></div>

        <div class="card">
          <div class="card-head"><h3>当前内容</h3><span class="hint">清理前先看一眼</span></div>
          <div class="reset-counts" style="margin-bottom:0">
            <div><b><?= $recCnt ?></b><span>菜谱</span></div>
            <div><b><?= $catCnt ?></b><span>分类</span></div>
            <div><b><?= $cmtCnt ?></b><span>评论</span></div>
          </div>
          <div class="reset-counts" style="margin-bottom:0">
            <div><b><?= $userCnt ?></b><span>前台用户</span></div>
            <div><b><?= $indCnt ?></b><span>食材图库</span></div>
            <div><b><?= $admCnt ?></b><span>管理员（保留）</span></div>
          </div>
        </div>

        <div class="card" style="border-color:#E5B9A8">
          <div class="card-head"><h3>一键清空全部内容</h3><span class="hint">保留后台登录和站点设置表</span></div>
          <p style="margin:0 0 12px;font-size:12.5px;line-height:1.8;color:var(--muted)">清空菜谱、分类、食材图库、标签、评论、点赞、收藏、评分、前台用户、同步映射和 App 下载卡片，删除内容图片与本站上传的安装包，只清空首页公告文字。欢迎音频、管理员和站点设置都不动；版本号、更新内容、历史版本始终保留，缺失时补回当前版本说明。</p>
          <form method="post" data-confirm="确定清空全部内容吗？系统会先自动导出一份 SQL 备份，然后删除内容数据、内容图片、本站上传的安装包和首页公告文字；版本号与版本更新内容不会被清空，缺失时会补回当前版本说明。">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="clean_all">
            <label class="reset-check"><input type="checkbox" name="confirm" value="1" required> 我已确认清空全部内容</label>
            <button class="btn danger" type="submit"><span class="ico" data-ico="trash"></span>清空全部内容</button>
          </form>
        </div>

        <div class="card">
          <div class="card-head"><h3>选择性清理</h3><span class="hint">只清勾选的部分</span></div>
          <form method="post" data-confirm="确定清理勾选的内容吗？系统会先自动导出一份 SQL 备份。">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="clean_selected">
            <div class="reset-grid">
<?php foreach ($groups as $key => $g): ?>
              <label class="reset-opt">
                <input type="checkbox" name="targets[]" value="<?= e($key) ?>">
                <b><?= e($g['label']) ?></b>
                <span><?= e($g['desc']) ?></span>
              </label>
<?php endforeach; ?>
            </div>
            <label class="reset-check"><input type="checkbox" name="images" value="1"> 同时删除内容图片（菜谱/步骤/分类/食材/图标/同步图）</label>
            <label class="reset-check"><input type="checkbox" name="confirm" value="1" required> 我已确认清理勾选内容</label>
            <button class="btn danger" type="submit"><span class="ico" data-ico="trash"></span>清理勾选内容</button>
          </form>
        </div>

        <p class="tip" style="margin-top:12px">清理完成后前台会是空站：没有菜谱、分类、评论和首页播报文字，但你的管理员账号仍能登录，系统设置和欢迎音频也还在。两个站都用时，本地和云端要分别清理。</p>
<?php sg_admin_foot(); ?>
