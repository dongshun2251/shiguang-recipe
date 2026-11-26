<?php
require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$out = ['status' => 'none'];
$challenge = $_SESSION['sg_qr_challenge'] ?? null;
$qr = $challenge ? sg_qr_find((string) ($challenge['token'] ?? '')) : null;

/* 二维码过期后由前端圆圈按钮调用：作废旧挑战，创建一张新二维码。
   用 POST + CSRF，避免被外部页面反复触发。 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('refresh') === '1') {
    csrf_verify();

    try {
        if ($qr && (string) $qr['status'] === 'pending') {
            q('UPDATE `' . T('qr_logins') . '` SET status = ? WHERE id = ? AND status = ?', ['expired', (int) $qr['id'], 'pending']);
        }
        $qr = sg_qr_create();
        $_SESSION['sg_qr_challenge'] = ['id' => (int) $qr['id'], 'token' => (string) $qr['token']];

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') $scheme = 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) $host = 'localhost';

        $out = [
            'status'     => 'pending',
            'url'        => $scheme . '://' . $host . url('qr-approve.php?t=' . rawurlencode((string) $qr['token'])),
            'expires_in' => max(1, strtotime((string) $qr['expires_at']) - time()),
        ];
    } catch (Throwable $e) {
        http_response_code(500);
        $out = ['status' => 'error', 'message' => '生成二维码失败'];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($qr) {
    $status = (string) $qr['status'];
    if ($status === 'pending' && strtotime((string) $qr['expires_at']) <= time()) {
        q('UPDATE `' . T('qr_logins') . '` SET status = ? WHERE id = ? AND status = ?', ['expired', (int) $qr['id'], 'pending']);
        $status = 'expired';
    }
    if ($status === 'approved' && strtotime((string) $qr['expires_at']) <= time()) {
        $status = 'expired';
    }
    $out = ['status' => in_array($status, ['pending', 'approved', 'expired', 'consumed'], true) ? $status : 'none'];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
