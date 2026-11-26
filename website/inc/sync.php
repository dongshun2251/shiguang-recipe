<?php
/**
 * 食光菜谱 · 本地 → 云端 单向同步
 * 只同步分类、图标、食材图库、菜谱及其图片；
 * 不同步用户、评论、收藏和站点设置。
 */

/** 把用户填的云端地址补成 /api/sync.php */
function sync_endpoint(string $base): string
{
    $base = rtrim(trim($base), '/');
    if ($base === '') {
        return '';
    }
    if (str_contains($base, '/api/sync.php')) {
        return $base;
    }
    $base = preg_replace('#/admin/sync\.php$#', '', $base);
    $base = rtrim($base, '/');
    return $base . '/api/sync.php';
}

/** 从同步接口地址还原云站根地址 */
function sync_cloud_base(string $url): string
{
    $url = trim($url);
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $path = (string) ($p['path'] ?? '');
    $path = preg_replace('#/api/sync\.php$#', '', $path);
    $path = preg_replace('#/admin/sync\.php$#', '', $path);
    return rtrim($base . '/' . ltrim($path, '/'), '/');
}

/** InfinityFree 的 slowAES 验证：GET 挑战页，本地算出 __test Cookie */
function sync_infinityfree_cookie(string $base, bool $force = false): string
{
    static $cache = [];
    if ($base === '' || !function_exists('openssl_decrypt')) {
        return '';
    }
    if (!$force) {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['sg_if_challenge'][$base]) && (int) $_SESSION['sg_if_challenge'][$base]['until'] > time()) {
            return (string) $_SESSION['sg_if_challenge'][$base]['cookie'];
        }
        if (isset($cache[$base]) && $cache[$base]['until'] > time()) {
            return $cache[$base]['cookie'];
        }
    }
    $ch = curl_init(rtrim($base, '/') . '/api/auth.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Shiguang-CloudSync/1.0',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!is_string($html) || $html === '') {
        return '';
    }
    if (!preg_match('/a=toNumbers\\("([0-9a-f]{32})"\\),b=toNumbers\\("([0-9a-f]{32})"\\),c=toNumbers\\("([0-9a-f]{32})"\\)/i', $html, $m)) {
        return '';
    }
    $iv = hex2bin($m[2]);
    $key = hex2bin($m[1]);
    $cipher = hex2bin($m[3]);
    $raw = openssl_decrypt($cipher, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($raw === false || $raw === '') {
        return '';
    }
    $cookie = '__test=' . bin2hex($raw);
    $expires = time() + 3000;
    $cache[$base] = ['cookie' => $cookie, 'until' => $expires];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['sg_if_challenge'][$base] = ['cookie' => $cookie, 'until' => $expires];
    }
    return $cookie;
}

/** 判断云端返回的是不是 InfinityFree 的验证页 */
function sync_is_challenge(string $raw): bool
{
    return strpos($raw, 'toNumbers') !== false || strpos($raw, '__test=') !== false || strpos($raw, '/aes.js') !== false;
}

/** 同步时统一用 slug 作为菜谱/分类的稳定键 */
function sync_make_slug(string $text, string $fallback = 'item'): string
{
    $slug = function_exists('slugify') ? trim(slugify($text)) : '';
    if ($slug === '') {
        $slug = $fallback . '-' . substr(md5($text), 0, 8);
    }
    return mb_substr($slug, 0, 64);
}

/** 本地图片转成可传的 base64 结构；远程 URL 直接返回 url */
function sync_image_pack(string $path): ?array
{
    $path = trim($path);
    if ($path === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $path)) {
        return ['url' => $path];
    }
    $file = SG_ROOT . '/' . ltrim($path, '/');
    if (!is_file($file)) {
        return null;
    }
    $size = filesize($file);
    if ($size === false || $size <= 0 || $size > 8 * 1024 * 1024) {
        return null;
    }
    $bin = file_get_contents($file);
    if ($bin === false) {
        return null;
    }
    return ['name' => basename($file), 'b64' => base64_encode($bin)];
}

/** 云端保存同步图片，按内容哈希去重，返回相对路径 */
function sync_image_store(?array $img, string $subDir = 'sync'): string
{
    if (!$img) {
        return '';
    }
    if (!empty($img['url']) && preg_match('#^https?://#i', (string) $img['url'])) {
        return (string) $img['url'];
    }
    $b64 = (string) ($img['b64'] ?? '');
    if ($b64 === '') {
        return '';
    }
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '' || strlen($bin) > 8 * 1024 * 1024) {
        return '';
    }
    $ext = 'png';
    $info = @getimagesizefromstring($bin);
    if ($info && !empty($info['mime'])) {
        $map = ['image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/png' => 'png'];
        $ext = $map[$info['mime']] ?? 'png';
    }
    $dir = SG_ROOT . '/uploads/' . $subDir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return '';
    }
    $name = sha1($bin) . '.' . $ext;
    $file = $dir . '/' . $name;
    if (!is_file($file)) {
        if (file_put_contents($file, $bin) === false) {
            return '';
        }
    }
    return 'uploads/' . $subDir . '/' . $name;
}

/** 本地/云端之间 POST JSON */
function sync_post_json(string $url, array $payload, string $token, int $timeout = 8): array
{
    if (!function_exists('curl_init')) {
        return [false, null, '服务器没有启用 curl 扩展'];
    }
    $base = sync_cloud_base($url);
    $cookie = sync_infinityfree_cookie($base);
    $postUrl = $url . (strpos($url, '?') === false ? '?i=1' : '&i=1');
    $raw = '';
    $code = 0;
    $ctype = '';
    $err = '';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $headers = ['Content-Type: application/json', 'X-SG-Sync-Token: ' . $token];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        $ch = curl_init($postUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Shiguang-CloudSync/1.0',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return [false, null, '连不上云端：' . ($err ?: '超时')];
        }
        if (!is_array(json_decode((string) $raw, true)) && sync_is_challenge((string) $raw) && $attempt === 0) {
            $cookie = sync_infinityfree_cookie($base, true);
            if ($cookie !== '') {
                continue;
            }
        }
        break;
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $raw)));
        if (sync_is_challenge((string) $raw)) {
            $msg = '云端仍被 InfinityFree 的浏览器验证拦截：自动验证没有通过（HTTP ' . $code . '）。请重试一次；仍不行再换支持 API 的云端主机。';
        } else {
            $msg = '云端返回的不是 JSON（HTTP ' . $code . ($ctype !== '' ? ' · ' . $ctype : '') . '）';
            if ($plain !== '') { $msg .= '：' . mb_substr($plain, 0, 120); }
        }
        $data = ['ok' => false, 'msg' => $msg];
    }
    if ($code < 200 || $code >= 300 || empty($data['ok'])) {
        return [false, $data, (string) ($data['msg'] ?? ('HTTP ' . $code))];
    }
    return [true, $data, ''];
}
