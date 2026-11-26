<?php
/**
 * 食光菜谱 · 应用引导
 * 所有前台 / 后台页面都会引入本文件
 */

define('SG_ROOT', dirname(__DIR__));
define('SG_VERSION', '1.23.58');

/* 站点 Web 根路径（例如 /shiguang），部署在根目录时为空 */
$sgRootReal = rtrim(str_replace('\\', '/', (string) realpath(SG_ROOT)), '/');
$sgDocRoot  = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$sgBasePath = '';

/* 优先用“当前脚本路径”反推站点根，避免 DOCUMENT_ROOT 与实际目录不一致（软链/面板/子目录）时算错 */
$sgScript     = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$sgScriptFile = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($sgScriptFile !== '' && $sgRootReal !== '' && strpos($sgScriptFile, $sgRootReal . '/') === 0) {
    $sgRel = substr($sgScriptFile, strlen($sgRootReal));
    if ($sgRel !== '' && substr($sgScript, -strlen($sgRel)) === $sgRel) {
        $sgBasePath = substr($sgScript, 0, strlen($sgScript) - strlen($sgRel));
    }
}
if ($sgBasePath === '' && $sgDocRoot !== '' && $sgRootReal !== '' && strpos($sgRootReal, $sgDocRoot) === 0) {
    $sgBasePath = substr($sgRootReal, strlen($sgDocRoot));
}
$sgBasePath = rtrim($sgBasePath, '/');
define('SG_WEB_BASE', rtrim('/' . ltrim($sgBasePath, '/'), '/'));
$GLOBALS['SG_BASE'] = SG_WEB_BASE . '/';

$configFile = SG_ROOT . '/config/config.php';
$lockFile   = SG_ROOT . '/config/install.lock';

// 未安装：跳转到安装程序
if (!is_file($configFile)) {
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/install/') === false) {
        header('Location: ' . SG_WEB_BASE . '/install/index.php');
        exit;
    }
}

$SG_CFG = is_file($configFile) ? require $configFile : [];

date_default_timezone_set($SG_CFG['app']['timezone'] ?? 'Asia/Shanghai');

/* 数据备份目录：部分主机解压后不会保留空目录，这里补建一次；失败时后台会提示权限 */
$SG_BACKUP_DIR = SG_ROOT . '/config/backup';
if (!is_dir($SG_BACKUP_DIR)) { @mkdir($SG_BACKUP_DIR, 0755, true); }

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('sg_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once SG_ROOT . '/inc/functions.php';
require_once SG_ROOT . '/inc/downloads.php';
require_once SG_ROOT . '/inc/security.php';
require_once SG_ROOT . '/inc/ai.php';
require_once SG_ROOT . '/inc/sync.php';
require_once SG_ROOT . '/inc/cooking-tips.php';

if (is_file(SG_ROOT . '/inc/layout.php')) {
    require_once SG_ROOT . '/inc/layout.php';
}

if (!empty($SG_CFG['db']['name'])) {
    try {
        db_connect($SG_CFG['db']);
        load_settings();
        /* v1.14.4：只迁移系统旧默认文案，之后以后台填写为准 */
        $sgLegacyUpdateTexts = [
            '今日推荐会显示点赞最高的厨友评价。',
            '云端同步已支持 InfinityFree 自动验证；版本更新内容可在后台填写。',
            '云端同步已支持 InfinityFree 自动验证；首页搜索框移到标题上方；版本号和更新内容可在后台填写。',
            '版本更新可点开查看历史记录；公告、推荐、更新、评论都能点击；公告支持自定义链接。',
            '版本更新记录页补全 v1.4.0 起的历史版本；后台仪表盘操作列取消固定；源码包继续为空内容基线。',
            '一键清空全部内容不再清空版本号和更新记录；版本历史继续保留。',
            '评论审核空状态去掉内层虚线框，只保留外层白色卡片；版本记录仍不受一键清空影响。',
            '一个步骤可添加最多 6 张照片；本步用时字段补上标签；评论空状态仍为单层卡片。',
            '每个步骤最多 9 张照片，支持 ← → 调整顺序；默认按上传顺序，不按内容自动排序。',
            '每个步骤最多 9 张照片，可用 ← → 调整顺序；本步用时单独填分钟，火候另填。',
            '每个步骤最多 9 张照片，可用 ← → 调整顺序；本步用时单独填分钟；手机端保存按钮不再重复。',
            '后台 AI 设置填好服务地址后，可一键读取该地址里已有的模型名，不用再手动猜写。',
            '分类图标支持在线搜索：输入“火锅、汤、蛋糕”等词，点一下就能用，不用自己下载图片。',
        ];
        if (in_array(setting('home_update_text', ''), $sgLegacyUpdateTexts, true)) {
            setting_set('home_update_text', '首页空数据时补上空状态卡片，不再只剩标题和一大片空白。');
        }
        sg_ratings_ensure();
        sg_comment_likes_ensure();
        sg_recipe_steps_images_ensure();
        sg_security_schema_ensure();
        sg_app_downloads_ensure();
    } catch (Throwable $ex) {
        $GLOBALS['SG_DB_ERROR'] = $ex->getMessage();
        if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/install/') === false) {
            http_response_code(500);
            echo '<!doctype html><meta charset="utf-8"><div style="font-family:system-ui;padding:40px;line-height:1.8">'
                . '<h2 style="color:#A63C1C">数据库连接或初始化失败</h2>'
                . '<p>' . htmlspecialchars($ex->getMessage(), ENT_QUOTES) . '</p>'
                . '<p>配置：<b>' . htmlspecialchars(($SG_CFG['db']['host'] ?? '?') . ':' . ($SG_CFG['db']['port'] ?? 3306), ENT_QUOTES) . '</b></p>'
                . (!empty($GLOBALS['SG_DB_TRIED']) ? '<p>已尝试：' . htmlspecialchars(implode('、', $GLOBALS['SG_DB_TRIED']), ENT_QUOTES) . '</p>' : '')
                . '<p>请检查 <b>config/config.php</b> 或重新运行 <a href="' . SG_WEB_BASE . '/install/index.php">安装程序</a>。</p></div>';
            exit;
        }
    }
}
