<?php
/**
 * 欢迎音频输出：URL 只用 ASCII 的 slot 参数，由 PHP 读文件再流式输出，
 * 避免中文文件名在部分服务器上被 URL 编码后 404；也避免音频地址被直接猜到。
 */
require_once __DIR__ . '/inc/bootstrap.php';

if (!empty($GLOBALS['SG_DB_ERROR'])) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('database unavailable');
}
$slot = (isset($_GET['slot']) && $_GET['slot'] === 'member') ? 'member' : 'guest';
/* 未登录的人不能直接取“登录后”的音频 */
if ($slot === 'member' && !current_user() && !current_admin()) {
    $slot = 'guest';
}
$st   = audio_slot_state($slot);

/* 后台诊断：/audio.php?slot=guest&info=1 返回当前绑定与解析结果（仅管理员） */
if (isset($_GET['info'])) {
    if (!current_admin()) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'slot'       => $slot,
        'enabled'    => $st['enabled'],
        'configured' => $st['file'],
        'fileExists' => $st['ok'],
        'resolved'   => welcome_audio_for_slot($slot),
        'guess'      => welcome_audio_slot_guess($slot),
        'version'    => SG_VERSION,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* 后台试听：即使这条未启用也能听（仅管理员） */
$preview = isset($_GET['preview']) && current_admin();
if ($preview) {
    $file = $st['ok'] ? $st['file'] : welcome_audio_slot_guess($slot);
    $file = audio_refresh_builtin($file, $slot);
    if ($file === '' || !audio_path_ok($file)) {
        $file = audio_builtin_default($slot);
    }
} else {
    $file = welcome_audio_for_slot($slot);
}

if ($file === '' || !audio_path_ok($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'welcome audio not configured';
    exit;
}

$full  = SG_ROOT . '/' . $file;
$size  = (int) filesize($full);
$mtime = (int) filemtime($full);
$etag  = '"' . md5($file . '|' . $mtime . '|' . $size) . '"';

session_write_close();

header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=0, must-revalidate');
header('ETag: ' . $etag);

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

$start = 0;
$end   = $size - 1;
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
    $start = (int) $m[1];
    if ($m[2] !== '') {
        $end = (int) $m[2];
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$mime = audio_file_mime($full);
header('Content-Type: ' . $mime);
header('Content-Length: ' . ($end - $start + 1));
header('Content-Disposition: inline; filename="welcome-' . $slot . ($mime === 'audio/mp4' ? '.m4a' : '.mp3') . '"');

while (ob_get_level() > 0) {
    ob_end_clean();
}
$fp = fopen($full, 'rb');
if (!$fp) {
    http_response_code(500);
    exit;
}
fseek($fp, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fp)) {
    $chunk = fread($fp, (int) min(8192, $left));
    if ($chunk === '' || $chunk === false) {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fp);
