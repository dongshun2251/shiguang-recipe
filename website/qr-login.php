<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('user.php');
}

/* 独立二维码页面已移除；旧链接统一回到登录卡里的扫码面。 */
if (!isset($_GET['claim'])) {
    redirect('login.php?face=qr');
}

/* 电脑端领取：手机已经确认，消费一次性挑战并建立这台电脑的会话 */
$challenge = $_SESSION['sg_qr_challenge'] ?? null;
$qr = $challenge ? sg_qr_find((string) ($challenge['token'] ?? '')) : null;
if (!$qr || (string) $qr['status'] !== 'approved' || strtotime((string) $qr['expires_at']) <= time()) {
    flash('二维码已失效或还没有在手机上确认，请重新生成。', 'err');
    redirect('login.php?face=qr');
}
$claimed = sg_qr_claim((int) $qr['id'], (int) $qr['user_id']);
if (!$claimed) {
    flash('二维码已被使用或已过期，请重新生成。', 'err');
    redirect('login.php?face=qr');
}
$u = fetch_one('SELECT * FROM `' . T('users') . '` WHERE id = ? AND status = 1', [(int) $claimed['user_id']]);
if (!$u) {
    flash('扫码确认的账号已不可用，请重新登录。', 'err');
    redirect('login.php?face=qr');
}
unset($_SESSION['sg_qr_challenge']);
session_regenerate_id(true);
try {
    sg_begin_user_session($u);
    flash('扫码成功，欢迎回来，' . ($u['display_name'] ?: $u['username']));
    redirect('user.php');
} catch (Throwable $e) {
    unset($_SESSION['sg_qr_challenge']);
    flash('建立电脑会话失败：' . $e->getMessage(), 'err');
    redirect('login.php?face=qr');
}
