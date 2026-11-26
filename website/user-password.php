<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (post('do') === 'password') {
        $old = (string) post('old_pass');
        $new = (string) post('new_pass');
        if (!password_verify($old, $u['password_hash'])) {
            flash('原密码不正确', 'err');
        } elseif (strlen($new) < 6) {
            flash('新密码至少 6 位', 'err');
        } else {
            try {
                sg_sessions_revoke_all((int) $u['id']);
                db_update(T('users'), ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [(int) $u['id']]);
                unset($_SESSION['uid'], $_SESSION['sg_utok'], $_SESSION['sg_sid'], $_SESSION['history']);
                flash('密码已修改，所有设备已退出，请用新密码登录。', 'ok');
                redirect('login.php');
            } catch (Throwable $e) {
                flash('修改失败：' . $e->getMessage(), 'err');
            }
        }
    }
    redirect('user-password.php');
}

sg_head('登录密码', 'user-password', '登录密码');
?>
        <div class="me-subhead">
          <a class="me-back" href="user-settings.php" aria-label="返回账号设置"><span class="ico" data-ico="back"></span></a>
          <div><h1>登录密码</h1><span>修改后所有设备都会退出</span></div>
        </div>

        <div class="card">
          <div class="card-head"><h3>修改密码</h3></div>
          <form method="post">
            <input type="hidden" name="do" value="password">
            <?= csrf_field() ?>
            <div class="field" style="margin-bottom:12px"><label>原密码</label><input class="input" name="old_pass" type="password" required></div>
            <div class="field" style="margin-bottom:14px"><label>新密码</label><input class="input" name="new_pass" type="password" required><span class="tip">至少 6 位</span></div>
            <button class="btn primary block" type="submit"><span class="ico" data-ico="lock"></span>修改密码</button>
          </form>
        </div>
<?php
sg_foot();
