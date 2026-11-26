<?php
/**
 * 后台 · 网站诊断（网站医生）
 * 一次检查运行环境、数据库、核心功能、音频、备份/AI/同步，以及关键页面是否能打开。
 * 只读检查，不修改站点设置；不调用外部 AI / 同步接口。
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$checks  = [];
$groups  = [];
$GLOBALS['DX_GROUPS'] = &$groups;

function dx_add(string $group, string $name, string $status, string $detail, string $fix = ''): void
{
    $GLOBALS['DX_GROUPS'][$group][] = [
        'name' => $name,
        'status' => $status,
        'detail' => $detail,
        'fix' => $fix,
    ];
}

function dx_label(string $s): string
{
    return [
        'ok' => '正常', 'warn' => '警告', 'fail' => '失败', 'info' => '信息', 'skip' => '跳过',
    ][$s] ?? '未知';
}

function dx_icon(string $s): string
{
    return $s === 'ok' ? 'check' : ($s === 'fail' ? 'alert' : ($s === 'warn' ? 'alert' : 'info'));
}

function dx_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $n = (int) $v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') return $n * 1024 * 1024 * 1024;
    if ($u === 'm') return $n * 1024 * 1024;
    if ($u === 'k') return $n * 1024;
    return $n;
}

function dx_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$root = SG_ROOT;

/* ---------- 运行环境 ---------- */
$requiredExt = ['pdo_mysql', 'mbstring', 'gd', 'fileinfo', 'json'];
$missingExt  = [];
foreach ($requiredExt as $ext) {
    if (!extension_loaded($ext)) $missingExt[] = $ext;
}
dx_add('运行环境', 'PHP 版本', version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'fail',
    PHP_VERSION . ' · ' . PHP_SAPI . ' · ' . PHP_OS);
dx_add('运行环境', '必需扩展', $missingExt ? 'fail' : 'ok',
    $missingExt ? '缺少：' . implode('、', $missingExt) : 'pdo_mysql / mbstring / gd / fileinfo / json 已加载',
    $missingExt ? '联系主机开启对应 PHP 扩展' : '');
$optExt = [];
foreach (['curl', 'openssl', 'zip'] as $ext) {
    if (!extension_loaded($ext)) $optExt[] = $ext;
}
dx_add('运行环境', '可选扩展', $optExt ? 'warn' : 'ok',
    $optExt ? '缺少：' . implode('、', $optExt) . '（AI / 同步 / 备份可能受影响）' : 'curl / openssl / zip 已加载');

$uploadMax = dx_bytes((string) ini_get('upload_max_filesize'));
$postMax   = dx_bytes((string) ini_get('post_max_size'));
dx_add('运行环境', '上传限制', $uploadMax >= 20 * 1024 * 1024 ? 'ok' : ($uploadMax >= 2 * 1024 * 1024 ? 'warn' : 'fail'),
    'upload_max_filesize=' . ini_get('upload_max_filesize') . ' · post_max_size=' . ini_get('post_max_size'),
    $uploadMax < 20 * 1024 * 1024 ? '音频最大支持 20 MB；建议把两个值都调到 20M 以上' : '');
dx_add('运行环境', '内存限制', dx_bytes((string) ini_get('memory_limit')) === -1 || dx_bytes((string) ini_get('memory_limit')) >= 128 * 1024 * 1024 ? 'ok' : 'warn',
    (string) ini_get('memory_limit'));

$writable = [];
foreach (['config', 'uploads', 'uploads/recipes', 'uploads/audio'] as $dir) {
    if (!is_dir($root . '/' . $dir) || !is_writable($root . '/' . $dir)) $writable[] = $dir;
}
dx_add('运行环境', '目录权限', $writable ? 'fail' : 'ok',
    $writable ? '不可写：' . implode('、', $writable) : 'config / uploads / uploads/recipes / uploads/audio 均可写',
    $writable ? '在主机面板或文件管理器把这些目录设为可写' : '');
dx_add('运行环境', '备份目录', is_dir($root . '/config/backup') && is_writable($root . '/config/backup') ? 'ok' : 'warn',
    is_dir($root . '/config/backup') ? 'config/backup 可写' : 'config/backup 不存在，数据备份不可用',
    !is_dir($root . '/config/backup') ? '新建 config/backup 并设为可写' : '');
dx_add('运行环境', '安装锁', is_file($root . '/config/install.lock') ? 'ok' : 'warn',
    is_file($root . '/config/install.lock') ? 'config/install.lock 存在' : '缺少 install.lock，/install/ 可能仍可进入');
dx_add('运行环境', 'HTTPS', $https ? 'ok' : 'warn', $https ? '当前是 HTTPS' : '当前不是 HTTPS（登录/评论建议开启）');
dx_add('运行环境', '站点地址', 'info', ($https ? 'https' : 'http') . '://' . $host . SG_WEB_BASE . ' · 版本 ' . SG_VERSION);
dx_add('运行环境', 'PHP 时区', 'info', date_default_timezone_get());

$free = @disk_free_space($root);
dx_add('运行环境', '磁盘剩余', $free === false ? 'info' : ($free >= 100 * 1024 * 1024 ? 'ok' : 'warn'),
    $free === false ? '主机未提供磁盘剩余信息' : round($free / 1024 / 1024, 1) . ' MB');

/* ---------- 数据库 ---------- */
$pdo = null;
$dbOk = false;
$dbErr = '';
$counts = [];
$tableMissing = [];
$colMissing = [];
$published = 0;
$categories = 0;
$pendingComments = 0;
$adminCount = 0;

$allTables = ['settings','admins','users','categories','icons','recipes','recipe_ingredients','ingredients','recipe_steps','tags','recipe_tags','comments','comment_likes','favorites','ratings','sync_map','user_totp','recovery_codes','support_tickets','admin_audit','auth_throttle','user_sessions','qr_logins'];
$reqCols = [
    'settings' => ['skey','svalue'],
    'admins' => ['username','password_hash','status'],
    'users' => ['username','password_hash','role','status'],
    'recipes' => ['id','title','cover','status','is_featured','published_at'],
    'recipe_ingredients' => ['recipe_id','name'],
    'recipe_steps' => ['recipe_id','step_no'],
    'comments' => ['recipe_id','content','status'],
    'favorites' => ['user_id','recipe_id'],
    'ratings' => ['recipe_id','user_id','rating'],
];

try {
    $pdo = db();
    $dbOk = true;
    $dbVer = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $dbCharset = (string) $pdo->query('SELECT @@character_set_database')->fetchColumn();
    $dbNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $drift = abs(strtotime($dbNow) - time());
    dx_add('数据库', '连接', 'ok', $dbVer . ' · 字符集 ' . $dbCharset . ' · 库 ' . ($GLOBALS['SG_CFG']['db']['name'] ?? ''));
    dx_add('数据库', '时区偏差', $drift > 300 ? 'warn' : 'ok', 'PHP / MySQL 时间相差 ' . $drift . ' 秒' . ($drift > 300 ? '（检查数据库时区）' : ''));

    foreach ($allTables as $t) {
        $table = T($t);
        try {
            $counts[$t] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        } catch (Throwable $e) {
            $tableMissing[] = $t;
        }
    }
    $tableFound = count($allTables) - count($tableMissing);
    dx_add('数据库', '数据表', $tableMissing ? 'fail' : 'ok',
        $tableFound . '/' . count($allTables) . ' 张存在' . ($tableMissing ? '；缺少 ' . implode('、', $tableMissing) : ''),
        $tableMissing ? '对照 sql/schema.sql 补齐缺失表，或重新运行安装/升级' : '');

    foreach ($reqCols as $t => $cols) {
        if (in_array($t, $tableMissing, true)) continue;
        $have = [];
        try {
            $rows = $pdo->query('SHOW COLUMNS FROM `' . T($t) . '`')->fetchAll(PDO::FETCH_COLUMN, 0);
            $have = array_map('strval', $rows);
        } catch (Throwable $e) {}
        $miss = array_values(array_diff($cols, $have));
        if ($miss) $colMissing[] = $t . ':' . implode('/', $miss);
    }
    dx_add('数据库', '关键字段', $colMissing ? 'fail' : 'ok',
        $colMissing ? '缺少 ' . implode('；', $colMissing) : '关键表字段齐全',
        $colMissing ? '数据库结构与当前源码不一致，按 sql/schema.sql 补齐字段' : '');

    $categories = (int) ($counts['categories'] ?? 0);
    $published = in_array('recipes', $tableMissing, true) ? 0 : (int) $pdo->query('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE status = "published"')->fetchColumn();
    $pendingComments = in_array('comments', $tableMissing, true) ? 0 : (int) $pdo->query('SELECT COUNT(*) FROM `' . T('comments') . '` WHERE status = "pending"')->fetchColumn();
    $adminCount = in_array('admins', $tableMissing, true) ? 0 : (int) $pdo->query('SELECT COUNT(*) FROM `' . T('admins') . '` WHERE status = 1')->fetchColumn();

    dx_add('数据库', '内容量', $published > 0 ? 'ok' : 'warn',
        '已发布菜谱 ' . $published . ' · 分类 ' . $categories . ' · 管理员 ' . $adminCount . ' · 待审评论 ' . $pendingComments,
        $published === 0 ? '前台首页/菜谱库会是空的，先发布菜谱或导入演示数据' : '');
    dx_add('数据库', '管理员账号', $adminCount > 0 ? 'ok' : 'fail', $adminCount . ' 个启用中的管理员');

    if (!in_array('recipes', $tableMissing, true) && !in_array('recipe_ingredients', $tableMissing, true)) {
        $orphanIng = (int) $pdo->query('SELECT COUNT(*) FROM `' . T('recipe_ingredients') . '` ri LEFT JOIN `' . T('recipes') . '` r ON r.id = ri.recipe_id WHERE r.id IS NULL')->fetchColumn();
        dx_add('数据库', '食材关联', $orphanIng ? 'warn' : 'ok', $orphanIng ? $orphanIng . ' 条食材找不到所属菜谱' : '食材关联正常');
    }
    if (!in_array('recipes', $tableMissing, true) && !in_array('recipe_steps', $tableMissing, true)) {
        $noStep = (int) $pdo->query('SELECT COUNT(*) FROM `' . T('recipes') . '` r LEFT JOIN `' . T('recipe_steps') . '` s ON s.recipe_id = r.id WHERE r.status = "published" AND s.id IS NULL')->fetchColumn();
        dx_add('数据库', '步骤完整性', $noStep ? 'warn' : 'ok', $noStep ? $noStep . ' 道已发布菜谱没有步骤' : '已发布菜谱都有步骤');
    }
} catch (Throwable $e) {
    $dbErr = $e->getMessage();
    dx_add('数据库', '连接', 'fail', $dbErr, '检查 config/config.php 的数据库账号/密码/库名，或主机数据库是否可用');
}

/* ---------- 核心功能与配置 ---------- */
$siteName = trim((string) setting('site_name', ''));
dx_add('功能与数据', '站点名称', $siteName !== '' ? 'ok' : 'warn', $siteName !== '' ? $siteName : 'site_name 为空');
$siteTz = (string) setting('site_timezone', '');
dx_add('功能与数据', '站点时区', $siteTz !== '' ? 'ok' : 'warn', $siteTz !== '' ? $siteTz : '未设置 site_timezone');
dx_add('功能与数据', '评论审核', setting('comment_review', '0') === '1' ? 'ok' : 'info',
    setting('comment_review', '0') === '1' ? '新评论先待审' : '新评论直接通过');
$perPage = (int) setting('per_page', '12');
dx_add('功能与数据', '列表分页', $perPage > 0 ? 'ok' : 'warn', '每页 ' . $perPage . ' 条');

$welcomeTpl = @file_get_contents($root . '/index.php');
$welcomeJs  = @file_get_contents($root . '/assets/app.js');
$hasWelcomeModal = is_string($welcomeTpl) && strpos($welcomeTpl, 'id="welcomeModal"') !== false && strpos($welcomeTpl, 'wm-title') !== false;
$hasWelcomeFlow  = is_string($welcomeJs) && strpos($welcomeJs, 'sg_welcome_shown_v2') !== false && strpos($welcomeJs, 'sgw2_seen') !== false && strpos($welcomeJs, 'welcome=1') !== false;
if (!$hasWelcomeModal) {
    dx_add('功能与数据', '欢迎卡', 'fail', '首页模板里找不到欢迎卡（welcomeModal / wm-title）', '检查 index.php 是否被旧版覆盖，或重新安装前台模板');
} elseif (!$hasWelcomeFlow) {
    dx_add('功能与数据', '欢迎卡', 'warn', '首页欢迎卡存在，但访问/刷新判断逻辑不完整', '检查 assets/app.js 的 initWelcome 与 sg_welcome_shown');
} else {
    dx_add('功能与数据', '欢迎卡', 'ok', '访问显示 / 刷新隐藏逻辑已就位；可点上方“预览欢迎卡”');
}

$requiredSettings = ['site_name','site_timezone','per_page','comment_review','theme_primary','audio_file','audio_file_after'];
$missingSettings = [];
if ($dbOk) {
    try {
        $haveKeys = $pdo->query('SELECT skey FROM `' . T('settings') . '`')->fetchAll(PDO::FETCH_COLUMN, 0);
        $haveKeys = array_map('strval', $haveKeys);
        $missingSettings = array_values(array_diff($requiredSettings, $haveKeys));
    } catch (Throwable $e) {}
}
if (!$dbOk) {
    dx_add('功能与数据', '设置项', 'skip', '数据库不可用，设置项检查跳过');
} else {
    dx_add('功能与数据', '设置项', $missingSettings ? 'warn' : 'ok',
        $missingSettings ? '缺少：' . implode('、', $missingSettings) : '关键设置项齐全',
        $missingSettings ? '在后台系统设置保存一次，或补齐对应设置' : '');
}

$aiOn = setting('ai_enabled', '0') === '1';
if (!$aiOn) {
    dx_add('AI / 同步 / 备份', 'AI 功能', 'info', '未开启（可选）');
} else {
    $aiKind = (string) setting('ai_kind', 'ollama');
    $aiBase = trim((string) setting('ai_base', ''));
    $aiKey  = trim((string) setting('ai_key', ''));
    $aiModel = trim((string) setting('ai_model', ''));
    $missingAi = [];
    if ($aiBase === '') $missingAi[] = '接口地址';
    if ($aiModel === '') $missingAi[] = '模型名';
    if ($aiKind !== 'ollama' && $aiKey === '') $missingAi[] = 'API Key';
    dx_add('AI / 同步 / 备份', 'AI 配置', $missingAi ? 'fail' : 'ok',
        '方式 ' . $aiKind . ' · ' . ($aiModel ?: '未填模型') . ($missingAi ? '；缺少 ' . implode('、', $missingAi) : ''),
        $missingAi ? '到后台系统设置补全 AI 配置' : '');
    if (!extension_loaded('curl')) {
        dx_add('AI / 同步 / 备份', 'AI 网络能力', 'fail', 'PHP 缺少 curl，AI 请求无法发出');
    } elseif (stripos($aiBase, 'https://') === 0 && !extension_loaded('openssl')) {
        dx_add('AI / 同步 / 备份', 'AI HTTPS', 'warn', 'AI 地址是 HTTPS，但 PHP 缺少 openssl');
    }
}

$syncUrl = trim((string) setting('sync_cloud_url', ''));
if ($syncUrl === '') {
    dx_add('AI / 同步 / 备份', '云端同步', 'info', '未配置（可选）');
} else {
    $syncToken = trim((string) setting('sync_token', ''));
    dx_add('AI / 同步 / 备份', '云端同步', $syncToken !== '' ? 'ok' : 'warn',
        sync_endpoint($syncUrl) . ($syncToken === '' ? '；缺少同步密钥' : ''),
        $syncToken === '' ? '在后台“云端同步”填同一串密钥' : '');
    if (!extension_loaded('curl')) {
        dx_add('AI / 同步 / 备份', '同步网络能力', 'fail', 'PHP 缺少 curl，云端同步请求无法发出');
    }
}

$backupDir = SG_ROOT . '/config/backup';
$backups = is_dir($backupDir) ? (glob($backupDir . '/*.sql') ?: []) : [];
$lastBackup = $backups ? max(array_map('filemtime', $backups)) : 0;
$backupAge = $lastBackup ? (time() - $lastBackup) : null;
dx_add('AI / 同步 / 备份', '数据备份', !is_dir($backupDir) || !is_writable($backupDir) ? 'warn' : ($lastBackup && $backupAge > 30 * 86400 ? 'warn' : 'ok'),
    $lastBackup ? '最近备份 ' . date('Y-m-d H:i', $lastBackup) : '还没有备份过',
    !$lastBackup ? '到后台“数据备份”生成一次 SQL' : '');

/* ---------- 音频 ---------- */
$audioSlots = [];
foreach (['guest', 'member'] as $slot) {
    $st = audio_slot_state($slot);
    $resolved = $st['file'] !== '' && audio_path_ok($st['file'])
        ? audio_refresh_builtin($st['file'], $slot)
        : audio_builtin_default($slot);
    if ($resolved === '' || !audio_path_ok($resolved)) $resolved = '';
    $full = $resolved !== '' ? SG_ROOT . '/' . $resolved : '';
    $size = $full !== '' && is_file($full) ? (int) filesize($full) : 0;
    $mtime = $full !== '' && is_file($full) ? (int) filemtime($full) : 0;
    $mime = $full !== '' ? audio_file_mime($full) : '';
    $head = '';
    if ($full !== '' && is_file($full)) {
        $raw = (string) @file_get_contents($full, false, null, 0, 16);
        $head = strtoupper(trim(chunk_split(bin2hex($raw), 2, ' ')));
    }
    $proxy = '../audio.php?slot=' . $slot . '&preview=1&v=' . ($mtime ?: time());
    $audioSlots[] = [
        'slot' => $slot,
        'label' => $st['label'],
        'enabled' => $st['enabled'],
        'configured' => $st['file'],
        'exists' => $st['ok'],
        'resolved' => $resolved,
        'size' => $size,
        'mtime' => $mtime,
        'mime' => $mime,
        'head' => $head,
        'proxy' => $proxy,
        'data_len' => $size > 0 && $size <= 4 * 1024 * 1024 ? (int) ceil($size * 1.37) + 31 : 0,
    ];

    $problems = [];
    $configuredMissing = $st['file'] !== '' && !$st['ok'];
    if (!$st['enabled']) $problems[] = '未启用';
    if ($resolved === '') $problems[] = '找不到可用文件';
    if ($configuredMissing && $resolved === '') $problems[] = '原文件丢失';
    elseif ($configuredMissing) $problems[] = '配置路径失效';
    if ($mime === 'audio/mp4') $problems[] = '实际是 MP4/AAC 容器';
    if ($problems) {
        $status = ($resolved === '') ? 'fail' : 'warn';
        $detail = $problems[0] . '：' . ($st['file'] !== '' ? $st['file'] : '未设置');
        if ($resolved !== '' && $resolved !== $st['file']) $detail .= '；已回退到 ' . $resolved;
        dx_add('音频', $st['label'], $status, $detail);
    } else {
        dx_add('音频', $st['label'], 'ok',
            ($resolved ?: '—') . ' · ' . round($size / 1024) . ' KB · ' . $mime . ' · 更新时间 ' . date('Y-m-d H:i', $mtime));
    }
}
dx_add('音频', '输出模式', sg_audio_embed_mode() ? 'warn' : 'ok',
    sg_audio_embed_mode() ? '嵌入 data URI（部分 WebView 可能不支持）' : 'audio.php 代理 + 前端 Blob 播放',
    sg_audio_embed_mode() ? 'gt.tc 主机会强制代理；其他主机建议关闭嵌入播放' : '');
if ($host !== '' && strpos($host, 'gt.tc') !== false) {
    dx_add('音频', 'gt.tc 主机', 'ok', '已强制走代理，忽略后台“嵌入播放”勾选');
}

/* ---------- 浏览器体检清单 ---------- */
$probes = [
    ['name' => '首页', 'url' => '../index.php', 'needle' => 'welcomeModal', 'kind' => 'html'],
    ['name' => '菜谱库', 'url' => '../recipes.php', 'needle' => '菜谱库', 'kind' => 'html'],
    ['name' => '搜索', 'url' => '../search.php?q=' . rawurlencode('鸡蛋'), 'needle' => '搜索', 'kind' => 'html'],
    ['name' => '采购清单', 'url' => '../shopping.php', 'needle' => '采购清单', 'kind' => 'html'],
    ['name' => '新手手册', 'url' => '../tips.php', 'needle' => '新手厨房手册', 'kind' => 'html'],
    ['name' => '登录页', 'url' => '../login.php', 'needle' => '', 'kind' => 'html'],
    ['name' => '注册页', 'url' => '../register.php', 'needle' => '', 'kind' => 'html'],
    ['name' => '找回密码', 'url' => '../forgot-password.php', 'needle' => '找回密码', 'kind' => 'html'],
    ['name' => '帮助与客服', 'url' => '../support.php', 'needle' => '帮助与客服', 'kind' => 'html'],
    ['name' => '后台仪表盘', 'url' => 'dashboard.php', 'needle' => '仪表盘', 'kind' => 'admin'],
];
if ($dbOk && !in_array('recipes', $tableMissing, true)) {
    try {
        $firstRec = fetch_one('SELECT id, title FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY id DESC LIMIT 1');
        if ($firstRec) {
            array_splice($probes, 2, 0, [[
                'name' => '菜谱详情',
                'url' => '../recipe.php?id=' . (int) $firstRec['id'],
                'needle' => '',
                'kind' => 'html',
            ]]);
        }
    } catch (Throwable $e) {
        dx_add('数据库', '菜谱详情检查', 'warn', '取不到已发布菜谱：' . $e->getMessage());
    }
}

/* ---------- 汇总 ---------- */
$countsStatus = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0, 'skip' => 0];
foreach ($groups as $items) {
    foreach ($items as $it) $countsStatus[$it['status']]++;
}
$overall = $countsStatus['fail'] ? 'fail' : ($countsStatus['warn'] ? 'warn' : 'ok');
$overallText = $overall === 'fail' ? '发现需要处理的问题' : ($overall === 'warn' ? '基本正常，有几项警告' : '未发现明显问题');
$overallNote = '服务器侧检查 ' . ($countsStatus['fail'] + $countsStatus['warn'] + $countsStatus['ok']) . ' 项；页面/音频请在下方点按钮实测。';

$reportLines = [];
foreach ($groups as $group => $items) {
    $reportLines[] = '【' . $group . '】';
    foreach ($items as $it) {
        $reportLines[] = '[' . dx_label($it['status']) . '] ' . $it['name'] . '：' . $it['detail'];
    }
}
$reportText = '食光菜谱网站诊断 v' . SG_VERSION . ' · ' . date('Y-m-d H:i:s') . "\n" . implode("\n", $reportLines);
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$audioJson = json_encode($audioSlots, $jsonFlags);
$probeJson = json_encode($probes, $jsonFlags);
$reportJson = json_encode($reportText, $jsonFlags);
?>
<?php sg_admin_head('网站诊断', 'admin-site-doctor', '网站诊断'); ?>

<style>
.dx-hero{display:flex;align-items:center;gap:14px;padding:16px 18px;border-radius:16px;border:1px solid var(--line);background:#FFFDFA;margin-bottom:14px}
.dx-hero .mark{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;flex:0 0 48px;background:#EEF5F0;color:#2A6350;font-size:22px;font-weight:700}
.dx-hero.is-warn .mark{background:#FBF3DF;color:#8A6417}.dx-hero.is-fail .mark{background:#FBEDE8;color:#A63C1C}
.dx-hero h2{margin:0;font:700 18px/1.35 var(--font-serif);color:var(--ink)}
.dx-hero p{margin:4px 0 0;color:var(--muted);font-size:12px;line-height:1.7}
.dx-hero .tags{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.dx-tag{font-size:11px;padding:4px 8px;border-radius:999px;background:#F4EEE3;color:#6C6355;white-space:nowrap}
.dx-tag.ok{background:#EEF5F0;color:#2A6350}.dx-tag.warn{background:#FBF3DF;color:#8A6417}.dx-tag.fail{background:#FBEDE8;color:#A63C1C}
.dx-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}
.dx-toolbar .btn,.dx-audio-actions .btn{flex:0 0 auto;max-width:100%}
.dx-card .card-head{margin-bottom:8px}
.dx-row{display:flex;gap:10px;padding:10px 0;border-bottom:1px dashed var(--line-2);align-items:flex-start;font-size:12.5px;line-height:1.65}
.dx-row:last-child{border-bottom:0}
.dx-badge{flex:0 0 46px;text-align:center;font-size:10.5px;font-weight:700;border-radius:999px;padding:3px 0;background:#F4EEE3;color:#6C6355;margin-top:1px}
.dx-row.is-ok .dx-badge{background:#EEF5F0;color:#2A6350}.dx-row.is-warn .dx-badge{background:#FBF3DF;color:#8A6417}.dx-row.is-fail .dx-badge{background:#FBEDE8;color:#A63C1C}.dx-row.is-info .dx-badge{background:#EEF2F5;color:#536B7A}
.dx-main{min-width:0;flex:1}.dx-main b{display:block;color:var(--ink);font-size:12.5px;margin-bottom:2px}.dx-main span{display:block;color:var(--muted);word-break:break-all}.dx-main em{display:block;font-style:normal;color:#A63C1C;font-size:11.5px;margin-top:3px}
.dx-kv{display:grid;gap:6px;margin-top:10px}.dx-kv div{display:flex;gap:10px;font-size:11.5px;color:var(--muted);word-break:break-all}.dx-kv b{margin-left:auto;color:var(--ink);font-weight:600;text-align:right}
.dx-log{margin:0;background:#241F1A;color:#F1E8DA;border-radius:10px;padding:14px;font:12px/1.75 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-all;min-height:90px}
.dx-browser-sum{font-size:11.5px;color:var(--muted)}
@media(max-width:700px){.dx-hero{align-items:flex-start}.dx-hero .tags{margin-left:0}.dx-hero{flex-wrap:wrap}.dx-badge{flex-basis:42px}}
#browser,#audio{scroll-margin-top:82px}
</style>

        <div class="page-head">
          <h1>网站诊断</h1>
          <span class="sub desk-only">像网站医生一样，逐项查环境、数据库、功能、音频和页面</span>
          <div class="actions">
            <a class="btn sm" href="settings.php"><span class="ico" data-ico="settings"></span>系统设置</a>
          </div>
        </div>

        <?= flash_render() ?>

        <div class="dx-hero is-<?= e($overall) ?>">
          <div class="mark"><?= $overall === 'ok' ? '✓' : ($overall === 'fail' ? '!' : '!') ?></div>
          <div>
            <h2><?= e($overallText) ?></h2>
            <p><?= e($overallNote) ?></p>
          </div>
          <div class="tags">
            <span class="dx-tag ok">正常 <?= (int) $countsStatus['ok'] ?></span>
            <span class="dx-tag warn">警告 <?= (int) $countsStatus['warn'] ?></span>
            <span class="dx-tag fail">失败 <?= (int) $countsStatus['fail'] ?></span>
          </div>
        </div>

        <div class="dx-toolbar">
          <button class="btn primary sm" type="button" data-dx-all><span class="ico" data-ico="refresh"></span>一键体检</button>
          <a class="btn sm" href="../index.php?welcome=1" target="_blank" rel="noopener"><span class="ico" data-ico="eye"></span>预览欢迎卡</a>
          <button class="btn sm" type="button" data-dx-copy><span class="ico" data-ico="share"></span>复制报告</button>
        </div>

<?php foreach ($groups as $group => $items): ?>
        <div class="card dx-card" style="margin-top:14px">
          <div class="card-head"><h3><?= e($group) ?></h3><span class="hint"><?= count($items) ?> 项</span></div>
          <div>
<?php foreach ($items as $it): ?>
            <div class="dx-row is-<?= e($it['status']) ?>">
              <span class="dx-badge"><?= e(dx_label($it['status'])) ?></span>
              <div class="dx-main">
                <b><?= e($it['name']) ?></b>
                <span><?= e($it['detail']) ?></span>
                <?php if (!empty($it['fix'])): ?><em><?= e($it['fix']) ?></em><?php endif; ?>
              </div>
            </div>
<?php endforeach; ?>
          </div>
        </div>
<?php endforeach; ?>

        <div class="card" style="margin-top:14px" id="audio">
          <div class="card-head"><h3>音频诊断</h3><span class="hint">文件、MIME、代理、Blob 播放</span></div>
          <p style="margin:0 0 10px;font-size:12.5px;color:var(--muted);line-height:1.8">前后台现在都走 Blob 播放；下面的测试会先看 HTTP 和真实字节，再用 Blob 静音播放验证解码，最后用直链做对照。</p>
<?php foreach ($audioSlots as $s): ?>
          <div style="padding:12px 0;border-top:1px dashed var(--line-2)">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><b style="font-size:13px"><?= e($s['label']) ?></b><span class="dx-tag <?= $s['enabled'] ? 'ok' : 'warn' ?>"><?= $s['enabled'] ? '已启用' : '未启用' ?></span></div>
            <div class="dx-kv">
              <div><span>文件</span><b><?= e($s['resolved'] !== '' ? $s['resolved'] : '没有可用文件') ?></b></div>
              <div><span>大小 / 类型</span><b><?= $s['size'] > 0 ? e(round($s['size'] / 1024) . ' KB · ' . $s['mime']) : '—' ?></b></div>
              <div><span>文件头</span><b><?= e($s['head'] !== '' ? $s['head'] : '—') ?></b></div>
              <div><span>代理地址</span><b><a href="<?= e($s['proxy']) ?>" target="_blank" rel="noopener"><?= e($s['proxy']) ?></a></b></div>
            </div>
            <div class="dx-audio-actions" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <button class="btn sm" type="button" data-dx-one-audio data-slot="<?= e($s['slot']) ?>"><span class="ico" data-ico="play"></span>测这条音频</button>
              <span data-audio-status="<?= e($s['slot']) ?>" style="font-size:12px;color:var(--muted)"></span>
            </div>
          </div>
<?php endforeach; ?>
        </div>

        <div class="card" style="margin-top:14px" id="browser">
          <div class="card-head"><h3>浏览器实测</h3><span class="dx-browser-sum" id="dxBrowserSum">还没测</span></div>
          <p style="margin:0 0 10px;font-size:12.5px;color:var(--muted);line-height:1.8">用当前浏览器真实打开关键页面和音频接口，能发现“服务器能连但页面/媒体打不开”的问题。</p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            <button class="btn primary sm" type="button" data-dx-all><span class="ico" data-ico="refresh"></span>一键体检</button>
            <button class="btn sm" type="button" data-dx-pages><span class="ico" data-ico="eye"></span>页面体检</button>
            <button class="btn sm" type="button" data-dx-audio><span class="ico" data-ico="volume"></span>音频测试</button>
          </div>
          <pre class="dx-log" id="dxPageOut">还没有页面体检。</pre>
          <pre class="dx-log" id="dxAudioOut" style="margin-top:10px">还没有音频测试。</pre>
        </div>

<script>
window.DX_PROBES = <?= $probeJson ?>;
window.DX_AUDIO = <?= $audioJson ?>;
window.DX_SERVER_REPORT = <?= $reportJson ?>;

(function () {
  var pageOut = document.getElementById('dxPageOut');
  var audioOut = document.getElementById('dxAudioOut');
  var browserSum = document.getElementById('dxBrowserSum');
  var pageLines = [], audioLines = [], browserCounts = { ok: 0, warn: 0, fail: 0 };
  var pageRunning = false, audioRunning = false;

  function logTo(lines, el, text) {
    lines.push(text);
    el.textContent = lines.join('\n');
  }
  function setSum() {
    if (!browserSum) return;
    browserSum.textContent = '正常 ' + browserCounts.ok + ' · 可疑 ' + browserCounts.warn + ' · 失败 ' + browserCounts.fail;
  }
  function mark(kind) {
    browserCounts[kind]++;
    setSum();
  }

  function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function navTypeNow() {
    var t = 'navigate';
    try {
      var nav = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
      if (nav && nav.type) t = nav.type;
      else if (performance.navigation) t = performance.navigation.type === 1 ? 'reload' : (performance.navigation.type === 2 ? 'back_forward' : 'navigate');
    } catch (e) {}
    return t;
  }

  async function testWelcome() {
    logTo(pageLines, pageOut, '==== 欢迎卡 ====');
    var d = new Date();
    var day = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    /* 欢迎卡只按当前标签页记一次，不按账号分键，和前台 v1.23.31 保持一致 */
    var uid = 0;
    try { uid = parseInt(window.SG_UID || 0, 10) || 0; } catch (e) {}
    var scope = uid > 0 ? '_u' + uid : '';
    var KEY = 'sg_welcome_off' + scope;
    var SHOWN = 'sg_welcome_shown_v2';
    var WIN_SHOWN = 'sgw2_seen=1';
    var LEGACY_SHOWN = 'sg_welcome_shown';
    var LEGACY_WIN = 'sgw_seen=1';
    var WIN_OFF = 'sgoff' + scope + '=' + day;

    function storageOk(kind) {
      try { var st = window[kind]; var k = '__dx_probe__'; st.setItem(k, '1'); st.removeItem(k); return true; } catch (e) { return false; }
    }
    function cookieGet(name) {
      try {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
      } catch (e) { return ''; }
    }
    function cookieSet(name, value, expire) {
      try {
        var c = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';
        if (expire) c += '; expires=' + expire.toUTCString();
        if (location.protocol === 'https:') c += '; Secure';
        document.cookie = c; return true;
      } catch (e) { return false; }
    }
    function cookieDel(name) {
      try {
        document.cookie = name + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      } catch (e) {}
    }
    function cookieWorks() {
      var k = '__dx_cookie_probe__';
      if (!cookieSet(k, '1')) return false;
      var ok = cookieGet(k) === '1';
      cookieDel(k);
      return ok;
    }
    function dayEnd() { var e = new Date(); e.setHours(23, 59, 59, 999); return e; }
    function winText(w) { try { return (w && w.name) || ''; } catch (e) { return ''; } }
    function hasToken(text, token) { return ('|' + (text || '') + '|').indexOf('|' + token + '|') !== -1; }
    function winSet(token) {
      try { if (!hasToken(winText(window), token)) window.name = (winText(window) ? winText(window) + '|' : '') + token; } catch (e) {}
    }
    function winDel(token) {
      try { window.name = (winText(window) || '').split('|').filter(function (p) { return p !== token; }).join('|'); } catch (e) {}
    }

    var lsOk = storageOk('localStorage');
    var ssOk = storageOk('sessionStorage');
    var ckOk = cookieWorks();
    var offLS = '', offCk = '', shownSS = '', shownWin = false, offWin = false;
    var legacySS = '', legacyWin = false;
    try { if (lsOk) offLS = localStorage.getItem(KEY) || ''; } catch (e) {}
    try { if (ckOk) offCk = cookieGet(KEY); } catch (e) {}
    try { if (ssOk) shownSS = sessionStorage.getItem(SHOWN) || ''; } catch (e) {}
    try { if (ssOk) legacySS = sessionStorage.getItem(LEGACY_SHOWN) || ''; } catch (e) {}
    shownWin = hasToken(winText(window), WIN_SHOWN);
    legacyWin = hasToken(winText(window), LEGACY_WIN);
    offWin = hasToken(winText(window), WIN_OFF);
    var offToday = offLS === day || offCk === day || offWin;

    logTo(pageLines, pageOut, '存储：localStorage ' + (lsOk ? '✅' : '❌ 被禁用') + ' · sessionStorage ' + (ssOk ? '✅' : '❌ 被禁用') + ' · Cookie ' + (ckOk ? '✅' : '❌ 被禁用'));
    logTo(pageLines, pageOut, '今日不提醒：' + (offToday ? '已开启（' + day + '）' : (offLS || offCk ? '其他日期 ' + (offLS || offCk) : '未开启')));
    logTo(pageLines, pageOut, '当前标签已显示：' + (shownSS === '1' || shownWin ? '是' : '否') + ' · window.name 标记：' + (shownWin ? '有' : '无'));
    if (legacySS === '1' || legacyWin) {
      logTo(pageLines, pageOut, '检测到旧版残留的已显示标记（新版会忽略它，不会再挡掉首次访问）');
    }
    if (!lsOk && !ssOk) {
      logTo(pageLines, pageOut, '⚠️ 原因线索：浏览器禁用了本地存储，旧版会误判首次/刷新；新版已用 window.name / Cookie 兜底。');
    }

    var frame = null;
    var orig = {
      offLS: offLS,
      offCk: offCk,
      offWin: offWin,
      shownSS: shownSS
    };
    function removeFrame() { if (frame && frame.parentNode) frame.remove(); frame = null; }
    function mountFrame() {
      return new Promise(function (resolve) {
        removeFrame();
        frame = document.createElement('iframe');
        frame.setAttribute('title', '欢迎卡诊断');
        frame.setAttribute('aria-hidden', 'true');
        frame.name = '';
        frame.style.cssText = 'position:fixed;left:-9999px;top:0;width:390px;height:844px;border:0;opacity:0;pointer-events:none;';
        var done = false;
        function finish() { if (!done) { done = true; resolve(); } }
        frame.onload = function () { setTimeout(finish, 650); };
        frame.src = '../index.php?dx_silent=1&dxw=' + Date.now();
        document.body.appendChild(frame);
        setTimeout(finish, 4000);
      });
    }
    function frameState() {
      try {
        var doc = frame && frame.contentDocument;
        if (!doc) return { accessible: false };
        var modal = doc.getElementById('welcomeModal');
        var card = doc.querySelector('.wm-card');
        return {
          accessible: true,
          hasModal: !!modal,
          open: !!(modal && card && !modal.hidden && modal.classList.contains('open')),
          hidden: modal ? modal.hidden : null,
          body: doc.body ? doc.body.className : '',
          name: winText(frame.contentWindow)
        };
      } catch (e) {
        return { accessible: false, error: e.name + ': ' + e.message };
      }
    }
    function reloadFrame() {
      return new Promise(function (resolve) {
        if (!frame || !frame.contentWindow) { resolve(); return; }
        var done = false;
        function finish() { if (!done) { done = true; setTimeout(resolve, 500); } }
        frame.onload = finish;
        try { frame.contentWindow.location.reload(); } catch (e) { finish(); }
        setTimeout(finish, 4000);
      });
    }
    function clearShown() {
      try { if (ssOk) sessionStorage.removeItem(SHOWN); } catch (e) {}
    }
    function restoreAll() {
      try {
        if (lsOk) {
          if (orig.offLS) localStorage.setItem(KEY, orig.offLS);
          else localStorage.removeItem(KEY);
        }
      } catch (e) {}
      try {
        if (ckOk) {
          if (orig.offCk) cookieSet(KEY, orig.offCk, dayEnd());
          else cookieDel(KEY);
        }
      } catch (e) {}
      try {
        if (ssOk) {
          if (orig.shownSS) sessionStorage.setItem(SHOWN, orig.shownSS);
          else sessionStorage.removeItem(SHOWN);
        }
      } catch (e) {}
      try {
        if (orig.offWin) winSet(WIN_OFF);
        else winDel(WIN_OFF);
      } catch (e) {}
    }

    if (offToday) {
      logTo(pageLines, pageOut, '当前已开启今日不提醒（' + day + '）：本浏览器今天不显示欢迎卡是设置生效，不是欢迎卡坏了。');
      logTo(pageLines, pageOut, '为了继续检查“首次访问是否正常”，本次会临时清除今日不提醒，测完恢复。');
      try { if (lsOk) localStorage.removeItem(KEY); } catch (e) {}
      try { if (ckOk) cookieDel(KEY); } catch (e) {}
      try { winDel(WIN_OFF); } catch (e) {}
    }

    logTo(pageLines, pageOut, '第一步：清除本标签“已显示”标记，模拟初次访问……');
    clearShown();
    await mountFrame();
    var first = frameState();
    if (!first.accessible) {
      logTo(pageLines, pageOut, '⚠️ 浏览器禁止读取嵌入首页：请用顶部“预览欢迎卡”人工确认');
      mark('warn');
      removeFrame();
      restoreAll();
      return;
    }
    if (!first.open) {
      logTo(pageLines, pageOut, '❌ 初次访问没有显示欢迎卡');
      logTo(pageLines, pageOut, '  节点存在：' + (first.hasModal ? '是' : '否') + ' · hidden：' + first.hidden + ' · body：' + (first.body || '（空）'));
      if (first.hidden === false) {
        logTo(pageLines, pageOut, '  ⚠️ 关键线索：节点已经取消 hidden，但缺 open 类，卡停在透明状态；常见于 requestAnimationFrame 未触发。');
      } else {
        logTo(pageLines, pageOut, '  可能原因：前台脚本报错、存储被拦截、或页面被旧缓存覆盖；请看浏览器控制台第一段红字。');
      }
      mark('fail');
      removeFrame();
      restoreAll();
      return;
    }
    logTo(pageLines, pageOut, '✅ 初次访问能打开欢迎卡');
    mark('ok');

    logTo(pageLines, pageOut, '第二步：刷新页面，预期不再显示……');
    await reloadFrame();
    var refreshed = frameState();
    if (!refreshed.accessible) {
      logTo(pageLines, pageOut, '⚠️ 刷新后无法读取欢迎卡状态');
      mark('warn');
    } else if (refreshed.open) {
      logTo(pageLines, pageOut, '❌ 刷新后仍然显示：本标签的“已显示”标记没有被识别');
      logTo(pageLines, pageOut, '  原因线索：sessionStorage ' + (ssOk ? '可用' : '被禁用') + ' · window.name ' + (refreshed.name ? '有标记' : '无标记'));
      mark('fail');
    } else if (refreshed.hidden === false) {
      logTo(pageLines, pageOut, '❌ 刷新后 hidden=false 但没有 open 类：卡是透明的，实际不可见但状态没关干净');
      mark('fail');
    } else {
      logTo(pageLines, pageOut, '✅ 刷新后已隐藏');
      mark('ok');
    }

    logTo(pageLines, pageOut, '第三步：勾选“今日不提醒”，关闭后刷新……');
    clearShown();
    await mountFrame();
    var third = frameState();
    var checkboxDoc = null;
    try { checkboxDoc = frame && frame.contentDocument; } catch (e) {}
    var cb = checkboxDoc ? checkboxDoc.getElementById('wmNoToday') : null;
    if (!third.accessible || !third.open) {
      logTo(pageLines, pageOut, '❌ 第三步页面没有重新打开欢迎卡，无法验证“今日不提醒”' + (third.hidden === false ? '（hidden=false 但缺 open 类，卡是透明的）' : ''));
      mark('fail');
    } else if (!cb) {
      logTo(pageLines, pageOut, '❌ 找不到“今日不提醒”复选框');
      mark('fail');
    } else {
      cb.checked = true;
      try { cb.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
      await sleep(150);
      var closeBtn = checkboxDoc.querySelector('#welcomeModal [data-wm-close]');
      if (closeBtn) closeBtn.click();
      await sleep(350);
      var savedLS = '', savedCk = '', savedWin = false;
      try { if (lsOk) savedLS = localStorage.getItem(KEY) || ''; } catch (e) {}
      try { if (ckOk) savedCk = cookieGet(KEY); } catch (e) {}
      savedWin = hasToken(winText(frame && frame.contentWindow), WIN_OFF);
      var saved = savedLS === day || savedCk === day || savedWin;
      logTo(pageLines, pageOut, '写入结果：localStorage=' + (savedLS || '空') + ' · Cookie=' + (savedCk || '空') + ' · window.name=' + (savedWin ? '有' : '无'));
      if (!saved) {
        logTo(pageLines, pageOut, '❌ 今日不提醒没有写进任何存储：浏览器把 localStorage / Cookie / window.name 都拦了');
        mark('fail');
      } else {
        logTo(pageLines, pageOut, '✅ 今日不提醒已写入');
        mark('ok');
        await reloadFrame();
        var afterOff = frameState();
        if (afterOff.accessible && afterOff.open) {
          logTo(pageLines, pageOut, '❌ 已经写入今日不提醒，但刷新后欢迎卡仍然出现');
          mark('fail');
        } else if (afterOff.accessible && afterOff.hidden === false) {
          logTo(pageLines, pageOut, '❌ 今日不提醒写入后，卡 hidden=false 但没有 open 类：透明卡仍在');
          mark('fail');
        } else {
          logTo(pageLines, pageOut, '✅ 刷新后按今日不提醒隐藏');
          mark('ok');
        }
      }
    }

    removeFrame();
    restoreAll();
    logTo(pageLines, pageOut, '测试会把临时状态恢复；强制预览可用顶部“预览欢迎卡”按钮。');
    logTo(pageLines, pageOut, '');
  }

  async function probePage(p, idx, total) {
    logTo(pageLines, pageOut, '[' + (idx + 1) + '/' + total + '] ' + p.name + ' …');
    var result = { name: p.name, url: p.url, kind: p.kind };
    try {
      var r = await fetch(p.url, { credentials: 'same-origin', cache: 'no-store' });
      var ct = (r.headers.get('content-type') || '').toLowerCase();
      var body = await r.text();
      result.status = r.status;
      result.ct = ct;
      result.bytes = body.length;
      result.final = r.url;
      result.fatal = /Fatal error|Parse error|Uncaught|Call to undefined|SQLSTATE|Database error/i.test(body);
      result.needle = p.needle ? body.indexOf(p.needle) !== -1 : true;
      result.loginWall = /login\.php/i.test(r.url) && p.kind === 'admin';
      if (!r.ok) {
        logTo(pageLines, pageOut, '  ❌ HTTP ' + r.status + ' · ' + ct);
        mark('fail');
      } else if (ct.indexOf('text/html') === -1) {
        logTo(pageLines, pageOut, '  ⚠️ 不是 HTML：' + ct);
        mark('warn');
      } else if (result.fatal) {
        logTo(pageLines, pageOut, '  ❌ 响应里有 PHP 错误');
        mark('fail');
      } else if (result.loginWall) {
        logTo(pageLines, pageOut, '  ❌ 被重定向到登录页（后台会话失效）');
        mark('fail');
      } else if (body.length < 300) {
        logTo(pageLines, pageOut, '  ⚠️ 页面只有 ' + body.length + ' 字节，可能被拦截');
        mark('warn');
      } else if (!result.needle) {
        logTo(pageLines, pageOut, '  ⚠️ 页面能打开，但没找到关键内容「' + p.needle + '」');
        mark('warn');
      } else {
        logTo(pageLines, pageOut, '  ✅ HTTP ' + r.status + ' · ' + body.length + ' 字节 · 关键内容通过');
        mark('ok');
      }
    } catch (e) {
      logTo(pageLines, pageOut, '  ❌ 请求失败：' + e.name + ': ' + e.message);
      mark('fail');
    }
  }

  async function runPages() {
    if (pageRunning) return;
    pageRunning = true;
    pageLines = [];
    pageOut.textContent = '页面体检中…';
    try {
      await testWelcome();
      for (var i = 0; i < window.DX_PROBES.length; i++) {
        await probePage(window.DX_PROBES[i], i, window.DX_PROBES.length);
      }
      logTo(pageLines, pageOut, '');
      logTo(pageLines, pageOut, '页面体检结束。');
    } finally { pageRunning = false; }
  }

  function audioProbe(src, doPlay, mute, playFull) {
    return new Promise(function (resolve) {
      var a = new Audio();
      var done = false;
      var info = { ok: false };
      a.preload = 'auto';
      a.volume = mute ? 0 : 0.8;
      a.muted = !!mute;
      function finish(extra) {
        if (done) return;
        done = true;
        try { a.pause(); } catch (e) {}
        Object.keys(extra || {}).forEach(function (k) { info[k] = extra[k]; });
        resolve(info);
      }
      a.addEventListener('loadedmetadata', function () { info.duration = a.duration; if (!doPlay) finish({ ok: true, decoded: true }); });
      a.addEventListener('ended', function () { finish({ ok: true, play: true, ended: true, currentTime: a.currentTime }); });
      a.addEventListener('error', function () { finish({ ok: false, code: a.error && a.error.code, error: a.error && a.error.message }); });
      setTimeout(function () { finish({ ok: false, timeout: true, readyState: a.readyState }); }, playFull ? 120000 : 5000);
      a.src = src;
      a.load();
      if (doPlay) {
        var p;
        try { p = a.play(); } catch (e) { finish({ ok: false, play: false, playError: e.name + ': ' + e.message }); return; }
        Promise.resolve(p).then(function () {
          if (playFull) return;
          setTimeout(function () { finish({ ok: a.currentTime > 0.05, play: true, currentTime: a.currentTime }); }, 800);
        }).catch(function (e) { finish({ ok: false, play: false, playError: e.name + ': ' + e.message }); });
      }
    });
  }

  async function probeAudio(s, index, total) {
    logTo(audioLines, audioOut, '==== ' + s.label + ' ====');
    logTo(audioLines, audioOut, '代理：' + s.proxy);
    var buf = null, ct = '', status = 0, bytes = 0, d = null;
    try {
      var r = await fetch(s.proxy + '&debugts=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' });
      status = r.status;
      ct = (r.headers.get('content-type') || '').toLowerCase();
      buf = await r.arrayBuffer();
      bytes = buf.byteLength;
      logTo(audioLines, audioOut, 'HTTP：' + status + ' · ' + (ct || '（空）') + ' · ' + bytes + ' 字节');
      if (ct.indexOf('text/html') !== -1) logTo(audioLines, audioOut, '❌ 被主机拦成了 HTML');
    } catch (e) {
      logTo(audioLines, audioOut, '❌ 请求失败：' + e.name + ': ' + e.message);
      mark('fail');
      return { ok: false, error: e.name + ': ' + e.message };
    }
    if (buf && ct.indexOf('text/html') === -1) {
      var blobUrl = URL.createObjectURL(new Blob([buf], { type: ct || 'audio/mpeg' }));
      d = await audioProbe(blobUrl, true, true);
      logTo(audioLines, audioOut, 'Blob 播放（静音）：' + (d.ok ? '✅ 可播放，时长 ' + Number(d.duration || 0).toFixed(2) + ' 秒' : '❌ ' + (d.error || d.playError || ('错误码 ' + d.code) || '超时')));
      URL.revokeObjectURL(blobUrl);
    }
    var p = await audioProbe(s.proxy, true, false, true);
    if (p.ok) { logTo(audioLines, audioOut, p.ended ? '直链对照：✅ 完整播放到结尾' : '直链对照：✅ 可播放'); }
    else if (p.playError && /NotAllowed/i.test(p.playError)) { logTo(audioLines, audioOut, '直链对照：⚠️ 被自动播放策略拦截'); }
    else { logTo(audioLines, audioOut, '直链对照：❌ ' + (p.error || p.playError || ('错误码 ' + p.code) || '超时')); }
    if (d && d.ok) { logTo(audioLines, audioOut, '结论：✅ Blob 播放链路正常（前后台实际走这条），直链失败可忽略。'); mark('ok'); }
    else { logTo(audioLines, audioOut, '结论：❌ Blob 播放也失败，请把这段报告发我。'); mark('fail'); }
    logTo(audioLines, audioOut, '');
    return { ok: !!(d && d.ok), duration: d && d.duration ? d.duration : 0 };
  }

  async function runAudio() {
    if (audioRunning) return;
    audioRunning = true;
    audioLines = [];
    audioOut.textContent = '音频测试中…';
    try {
      for (var i = 0; i < window.DX_AUDIO.length; i++) await probeAudio(window.DX_AUDIO[i], i, window.DX_AUDIO.length);
      logTo(audioLines, audioOut, '音频测试结束。');
    } finally { audioRunning = false; }
  }

  async function runOneAudio(slot) {
    var s = (window.DX_AUDIO || []).filter(function (x) { return x.slot === slot; })[0];
    if (!s) return;
    var status = document.querySelector('[data-audio-status="' + slot + '"]');
    if (status) { status.textContent = '测试中…'; status.style.color = 'var(--muted)'; }
    audioLines = [];
    audioOut.textContent = '测试中…';
    var res = await probeAudio(s, 0, 1);
    if (status) {
      if (res && res.ok) {
        status.textContent = '✅ 可播放' + (res.duration ? ' · ' + res.duration.toFixed(2) + ' 秒' : '');
        status.style.color = 'var(--green)';
      } else {
        status.textContent = '❌ 没通过，详见下方音频报告';
        status.style.color = 'var(--brand)';
      }
    }
  }

  function bindAll(sel, fn) {
    Array.prototype.slice.call(document.querySelectorAll(sel)).forEach(function (b) { b.addEventListener('click', fn); });
  }

  bindAll('[data-dx-pages]', runPages);
  bindAll('[data-dx-audio]', runAudio);
  bindAll('[data-dx-one-audio]', function () { runOneAudio(this.getAttribute('data-slot')); });
  bindAll('[data-dx-all]', async function () {
    var browser = document.getElementById('browser');
    function jumpBrowser() {
      if (!browser || !browser.scrollIntoView) return;
      try { browser.scrollIntoView({ behavior: 'auto', block: 'start' }); } catch (e) { window.scrollTo(0, browser.offsetTop - 70); }
    }
    jumpBrowser();
    window.setTimeout(jumpBrowser, 80);
    pageLines = []; audioLines = []; browserCounts = { ok: 0, warn: 0, fail: 0 }; setSum();
    await runPages();
    await runAudio();
  });

  bindAll('[data-dx-copy]', function () {
    var text = window.DX_SERVER_REPORT + '\n\n【页面体检】\n' + pageOut.textContent + '\n\n【音频测试】\n' + audioOut.textContent;
    var done = function () { if (window.SG_TOAST) window.SG_TOAST('诊断报告已复制'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else { fallback(); }
    function fallback() {
      var t = document.createElement('textarea');
      t.value = text;
      document.body.appendChild(t);
      t.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      t.remove();
    }
  });
})();
</script>

<?php sg_admin_foot(); ?>
