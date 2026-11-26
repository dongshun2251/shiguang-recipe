<?php
/**
 * 食光菜谱 · 部署诊断（不依赖 bootstrap，安装未完成时也能打开）
 * 访问 /doctor.php 会列出路径、权限、配置文件、install.lock、数据库连接状态。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root       = __DIR__;
$configFile = $root . '/config/config.php';
$lockFile   = $root . '/config/install.lock';
$cfg        = is_file($configFile) ? @include $configFile : null;
$isPost     = $_SERVER['REQUEST_METHOD'] === 'POST';

$action = $_POST['action'] ?? '';
$msg    = '';
if ($isPost && $action === 'write_lock') {
    if (!is_file($configFile)) {
        $msg = 'config/config.php 不存在，无法写入 install.lock，请重新运行安装程序。';
    } elseif (file_put_contents($lockFile, "installed at " . date('Y-m-d H:i:s') . "\n") === false) {
        $msg = '写入失败：' . $lockFile . '（目录不可写，请用面板或 SSH 手工创建该文件）';
    } else {
        @chmod($lockFile, 0644);
        $msg = '已写入 config/install.lock。';
    }
}

/* 复算站点根路径（与 bootstrap 相同算法，便于对照） */
$rootReal = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
$script   = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$fileReal = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
$base     = '';
if ($fileReal !== '' && strpos($fileReal, $rootReal . '/') === 0) {
    $rel = substr($fileReal, strlen($rootReal));
    if ($rel !== '' && substr($script, -strlen($rel)) === $rel) {
        $base = substr($script, 0, strlen($script) - strlen($rel));
    }
}
if ($base === '' && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $doc = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'])), '/');
    if ($doc !== '' && strpos($rootReal, $doc) === 0) {
        $base = substr($rootReal, strlen($doc));
    }
}
$base = rtrim($base, '/');

$dbOk   = null; $dbErr = ''; $tableCnt = 0; $dbVer = ''; $sqlMode = '';
if (is_array($cfg) && !empty($cfg['db']['name'])) {
    try {
        $d = $cfg['db'];
        $dsn = 'mysql:host=' . $d['host'] . ';port=' . ($d['port'] ?? 3306) . ';dbname=' . $d['name'] . ';charset=' . ($d['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $dbOk = true;
        $tableCnt = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($d['name']))->fetchColumn();
        $dbVer   = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $sqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    } catch (Throwable $e) {
        $dbOk = false;
        $dbErr = $e->getMessage();
    }
}

function row(string $k, string $v, string $state = ''): void
{ ?>
      <div class="row"><span class="k"><?= htmlspecialchars($k) ?></span><span class="v <?= $state ?>"><?= $v ?></span></div>
<?php }
?><!DOCTYPE html>
<html lang="zh-CN" translate="no">
<head>
<meta charset="UTF-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>部署诊断 · 食光菜谱</title>
<style>
 body{margin:0;padding:18px;background:#ECE6DA;font:14px/1.7 "Noto Sans CJK SC",system-ui,sans-serif;color:#1B1712}
 .wrap{max-width:760px;margin:0 auto;background:#FFFDFA;border-radius:14px;padding:20px;box-shadow:0 10px 30px -18px rgba(0,0,0,.4)}
 h1{font-size:20px;margin:0 0 4px;font-family:"Noto Serif CJK SC",serif}
 p.sub{margin:0 0 18px;color:#8A8378;font-size:12.5px}
 h2{font-size:13px;margin:20px 0 8px;color:#8A8378;font-weight:600;letter-spacing:.06em}
 .row{display:flex;gap:12px;padding:9px 0;border-bottom:1px dashed #E4DCCD;font-size:12.5px;word-break:break-all}
 .row .k{flex:0 0 150px;color:#6C6355}
 .row .v{flex:1;color:#1B1712}
 .ok{color:#2E6B57;font-weight:600}.bad{color:#C8502B;font-weight:600}.warn{color:#B0761F;font-weight:600}
 .msg{margin:14px 0;padding:10px 12px;border-radius:9px;background:#FBF0E9;color:#A63C1C;font-size:12.5px}
 button{margin-top:16px;height:38px;padding:0 16px;border:0;border-radius:9px;background:#C8502B;color:#fff;font-size:13px}
 .tip{margin-top:16px;font-size:11.5px;color:#8A8378;line-height:1.8}
</style>
</head>
<body>
<div class="wrap">
  <h1>部署诊断</h1>
  <p class="sub">食光菜谱 · 用来定位“点了没反应 / 一直跳回安装页”这类问题。打开本页无需数据库。</p>
<?php if ($msg !== ''): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <h2>路径</h2>
<?php
row('站点绝对路径', htmlspecialchars($rootReal));
row('当前脚本', htmlspecialchars($script));
row('DOCUMENT_ROOT', htmlspecialchars((string) ($_SERVER['DOCUMENT_ROOT'] ?? '—')));
row('推算站点根路径', $base === '' ? '（空，即部署在域名根目录）' : htmlspecialchars($base), 'ok');
?>
  <h2>文件</h2>
<?php
row('config/config.php', is_file($configFile) ? '存在' : '不存在', is_file($configFile) ? 'ok' : 'bad');
row('config 目录可写', is_writable(dirname($configFile)) ? '可写' : '不可写', is_writable(dirname($configFile)) ? 'ok' : 'bad');
row('config/install.lock', is_file($lockFile) ? '存在' : '不存在（安装未标记完成）', is_file($lockFile) ? 'ok' : 'warn');
row('install 目录', is_dir($root . '/install') ? (is_writable($root . '/install') ? '仍在，可写' : '仍在，不可写') : '已删除', is_dir($root . '/install') ? 'warn' : 'ok');
row('uploads 目录可写', is_writable($root . '/uploads') ? '可写' : '不可写', is_writable($root . '/uploads') ? 'ok' : 'bad');
?>
  <h2>数据库</h2>
<?php
if (!is_array($cfg)) {
    row('配置文件内容', '无法读取，站点尚未完成安装', 'bad');
} else {
    row('数据库名', htmlspecialchars((string) ($cfg['db']['name'] ?? '—')));
    row('连接结果', $dbOk ? '连接成功，表数量 ' . $tableCnt : '连接失败：' . htmlspecialchars($dbErr), $dbOk ? 'ok' : 'bad');
    if ($dbOk) {
        row('数据库版本', htmlspecialchars($dbVer));
        row('sql_mode', htmlspecialchars($sqlMode === '' ? '（默认）' : $sqlMode), strpos($sqlMode, 'ONLY_FULL_GROUP_BY') !== false ? 'warn' : '');
    }
}
?>
  <h2>登录会话（前台多设备 / 管理员单设备）</h2>
<?php
if (!is_array($cfg) || !$dbOk) {
    row('登录会话', '数据库未连接，无法读取', 'warn');
} else {
    $prefix = $cfg['db']['prefix'] ?? 'sg_';
    try {
        $userSessions = (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'user_sessions` WHERE revoked_at IS NULL')->fetchColumn();
        $qrPending = (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'qr_logins` WHERE status = "pending" AND expires_at > NOW()')->fetchColumn();
        row('前台在线设备', (string) $userSessions, 'ok');
        row('待确认二维码', (string) $qrPending, $qrPending ? 'ok' : '');
    } catch (Throwable $e) {
        row('前台会话表', '尚未建立，升级后访问一次前台即可自动补表', 'warn');
    }
    $adminLocks = $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'settings` WHERE skey LIKE "sess_a_%"')->fetchColumn();
    row('管理员登录锁', (string) (int) $adminLocks . ' 条', 'ok');
}
?>
  <h2>可用入口</h2>
<?php
row('前台首页', '<a href="' . htmlspecialchars($base) . '/index.php">' . htmlspecialchars($base) . '/index.php</a>');
row('后台登录', '<a href="' . htmlspecialchars($base) . '/admin/login.php">' . htmlspecialchars($base) . '/admin/login.php</a>');
row('自检页', '<a href="' . htmlspecialchars($base) . '/check.php">' . htmlspecialchars($base) . '/check.php</a>');
?>
<?php if (!is_file($lockFile) && is_file($configFile)): ?>
  <form method="post"><input type="hidden" name="action" value="write_lock">
    <button type="submit">尝试补写 config/install.lock</button>
  </form>
<?php endif; ?>
  <p class="tip">如果“config/config.php”显示不存在，而安装页却说安装完成，说明安装时写入文件失败（通常是目录权限），需要把 config、uploads、install 目录都不带权限限制地重新解压一次，再跑安装。<br>
  如果这里一切正常但首页仍会跳回安装页，请把本页截图发我，上面几个路径值就是关键。</p>
</div>
</body>
</html>
