<?php
/**
 * 后台 · 源码备份
 * 在服务器上把当前程序打包成 ZIP，供超级管理员下载。
 * 默认生成可移植部署包：不包含本站 config.php，附配置样例和部署说明；
 * 勾选后可以一并导出数据库 .sql。
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$a = current_admin();
if (($a['role'] ?? '') !== 'super') {
    flash('只有超级管理员可以下载完整源码', 'err');
    redirect('more.php');
}

$backupDir = SG_ROOT . '/config/backup';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }
$zipReady = class_exists('ZipArchive');

function sb_size(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function sb_name(string $version): string
{
    $v = preg_replace('/[^0-9A-Za-z._-]/', '', $version);
    return 'shiguang-source-v' . ($v !== '' ? $v : 'unknown') . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.zip';
}

function sb_dump_db(PDO $pdo, string $prefix, string $file): array
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $keep = array_values(array_filter($tables, function ($t) use ($prefix) {
        return strpos($t, $prefix) === 0;
    }));
    $fh = fopen($file, 'wb');
    if (!$fh) throw new RuntimeException('无法写入临时数据库文件');
    $rows = 0;
    fwrite($fh, "-- 食光菜谱数据库备份\n-- 生成时间：" . date('Y-m-d H:i:s') . "\n-- 表前缀：{$prefix}\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    foreach ($keep as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_ASSOC);
        $ddl = $create['Create Table'] ?? ($create['Create View'] ?? '');
        if ($ddl === '') continue;
        fwrite($fh, "DROP TABLE IF EXISTS `{$t}`;\n" . $ddl . ";\n\n");
        $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_map(function ($c) { return '`' . $c . '`'; }, array_keys($row));
            $vals = array_map(function ($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote((string) $v); }, array_values($row));
            fwrite($fh, "INSERT INTO `{$t}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n");
            $rows++;
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return [count($keep), $rows];
}

function sb_config_example(string $prefix): string
{
    $prefix = preg_replace('/[^a-z0-9_]/i', '', $prefix);
    if ($prefix === '' || substr($prefix, -1) !== '_') $prefix .= '_';
    return "<?php\n// 食光菜谱 · 部署配置样例\n// 复制本文件为 config/config.php，再填写下面 5 项\nreturn [\n"
        . "    'db' => [\n"
        . "        'host'    => '127.0.0.1',\n"
        . "        'port'    => 3306,\n"
        . "        'name'    => 'shiguang',\n"
        . "        'user'    => 'root',\n"
        . "        'pass'    => '',\n"
        . "        'prefix'  => '" . $prefix . "',\n"
        . "        'charset' => 'utf8mb4',\n"
        . "    ],\n"
        . "    'app' => ['timezone' => 'Asia/Shanghai', 'debug' => false],\n"
        . "];\n";
}

function sb_deploy_readme(bool $db, bool $withConfig, string $prefix): string
{
    $out = "食光菜谱 · 可移植部署包\n"
        . "========================================\n"
        . "本包默认不包含原站的 config/config.php，避免把原服务器数据库地址和密码带到新服务器。\n"
        . "程序入口 index.php 在压缩包根目录，不要多套一层文件夹。\n\n"
        . "包含：程序文件、uploads（若打包时勾选）、database.sql（若勾选数据库）\n"
        . ($withConfig ? "注意：本包勾选了「包含本站 config.php」，换服务器前必须改里面的数据库地址，否则会连回原站数据库。\n" : "")
        . "\n【有 database.sql 的部署方式】\n"
        . "1. 在新服务器新建 MySQL 数据库（例如 shiguang）。\n"
        . "2. 用 phpMyAdmin 导入 database.sql。表前缀是 {$prefix}，配置文件里的 prefix 要一致。\n"
        . "3. 把 config/config.example.php 复制为 config/config.php，填 host、port、name、user、pass。\n"
        . "4. 上传本包全部文件到网站根目录，确认根目录直接有 index.php。\n"
        . "5. 直接访问域名。不要再运行 install/，否则会要求重装并可能清空已有表。\n\n"
        . "【没有 database.sql 的全新安装】\n"
        . "1. 上传文件到网站根目录。\n"
        . "2. 访问 /install/，按向导填新数据库信息，安装程序会建库建表。\n"
        . "3. 装完会自动写 config/config.php 和 config/install.lock。\n\n"
        . "【环境要求】\n"
        . "PHP 8.0+，启用 pdo_mysql；打包/解压需要 ZipArchive。\n"
        . "uploads 目录要可写，config 目录要可写。\n";
    if (!$db) {
        $out .= "\n本包没有包含数据库；需要原站数据时，请先在原站后台「数据备份」导出 .sql，再导入新库。\n";
    }
    return $out;
}

function sb_add_tree(ZipArchive $zip, string $root, array $skipDirs, array $skipFiles, int &$count, int &$bytes): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $path => $info) {
        if (!$info->isFile()) continue;
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        foreach ($skipDirs as $d) {
            if ($rel === $d || strpos($rel, $d . '/') === 0) continue 2;
        }
        if (in_array($rel, $skipFiles, true)) continue;
        if (preg_match('/\.(log|tmp)$/i', $rel)) continue;
        if (preg_match('/^(\.DS_Store|Thumbs\.db)$/i', $rel)) continue;
        if (strpos($rel, '/') === false && preg_match('/\.(txt|zip)$/i', $rel)) continue;
        if (!$zip->addFile($path, $rel)) continue;
        $count++;
        $bytes += (int) $info->getSize();
    }
}

/* 下载：只允许 config/backup 下的 zip，且必须是管理员会话 */
$dl = (string) get('download');
if ($dl !== '') {
    $n = basename($dl);
    $f = $backupDir . '/' . $n;
    if (preg_match('/^[A-Za-z0-9._-]+\.zip$/', $n) && is_file($f)) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(0);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $n . '"');
        header('Content-Length: ' . filesize($f));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($f);
        exit;
    }
    flash('源码包不存在或文件名不合法', 'err');
    redirect('source-backup.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string) post('do');

    if ($do === 'create') {
        try {
            if (!$zipReady) throw new RuntimeException('PHP 未启用 ZipArchive，无法打包源码');
            if (!is_dir($backupDir) || !is_writable($backupDir)) throw new RuntimeException('config/backup 目录不可写，请检查权限');

            @set_time_limit(300);
            @ini_set('memory_limit', '256M');
            @ignore_user_abort(true);

            $includeConfig = post('include_config') === '1';
            $includeUploads = post('include_uploads') === '1';
            $includeShots = post('include_shots') === '1';
            $includeDatabase = post('include_database') === '1';

            $cfgNow = @include SG_ROOT . '/config/config.php';
            $prefix = is_array($cfgNow) ? (string) ($cfgNow['db']['prefix'] ?? 'sg_') : 'sg_';

            $name = sb_name(defined('SG_VERSION') ? SG_VERSION : 'unknown');
            $path = $backupDir . '/' . $name;
            $skipDirs = ['config/backup'];
            if (!$includeUploads) $skipDirs[] = 'uploads';
            if (!$includeShots) $skipDirs[] = 'shots';
            $skipFiles = [];
            if (!$includeConfig) {
                $skipFiles[] = 'config/config.php';
                $skipFiles[] = 'config/install.lock';
            }

            $dbTmp = '';
            $dbRows = 0;
            if ($includeDatabase) {
                $dbTmp = $backupDir . '/deploy-db-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
                try {
                    $dbInfo = sb_dump_db(db(), $prefix, $dbTmp);
                    $dbRows = (int) ($dbInfo[1] ?? 0);
                } catch (Throwable $e) {
                    @unlink($dbTmp);
                    throw $e;
                }
            }

            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                if ($dbTmp !== '') @unlink($dbTmp);
                throw new RuntimeException('无法创建源码压缩包');
            }
            $count = 0;
            $bytes = 0;
            try {
                sb_add_tree($zip, SG_ROOT, $skipDirs, $skipFiles, $count, $bytes);
                if ($dbTmp !== '' && !$zip->addFile($dbTmp, 'database.sql')) {
                    throw new RuntimeException('无法把数据库文件加入压缩包');
                }
                $zip->addFromString('config/config.example.php', sb_config_example($prefix));
                $zip->addFromString('DEPLOY.txt', sb_deploy_readme($includeDatabase, $includeConfig, $prefix));
                $zip->close();
            } catch (Throwable $e) {
                $zip->close();
                @unlink($path);
                if ($dbTmp !== '') @unlink($dbTmp);
                throw $e;
            }
            if ($dbTmp !== '') @unlink($dbTmp);
            if ($count === 0) {
                @unlink($path);
                throw new RuntimeException('没有可打包的文件');
            }
            @file_put_contents($backupDir . '/source-backup.log', date('Y-m-d H:i:s') . ' ' . ($a['username'] ?? 'admin') . ' ' . $name . ' files=' . $count . ' bytes=' . $bytes . ' db=' . ($includeDatabase ? '1' : '0') . "\n", FILE_APPEND);
            flash('源码包已生成：' . $name . '（' . $count . ' 个文件，约 ' . sb_size($bytes) . ($includeDatabase ? '，含数据库 ' . $dbRows . ' 行' : '') . '）');
            redirect('source-backup.php?download=' . urlencode($name));
        } catch (Throwable $e) {
            flash('打包失败：' . $e->getMessage(), 'err');
            redirect('source-backup.php');
        }
    }

    if ($do === 'delete' || $do === 'batch_delete') {
        $one = basename((string) post('single'));
        if ($one !== '') {
            $f = $backupDir . '/' . $one;
            if (preg_match('/^[A-Za-z0-9._-]+\.zip$/', $one) && is_file($f) && @unlink($f)) {
                flash('已删除源码包 ' . $one);
            } else {
                flash('源码包不存在或无法删除', 'err');
            }
            redirect('source-backup.php');
        }

        $list = $_POST['files'] ?? [];
        if (!is_array($list)) $list = [];
        $list = array_values(array_unique(array_map(function ($v) {
            return is_scalar($v) ? basename((string) $v) : '';
        }, $list)));
        if (!$list) {
            flash('请先勾选要删除的源码包', 'err');
            redirect('source-backup.php');
        }

        $ok = 0;
        $bad = 0;
        foreach ($list as $n) {
            $isBackupName = strpos($n, 'code-v') === 0 || strpos($n, 'shiguang-source-') === 0;
            $f = $backupDir . '/' . $n;
            if (preg_match('/^[A-Za-z0-9._-]+\.zip$/', $n) && $isBackupName && is_file($f) && @unlink($f)) {
                $ok++;
            } else {
                $bad++;
            }
        }
        if ($ok > 0) {
            flash('已删除 ' . $ok . ' 个源码包' . ($bad > 0 ? '，另有 ' . $bad . ' 个无法删除' : ''));
        } else {
            flash('选中的源码包不存在或无法删除', 'err');
        }
        redirect('source-backup.php');
    }
}

$sourceFiles = [];
$codeFiles = [];
foreach (glob($backupDir . '/*.zip') ?: [] as $f) {
    $n = basename($f);
    if (!preg_match('/^[A-Za-z0-9._-]+\.zip$/', $n)) continue;
    $isCode = strpos($n, 'code-v') === 0;
    $isSource = strpos($n, 'shiguang-source-') === 0;
    if (!$isCode && !$isSource) continue;
    $item = [
        'name' => $n,
        'size' => filesize($f),
        'time' => filemtime($f),
    ];
    if ($isCode) $codeFiles[] = $item;
    else $sourceFiles[] = $item;
}
usort($sourceFiles, function ($x, $y) { return $y['time'] <=> $x['time']; });
usort($codeFiles, function ($x, $y) { return $y['time'] <=> $x['time']; });

sg_admin_head('源码备份', 'admin-source-backup', '源码备份');
?>
<style>
.sb-list-card{background:var(--surface);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.sb-file{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line-2)}
.sb-file:last-child{border-bottom:0}
.sb-check{flex:0 0 17px;width:17px;height:17px;margin:0;accent-color:var(--brand);cursor:pointer}
.sb-bulk{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--line-2);background:var(--surface);position:sticky;top:0;z-index:2}
.sb-all-label{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);cursor:pointer;white-space:nowrap}
.sb-all-label input{width:16px;height:16px;margin:0;accent-color:var(--brand);cursor:pointer}
.sb-bulk-cnt{font-size:11.5px;color:var(--muted);white-space:nowrap}
.sb-bulk .btn{margin-left:auto}
.sb-bulk .btn.is-off{opacity:.55}
.sb-file-ic{width:36px;height:36px;flex:0 0 36px;border-radius:9px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center}
.sb-file-ic .ico{width:18px;height:18px}
.sb-file-main{flex:1;min-width:0}
.sb-file-main b{display:block;font-size:12.5px;line-height:1.5;color:var(--ink);word-break:break-all}
.sb-file-main span{display:block;margin-top:3px;font-size:11.5px;line-height:1.6;color:var(--muted)}
.sb-file-acts{display:flex;align-items:center;gap:6px;flex:0 0 auto}
.sb-file-acts form{margin:0}
.sb-file-acts .btn{flex:0 0 auto;height:30px;padding:0 12px;font-size:12px}
.sb-empty{display:flex;align-items:center;gap:12px;color:var(--muted);font-size:12.5px;padding:16px}
.sb-empty .ico{width:28px;height:28px}
.sb-empty b{display:block;color:var(--ink);font-size:13px}
.sb-auto{margin-top:14px;padding:0;overflow:hidden}
.sb-auto summary .ico{width:18px;height:18px}
.sb-auto summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;padding:13px 16px;font-size:12.5px;color:var(--ink)}
.sb-auto summary::-webkit-details-marker{display:none}
.sb-auto summary b{color:var(--muted);font-weight:400;font-size:11.5px;margin-left:auto}
.sb-auto summary span.sb-chev{color:var(--muted);font-size:11px}
.sb-auto[open] summary{border-bottom:1px solid var(--line-2)}
@media(max-width:700px){
  .sb-file{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:8px;padding:12px 14px}
  .sb-file-ic{display:none}
  .sb-bulk{padding:9px 14px;gap:8px}
  .sb-bulk-cnt{display:none}
  .sb-bulk .btn{padding:0 10px}
  .sb-file-main{min-width:0}
  .sb-file-main b{font-size:12.5px;line-height:1.45}
  .sb-file-main span{margin-top:2px}
  .sb-file-acts{display:flex;margin-left:0;gap:5px;flex-direction:row}
  .sb-file-acts .btn{height:29px;min-width:0;padding:0 8px;font-size:12px}
  .sb-auto summary{padding:12px 14px}
  .sb-auto summary .sb-chev{display:none}
}
</style>
        <div class="page-head">
          <h1>源码备份</h1>
          <span class="sub desk-only">生成能部署到其他服务器的整站包，可一并带上数据库</span>
          <div class="actions">
            <a class="btn" href="backup.php"><span class="ico" data-ico="database"></span>数据备份</a>
          </div>
        </div>

        <?= flash_render() ?>

        <div class="alert info" style="margin-bottom:14px">
          <span class="ico" data-ico="info"></span>
          <span>默认生成<b>可移植部署包</b>：不带上本站的 <b>config.php</b>，避免新服务器连回旧数据库；包内会附 <b>config/config.example.php</b> 和 <b>DEPLOY.txt</b> 部署说明。勾选数据库后会多一个 <b>database.sql</b>。打包会跳过 <b>config/backup</b>，避免压缩包自己套自己。</span>
        </div>

        <?php if (!$zipReady): ?>
        <div class="alert err" style="margin-bottom:14px">
          <span class="ico" data-ico="alert"></span>
          <span>当前 PHP 没有启用 <b>ZipArchive</b>，无法在后台打包源码。可以到 InfinityFree 文件管理器里手动压缩下载。</span>
        </div>
        <?php endif; ?>

        <section class="hub-panel">
          <div class="hub-panel-head"><h2>打包当前源码</h2><span>默认包含程序文件和 uploads，不带本站数据库配置；可附数据库和测试截图</span></div>
          <form method="post" class="card" style="margin:0"<?= $zipReady ? '' : ' aria-disabled="true"' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="create">

            <div class="field">
              <label>包含内容</label>
              <div style="display:grid;gap:9px;margin-top:8px">
                <label style="display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.7">
                  <input type="checkbox" name="include_uploads" value="1" checked style="accent-color:var(--brand);margin-top:3px">
                  <span><b>uploads/</b>：用户上传的菜谱封面、欢迎音频、安装包等。完整恢复整站必须包含。</span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.7">
                  <input type="checkbox" name="include_database" value="1" checked style="accent-color:var(--brand);margin-top:3px">
                  <span><b>database.sql</b>：把当前站数据库一并导出。换新服务器时先导入它，再填新库地址，就能带着菜谱和账号一起部署。</span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.7">
                  <input type="checkbox" name="include_config" value="1" style="accent-color:var(--brand);margin-top:3px">
                  <span><b>config/config.php</b>：本站数据库地址、账号和密码。<b>默认不勾</b>；勾了只适合原服务器原地恢复，换服务器会连错库，请勿外传。</span>
                </label>
                <label style="display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.7">
                  <input type="checkbox" name="include_shots" value="1" style="accent-color:var(--brand);margin-top:3px">
                  <span><b>shots/</b>：开发测试截图，约 17 MB，和网站运行无关；一般不用勾。</span>
                </label>
              </div>
            </div>

            <div class="alert" style="margin-top:14px;background:#FBF6EE;border:1px dashed #DCC9AE">
              <span class="ico" data-ico="alert"></span>
              <span>换服务器部署时，<b>不要勾 config/config.php</b>，用包里的 <b>config/config.example.php</b> 填新数据库。勾了数据库时，导入 <b>database.sql</b> 后不要再运行 install/，否则可能要求重装并清空表。</span>
            </div>

            <div style="margin-top:16px">
              <button class="btn primary" type="submit"<?= $zipReady ? '' : ' disabled' ?>><span class="ico" data-ico="down"></span>立即打包并下载</button>
              <span class="tip" style="margin-left:8px">文件会先存到 config/backup；可移植包默认不带本站 config。</span>
            </div>
          </form>
        </section>

        <section class="hub-panel" style="margin-top:18px">
          <div class="hub-panel-head"><h2>完整源码包</h2><span><?= count($sourceFiles) ?> 个 · 手动打包的整站备份</span></div>
<?php if (!$sourceFiles): ?>
          <div class="sb-list-card"><div class="sb-empty"><span class="ico" data-ico="down"></span><div><b>还没有完整源码包</b><span>点上面的「立即打包并下载」生成第一份。</span></div></div></div>
<?php else: ?>
          <form method="post" class="sb-batch-form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="batch_delete">
            <div class="sb-bulk">
              <label class="sb-all-label"><input type="checkbox" class="sb-all">全选</label>
              <span class="sb-bulk-cnt">已选 <b data-sb-count>0</b> 个</span>
              <button class="btn sm danger is-off" type="submit" name="batch_action" value="delete" data-confirm="确定删除选中的源码包吗？此操作不可恢复。">删除选中</button>
            </div>
          <div class="sb-list-card">
<?php foreach ($sourceFiles as $f): ?>
            <div class="sb-file">
              <input class="sb-check" type="checkbox" name="files[]" value="<?= e($f['name']) ?>">
              <span class="sb-file-ic"><span class="ico" data-ico="down"></span></span>
              <div class="sb-file-main">
                <b><?= e($f['name']) ?></b>
                <span><?= e(sb_size((int) $f['size'])) ?> · <?= e(date('Y-m-d H:i', (int) $f['time'])) ?></span>
              </div>
              <div class="sb-file-acts">
                <a class="btn sm" href="source-backup.php?download=<?= e(urlencode($f['name'])) ?>"><span class="ico" data-ico="down"></span>下载</a>
                <button class="btn sm danger" type="submit" name="single" value="<?= e($f['name']) ?>" data-confirm="确定删除源码包「<?= e($f['name']) ?>」吗？">删除</button>
              </div>
            </div>
<?php endforeach; ?>
          </div>
          </form>
<?php endif; ?>
        </section>

<?php if ($codeFiles): ?>
        <details class="card sb-auto">
          <summary><span class="ico" data-ico="refresh"></span>更新前自动备份<span class="sb-chev">点击展开</span><b><?= count($codeFiles) ?> 个</b></summary>
          <form method="post" class="sb-batch-form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="batch_delete">
            <div class="sb-bulk">
              <label class="sb-all-label"><input type="checkbox" class="sb-all">全选</label>
              <span class="sb-bulk-cnt">已选 <b data-sb-count>0</b> 个</span>
              <button class="btn sm danger is-off" type="submit" name="batch_action" value="delete" data-confirm="确定删除选中的自动备份吗？只保留需要的版本。">删除选中</button>
            </div>
          <div class="sb-list-card" style="border:0;border-radius:0">
<?php foreach ($codeFiles as $f): ?>
            <div class="sb-file">
              <input class="sb-check" type="checkbox" name="files[]" value="<?= e($f['name']) ?>">
              <span class="sb-file-ic"><span class="ico" data-ico="refresh"></span></span>
              <div class="sb-file-main">
                <b><?= e($f['name']) ?></b>
                <span><?= e(sb_size((int) $f['size'])) ?> · <?= e(date('Y-m-d H:i', (int) $f['time'])) ?></span>
              </div>
              <div class="sb-file-acts">
                <a class="btn sm" href="source-backup.php?download=<?= e(urlencode($f['name'])) ?>"><span class="ico" data-ico="down"></span>下载</a>
                <button class="btn sm danger" type="submit" name="single" value="<?= e($f['name']) ?>" data-confirm="确定删除源码包「<?= e($f['name']) ?>」吗？">删除</button>
              </div>
            </div>
<?php endforeach; ?>
          </div>
          </form>
        </details>
<?php endif; ?>

<script>
(function () {
  document.querySelectorAll('form.sb-batch-form').forEach(function (form) {
    var all = form.querySelector('.sb-all');
    var boxes = Array.prototype.slice.call(form.querySelectorAll('.sb-check'));
    var count = form.querySelector('[data-sb-count]');
    var del = form.querySelector('[data-sb-del]') || form.querySelector('.sb-bulk .btn.danger');
    function sync() {
      var n = boxes.filter(function (b) { return b.checked; }).length;
      if (count) count.textContent = n;
      if (all) {
        all.checked = n === boxes.length && n > 0;
        all.indeterminate = n > 0 && n < boxes.length;
      }
      if (del) {
        del.classList.toggle('is-off', n === 0);
        del.disabled = n === 0;
      }
    }
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    if (all) {
      all.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = all.checked; });
        sync();
      });
    }
    sync();
  });
})();
</script>
<?php sg_admin_foot(); ?>
