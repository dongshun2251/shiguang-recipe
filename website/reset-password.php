<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('user-settings.php');
}

$uid = (int) ($_SESSION['sg_reset_uid'] ?? 0);
$at  = (int) ($_SESSION['sg_reset_at'] ?? 0);
if (!$uid || $at <= time() - 900) {
    unset($_SESSION['sg_reset_uid'], $_SESSION['sg_reset_at'], $_SESSION['sg_reset_via'], $_SESSION['sg_reset_code_id'], $_SESSION['sg_reset_name']);
    flash('重设密码的验证已过期，请重新验证。', 'err');
    redirect('forgot-password.php');
}
$u = fetch_one('SELECT * FROM `' . T('users') . '` WHERE id = ? AND status = 1', [$uid]);
if (!$u) {
    unset($_SESSION['sg_reset_uid'], $_SESSION['sg_reset_at'], $_SESSION['sg_reset_via'], $_SESSION['sg_reset_code_id'], $_SESSION['sg_reset_name']);
    redirect('forgot-password.php');
}

$via = (string) ($_SESSION['sg_reset_via'] ?? '');
$codeId = (int) ($_SESSION['sg_reset_code_id'] ?? 0);
$name = (string) ($_SESSION['sg_reset_name'] ?? ($u['display_name'] ?: $u['username']));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pw  = (string) post('password');
    $pw2 = (string) post('password2');
    if (strlen($pw) < 6) {
        $error = '新密码至少 6 位';
    } elseif ($pw !== $pw2) {
        $error = '两次输入的密码不一致';
    } else {
        try {
            sg_sessions_revoke_all($uid);
            db_update(T('users'), ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)], 'id = ?', [$uid]);
            if ($codeId > 0) {
                sg_recovery_code_use($codeId);
            }
            if ($via === 'admin') {
                q('DELETE FROM `' . T('user_totp') . '` WHERE user_id = ?', [$uid]);
                q('DELETE FROM `' . T('recovery_codes') . '` WHERE user_id = ?', [$uid]);
            }
            unset($_SESSION['sg_reset_uid'], $_SESSION['sg_reset_at'], $_SESSION['sg_reset_via'], $_SESSION['sg_reset_code_id'], $_SESSION['sg_reset_name']);
            flash('密码已重置，请用新密码登录。');
            redirect('login.php');
        } catch (Throwable $e) {
            $error = '重置失败：' . $e->getMessage();
        }
    }
}

sg_head('重设密码', 'reset-password', '重设密码');
?>
<div class="auth-stage">
  <div class="auth-card">
    <div class="brandline">
      <span class="logo" style="width:30px;height:30px;font-size:17px">食</span>
      <div><b><?= e(setting('site_name', '食光菜谱')) ?></b><span>SHIGUANG RECIPE</span></div>
    </div>
    <h2>给 <?= e($name) ?> 设新密码</h2>
    <p class="sub"><?= $via === 'admin' ? '管理员重置码已验证。保存新密码后，验证器会关闭，所有设备都会退出。' : '验证已通过。保存新密码后，所有设备都会退出，需要用新密码重新登录。' ?></p>
<?php if ($error !== ''): ?>
    <div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($error) ?></span></div>
<?php endif; ?>
    <form class="auth-form" method="post" action="reset-password.php">
      <?= csrf_field() ?>
      <div class="field">
        <label>新密码</label>
        <input class="input" type="password" name="password" placeholder="至少 6 位" required autocomplete="new-password">
      </div>
      <div class="field">
        <label>确认新密码</label>
        <input class="input" type="password" name="password2" placeholder="再输一次" required autocomplete="new-password">
      </div>
      <button class="btn primary auth-submit" type="submit"><span class="ico" data-ico="check"></span>保存新密码</button>
    </form>
    <a class="btn block" href="login.php"><span class="ico" data-ico="back"></span>取消，返回登录</a>
  </div>
</div>
<?php
sg_foot();
