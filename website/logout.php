<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (!empty($_SESSION['uid'])) {
    $uid = (int) $_SESSION['uid'];
    $token = (string) ($_SESSION['sg_utok'] ?? '');
    $sid = (int) ($_SESSION['sg_sid'] ?? 0);
    if ($sid <= 0 && $token !== '') {
        $row = sg_session_find($token, $uid);
        $sid = $row ? (int) $row['id'] : 0;
    }
    if ($sid > 0) {
        sg_session_revoke($sid, $uid);
    }
}
unset($_SESSION['uid'], $_SESSION['sg_utok'], $_SESSION['sg_sid'], $_SESSION['history']);
session_regenerate_id(true);
flash('已退出登录');
redirect('index.php');
