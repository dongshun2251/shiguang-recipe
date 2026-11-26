<?php
/**
 * 源码更新核心：解压校验、代码备份、覆盖文件。
 * 只被 admin/update.php 使用；不执行任何数据库操作。
 */
if (!defined('SG_ROOT')) { exit; }

function up_version_of(string $file): string
{
    $src = @file_get_contents($file);
    if ($src !== false && preg_match("/define\\('SG_VERSION'\\s*,\\s*'([^']+)'\\)/", $src, $m)) {
        return $m[1];
    }
    return '';
}

function up_zip_name(string $version): string
{
    return 'code-v' . preg_replace('/[^0-9A-Za-z._-]/', '', $version) . '-' . date('Ymd-His') . '.zip';
}

/** 把当前代码打包；排除 config、uploads、shots，避免把用户数据和密钥打进代码备份 */
function up_backup_code(string $dir, string $version): string
{
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('config/backup 目录不可写，无法先备份当前代码');
    }
    $file = $dir . '/' . up_zip_name($version);
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('无法创建代码备份 ZIP');
    }
    $root = SG_ROOT;
    $skipTop = ['config', 'uploads', 'shots', '.git', 'node_modules'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $count = 0;
    foreach ($it as $path => $info) {
        if (!$info->isFile()) { continue; }
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $top = explode('/', $rel)[0];
        if (in_array($top, $skipTop, true)) { continue; }
        if ($zip->addFile($path, $rel) === false) {
            $zip->close();
            @unlink($file);
            throw new RuntimeException('代码备份写入失败：' . $rel);
        }
        $count++;
    }
    $zip->close();
    if ($count === 0) { @unlink($file); throw new RuntimeException('没有可备份的代码文件'); }
    return basename($file);
}

/** 把重定向里的相对地址拼成绝对地址 */
function up_url_join(string $base, string $rel): string
{
    $rel = trim($rel);
    if (preg_match('#^https?://#i', $rel)) { return $rel; }
    $b = parse_url($base);
    if (!$b || empty($b['host'])) { return $rel; }
    $scheme = $b['scheme'] ?? 'https';
    $host   = $b['host'];
    $port   = isset($b['port']) ? ':' . (int) $b['port'] : '';
    if (strpos($rel, '//') === 0) { return $scheme . ':' . $rel; }
    $path = $b['path'] ?? '/';
    if ($rel !== '' && $rel[0] === '?') { return $scheme . '://' . $host . $port . $path . $rel; }
    if ($rel !== '' && $rel[0] === '/') { return $scheme . '://' . $host . $port . $rel; }
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return $scheme . '://' . $host . $port . $dir . $rel;
}

/**
 * 下载远程 ZIP 到临时文件；只允许 http(s)，限制体积。
 * 共享主机常因 open_basedir 禁用 CURLOPT_FOLLOWLOCATION，
 * 所以这里手动处理 301/302/303/307/308，不依赖 curl 自动跟随。
 */
function up_fetch_zip(string $url, string $dest): void
{
    if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
        throw new RuntimeException('服务器不支持远程下载，请改为上传 ZIP 文件');
    }
    $fp = fopen($dest, 'wb');
    if (!$fp) { throw new RuntimeException('临时文件不可写'); }

    $current = $url;
    $maxHops = 6;
    $ok = false;
    $lastCode = 0;
    $lastError = '';

    for ($hop = 0; $hop <= $maxHops; $hop++) {
        $location = '';
        $ch = curl_init($current);
        $opts = [
            CURLOPT_FILE => $fp,
            /* 不依赖自动跟随：InfinityFree 这类主机开了 open_basedir 后该选项会失效 */
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_MAXFILESIZE => 20 * 1024 * 1024,
            CURLOPT_USERAGENT => 'Shiguang-Updater/1.0',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
                $t = trim($line);
                if (stripos($t, 'location:') === 0) {
                    $location = trim(substr($t, 9));
                }
                return strlen($line);
            },
        ];
        curl_setopt_array($ch, $opts);
        $exec = curl_exec($ch);
        $lastCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $lastError = (string) curl_error($ch);
        curl_close($ch);

        if ($exec === false) {
            break;
        }

        if (in_array($lastCode, [301, 302, 303, 307, 308], true) && $location !== '') {
            if ($hop >= $maxHops) {
                $lastError = '重定向次数过多';
                break;
            }
            $current = up_url_join($current, $location);
            ftruncate($fp, 0);
            rewind($fp);
            continue;
        }

        if ($lastCode >= 200 && $lastCode < 300) {
            $ok = true;
        }
        break;
    }

    fclose($fp);

    if (!$ok) {
        @unlink($dest);
        $detail = $lastCode > 0 ? 'HTTP ' . $lastCode : '网络错误';
        if ($lastError !== '') { $detail .= ' · ' . $lastError; }
        throw new RuntimeException('远程下载失败（' . $detail . '）');
    }
    if (!is_file($dest) || filesize($dest) <= 0) {
        @unlink($dest);
        throw new RuntimeException('远程下载失败（文件为空）');
    }
    if (filesize($dest) > 20 * 1024 * 1024) {
        @unlink($dest);
        throw new RuntimeException('源码包超过 20 MB，已拒绝');
    }
}

/**
 * 解压 ZIP 到 staging；只接受普通文件，拒绝 ../ 和绝对路径。
 * 返回 staging 目录、文件数量和包内版本。
 */
function up_stage_zip(string $zipFile, string $stage): array
{
    if (!class_exists('ZipArchive')) { throw new RuntimeException('PHP 未启用 ZipArchive 扩展'); }
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) { throw new RuntimeException('不是有效的 ZIP 源码包'); }

    $prefix = '';
    $hasRoot = false;
    $hasShiguang = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if ($name === 'index.php' || $name === 'inc/bootstrap.php') { $hasRoot = true; }
        if ($name === 'shiguang/index.php' || $name === 'shiguang/inc/bootstrap.php') { $hasShiguang = true; }
    }
    if ($hasShiguang) { $prefix = 'shiguang/'; }
    if (!$hasRoot && !$hasShiguang) {
        $zip->close();
        throw new RuntimeException('源码包里找不到 index.php / inc/bootstrap.php');
    }

    if ($zip->numFiles > 5000) {
        $zip->close();
        throw new RuntimeException('源码包文件数量过多，已拒绝');
    }
    $rawBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $rawBytes += (int) ($stat['size'] ?? 0);
        if ($rawBytes > 200 * 1024 * 1024) {
            $zip->close();
            throw new RuntimeException('源码包解压后超过 200 MB，已拒绝');
        }
    }

    @mkdir($stage, 0755, true);
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $raw = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if ($raw === '' || substr($raw, -1) === '/') { continue; }
        $raw = ltrim($raw, '/');
        if (strpos($raw, "\0") !== false) { $zip->close(); throw new RuntimeException('ZIP 内含非法文件名'); }
        $parts = explode('/', $raw);
        if (in_array('..', $parts, true) || in_array('.', $parts, true)) {
            $zip->close();
            throw new RuntimeException('ZIP 内含路径穿越文件');
        }
        if ($prefix !== '' && strpos($raw, $prefix) !== 0) { continue; }
        $rel = $prefix === '' ? $raw : substr($raw, strlen($prefix));
        if ($rel === '') { continue; }
        $top = explode('/', $rel)[0];
        if ($top === 'config' && $rel !== 'config/.htaccess') { continue; }
        if (in_array($top, ['shots', '.git', 'node_modules'], true)) { continue; }
        if ($top === 'uploads') {
            $base = basename($rel);
            if (!in_array($base, ['.htaccess', 'index.html'], true)) { continue; }
        }
        $out = $stage . '/' . $rel;
        if (!is_dir(dirname($out)) && !@mkdir(dirname($out), 0755, true) && !is_dir(dirname($out))) {
            $zip->close();
            throw new RuntimeException('无法创建临时目录：' . $rel);
        }
        $stream = $zip->getStream($raw);
        if (!$stream) { continue; }
        $fp = fopen($out, 'wb');
        if (!$fp) { fclose($stream); $zip->close(); throw new RuntimeException('临时文件不可写：' . $rel); }
        stream_copy_to_stream($stream, $fp);
        fclose($fp);
        fclose($stream);
        $count++;
    }
    $zip->close();

    foreach (['index.php', 'inc/bootstrap.php', 'admin', 'assets'] as $need) {
        if (!file_exists($stage . '/' . $need)) {
            throw new RuntimeException('源码包缺少必要文件：' . $need);
        }
    }
    $version = up_version_of($stage . '/inc/bootstrap.php');
    if ($version === '') { throw new RuntimeException('新版 inc/bootstrap.php 里没有 SG_VERSION'); }
    return ['stage' => $stage, 'count' => $count, 'version' => $version];
}

/** 只覆盖代码；config 永远不碰，uploads 只放入 .htaccess / index.html */
function up_apply_tree(string $stage): int
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $count = 0;
    foreach ($it as $path => $info) {
        if (!$info->isFile()) { continue; }
        $rel = str_replace('\\', '/', substr($path, strlen($stage) + 1));
        if (strpos($rel, 'config/') === 0 && $rel !== 'config/.htaccess') { continue; }
        $dest = SG_ROOT . '/' . $rel;
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建目录：' . $rel);
        }
        $tmp = $dest . '.sgupdate.tmp';
        if (!@copy($path, $tmp)) { throw new RuntimeException('无法写入：' . $rel); }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new RuntimeException('无法替换文件：' . $rel);
        }
        $count++;
    }
    return $count;
}
