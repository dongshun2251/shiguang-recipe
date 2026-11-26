<?php
/**
 * 食光菜谱 · 安装向导（4 步）
 * 1 环境检测 → 2 数据库配置 → 3 管理员账号 → 4 完成
 */
require_once __DIR__ . '/../inc/bootstrap.php';

$configFile = SG_ROOT . '/config/config.php';
$lockFile   = SG_ROOT . '/config/install.lock';
$installed  = is_file($configFile) && is_file($lockFile);

if (isset($_GET['restart'])) {
    unset($_SESSION['sg_install']);
    redirect(SG_WEB_BASE . '/install/index.php');
}
$install = $_SESSION['sg_install'] ?? ['step' => 1, 'db' => []];
$step    = (int) ($install['step'] ?? 1);
$errors  = [];
$notice  = '';

/* 配置丢失自愈：会话里还留着第 2 步填的库信息时，自动把 config.php 补回来，
   避免覆盖上传新版代码（包里不带 config）后卡在「创建管理员失败」 */
if (!is_file($configFile) && !empty($install['db']['name']) && !empty($install['db']['user'])) {
    try {
        inst_write_config($configFile, $install['db']);
        $notice = '检测到 config/config.php 丢失，已用第 2 步保存的数据库信息自动恢复，数据未受影响。';
    } catch (Throwable $ex) {
        $errors[] = '配置文件丢失，自动恢复失败：' . $ex->getMessage();
    }
}

$installed = is_file($configFile) && is_file($lockFile);

function inst_shell_open(string $title, int $step): void
{
    ?>
<!DOCTYPE html>
<html lang="zh-CN" translate="no" class="<?= sg_is_mobile_ua() ? 'ua-mobile' : 'desk' ?>">
<head>
<meta charset="UTF-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · 食光菜谱 安装程序</title>
<link rel="stylesheet" href="../assets/base.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="../assets/app-extra.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="../assets/ua-mobile.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="../assets/desk.css?v=<?= SG_VERSION ?>">
<link rel="stylesheet" href="../assets/refresh.css?v=<?= SG_VERSION ?>">
<style>
.install-cols { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 16px; align-items: start; }
@media (max-width: 1000px) { .install-cols { grid-template-columns: 1fr; } }
</style>
</head>
<body class="<?= sg_is_mobile_ua() ? 'ua-mobile' : '' ?>" data-page="install" data-pagename="安装向导">
<div class="window">
  <div class="titlebar">
    <div class="tb-brand"><span class="logo">食</span><span class="tb-name">食光菜谱 · 安装向导<em>SHIGUANG · INSTALLER</em></span></div>
    <div class="tb-tools">
      <button class="tbtn icon-only" data-toast="安装向导 · 共 4 步"><span class="ico"><?= sg_icon('settings') ?></span></button>
    </div>
  </div>
  <div class="appbody">
    <main class="main">
      <div class="install-wrap">
        <div class="install-mobile-head">
          <span class="logo" style="width:38px;height:38px;font-size:20px;border-radius:10px">食</span>
          <div class="im-copy"><b>食光菜谱 · 安装向导</b><span>第 <?= $step ?> 步 / 共 4 步 · <?= e(['环境检测', '数据库配置', '管理员账号', '完成'][$step - 1] ?? '') ?></span></div>
        </div>
        <div class="install-mobile-progress" aria-label="安装进度">
          <div class="im-bar"><i style="width:<?= (int) round($step / 4 * 100) ?>%"></i></div>
          <div class="im-labels">
            <?php $labels = ['环境检测', '数据库配置', '管理员账号', '完成']; foreach ($labels as $i => $lb): ?>
            <span class="<?= $step > $i + 1 ? 'done' : ($step === $i + 1 ? 'on' : '') ?>"><?= e($lb) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="install-hero">
          <span class="logo" style="width:44px;height:44px;font-size:24px;margin:0 auto 12px;border-radius:11px">食</span>
          <h1 style="margin:0;font-family:var(--font-serif);font-size:26px">食光菜谱 · 安装程序</h1>
          <p style="margin:6px 0 0;font-size:12.5px;color:var(--muted)">4 个步骤完成部署，安装目录会保留作重装入口</p>
        </div>
        <div class="stepper">
          <?php
          foreach ($labels as $i => $lb):
              $n = $i + 1;
              $cls = $step > $n ? 'done' : ($step === $n ? 'act' : '');
              ?>
            <div class="st"><span class="b <?= $cls ?>"><?php if ($step > $n): ?><span class="ico" data-ico="check"></span><?php else: ?><?= $n ?><?php endif; ?></span><span class="tx"><b><?= $lb ?></b><span><?= $step > $n ? '已完成' : ($step === $n ? '进行中' : '待进行') ?></span></span></div>
            <?php if ($n < 4): ?><span class="line"></span><?php endif; ?>
          <?php endforeach; ?>
        </div>
<?php
}

function inst_shell_close(int $step): void
{
    ?>
        <div class="install-foot" style="display:flex;align-items:center;gap:10px;margin-top:20px;font-size:11.5px;color:var(--muted)">
          <span>当前步骤 <?= $step ?> / 4</span>
          <span style="margin-left:auto">环境：PHP <?= e(PHP_VERSION) ?> · <?= e(PHP_OS_FAMILY) ?></span>
        </div>
      </div>
    </main>
  </div>
  <div class="statusbar desk-only">
    <span class="dot"></span><span>安装程序运行中</span><span>第 <?= $step ?> 步</span><span><?= is_file(SG_ROOT . '/config/install.lock') ? '已写入安装锁' : '未写入安装锁' ?></span>
    <span class="sb-right"><span>PHP <?= e(PHP_VERSION) ?></span><span>v<?= SG_VERSION ?></span></span>
  </div>
</div>
<script src="../assets/app.js?v=<?= SG_VERSION ?>"></script>
</body>
</html>
<?php
}

/* ---------------------------------------------------------
 * 已安装站点的重装安全门
 * 安装目录不再自动删除；进入安装程序前必须验证当前管理员账号密码。
 * 连续失败 5 次冻结 5 分钟。
 * --------------------------------------------------------- */
function inst_guard_file(): string
{
    return SG_ROOT . '/config/install-guard.json';
}

function inst_guard_read(): array
{
    $f = inst_guard_file();
    if (!is_file($f)) {
        return ['attempts' => 0, 'locked_until' => 0];
    }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) {
        return ['attempts' => 0, 'locked_until' => 0];
    }
    $attempts = max(0, (int) ($d['attempts'] ?? 0));
    $locked   = max(0, (int) ($d['locked_until'] ?? 0));
    if ($locked > 0 && $locked <= time()) {
        @unlink($f);
        return ['attempts' => 0, 'locked_until' => 0];
    }
    return ['attempts' => $attempts, 'locked_until' => $locked];
}

function inst_guard_save(array $d): void
{
    $f = inst_guard_file();
    @file_put_contents($f, json_encode([
        'attempts'     => max(0, (int) ($d['attempts'] ?? 0)),
        'locked_until' => max(0, (int) ($d['locked_until'] ?? 0)),
        'updated_at'   => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0600);
}

function inst_gate_ok(): bool
{
    return !empty($_SESSION['sg_install_auth'])
        && (time() - (int) $_SESSION['sg_install_auth']) < 1800;
}

function inst_gate_clear(): void
{
    unset($_SESSION['sg_install_auth']);
    @unlink(inst_guard_file());
}

function inst_gate_fail(): array
{
    /* 串行化读改写，避免并发请求绕过 5 次上限 */
    $lockFile = SG_ROOT . '/config/install-guard.lock';
    $fh = @fopen($lockFile, 'c');
    if ($fh) {
        @flock($fh, LOCK_EX);
    }
    $g = inst_guard_read();
    $g['attempts'] = (int) $g['attempts'] + 1;
    if ($g['attempts'] >= 5) {
        $g['locked_until'] = time() + 300;
    }
    inst_guard_save($g);
    if ($fh) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
    return $g;
}

function inst_gate_page(array $errors, int $remaining, int $lockedSeconds): void
{
    inst_shell_open('安全验证', 4);
    ?>
        <div class="card">
          <div class="card-head"><h3>已安装 · 重装安全验证</h3><span class="hint">最多 5 次 · 失败冻结 5 分钟</span></div>
          <p style="margin:0 0 14px;font-size:13px;color:var(--muted);line-height:1.8">
            系统已经安装完成。为确认是站长本人，进入安装程序前必须验证<b>当前管理员账号和密码</b>。
            验证只用于重装入口，不会改动任何数据。
          </p>
<?php if ($lockedSeconds > 0): ?>
          <div class="alert err" style="margin-bottom:14px"><span class="ico" data-ico="lock"></span><span>已连续 5 次验证失败，冻结中。请 <b><?= (int) ceil($lockedSeconds / 60) ?> 分钟</b>后再试（约 <?= (int) $lockedSeconds ?> 秒）。</span></div>
<?php endif; ?>
<?php foreach ($errors as $er): ?>
          <div class="alert err" style="margin-bottom:12px"><span class="ico" data-ico="alert"></span><span><?= e($er) ?></span></div>
<?php endforeach; ?>
<?php if ($lockedSeconds <= 0): ?>
          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="gate">
            <div class="form-grid" style="margin-bottom:12px">
              <div class="field"><label>管理员账号 <span class="req">*</span></label><input class="input" name="gate_user" value="<?= e(post('gate_user')) ?>" required autofocus></div>
              <div class="field"><label>管理员密码 <span class="req">*</span></label><input class="input" type="password" name="gate_pass" required></div>
            </div>
            <div class="field" style="margin-bottom:14px"><label>数据库密码（只有近期改过才填）</label><input class="input" type="password" name="gate_db_pass" placeholder="留空则使用 config/config.php 里的密码"><span class="tip">数据库密码改过、站点报 Access denied 时，在这里填新密码，安装程序用它验证管理员。</span></div>
            <button class="btn primary block" type="submit"><span class="ico" data-ico="shield"></span>验证并进入安装程序</button>
            <p class="tip" style="margin-top:10px">本次还可尝试 <b><?= max(0, $remaining) ?></b> 次。验证通过后 30 分钟内有效。</p>
          </form>
<?php endif; ?>
        </div>
    <?php
    inst_shell_close(4);
    exit;
}

/* 写配置文件：安装第 2 步和「配置丢失自愈」共用 */
function inst_write_config(string $file, array $db): void
{
    $cfg = [
        'db' => [
            'host'    => $db['host'],
            'port'    => (int) $db['port'],
            'name'    => $db['name'],
            'user'    => $db['user'],
            'pass'    => (string) ($db['pass'] ?? ''),
            'prefix'  => $db['prefix'],
            'charset' => 'utf8mb4',
        ],
        'app' => ['timezone' => 'Asia/Shanghai', 'debug' => false],
    ];
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建 config 目录，请检查站点目录权限');
    }
    $php = "<?php\n// 食光菜谱配置文件（安装程序自动生成 " . date('Y-m-d H:i:s') . "）\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents($file, $php) === false) {
        throw new RuntimeException('无法写入 config/config.php，请检查 config 目录权限');
    }
    @chmod($file, 0644);
    /* 立刻回读验证：写入成功但读不出来同样视为失败 */
    $back = @include $file;
    if (!is_array($back) || empty($back['db']['name'])) {
        throw new RuntimeException('config/config.php 写入后无法读取，请检查目录权限或手机存储限制');
    }
}

/* 安装页的 current_admin 安全检查：
   安装器里 bootstrap 可能还没连上库，直接调用会抛出“数据库尚未连接”。
   这里尝试用 config.php 再连一次；失败就按“未登录”处理，不再中断整页。 */
function inst_current_admin(): ?array
{
    if (empty($_SESSION['aid'])) {
        return null;
    }
    try {
        if (empty($GLOBALS['SG_PDO']) && is_file(SG_ROOT . '/config/config.php')) {
            $cfg = require SG_ROOT . '/config/config.php';
            if (!empty($cfg['db']['name'])) {
                db_connect($cfg['db']);
            }
        }
        return current_admin();
    } catch (Throwable $ex) {
        return null;
    }
}

/* ---------------------------------------------------------
 * 动作处理
 * --------------------------------------------------------- */
$action = post('action');

/* 已安装站点：进入安装程序前强制验证当前管理员账号密码（最多 5 次，失败冻结 5 分钟） */
if ($installed && !inst_gate_ok()) {
    $guard = inst_guard_read();
    if ((int) $guard['locked_until'] > time()) {
        inst_gate_page([], 0, (int) $guard['locked_until'] - time());
    }
    if ($action !== 'gate') {
        inst_gate_page([], 5 - (int) $guard['attempts'], 0);
    }
    if (!hash_equals(csrf_token(), (string) post('_token'))) {
        inst_gate_page(['页面已过期，请刷新后重试。'], 5 - (int) $guard['attempts'], 0);
    }
    $gateUser = trim((string) post('gate_user'));
    $gatePass = (string) post('gate_pass');
    $gateDbPass = (string) post('gate_db_pass');
    $errors = [];
    if ($gateUser === '' || $gatePass === '') {
        $errors[] = '请填写管理员账号和密码';
    } else {
        try {
            $cfg = is_file($configFile) ? (@include $configFile) : [];
            $db  = is_array($cfg) ? ($cfg['db'] ?? []) : [];
            if (empty($db['name']) || empty($db['user'])) {
                throw new RuntimeException('配置文件不可读，请检查 config/config.php');
            }
            $passUse = $gateDbPass !== '' ? $gateDbPass : (string) ($db['pass'] ?? '');
            $pdo = new PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                $db['user'], $passUse,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $prefix = (string) ($db['prefix'] ?? 'sg_');
            $st = $pdo->prepare("SELECT * FROM `{$prefix}admins` WHERE username = ? AND status = 1 LIMIT 1");
            $st->execute([$gateUser]);
            $admin = $st->fetch(PDO::FETCH_ASSOC);
            if (!$admin || !password_verify($gatePass, (string) ($admin['password_hash'] ?? ''))) {
                $errors[] = '管理员账号或密码不正确';
            }
        } catch (Throwable $ex) {
            $errors[] = '数据库连接失败：' . $ex->getMessage() . '（如果数据库密码改过，请在下方填写新密码）';
        }
    }
    if (!$errors) {
        @unlink(inst_guard_file());
        $_SESSION['sg_install_auth'] = time();
        if ($gateDbPass !== '') {
            $_SESSION['sg_install_gate_db_pass'] = $gateDbPass;
        }
        redirect(SG_WEB_BASE . '/install/index.php');
    }
    $g = inst_gate_fail();
    $locked = ((int) $g['locked_until'] > time()) ? ((int) $g['locked_until'] - time()) : 0;
    inst_gate_page($errors, 5 - (int) $g['attempts'], $locked);
}

/* 重新安装：需先通过上面的管理员验证，确认后解锁并回到第 2 步 */
if ($action === 'reinstall') {
    if (!hash_equals(csrf_token(), (string) post('_token'))) {
        $errors[] = '页面已过期，请刷新后重试。';
    } elseif (!inst_current_admin() && !inst_gate_ok()) {
        $errors[] = '重新安装需要管理员权限，请先完成管理员账号密码验证。';
    } else {
        @unlink($lockFile);
        $cfg = is_file($configFile) ? (require $configFile) : [];
        $_SESSION['sg_install'] = [
            'step'      => 2,
            'reinstall' => true,
            'db'        => [
                'host'   => $cfg['db']['host'] ?? '127.0.0.1',
                'port'   => $cfg['db']['port'] ?? 3306,
                'name'   => $cfg['db']['name'] ?? '',
                'user'   => $cfg['db']['user'] ?? 'root',
                'pass'   => $_SESSION['sg_install_gate_db_pass'] ?? ($cfg['db']['pass'] ?? ''),
                'prefix' => $cfg['db']['prefix'] ?? 'sg_',
            ],
        ];
        redirect(SG_WEB_BASE . '/install/index.php');
    }
}

if ($installed && $step < 3 && $action !== 'reinstall') {
    $step  = 4;
    $notice = '系统已安装完成。需要重装时无需手动删库：先登录后台，再在本页点击「重新安装」，安装程序会清空同前缀数据表并重建。';
}

if ($action === 'testdb') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $host = post('db_host', '127.0.0.1');
        $port = post_int('db_port', 3306);
        $name = post('db_name');
        $user = post('db_user');
        $pass = post('db_pass');
        $pdo  = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        $has = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $has->execute([$name]);
        echo json_encode(['ok' => true, 'msg' => '连接成功：MySQL ' . $ver . ($has->fetch() ? '，数据库已存在' : '，数据库将自动创建')], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'msg' => '连接失败：' . $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'env') {
    $install['step'] = 2;
    $_SESSION['sg_install'] = $install;
    redirect(SG_WEB_BASE . '/install/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'db') {
    $host   = trim(post('db_host', '127.0.0.1'));
    $port   = post_int('db_port', 3306);
    $name   = trim(post('db_name', 'shiguang'));
    $user   = trim(post('db_user', 'root'));
    $pass   = (string) post('db_pass');
    $prefix = preg_replace('/[^a-z0-9_]/i', '', post('db_prefix', 'sg_'));
    if ($prefix === '') {
        $prefix = 'sg_';
    }
    if ($prefix !== '' && substr($prefix, -1) !== '_') {
        $prefix .= '_';
    }

    if ($name === '' || $user === '') {
        $errors[] = '数据库名称与用户名不能为空';
    } else {
        try {
            $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$name}`");

            /* 重装：检测同前缀旧表；勾选清空则删除，未勾选则阻止覆盖 */
            $qt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?');
            $qt->execute([$name, $prefix . '%']);
            $oldTables = $qt->fetchAll(PDO::FETCH_COLUMN);
            if ($oldTables) {
                if (post('wipe') === '1') {
                    foreach ($oldTables as $t) {
                        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $t) . '`');
                    }
                } else {
                    throw new RuntimeException('数据库 ' . $name . ' 中已存在 ' . count($oldTables) . ' 张以 ' . $prefix . ' 开头的表；如果是重装，请勾选「清空已有数据表」，否则请更换表前缀或数据库名。');
                }
            }

            $sqlFile = SG_ROOT . '/sql/schema.sql';
            $sql     = file_get_contents($sqlFile);
            if ($sql === false) {
                throw new RuntimeException('无法读取 sql/schema.sql');
            }
            $sql = str_replace('`sg_', '`' . $prefix, $sql);
            foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
                $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
                if ($stmt === '') {
                    continue;
                }
                $pdo->exec($stmt);
            }

            /* 写入配置文件（内部会回读验证） */
            inst_write_config($configFile, ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'prefix' => $prefix]);


            $install['db']   = ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'prefix' => $prefix];
            $install['step'] = 3;
            $_SESSION['sg_install'] = $install;
            redirect(SG_WEB_BASE . '/install/index.php');
        } catch (Throwable $ex) {
            $errors[] = '数据库处理失败：' . $ex->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'admin') {
    $adminUser = trim(post('admin_user', 'admin'));
    $adminPass = (string) post('admin_pass');
    $adminPass2 = (string) post('admin_pass2');
    $siteName  = trim(post('site_name', '食光菜谱'));
    $siteSub   = trim(post('site_sub', '家常菜谱库'));

    if (mb_strlen($adminUser) < 3) {
        $errors[] = '管理员账号至少 3 个字符';
    }
    if (strlen($adminPass) < 6) {
        $errors[] = '管理员密码至少 6 位';
    }
    if ($adminPass !== $adminPass2) {
        $errors[] = '两次输入的密码不一致';
    }

    if (!$errors) {
        try {
            $cfg = is_file($configFile) ? (@include $configFile) : null;
            if (!is_array($cfg) || empty($cfg['db']['name'])) {
                $sdb = $install['db'] ?? [];
                if (empty($sdb['name']) || empty($sdb['user'])) {
                    throw new RuntimeException('配置文件 config/config.php 丢失，且当前会话没有保存数据库信息。请返回第 2 步重新填写一次数据库配置，数据不会被清空。');
                }
                inst_write_config($configFile, $sdb);
                $cfg = @include $configFile;
                if (!is_array($cfg)) {
                    throw new RuntimeException('config/config.php 恢复失败，请检查 config 目录权限');
                }
            }
            $pdo = new PDO(
                "mysql:host={$cfg['db']['host']};port={$cfg['db']['port']};dbname={$cfg['db']['name']};charset=utf8mb4",
                $cfg['db']['user'],
                $cfg['db']['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pfx = $cfg['db']['prefix'];

            $st = $pdo->prepare("INSERT INTO `{$pfx}admins` (username, password_hash, display_name, role, status, created_at) VALUES (?, ?, ?, 'super', 1, NOW())");
            $st->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminUser]);

            $settings = [
                'site_name'      => $siteName !== '' ? $siteName : '食光菜谱',
                'site_sub'       => $siteSub !== '' ? $siteSub : '家常菜谱库',
                'site_desc'      => '把一顿普通的晚饭，做成值得记住的味道。',
                'site_icp'       => '',
                'site_timezone'  => 'Asia/Shanghai',
                'per_page'       => '12',
                'comment_review' => '1',
                'theme_primary'  => '#C8502B',
                'install_time'   => date('Y-m-d H:i:s'),
            ];
            $ins = $pdo->prepare("INSERT INTO `{$pfx}settings` (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
            foreach ($settings as $k => $v) {
                $ins->execute([$k, $v]);
            }

            /*
             * 内置欢迎音频：源码包自带两条默认 MP3，全新安装时复制到 uploads/audio
             * 并写入音频位；旧音频文件已丢失（整目录重装）时也会自动修复成默认音频。
             */
            $audioDir = SG_ROOT . '/uploads/audio';
            if (!is_dir($audioDir)) {
                @mkdir($audioDir, 0775, true);
            }
            $audioDefaults = [
                ['assets/audio/welcome-guest.mp3',  '欢迎您加入食光菜谱-登录前.mp3', 'audio_file',       'audio_enabled'],
                ['assets/audio/welcome-member.mp3', '欢迎您加入食光菜谱-登录后.mp3', 'audio_file_after', 'audio_after_enabled'],
            ];
            $getSetting = $pdo->prepare("SELECT svalue FROM `{$pfx}settings` WHERE skey = ?");
            $setSetting = $pdo->prepare("INSERT INTO `{$pfx}settings` (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
            foreach ($audioDefaults as $ad) {
                [$srcRel, $dstName, $fileKey, $enabledKey] = $ad;
                $getSetting->execute([$fileKey]);
                $cur = $getSetting->fetchColumn();
                $curPath = ($cur === false) ? '' : (string) $cur;
                $isBuiltin = $curPath !== '' && basename($curPath) === $dstName;
                /* 用户自己上传且仍有效的音频不覆盖；只把内置默认音同步成新版 */
                if ($curPath !== '' && !$isBuiltin && is_file(SG_ROOT . '/' . $curPath)) {
                    continue;
                }
                $src = SG_ROOT . '/' . $srcRel;
                if (!is_file($src)) {
                    continue;
                }
                $dst = $audioDir . '/' . $dstName;
                if (!is_file($dst) || md5_file($dst) !== md5_file($src)) {
                    @copy($src, $dst);
                }
                if (!is_file($dst)) {
                    continue;
                }
                $setSetting->execute([$fileKey, 'uploads/audio/' . $dstName]);
                $getSetting->execute([$enabledKey]);
                if ($getSetting->fetchColumn() === false) {
                    $setSetting->execute([$enabledKey, '1']);
                }
            }

            if (file_put_contents($lockFile, "installed at " . date('Y-m-d H:i:s') . "\n") === false) {
                throw new RuntimeException('无法写入 config/install.lock，请检查 config 目录权限');
            }
            @chmod($lockFile, 0644);

            $install['step'] = 4;
            $install['admin'] = $adminUser;
            $_SESSION['sg_install'] = $install;
            redirect(SG_WEB_BASE . '/install/index.php');
        } catch (Throwable $ex) {
            $errors[] = '创建管理员失败：' . $ex->getMessage();
        }
    }
}

/* 环境检测 */
function inst_env_checks(): array
{
    $root = SG_ROOT;
    $items = [
        ['PHP 版本', '需要 ≥ 7.4 · 当前 ' . PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='), true],
        ['PDO 与 pdo_mysql', '数据库连接与建库建表', extension_loaded('pdo_mysql'), true],
        ['GD 图像扩展', '封面图裁切与压缩', extension_loaded('gd'), false],
        ['mbstring 扩展', '中文截断与校验', extension_loaded('mbstring'), true],
        ['config 目录可写', '/config 需要写入配置文件', is_writable($root . '/config') || is_writable($root), true],
        ['uploads 目录可写', '/uploads 存放菜谱图片', is_writable($root . '/uploads') || is_writable($root . '/uploads/recipes'), true],
        ['建表脚本', '/sql/schema.sql 共 24 张表', is_file($root . '/sql/schema.sql'), true],
        ['PHP 上传限制', '当前 ' . ini_get('upload_max_filesize') . '（建议 ≥ 8M）', true, false],
    ];
    return $items;
}

$envCheck = inst_env_checks();

inst_shell_open('安装向导', $step);
?>

<?php if ($notice): ?>
        <div class="alert ok" style="margin-bottom:16px"><span class="ico" data-ico="check"></span><span><?= e($notice) ?></span></div>
<?php endif; ?>
<?php foreach ($errors as $er): ?>
        <div class="alert err" style="margin-bottom:12px"><span class="ico" data-ico="alert"></span><span><?= e($er) ?></span></div>
<?php endforeach; ?>

<?php if ($step === 1): ?>
        <div class="install-cols">
          <div class="card">
            <div class="card-head"><h3>环境检测</h3>
              <?php $allOk = !in_array(false, array_column($envCheck, 2), true); ?>
              <span class="badge <?= $allOk ? 'live' : 'draft' ?>" style="margin-left:auto"><?= $allOk ? '全部通过' : '存在未通过项' ?></span>
            </div>
            <table class="tb install-env-table" style="font-size:12px;min-width:0">
              <?php foreach ($envCheck as $it): ?>
              <tr>
                <td style="width:130px"><b style="font-size:12px"><?= e($it[0]) ?></b></td>
                <td style="color:var(--muted);font-size:11.5px"><?= e($it[1]) ?></td>
                <td style="width:70px;text-align:right">
                  <?php if ($it[2]): ?><span class="badge live">通过</span>
                  <?php elseif ($it[3]): ?><span class="badge hot">未通过</span>
                  <?php else: ?><span class="badge draft">建议</span><?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
          </div>

          <div class="card" style="background:#FBF6EE">
            <div class="card-head"><h3>安装前请注意</h3></div>
            <div class="tips">
              <div class="tip"><span class="ico" data-ico="info"></span><span>安装程序会自动创建数据库（若不存在）并导入 24 张表，同时写入 <b>config/config.php</b>。</span></div>
              <div class="tip warn"><span class="ico" data-ico="alert"></span><span>请确保 config、uploads 目录可写；安装完成后 install 目录会保留，重装入口需要管理员账号密码验证。</span></div>
              <div class="tip"><span class="ico" data-ico="shield"></span><span>默认后台入口为 <b>/admin/login.php</b>，安装完成后请立即登录并修改管理员密码。</span></div>
            </div>
            <form method="post" style="margin-top:16px">
              <input type="hidden" name="action" value="env">
              <button class="btn primary block" type="submit"><?php if ($allOk): ?>环境通过，下一步 &gt;<?php else: ?>继续（未通过项可能影响安装）<?php endif; ?></button>
            </form>
          </div>
        </div>

<?php elseif ($step === 2): ?>
        <?php $dbPre = $install['db'] ?? []; $isReinstall = !empty($install['reinstall']); ?>
        <div class="card">
          <div class="card-head"><h3><?= $isReinstall ? '重新安装 · 数据库配置' : '数据库配置' ?></h3><span class="hint">将写入 config/config.php</span></div>
          <?php if ($isReinstall): ?>
          <div class="alert err" style="margin-bottom:14px"><span class="ico" data-ico="alert"></span><span>当前为重新安装：继续将清空同前缀的全部数据表并重建，原有菜谱、用户、评论会丢失。</span></div>
          <?php endif; ?>
          <form method="post" id="dbForm">
            <input type="hidden" name="action" value="db">
            <div class="form-grid" style="grid-template-columns:1fr 110px;margin-bottom:12px">
              <div class="field"><label>主机地址 <span class="req">*</span></label><input class="input" name="db_host" value="<?= e(post('db_host', $dbPre['host'] ?? '127.0.0.1')) ?>" required></div>
              <div class="field"><label>端口</label><input class="input" name="db_port" value="<?= e(post('db_port', (string) ($dbPre['port'] ?? '3306'))) ?>"></div>
            </div>
            <div class="form-grid" style="margin-bottom:12px">
              <div class="field"><label>数据库名称 <span class="req">*</span></label><input class="input" name="db_name" value="<?= e(post('db_name', $dbPre['name'] ?? 'shiguang')) ?>" required></div>
              <div class="field"><label>表前缀</label><input class="input" name="db_prefix" value="<?= e(post('db_prefix', $dbPre['prefix'] ?? 'sg_')) ?>"><span class="tip">建议保留 sg_，避免与其他程序冲突</span></div>
            </div>
            <div class="form-grid" style="margin-bottom:14px">
              <div class="field"><label>用户名 <span class="req">*</span></label><input class="input" name="db_user" value="<?= e(post('db_user', $dbPre['user'] ?? 'root')) ?>" required></div>
              <div class="field"><label>密码</label><input class="input" name="db_pass" type="password" value="<?= e(post('db_pass', $dbPre['pass'] ?? '')) ?>"></div>
            </div>
            <label class="ckrow" style="margin-bottom:10px">
              <input type="checkbox" name="wipe" value="1"<?= $isReinstall ? ' checked' : '' ?>>
              <span>清空该前缀下已存在的全部数据表后重建（<b>重装必须勾选</b>，会删除原有数据）</span>
            </label>
            <div class="notice-bar" style="margin-bottom:14px">
              <span class="ico" data-ico="check"></span>
              <span>安装后为空白站点：0 菜谱、0 分类、0 用户、0 评论，仅创建你自己的管理员账号。</span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn" type="button" data-testdb style="flex:1"><span class="ico" data-ico="database"></span>测试连接</button>
              <button class="btn primary" type="submit" style="flex:1"><span class="ico" data-ico="check"></span>保存并导入数据表</button>
            </div>
            <p style="margin:12px 0 0;font-size:11.5px;color:var(--muted)">测试连接只验证账号密码，不会创建数据库；点击「保存并导入」才会建库建表。</p>
          </form>
        </div>

<?php elseif ($step === 3): ?>
        <div class="install-cols">
          <div class="card">
            <div class="card-head"><h3>管理员账号</h3><span class="hint">用于登录后台</span></div>
            <form method="post">
              <input type="hidden" name="action" value="admin">
              <div class="field" style="margin-bottom:12px"><label>管理员账号 <span class="req">*</span></label><input class="input" name="admin_user" value="<?= e(post('admin_user', 'admin')) ?>" required><span class="tip">至少 3 个字符，建议不要使用 admin</span></div>
              <div class="field" style="margin-bottom:12px"><label>登录密码 <span class="req">*</span></label><input class="input" name="admin_pass" type="password" required><span class="tip">至少 6 位，建议字母 + 数字组合</span></div>
              <div class="field" style="margin-bottom:16px"><label>确认密码 <span class="req">*</span></label><input class="input" name="admin_pass2" type="password" required></div>

              <div class="section-head" style="padding:4px 0 10px"><h2 style="font-size:13px">站点信息</h2></div>
              <div class="field" style="margin-bottom:12px"><label>站点名称</label><input class="input" name="site_name" value="<?= e(post('site_name', '食光菜谱')) ?>"></div>
              <div class="field" style="margin-bottom:16px"><label>副标题</label><input class="input" name="site_sub" value="<?= e(post('site_sub', '家常菜谱库')) ?>"></div>
              <button class="btn primary block" type="submit"><span class="ico" data-ico="check"></span>完成安装</button>
            </form>
          </div>
          <div class="card" style="background:#FBF6EE">
            <div class="card-head"><h3>即将完成</h3></div>
            <div class="meta-box">
              <div class="meta-row"><span class="ico" data-ico="database"></span><span>数据库</span><b><?= e(($install['db']['name'] ?? '') . ' · ' . ($install['db']['prefix'] ?? 'sg_')) ?></b></div>
              <div class="meta-row"><span class="ico" data-ico="check"></span><span>数据表</span><b style="color:var(--green)">已导入 24 张表</b></div>
              <div class="meta-row"><span class="ico" data-ico="folder"></span><span>配置文件</span><b>config/config.php</b></div>
            </div>
            <div class="tips" style="margin-top:14px">
              <div class="tip"><span class="ico" data-ico="shield"></span><span>安装完成后会生成 <b>config/install.lock</b>，防止重复安装。</span></div>
              <div class="tip warn"><span class="ico" data-ico="alert"></span><span>install 目录会保留作重装入口，进入前必须验证管理员账号密码；不打算再用时可手动删除。</span></div>
            </div>
          </div>
        </div>

<?php else: ?>
        <?php
        /* 安装目录不再自动删除：保留 install/ 作为受管理员验证保护的重装入口 */
        $cleanState = 'kept';
        ?>
        <div class="card" style="text-align:center;padding:38px 24px">
          <span class="logo" style="width:56px;height:56px;font-size:30px;margin:0 auto 16px;border-radius:14px">食</span>
          <h2 style="margin:0 0 6px;font-family:var(--font-serif);font-size:22px">安装完成</h2>
          <p style="margin:0 auto 20px;font-size:12.5px;color:var(--muted);max-width:480px">数据库已就绪，管理员账号已创建。<b style="color:var(--green)">install 安装目录已保留</b>，作为重装入口：以后改了数据库密码，也能在这里验证管理员后重新安装。不打算再用时可手动删除 install 目录。</p>
          <?php if (!is_file($configFile) || !is_file($lockFile)): ?>
          <div class="alert err" style="margin:0 auto 18px;max-width:520px;text-align:left">
            <span class="ico" data-ico="alert"></span>
            <span>配置文件写入不完整：config/config.php <?= is_file($configFile) ? '已写入' : '缺失' ?>，config/install.lock <?= is_file($lockFile) ? '已写入' : '缺失' ?>。
            这种情况下前台会一直被重定向回安装页。请检查 config 目录权限后重新安装，或打开 <b>/doctor.php</b> 查看诊断。</span>
            <div style="margin-top:10px"><a class="btn" href="index.php?restart=1"><span class="ico" data-ico="refresh"></span>重新开始安装</a></div>
          </div>
          <?php endif; ?>
          <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <a class="btn primary" href="../index.php"><span class="ico" data-ico="home"></span>打开前台首页</a>
            <a class="btn" href="../admin/login.php"><span class="ico" data-ico="lock"></span>登录管理后台</a>
          </div>
          <div class="meta-box" style="margin-top:24px;text-align:left">
            <div class="meta-row"><span class="ico" data-ico="user"></span><span>管理员</span><b><?= e($install['admin'] ?? '—') ?></b></div>
            <div class="meta-row"><span class="ico" data-ico="database"></span><span>数据库</span><b><?= e(($install['db']['name'] ?? '') ?: '已完成') ?></b></div>
            <div class="meta-row"><span class="ico" data-ico="clock"></span><span>安装时间</span><b><?= date('Y-m-d H:i') ?></b></div>
          </div>
        </div>

<?php if ($installed): ?>
        <div class="card" style="margin-top:16px;text-align:left">
          <div class="card-head"><h3>重新安装</h3><span class="hint">无需手动删数据库</span></div>
          <p style="margin:0 0 12px;font-size:12.5px;color:var(--muted);line-height:1.75">重装会清空该表前缀下的全部数据表，再重新建表并创建新的管理员账号。为避免误操作，进入本页前已要求验证当前管理员账号密码。</p>
          <?php if (inst_current_admin() || inst_gate_ok()): ?>
          <form method="post" onsubmit="return confirm('确定要清空当前数据库中的全部食光菜谱数据表并重装吗？此操作不可恢复。');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reinstall">
            <button class="btn primary" type="submit"><span class="ico" data-ico="refresh"></span>清空数据并重新安装</button>
          </form>
          <?php else: ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn" href="../admin/login.php"><span class="ico" data-ico="lock"></span>先登录后台</a>
            <span class="tip" style="align-self:center">登录后回到本页即可重装</span>
          </div>
          <?php endif; ?>
        </div>
<?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('click', function (e) {
  var t = e.target.closest('[data-testdb]');
  if (!t) return;
  e.preventDefault();
  var f = document.getElementById('dbForm');
  var data = new URLSearchParams(new FormData(f));
  data.set('action', 'testdb');
  fetch('index.php', { method: 'POST', body: data.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
    .then(function (r) { return r.json(); })
    .then(function (j) { window.SG_TOAST ? SG_TOAST(j.msg) : alert(j.msg); })
    .catch(function () { window.SG_TOAST ? SG_TOAST('请求失败，请重试') : alert('请求失败'); });
});
</script>
<?php
inst_shell_close($step);
