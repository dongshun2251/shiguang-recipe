<?php
/**
 * 食光菜谱 · App 下载
 * 下载卡片存数据库，后台可以上传安装包或填写任意 http(s) 链接。
 */

/** 老站不跑 upgrade.php 也会自动补表 */
function sg_app_downloads_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    q('CREATE TABLE IF NOT EXISTS `' . T('app_downloads') . '` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `platform` VARCHAR(16) NOT NULL DEFAULT \'other\',
        `title` VARCHAR(80) NOT NULL,
        `ver` VARCHAR(32) NOT NULL DEFAULT \'\',
        `note` VARCHAR(255) NOT NULL DEFAULT \'\',
        `url` TEXT NOT NULL,
        `app_link` VARCHAR(255) NOT NULL DEFAULT \'\',
        `size` VARCHAR(32) NOT NULL DEFAULT \'\',
        `sort` INT NOT NULL DEFAULT 0,
        `status` TINYINT NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_dl_order` (`status`, `sort`, `id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $appLinkAdded = false;
    if (!sg_has_column('app_downloads', 'app_link')) {
        try {
            q('ALTER TABLE `' . T('app_downloads') . '` ADD COLUMN `app_link` VARCHAR(255) NOT NULL DEFAULT \'\' AFTER `url`');
            $appLinkAdded = true;
        } catch (Throwable $e) {
            // 并发请求可能已经加过；只忽略重复列，其他错误继续抛出
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }
    if ($appLinkAdded) {
        /* 老站已有的 Android / Windows 卡片自动补上打开链接，旧版 App 不支持时会自动转到下载 */
        q('UPDATE `' . T('app_downloads') . '` SET `app_link` = \'shiguang://open\' WHERE `app_link` = \'\' AND `platform` IN (\'android\', \'windows\')');
    }
    $done = true;
}

/** 平台显示信息：名称、短标签、提示、图标 */
function sg_app_platform_meta(string $platform): array
{
    $map = [
        'android' => ['label' => 'Android 手机', 'short' => 'ANDROID', 'hint' => 'APK 安装包', 'icon' => 'down'],
        'tablet'  => ['label' => 'Android 平板', 'short' => 'TABLET', 'hint' => '平板 APK', 'icon' => 'tablet'],
        'windows' => ['label' => 'Windows 电脑', 'short' => 'WINDOWS', 'hint' => 'Windows 10 / 11', 'icon' => 'box'],
        'ios'     => ['label' => 'iPhone / iPad', 'short' => 'IOS', 'hint' => 'TestFlight / App Store', 'icon' => 'down'],
        'mac'     => ['label' => 'Mac 电脑', 'short' => 'MACOS', 'hint' => 'DMG 安装包', 'icon' => 'box'],
        'web'     => ['label' => '网页版', 'short' => 'WEB', 'hint' => '浏览器直接打开', 'icon' => 'expand'],
        'other'   => ['label' => '其他下载', 'short' => 'OTHER', 'hint' => '外部链接', 'icon' => 'down'],
    ];
    return $map[$platform] ?? $map['other'];
}

/** 读取下载卡片；$onlyOnline=true 只取启用的 */
function sg_app_downloads(bool $onlyOnline = true): array
{
    sg_app_downloads_ensure();
    $sql = 'SELECT * FROM `' . T('app_downloads') . '`';
    if ($onlyOnline) {
        $sql .= ' WHERE status = 1';
    }
    $sql .= ' ORDER BY sort ASC, id ASC';
    return fetch_all($sql);
}

/** 生成可点击地址；支持 http(s)、站内 uploads/...、站内 /... */
function sg_app_download_href(string $url): string
{
    $url = trim($url);
    if ($url === '') { return ''; }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    return url($url);
}

/** 格式化字节 */
function sg_app_size_text(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
    }
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return max(1, $bytes) . ' B';
}

/** 上传安装包到 uploads/apps；只允许安装包/压缩包，不允许脚本 */
function upload_app_package(array $file): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('文件超过服务器上传上限（当前 upload_max_filesize=' . ini_get('upload_max_filesize') . '），请把安装包放到网盘，然后填写外链');
    }
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        throw new RuntimeException('安装包上传失败，请重试，或改用外链');
    }
    if ((int) ($file['size'] ?? 0) <= 0) {
        throw new RuntimeException('这个文件是空的');
    }
    if ((int) $file['size'] > 256 * 1024 * 1024) {
        throw new RuntimeException('安装包不能超过 256 MB；更大的文件请使用网盘外链');
    }

    $original = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['apk', 'exe', 'msi', 'zip', 'dmg', 'ipa', 'pkg'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('只支持 ' . implode(' / ', $allowed) . ' 安装包；网页、脚本等文件不能直接上传');
    }
    /* InfinityFree 免费主机明确禁止 .apk / .exe 文件，上传完成后会被主机自动删除。
       这两种安装包必须放到网盘/CDN，再把直链填进“下载链接”，不要走本站上传。 */
    if (in_array($ext, ['apk', 'exe'], true)) {
        throw new RuntimeException('InfinityFree 免费主机禁止 .apk / .exe 文件，上传后会被自动删除。请把安装包放到网盘或 CDN，把直链填到“下载链接”里。');
    }

    $dir = SG_ROOT . '/uploads/apps';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('上传目录不可写，请检查 uploads/apps 权限');
    }
    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME));
    $safeBase = trim($safeBase, '-_.');
    if ($safeBase === '') { $safeBase = 'shiguang-app'; }
    $safeBase = mb_substr($safeBase, 0, 48);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '-' . $safeBase . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('保存安装包失败，请检查 uploads/apps 权限');
    }
    @chmod($dir . '/' . $name, 0644);

    return [
        'url'  => 'uploads/apps/' . $name,
        'size' => sg_app_size_text((int) $file['size']),
    ];
}

/** 删除上传到本站的安装包；外链不动 */
function sg_app_package_delete(string $url): void
{
    $url = trim($url);
    if (strpos($url, 'uploads/apps/') !== 0) { return; }
    $path = SG_ROOT . '/' . $url;
    if (is_file($path)) { @unlink($path); }
}
