<?php
/**
 * 账号安全：TOTP、恢复码、客服工单与审计。
 * 不依赖邮箱，所有令牌都按一次性凭证处理。
 */

function sg_security_schema_ensure(): void
{
    if (!empty($GLOBALS['SG_SECURITY_SCHEMA_OK'])) return;
    if (setting('security_schema_v2', '') === '1') {
        $GLOBALS['SG_SECURITY_SCHEMA_OK'] = true;
        return;
    }
    try {
        q('CREATE TABLE IF NOT EXISTS `' . T('user_totp') . '` (
            `user_id` INT UNSIGNED NOT NULL,
            `secret` VARCHAR(64) NOT NULL,
            `enabled` TINYINT NOT NULL DEFAULT 0,
            `last_step` BIGINT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS `' . T('recovery_codes') . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `code_hash` VARCHAR(255) NOT NULL,
            `kind` VARCHAR(16) NOT NULL DEFAULT \'recovery\',
            `expires_at` DATETIME NULL,
            `used_at` DATETIME NULL,
            `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_recovery_user` (`user_id`, `kind`, `used_at`),
            KEY `idx_recovery_expire` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS `' . T('support_tickets') . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ticket_no` VARCHAR(32) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `username` VARCHAR(64) NOT NULL DEFAULT \'\',
            `contact` VARCHAR(255) NOT NULL DEFAULT \'\',
            `subject` VARCHAR(120) NOT NULL DEFAULT \'\',
            `body` TEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT \'open\',
            `reply` TEXT NULL,
            `admin_note` TEXT NULL,
            `handled_by` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_ticket_no` (`ticket_no`),
            KEY `idx_ticket_status` (`status`, `created_at`),
            KEY `idx_ticket_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS `' . T('admin_audit') . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `action` VARCHAR(40) NOT NULL,
            `target` VARCHAR(80) NOT NULL DEFAULT \'\',
            `detail` TEXT NULL,
            `ip` VARCHAR(45) NOT NULL DEFAULT \'\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_audit_time` (`created_at`),
            KEY `idx_audit_admin` (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS `' . T('auth_throttle') . '` (
            `bucket` CHAR(64) NOT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `first_at` DATETIME NOT NULL,
            `blocked_until` DATETIME NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`bucket`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS `' . T('user_sessions') . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `device_name` VARCHAR(120) NOT NULL DEFAULT \'\',
            `user_agent` VARCHAR(255) NOT NULL DEFAULT \'\',
            `ip` VARCHAR(45) NOT NULL DEFAULT \'\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `revoked_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_session_token` (`token_hash`),
            KEY `idx_session_user` (`user_id`, `revoked_at`, `last_seen_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        q('CREATE TABLE IF NOT EXISTS `' . T('qr_logins') . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `token_hash` CHAR(64) NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `requester_ip` VARCHAR(45) NOT NULL DEFAULT \'\',
            `requester_ua` VARCHAR(255) NOT NULL DEFAULT \'\',
            `device_name` VARCHAR(120) NOT NULL DEFAULT \'\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            `approved_at` DATETIME NULL,
            `consumed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_qr_token` (`token_hash`),
            KEY `idx_qr_status` (`status`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        setting_set('security_schema_v1', '1');
        setting_set('security_schema_v2', '1');
        $GLOBALS['SG_SECURITY_SCHEMA_OK'] = true;
    } catch (Throwable $e) {
        $GLOBALS['SG_SECURITY_SCHEMA_ERROR'] = $e->getMessage();
    }
}

function sg_security_ready(): bool
{
    return !empty($GLOBALS['SG_SECURITY_SCHEMA_OK']);
}

/* ---------- Base32 / TOTP（RFC 4648 + RFC 6238） ---------- */

function sg_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $bits = 0;
    $value = 0;
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $value = ($value << 8) | ord($data[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $out .= $alphabet[($value >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    if ($bits > 0) {
        $out .= $alphabet[($value << (5 - $bits)) & 31];
    }
    return $out;
}

function sg_base32_decode(string $code): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $code = strtoupper(preg_replace('/[^A-Z2-7]/', '', $code));
    $out = '';
    $bits = 0;
    $value = 0;
    for ($i = 0, $n = strlen($code); $i < $n; $i++) {
        $pos = strpos($alphabet, $code[$i]);
        if ($pos === false) continue;
        $value = ($value << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $out .= chr(($value >> ($bits - 8)) & 255);
            $bits -= 8;
        }
    }
    return $out;
}

function sg_totp_code(string $secret, ?int $time = null): string
{
    $key = sg_base32_decode($secret);
    if ($key === '') return '';
    $step = (int) floor(($time ?? time()) / 30);
    $hash = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $code = ((ord($hash[$offset]) & 0x7f) << 24)
          | ((ord($hash[$offset + 1]) & 0xff) << 16)
          | ((ord($hash[$offset + 2]) & 0xff) << 8)
          | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($code % 1000000), 6, '0', STR_PAD_LEFT);
}

function sg_totp_verify(string $secret, string $code, int $lastStep = 0): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') return null;
    $now = (int) floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        $step = $now + $i;
        if ($step <= $lastStep) continue;
        if (hash_equals(sg_totp_code($secret, $step * 30), $code)) {
            return $step;
        }
    }
    return null;
}

function sg_totp_uri(string $secret, string $username): string
{
    $site = setting('site_name', '食光菜谱');
    $label = $site . ':' . $username;
    return 'otpauth://totp/' . rawurlencode($label) . '?secret=' . rawurlencode($secret)
         . '&issuer=' . rawurlencode($site) . '&algorithm=SHA1&digits=6&period=30';
}

function sg_totp_row(int $uid): ?array
{
    if (!sg_security_ready()) return null;
    return fetch_one('SELECT * FROM `' . T('user_totp') . '` WHERE user_id = ?', [$uid]);
}

function sg_totp_enabled(int $uid): bool
{
    $row = sg_totp_row($uid);
    return $row && (int) $row['enabled'] === 1 && (string) $row['secret'] !== '';
}

function sg_totp_save(int $uid, string $secret, bool $enabled, int $lastStep = 0): void
{
    q('INSERT INTO `' . T('user_totp') . '` (user_id, secret, enabled, last_step)
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = VALUES(enabled), last_step = VALUES(last_step)',
       [$uid, $secret, $enabled ? 1 : 0, $lastStep]);
}

function sg_totp_touch_step(int $uid, int $step): void
{
    q('UPDATE `' . T('user_totp') . '` SET last_step = GREATEST(last_step, ?) WHERE user_id = ?', [$step, $uid]);
}

/* ---------- 一次性恢复码 / 管理员重置码 ---------- */

function sg_recovery_clean(string $code): string
{
    return strtoupper(preg_replace('/[^A-Z2-9]/', '', $code));
}

function sg_recovery_format(string $clean): string
{
    return substr($clean, 0, 5) . '-' . substr($clean, 5);
}

function sg_recovery_make(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $raw = '';
    for ($i = 0; $i < 10; $i++) {
        $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return sg_recovery_format($raw);
}

function sg_recovery_codes_generate(int $uid, int $count = 8, string $kind = 'recovery', int $ttlMinutes = 0, int $createdBy = 0): array
{
    if ($kind === 'recovery') {
        q('DELETE FROM `' . T('recovery_codes') . '` WHERE user_id = ? AND kind = ?', [$uid, 'recovery']);
    }
    $codes = [];
    $expires = $ttlMinutes > 0 ? date('Y-m-d H:i:s', time() + $ttlMinutes * 60) : null;
    for ($i = 0; $i < $count; $i++) {
        $plain = sg_recovery_make();
        $clean = sg_recovery_clean($plain);
        db_insert(T('recovery_codes'), [
            'user_id' => $uid,
            'code_hash' => password_hash($clean, PASSWORD_DEFAULT),
            'kind' => $kind,
            'expires_at' => $expires,
            'created_by' => $createdBy,
        ]);
        $codes[] = $plain;
    }
    return $codes;
}

function sg_recovery_code_find(int $uid, string $code, array $kinds = ['recovery']): ?array
{
    $clean = sg_recovery_clean($code);
    if (strlen($clean) < 8) return null;
    $kinds = array_values(array_intersect($kinds, ['recovery', 'admin']));
    if (!$kinds) return null;
    $in = implode(',', array_fill(0, count($kinds), '?'));
    $rows = fetch_all('SELECT * FROM `' . T('recovery_codes') . '`
        WHERE user_id = ? AND used_at IS NULL AND kind IN (' . $in . ')
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY id DESC LIMIT 20', array_merge([$uid], $kinds));
    foreach ($rows as $row) {
        if (password_verify($clean, $row['code_hash'])) {
            return $row;
        }
    }
    return null;
}

function sg_recovery_code_use(int $codeId): void
{
    db_update(T('recovery_codes'), ['used_at' => date('Y-m-d H:i:s')], 'id = ? AND used_at IS NULL', [$codeId]);
}

function sg_recovery_code_consume(int $uid, string $code, array $kinds = ['recovery']): ?array
{
    $row = sg_recovery_code_find($uid, $code, $kinds);
    if (!$row) return null;
    sg_recovery_code_use((int) $row['id']);
    return $row;
}

function sg_recovery_codes_left(int $uid): int
{
    if (!sg_security_ready()) return 0;
    return (int) fetch_val('SELECT COUNT(*) FROM `' . T('recovery_codes') . '`
        WHERE user_id = ? AND kind = ? AND used_at IS NULL
          AND (expires_at IS NULL OR expires_at > NOW())', [$uid, 'recovery'], 0);
}

/* ---------- 简单但可用的频率限制 ---------- */

function sg_throttle_bucket(string $scope, string $subject): string
{
    return hash('sha256', $scope . '|' . strtolower($subject) . '|' . client_ip());
}

function sg_throttle_allow(string $bucket, int $limit = 5, int $window = 600, int $block = 900): bool
{
    if (!sg_security_ready()) return true;
    $row = fetch_one('SELECT * FROM `' . T('auth_throttle') . '` WHERE bucket = ?', [$bucket]);
    if (!$row) return true;
    $now = time();
    if (!empty($row['blocked_until']) && strtotime($row['blocked_until']) > $now) return false;
    if (strtotime($row['first_at']) > $now - $window && (int) $row['attempts'] >= $limit) return false;
    return true;
}

function sg_throttle_fail(string $bucket, int $limit = 5, int $window = 600, int $block = 900): void
{
    if (!sg_security_ready()) return;
    $now = time();
    $row = fetch_one('SELECT * FROM `' . T('auth_throttle') . '` WHERE bucket = ?', [$bucket]);
    if (!$row || strtotime($row['first_at']) <= $now - $window) {
        $attempts = 1;
        $first = date('Y-m-d H:i:s');
    } else {
        $attempts = (int) $row['attempts'] + 1;
        $first = $row['first_at'];
    }
    $blocked = $attempts >= $limit ? date('Y-m-d H:i:s', $now + $block) : null;
    q('REPLACE INTO `' . T('auth_throttle') . '` (bucket, attempts, first_at, blocked_until, updated_at)
       VALUES (?, ?, ?, ?, NOW())', [$bucket, $attempts, $first, $blocked]);
}

function sg_throttle_clear(string $bucket): void
{
    if (!sg_security_ready()) return;
    q('DELETE FROM `' . T('auth_throttle') . '` WHERE bucket = ?', [$bucket]);
}

/* ---------- 管理员审计 ---------- */

function sg_admin_audit(string $action, string $target = '', string $detail = ''): void
{
    if (!sg_security_ready()) return;
    $a = current_admin();
    db_insert(T('admin_audit'), [
        'admin_id' => $a ? (int) $a['id'] : 0,
        'action' => $action,
        'target' => $target,
        'detail' => $detail,
        'ip' => client_ip(),
    ]);
}

/* ---------- 工单号 ---------- */

function sg_ticket_new_no(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $suffix = '';
        for ($i = 0; $i < 8; $i++) $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $no = 'SG' . date('ymd') . $suffix;
        $exists = fetch_one('SELECT id FROM `' . T('support_tickets') . '` WHERE ticket_no = ?', [$no]);
    } while ($exists);
    return $no;
}

function sg_safe_next(string $next, string $fallback = 'user.php'): string
{
    $next = trim($next);
    if ($next === '' || strpos($next, '//') !== false || strpos($next, ':') !== false) {
        return $fallback;
    }
    return $next;
}

/* ---------- 多设备登录会话 ---------- */

function sg_session_limit(): int
{
    $n = (int) setting('session_limit', '5');
    return max(1, min(20, $n));
}

function sg_device_name(?string $ua = null): string
{
    $ua = $ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return '未知设备';
    $browser = '浏览器';
    foreach ([
        'MicroMessenger/' => '微信', 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome',
        'Firefox/' => 'Firefox', 'Safari/' => 'Safari',
    ] as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $browser = $label; break; }
    }
    $os = '未知系统';
    foreach ([
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
        'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Linux' => 'Linux',
    ] as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $os = $label; break; }
    }
    return $browser . ' / ' . $os;
}

function sg_session_create(int $uid, ?string $deviceName = null, ?string $token = null): array
{
    $token = ($token !== null && $token !== '') ? $token : sg_new_token();
    $hash  = hash('sha256', $token);
    $ua    = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $device = $deviceName !== null && trim($deviceName) !== ''
        ? trim($deviceName)
        : sg_device_name($ua);
    if (mb_strlen($device) > 120) $device = mb_substr($device, 0, 120);

    $id = db_insert(T('user_sessions'), [
        'user_id'     => $uid,
        'token_hash'  => $hash,
        'device_name' => $device,
        'user_agent'  => $ua,
        'ip'          => substr(client_ip(), 0, 45),
    ]);

    /* 清理旧版单设备锁留下的 setting，避免升级后继续误判 */
    q('DELETE FROM `' . T('settings') . '` WHERE skey = ?', [sg_lock_key('u', $uid)]);

    /* 超过上限时，自动退出最久未使用的设备，给新设备让位 */
    $limit = sg_session_limit();
    $sessions = sg_sessions_active($uid);
    if (count($sessions) > $limit) {
        $extra = array_slice($sessions, $limit);
        foreach ($extra as $s) {
            if ((int) $s['id'] === (int) $id) continue;
            sg_session_revoke((int) $s['id'], $uid);
        }
    }
    return ['id' => (int) $id, 'token' => $token, 'device_name' => $device];
}

function sg_session_find(string $token, int $uid = 0): ?array
{
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $where = 'token_hash = ? AND revoked_at IS NULL';
    $params = [$hash];
    if ($uid > 0) {
        $where .= ' AND user_id = ?';
        $params[] = $uid;
    }
    return fetch_one('SELECT * FROM `' . T('user_sessions') . '` WHERE ' . $where . ' LIMIT 1', $params);
}

function sg_session_touch(int $sessionId, int $uid): void
{
    q('UPDATE `' . T('user_sessions') . '` SET last_seen_at = NOW()
       WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$sessionId, $uid]);
}

function sg_sessions_active(int $uid): array
{
    if (!sg_security_ready()) return [];
    return fetch_all('SELECT * FROM `' . T('user_sessions') . '`
        WHERE user_id = ? AND revoked_at IS NULL
        ORDER BY last_seen_at DESC, id DESC', [$uid]);
}

function sg_session_revoke(int $sessionId, int $uid): void
{
    q('UPDATE `' . T('user_sessions') . '` SET revoked_at = NOW()
       WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$sessionId, $uid]);
}

function sg_sessions_revoke_all(int $uid, ?int $exceptId = null): void
{
    if ($exceptId !== null && $exceptId > 0) {
        q('UPDATE `' . T('user_sessions') . '` SET revoked_at = NOW()
           WHERE user_id = ? AND revoked_at IS NULL AND id <> ?', [$uid, $exceptId]);
    } else {
        q('UPDATE `' . T('user_sessions') . '` SET revoked_at = NOW()
           WHERE user_id = ? AND revoked_at IS NULL', [$uid]);
    }
}

/* ---------- 二维码登录（已登录手机确认，不是验证器扫码） ---------- */

function sg_qr_create(): array
{
    $token = sg_new_token();
    $hash  = hash('sha256', $token);
    $ua    = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $expires = date('Y-m-d H:i:s', time() + 120);
    $id = db_insert(T('qr_logins'), [
        'token_hash'   => $hash,
        'status'       => 'pending',
        'requester_ip' => substr(client_ip(), 0, 45),
        'requester_ua' => $ua,
        'device_name'  => sg_device_name($ua),
        'expires_at'   => $expires,
    ]);
    return ['id' => (int) $id, 'token' => $token, 'expires_at' => $expires];
}

function sg_qr_find(string $token): ?array
{
    if ($token === '') return null;
    return fetch_one('SELECT * FROM `' . T('qr_logins') . '` WHERE token_hash = ? LIMIT 1', [hash('sha256', $token)]);
}

function sg_qr_approve(int $qrId, int $uid): bool
{
    $st = q('UPDATE `' . T('qr_logins') . '` SET status = ?, user_id = ?, approved_at = NOW(),
       expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
       WHERE id = ? AND status = ? AND expires_at > NOW()', ['approved', $uid, $qrId, 'pending']);
    return $st->rowCount() > 0;
}

function sg_qr_claim(int $qrId, int $uid = 0): ?array
{
    $where = 'id = ? AND status = ? AND expires_at > NOW()';
    $params = [$qrId, 'approved'];
    if ($uid > 0) {
        $where .= ' AND user_id = ?';
        $params[] = $uid;
    }
    $row = fetch_one('SELECT * FROM `' . T('qr_logins') . '` WHERE ' . $where . ' LIMIT 1', $params);
    if (!$row) return null;
    $st = q('UPDATE `' . T('qr_logins') . '` SET status = ?, consumed_at = NOW()
       WHERE id = ? AND status = ? AND expires_at > NOW()', ['consumed', $qrId, 'approved']);
    return $st->rowCount() > 0 ? $row : null;
}
