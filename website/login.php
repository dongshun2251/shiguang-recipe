<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('user.php');
}
if (!empty($_SESSION['sg_2fa_uid']) && (int) ($_SESSION['sg_2fa_at'] ?? 0) > time() - 300) {
    redirect('login-2fa.php');
}

$next  = get('next', '');
$error = '';
$authFace = get('face', '') === 'qr' ? 'qr' : 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $login = trim(post('login'));
    $pass  = (string) post('password');
    $next  = sg_safe_next(post('next', ''), 'user.php');
    $thr   = sg_throttle_bucket('login', $login);

    if (!sg_throttle_allow($thr, 8, 600, 900)) {
        $error = '尝试次数过多，请 15 分钟后再试';
    } else {
        $candidates = fetch_all('SELECT * FROM `' . T('users') . '` WHERE username = ? AND status = 1', [$login]);
        $u = null;
        foreach ($candidates as $row) {
            if (password_verify($pass, $row['password_hash'])) {
                $u = $row;
                break;
            }
        }
        if (!$u) {
            sg_throttle_fail($thr, 8, 600, 900);
            $error = '账号或密码不正确';
        } else {
            sg_throttle_clear($thr);
            if (sg_totp_enabled((int) $u['id'])) {
                session_regenerate_id(true);
                $_SESSION['sg_2fa_uid'] = (int) $u['id'];
                $_SESSION['sg_2fa_at']  = time();
                $_SESSION['sg_2fa_next'] = $next;
                redirect('login-2fa.php');
            }
            session_regenerate_id(true);
            try {
                sg_begin_user_session($u);
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $u = null;
            }
            if ($error === '') {
                flash('欢迎回来，' . ($u['display_name'] ?: $u['username']));
                redirect($next);
            }
        }
    }
}

sg_head('登录', 'login', '登录');
?>
      <?php
      $authError = $error;
      $authNext = $next;
      require __DIR__ . '/inc/auth-card.php';
      ?>
<?php
sg_foot();
