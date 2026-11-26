<?php
/**
 * 后台 · 更新系统
 *
 * 上传新版源码 ZIP（或填 CDN 链接），只覆盖代码：
 * - 保留 config/config.php、config/install.lock、config/backup
 * - 保留 uploads 里的图片和安装包，只更新 uploads 下的 .htaccess / index.html
 * - 更新前自动打包当前代码到 config/backup/code-*.zip
 * - 不执行 DROP / DELETE；数据库增量由新版 bootstrap / upgrade.php 完成
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$a = current_admin();
if (($a['role'] ?? '') !== 'super') {
    flash('只有超级管理员可以更新源码', 'err');
    redirect('more.php');
}

$backupDir = SG_ROOT . '/config/backup';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }
$maxUpload = ini_get('upload_max_filesize');
$zipReady = class_exists('ZipArchive');
$rootReady = is_writable(SG_ROOT);
$currentVersion = defined('SG_VERSION') ? SG_VERSION : '';

require_once __DIR__ . '/../inc/updater.php';

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'update') {
    csrf_verify();
    $stage = '';
    try {
        if (!$zipReady) { throw new RuntimeException('PHP 未启用 ZipArchive，无法解压更新包'); }
        if (!$rootReady) { throw new RuntimeException('网站根目录不可写，请先调整目录权限'); }
        if (!is_dir($backupDir) || !is_writable($backupDir)) { throw new RuntimeException('config/backup 不可写'); }

        $zipPath = $backupDir . '/update-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $hasFile = !empty($_FILES['package']['name']);
        if ($hasFile) {
            if ((int) ($_FILES['package']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('ZIP 上传失败，错误码：' . (int) $_FILES['package']['error']);
            }
            if (strtolower(pathinfo((string) $_FILES['package']['name'], PATHINFO_EXTENSION)) !== 'zip') {
                throw new RuntimeException('只支持 ZIP 源码包');
            }
            if ((int) $_FILES['package']['size'] > 20 * 1024 * 1024) {
                throw new RuntimeException('源码包超过 20 MB');
            }
            if (!move_uploaded_file($_FILES['package']['tmp_name'], $zipPath)) {
                throw new RuntimeException('无法保存上传的 ZIP');
            }
        } else {
            $url = trim((string) post('url'));
            if ($url === '') { throw new RuntimeException('请上传 ZIP，或填写源码包链接'); }
            up_fetch_zip($url, $zipPath);
        }

        $stage = $backupDir . '/update-stage-' . bin2hex(random_bytes(4));
        $info = up_stage_zip($zipPath, $stage);
        $oldVersion = $currentVersion;
        $backup = up_backup_code($backupDir, $oldVersion);
        $changed = up_apply_tree($stage);
        @file_put_contents($backupDir . '/update.log', date('Y-m-d H:i:s') . ' ' . ($a['username'] ?? 'admin') . ' ' . $oldVersion . ' -> ' . $info['version'] . ' files=' . $changed . ' backup=' . $backup . "\n", FILE_APPEND);
        if (function_exists('opcache_reset')) { @opcache_reset(); }
        flash('系统已更新：v' . $oldVersion . ' → v' . $info['version'] . '，覆盖 ' . $changed . ' 个文件；旧代码备份在 config/backup/' . $backup);
    } catch (Throwable $e) {
        flash('更新失败：' . $e->getMessage(), 'err');
    } finally {
        if ($stage !== '' && is_dir($stage)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $p => $i) { $i->isDir() ? @rmdir($p) : @unlink($p); }
            @rmdir($stage);
        }
        if (!empty($zipPath) && is_file($zipPath)) { @unlink($zipPath); }
    }
    redirect('update.php');
}

sg_admin_head('更新系统', 'admin-update', '更新系统');
?>
<div class="page-head">
  <h1>更新系统</h1>
  <span class="sub desk-only">上传新版 ZIP，只覆盖代码，不清空已有数据</span>
  <div class="actions">
    <a class="btn sm" href="backup.php"><span class="ico" data-ico="database"></span>先备份数据库</a>
  </div>
</div>

<?= flash_render() ?>

<div class="alert info" style="margin-bottom:14px">
  <span class="ico" data-ico="info"></span>
  <span>更新范围只包括程序文件。数据库、<b>config/config.php</b>、<b>uploads 里的图片和安装包</b>都不会被清空；更新前会自动把当前代码打包到 <b>config/backup</b>。</span>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-head"><h3>当前环境</h3><span class="hint">更新前先看一眼</span></div>
  <div class="form-grid">
    <div class="field"><label>当前版本</label><input class="input" value="v<?= e($currentVersion) ?>" readonly></div>
    <div class="field"><label>ZIP 解压</label><input class="input" value="<?= $zipReady ? '可用' : '缺少 ZipArchive，无法更新' ?>" readonly></div>
    <div class="field"><label>上传上限</label><input class="input" value="<?= e((string) $maxUpload) ?>" readonly></div>
    <div class="field"><label>网站根目录</label><input class="input" value="<?= $rootReady ? '可写' : '不可写' ?>" readonly></div>
  </div>
  <p class="tip" style="margin-top:10px">服务器上传限制较小时，建议直接粘贴源码包的 CDN 链接；源码包本身约 2 MB。</p>
</div>

<div class="card">
  <div class="card-head"><h3>上传新版源码</h3><span class="hint">ZIP 内可以是 shiguang/ 目录，也可以直接是 index.php 根目录</span></div>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="update">
    <div class="field">
      <label for="packageInput">源码 ZIP</label>
      <label class="file-field" for="packageInput" data-file-field>
        <input class="file-native" id="packageInput" type="file" name="package" accept=".zip">
        <span class="file-pick"><span class="ico" data-ico="folder"></span><span>选择文件</span></span>
        <span class="file-name" data-file-name>未选择任何文件</span>
      </label>
    </div>
    <div class="field" style="margin-top:12px"><label for="packageUrl">或者填源码包直链</label><input class="input" id="packageUrl" name="url" placeholder="https://aka.doubaocdn.com/s/xxxx" autocomplete="off"></div>
    <p class="tip">只支持 HTTP(S) 链接；更新完成后如果页面提示数据库字段缺失，打开 <a href="../upgrade.php" target="_blank" rel="noopener" style="color:var(--brand)">upgrade.php</a> 点一次升级，数据不会被删。</p>
    <div style="margin-top:16px"><button class="btn primary" type="submit" data-confirm="确定用这个源码包覆盖当前程序文件吗？数据库和 config 不会被清空，更新前会自动备份当前代码。"><span class="ico" data-ico="refresh"></span>开始更新</button></div>
  </form>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-head"><h3>更新后检查</h3><span class="hint">三件事</span></div>
  <div class="meta-row"><span class="ico" data-ico="check"></span><span>打开前台首页和后台仪表盘</span><b>看是否正常</b></div>
  <div class="meta-row"><span class="ico" data-ico="check"></span><span>看后台底部版本号</span><b>应变成新版</b></div>
  <div class="meta-row"><span class="ico" data-ico="check"></span><span>如果数据库结构有变化</span><b><a href="../upgrade.php" target="_blank" rel="noopener" style="color:var(--brand)">打开升级页补表 →</a></b></div>
</div>

<script>
(function () {
  document.querySelectorAll('[data-file-field]').forEach(function (wrap) {
    var input = wrap.querySelector('input[type="file"]');
    var name = wrap.querySelector('[data-file-name]');
    if (!input || !name) return;
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      name.textContent = file ? file.name : '未选择任何文件';
      name.classList.toggle('has-file', !!file);
    });
  });
})();
</script>

<?php sg_admin_foot(); ?>
