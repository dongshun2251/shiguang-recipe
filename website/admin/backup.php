<?php
/**
 * 后台 · 数据备份
 * 不依赖 mysqldump/exec，纯 PHP 导出结构 + 数据，适合手机上的 PHP 环境。
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$dir = SG_ROOT . '/config/backup';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

$msg = '';
$err = '';
$cfg = @include SG_ROOT . '/config/config.php';
$prefix = is_array($cfg) ? ($cfg['db']['prefix'] ?? 'sg_') : 'sg_';

function bk_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    foreach (glob($dir . '/*.sql') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($out, function ($a, $b) { return $b['time'] <=> $a['time']; });
    return $out;
}

function bk_dump(PDO $pdo, string $prefix, string $file): array
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $keep = array_values(array_filter($tables, function ($t) use ($prefix) { return strpos($t, $prefix) === 0; }));
    $fh = fopen($file, 'wb');
    if (!$fh) { throw new RuntimeException('无法写入备份文件，请检查 config/backup 目录权限'); }
    $rowsCount = 0;
    fwrite($fh, "-- 食光菜谱数据备份\n-- 生成时间：" . date('Y-m-d H:i:s') . "\n-- 表前缀：{$prefix}\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    foreach ($keep as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_ASSOC);
        $ddl = $create['Create Table'] ?? ($create['Create View'] ?? '');
        if ($ddl === '') { continue; }
        fwrite($fh, "DROP TABLE IF EXISTS `{$t}`;\n" . $ddl . ";\n\n");
        $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_map(function ($c) { return '`' . $c . '`'; }, array_keys($row));
            $vals = array_map(function ($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote((string) $v); }, array_values($row));
            fwrite($fh, "INSERT INTO `{$t}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n");
            $rowsCount++;
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return [count($keep), $rowsCount];
}

/* 创建备份 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'create') {
    csrf_verify();
    try {
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('config/backup 目录不可写，请检查权限');
        }
        $name = 'shiguang-' . date('Ymd-His') . '.sql';
        $info = bk_dump(db(), $prefix, $dir . '/' . $name);
        $msg = '备份完成：' . $name . '（' . $info[0] . ' 张表，' . $info[1] . ' 行数据）';
    } catch (Throwable $ex) {
        $err = '备份失败：' . $ex->getMessage();
    }
}

/* 下载 */
if (get('download') !== '') {
    $n = basename(get('download'));
    $f = $dir . '/' . $n;
    if (preg_match('/\.sql$/i', $n) && is_file($f)) {
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $n . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
    $err = '备份文件不存在';
}

/* 删除 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    csrf_verify();
    $n = basename(post('file'));
    $f = $dir . '/' . $n;
    if (preg_match('/\.sql$/i', $n) && is_file($f) && @unlink($f)) {
        $msg = '已删除备份 ' . $n;
    } else {
        $err = '删除失败，文件不存在或没有权限';
    }
}

$files = bk_files($dir);
sg_admin_head('数据备份', 'backup', '数据备份');
?>
        <div class="page-head">
          <h1>数据备份</h1>
          <span class="sub desk-only">导出结构 + 数据，换机或升级前先备一份</span>
          <div class="actions">
            <form method="post" style="display:inline">
              <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="do" value="create">
              <button class="btn primary" type="submit"><span class="ico" data-ico="database"></span>立即备份</button>
            </form>
          </div>
        </div>

<?php if ($msg !== ''): ?><div class="alert ok"><span class="ico" data-ico="check"></span><span><?= e($msg) ?></span></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($err) ?></span></div><?php endif; ?>

        <div class="card">
          <div class="card-head"><h3>备份文件</h3><span class="hint"><?= count($files) ?> 个 · 目录 config/backup</span></div>
<?php if (!$files): ?>
          <p style="margin:0;font-size:12.5px;color:var(--muted)">还没有备份。点右上角「立即备份」生成第一个。</p>
<?php else: ?>
          <div class="bk-list">
<?php foreach ($files as $f): ?>
            <div class="bk-row">
              <span class="ico" data-ico="database"></span>
              <div class="bk-m"><b><?= e($f['name']) ?></b><span><?= e(fmt_date(date('Y-m-d H:i:s', $f['time']))) ?> · <?= number_format($f['size'] / 1024, 1) ?> KB</span></div>
              <a class="btn sm" href="backup.php?download=<?= e(urlencode($f['name'])) ?>"><span class="ico" data-ico="down"></span>下载</a>
              <form method="post" onsubmit="return confirm('确定删除这份备份？')" style="display:inline">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="file" value="<?= e($f['name']) ?>">
                <button class="btn sm" type="submit"><span class="ico" data-ico="trash"></span>删除</button>
              </form>
            </div>
<?php endforeach; ?>
          </div>
<?php endif; ?>
        </div>

        <div class="card" style="margin-top:14px;background:#FBF6EE">
          <div class="card-head"><h3>说明</h3></div>
          <p class="tip-line">备份文件是标准 SQL（含 DROP + CREATE + INSERT），恢复时在 phpMyAdmin「导入」或
          <code>mysql -u root db &lt; 文件.sql</code> 即可。文件放在 <b>config/backup/</b>，建议定期下载到电脑，避免手机丢失。</p>
        </div>
<?php
sg_admin_foot();
