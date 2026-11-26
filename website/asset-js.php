<?php
/* 静态 JS 代理：InfinityFree 会给 /assets/*.js 返回人机验证 HTML，
   这里用 PHP 读出同一份文件并返回正确的 JavaScript MIME，绕过该拦截。 */
$allowed = [
    'app.js'       => 'assets/app.js',
    'jsQR.js'      => 'assets/jsQR.js',
    'qrcode.min.js'=> 'assets/qrcode.min.js',
];
$name = isset($_GET['f']) ? (string) $_GET['f'] : '';
if (!isset($allowed[$name])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
$file = __DIR__ . '/' . $allowed[$name];
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
header('Content-Type: application/javascript; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . sha1($name . '|' . filemtime($file) . '|' . filesize($file)) . '"');
readfile($file);
