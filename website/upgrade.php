<?php
/**
 * 食光菜谱 · 免重装升级 / 重建配置
 *
 * 用途：覆盖上传新版代码后，如果 config/config.php 丢了，不需要重跑安装向导，
 *      在这里填一次数据库信息即可；表和数据都不动。
 *
 * 用法：浏览器打开 /upgrade.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
@set_time_limit(120);

$root       = __DIR__;
$configFile = $root . '/config/config.php';
$lockFile   = $root . '/config/install.lock';

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function cfg_write($file, $db)
{
    $cfg = [
        'db' => [
            'host'    => $db['host'],
            'port'    => (int) $db['port'],
            'name'    => $db['name'],
            'user'    => $db['user'],
            'pass'    => $db['pass'],
            'prefix'  => $db['prefix'],
            'charset' => 'utf8mb4',
        ],
        'app' => [
            'timezone' => 'Asia/Shanghai',
            'debug'    => false,
        ],
    ];
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0755, true);
    }
    $php = "<?php\n// 食光菜谱配置文件（upgrade.php 生成 " . date('Y-m-d H:i:s') . "）\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents($file, $php) === false) {
        throw new RuntimeException('无法写入 config/config.php，请检查 config 目录权限');
    }
    @chmod($file, 0644);
}

function pdo_connect($db)
{
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . (int) $db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}

$existsCfg = is_file($configFile);
$msg = '';
$err = '';
$rows = [];

if ($existsCfg) {
    $cfg = @include $configFile;
    if (is_array($cfg) && empty($needAdmin)) {
        try {
            $pdoG = pdo_connect($cfg['db']);
            $needAdmin = ((int) $pdoG->query("SELECT COUNT(*) FROM `{$cfg['db']['prefix']}admins`")->fetchColumn() === 0);
        } catch (Throwable $e) { /* 连不上就先不提示 */ }
    }
}
if (!isset($needAdmin)) { $needAdmin = false; }

/* -------- 处理提交 -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['action']) ? $_POST['action'] : '';
    $db  = [
        'host'   => trim((string) (isset($_POST['host']) ? $_POST['host'] : '127.0.0.1')),
        'port'   => (int) (isset($_POST['port']) ? $_POST['port'] : 3306),
        'name'   => trim((string) (isset($_POST['name']) ? $_POST['name'] : '')),
        'user'   => trim((string) (isset($_POST['user']) ? $_POST['user'] : '')),
        'pass'   => (string) (isset($_POST['pass']) ? $_POST['pass'] : ''),
        'prefix' => trim((string) (isset($_POST['prefix']) ? $_POST['prefix'] : 'sg_')),
    ];
    try {
        if ($act === 'save-config' || $act === 'upgrade') {
            /* 表单留空时沿用已有配置 */
            if ($db['name'] === '' && $existsCfg) {
                $db = $cfg['db'];
                $db['port'] = isset($db['port']) ? $db['port'] : 3306;
            }
            if ($db['prefix'] === '') { $db['prefix'] = 'sg_'; }

            $pdo = pdo_connect($db);

            $need = $db['prefix'] . 'recipes';
            $has  = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($need))->fetchColumn();
            if (!$has) {
                throw new RuntimeException('这个数据库里没有 ' . $need . ' 表，看起来不是食光菜谱的库。请核对库名 / 表前缀，或去 install/ 完整安装。');
            }

            cfg_write($configFile, $db);
            if (!is_file($lockFile)) {
                @file_put_contents($lockFile, "upgraded at " . date('Y-m-d H:i:s') . "\n");
            }

            if ($act === 'upgrade') {
                /* 只做增量：CREATE TABLE IF NOT EXISTS + 补缺失列，绝不 DROP / TRUNCATE / DELETE */
                $sqlFile = $root . '/sql/schema.sql';
                if (is_file($sqlFile)) {
                    $sql = str_replace('`sg_', '`' . $db['prefix'], file_get_contents($sqlFile));
                    $made = 0;
                    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
                        $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
                        if ($stmt === '' || stripos($stmt, 'CREATE TABLE') === false) { continue; }
                        $pdo->exec($stmt);
                        $made++;
                    }
                    $rows[] = ['建表检查', '已执行 ' . $made . ' 条 CREATE TABLE IF NOT EXISTS（已存在的表不动）', 'ok'];
                }

                /* 兼容旧库：recipes.user_id（投稿归属） */
                $tbR = $db['prefix'] . 'recipes';
                $colR = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $tbR) . "` LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
                if (!$colR) {
                    $pdo->exec("ALTER TABLE `" . str_replace('`', '', $tbR) . "` ADD COLUMN `user_id` INT UNSIGNED NOT NULL DEFAULT 0");
                    $rows[] = ['补列', $tbR . '.user_id 已添加', 'ok'];
                } else {
                    $rows[] = ['补列', $tbR . '.user_id 已存在，跳过', ''];
                }

                /* 兼容旧库：session_token 列 */
                foreach (['admins', 'users'] as $t) {
                    $tb = $db['prefix'] . $t;
                    $col = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $tb) . "` LIKE 'session_token'")->fetch(PDO::FETCH_ASSOC);
                    if (!$col) {
                        $pdo->exec("ALTER TABLE `" . str_replace('`', '', $tb) . "` ADD COLUMN `session_token` VARCHAR(40) NULL");
                        $rows[] = ['补列', $tb . '.session_token 已添加', 'ok'];
                    } else {
                        $rows[] = ['补列', $tb . '.session_token 已存在，跳过', ''];
                    }
                }

                /* 兼容旧库：recipe_steps.images（一步多图） */
                $tbS = $db['prefix'] . 'recipe_steps';
                $colS = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $tbS) . "` LIKE 'images'")->fetch(PDO::FETCH_ASSOC);
                if (!$colS) {
                    $pdo->exec("ALTER TABLE `" . str_replace('`', '', $tbS) . "` ADD COLUMN `images` TEXT NULL AFTER `image`");
                    $rows[] = ['补列', $tbS . '.images 已添加', 'ok'];
                } else {
                    $rows[] = ['补列', $tbS . '.images 已存在，跳过', ''];
                }

                /* 兼容旧库：users.avatar（用户头像） */
                $tbU = $db['prefix'] . 'users';
                $colU = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', $tbU) . "` LIKE 'avatar'")->fetch(PDO::FETCH_ASSOC);
                if (!$colU) {
                    $pdo->exec("ALTER TABLE `" . str_replace('`', '', $tbU) . "` ADD COLUMN `avatar` VARCHAR(255) NOT NULL DEFAULT ''");
                    $rows[] = ['补列', $tbU . '.avatar 已添加', 'ok'];
                } else {
                    $rows[] = ['补列', $tbU . '.avatar 已存在，跳过', ''];
                }

                @file_put_contents($root . '/config/schema.version', '1.6.6');
                $rows[] = ['数据', '原有菜谱 / 用户 / 评论 / 设置全部保留，未执行任何删除语句', 'ok'];
                $cntAdmin = (int) $pdo->query("SELECT COUNT(*) FROM `{$db['prefix']}admins`")->fetchColumn();
                $needAdmin = ($cntAdmin === 0);
                $rows[] = ['后台账号', $needAdmin ? '还没有管理员账号，请在下方创建' : '已有 ' . $cntAdmin . ' 个管理员，跳过', $needAdmin ? 'warn' : 'ok'];
                $msg = '升级完成，数据未改动。请回首页刷新，或进后台确认显示正常。';
            } else {
                $msg = '配置已写入 config/config.php，可以正常访问网站了（数据未改动）。';
            }
            $cfgNow = @include $configFile;
            if (is_array($cfgNow)) {
                $pdoAdm = pdo_connect($cfgNow['db']);
                $cntAdm = (int) $pdoAdm->query("SELECT COUNT(*) FROM `{$cfgNow['db']['prefix']}admins`")->fetchColumn();
                $needAdmin = ($cntAdm === 0);
            }
        } elseif ($act === 'create-admin') {
            $cfgFile = is_file($configFile) ? (@include $configFile) : null;
            if (!is_array($cfgFile)) { throw new RuntimeException('config/config.php 还没写好，请先点「写配置 + 补表结构」'); }
            $pdo = pdo_connect($cfgFile['db']);
            $pfx = $cfgFile['db']['prefix'];
            $u = trim((string) (isset($_POST['admin_user']) ? $_POST['admin_user'] : ''));
            $p1 = (string) (isset($_POST['admin_pass']) ? $_POST['admin_pass'] : '');
            $p2 = (string) (isset($_POST['admin_pass2']) ? $_POST['admin_pass2'] : '');
            if (mb_strlen($u) < 3) { throw new RuntimeException('管理员账号至少 3 个字符'); }
            if (strlen($p1) < 6) { throw new RuntimeException('密码至少 6 位'); }
            if ($p1 !== $p2) { throw new RuntimeException('两次输入的密码不一致'); }
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$pfx}admins`")->fetchColumn();
            if ($cnt > 0) { throw new RuntimeException('后台已经有管理员账号了，不需要再创建'); }
            $st = $pdo->prepare("INSERT INTO `{$pfx}admins` (username, password_hash, display_name, role, status, created_at) VALUES (?, ?, ?, 'super', 1, NOW())");
            $st->execute([$u, password_hash($p1, PASSWORD_DEFAULT), $u]);
            if (!is_file($lockFile)) { @file_put_contents($lockFile, "installed at " . date('Y-m-d H:i:s') . "\n"); }
            $rows[] = ['管理员', '已创建 ' . $u . '，现在可以去后台登录了', 'ok'];
            $msg = '管理员已创建，打开 /admin/login.php 登录。';
        } elseif ($act === 'probe') {
            $pdo = pdo_connect($db);
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $has = false;
            foreach ($tables as $t) { if ($t === $db['prefix'] . 'recipes') { $has = true; break; } }
            $rows[] = ['连接', '成功', 'ok'];
            $rows[] = ['表数量', (string) count($tables), ''];
            $rows[] = ['识别', $has ? '发现 ' . $db['prefix'] . 'recipes，是本项目的库' : '没有找到 ' . $db['prefix'] . 'recipes，请核对前缀', $has ? 'ok' : 'warn'];
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" translate="no">
<head>
<meta charset="utf-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>食光菜谱 · 免重装升级</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; padding: 28px 16px 60px; background: #F6F1E9; color: #2A2119;
         font: 14px/1.7 -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; }
  .wrap { max-width: 620px; margin: 0 auto; }
  h1 { font-size: 20px; margin: 0 0 6px; }
  .sub { color: #8A8175; font-size: 12.5px; margin-bottom: 18px; }
  .card { background: #FFFDFA; border: 1px solid #E8DDCE; border-radius: 14px; padding: 18px; margin-bottom: 14px; }
  label { display: block; font-size: 12px; color: #8A8175; margin: 10px 0 4px; }
  input { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #E3D8C8; border-radius: 9px; background: #fff; font-size: 14px; }
  .grid2 { display: grid; grid-template-columns: 1fr 120px; gap: 10px; }
  button { height: 40px; padding: 0 16px; border-radius: 10px; border: 1px solid #E3D8C8; background: #fff; font-size: 14px; cursor: pointer; }
  button.primary { background: #C8502B; border-color: #C8502B; color: #fff; font-weight: 600; }
  .alert { padding: 11px 13px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
  .alert.err { background: #FDECE8; color: #A33318; border: 1px solid #F3C9BC; }
  .alert.ok { background: #EDF6EC; color: #2F6B35; border: 1px solid #CBE3C8; }
  .row { display: flex; gap: 10px; padding: 8px 0; border-bottom: 1px dashed #EFE6D8; font-size: 13px; }
  .row b { min-width: 92px; color: #8A8175; font-weight: 500; }
  .row.warn b, .row.warn span { color: #A33318; }
  .tip { font-size: 12.5px; color: #8A8175; line-height: 1.75; }
  .actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
</style>
</head>
<body>
<div class="wrap">
  <h1>免重装升级</h1>
  <div class="sub">只写配置 / 补表结构，不动数据库里的任何数据。</div>

<?php if ($msg !== ''): ?><div class="alert ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>

<?php foreach ($rows as $r): ?>
  <div class="row <?= h($r[2]) ?>"><b><?= h($r[0]) ?></b><span><?= h($r[1]) ?></span></div>
<?php endforeach; ?>

  <div class="card">
    <div class="tip">
      当前配置文件：<b><?= $existsCfg ? 'config/config.php 存在' : 'config/config.php 缺失' ?></b><br>
      <?php if ($existsCfg): ?>
        库：<?= h(isset($cfg['db']['name']) ? $cfg['db']['name'] : '?') ?> ·
        前缀：<?= h(isset($cfg['db']['prefix']) ? $cfg['db']['prefix'] : '?') ?> ·
        端口：<?= h(isset($cfg['db']['port']) ? $cfg['db']['port'] : '?') ?>
      <?php else: ?>
        填下面的数据库信息就能恢复访问，不需要跑安装向导。
      <?php endif; ?>
    </div>

    <form method="post">
      <label>数据库地址</label>
      <input name="host" value="<?= h($existsCfg && isset($cfg['db']['host']) ? $cfg['db']['host'] : '127.0.0.1') ?>">
      <label>端口</label>
      <input name="port" value="<?= h($existsCfg && isset($cfg['db']['port']) ? $cfg['db']['port'] : 3306) ?>">
      <label>库名</label>
      <input name="name" value="<?= h($existsCfg && isset($cfg['db']['name']) ? $cfg['db']['name'] : '') ?>" placeholder="例如 shiguang">
      <div class="grid2">
        <div>
          <label>用户名</label>
          <input name="user" value="<?= h($existsCfg && isset($cfg['db']['user']) ? $cfg['db']['user'] : 'root') ?>">
        </div>
        <div>
          <label>表前缀</label>
          <input name="prefix" value="<?= h($existsCfg && isset($cfg['db']['prefix']) ? $cfg['db']['prefix'] : 'sg_') ?>">
        </div>
      </div>
      <label>密码</label>
      <input name="pass" type="password" value="<?= h($existsCfg && isset($cfg['db']['pass']) ? $cfg['db']['pass'] : '') ?>">

      <div class="actions">
        <button type="submit" name="action" value="probe">先测试连接</button>
        <button type="submit" name="action" value="save-config">只写配置（不改表）</button>
        <button type="submit" name="action" value="upgrade" class="primary">写配置 + 补表结构（推荐）</button>
      </div>
    </form>
  </div>

<?php if ($needAdmin): ?>
  <div class="card">
    <h2 style="font-size:16px;margin:0 0 4px">创建后台管理员</h2>
    <div class="tip" style="margin-bottom:8px">数据库表已就绪但没有管理员账号（上次安装停在第 3 步）。在这里补一个即可，不用重装。</div>
    <form method="post">
      <label>管理员账号</label>
      <input name="admin_user" value="admin" autocomplete="off">
      <label>登录密码</label>
      <input name="admin_pass" type="password" autocomplete="new-password">
      <label>确认密码</label>
      <input name="admin_pass2" type="password" autocomplete="new-password">
      <div class="actions"><button type="submit" name="action" value="create-admin" class="primary">创建管理员</button></div>
    </form>
  </div>
<?php endif; ?>

  <div class="card tip">
    <b>以后升级的正确姿势：</b><br>
    1. 覆盖上传新代码时，<b>不要把 config 目录覆盖掉</b>（只传其他文件），这样连这一步都不用跑。<br>
    2. 如果 config 已经丢了：打开本页填库信息 → 点「写配置 + 补表结构」，数据原样保留。<br>
    3. 只有想清空重来时才去 install/ 重装；install 会重建表。<br>
    4. 弄好后建议删掉或改名 upgrade.php，避免别人打开。
  </div>
</div>
</body>
</html>
