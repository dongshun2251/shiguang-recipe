<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('user.php');
}
if (isset($_GET['cancel'])) {
    unset($_SESSION['sg_2fa_uid'], $_SESSION['sg_2fa_at'], $_SESSION['sg_2fa_next']);
    flash('已取消两步验证，请重新登录。');
    redirect('login.php');
}

$uid = (int) ($_SESSION['sg_2fa_uid'] ?? 0);
$at  = (int) ($_SESSION['sg_2fa_at'] ?? 0);
if (!$uid || $at <= time() - 600) {
    unset($_SESSION['sg_2fa_uid'], $_SESSION['sg_2fa_at'], $_SESSION['sg_2fa_next']);
    flash('验证已过期，请重新登录。', 'err');
    redirect('login.php');
}
$u = fetch_one('SELECT * FROM `' . T('users') . '` WHERE id = ? AND status = 1', [$uid]);
if (!$u) {
    unset($_SESSION['sg_2fa_uid'], $_SESSION['sg_2fa_at'], $_SESSION['sg_2fa_next']);
    redirect('login.php');
}
if (!sg_totp_enabled($uid)) {
    unset($_SESSION['sg_2fa_uid'], $_SESSION['sg_2fa_at'], $_SESSION['sg_2fa_next']);
    flash('该账号已关闭两步验证，请重新登录。');
    redirect('login.php');
}

$next  = sg_safe_next((string) ($_SESSION['sg_2fa_next'] ?? ''), 'user.php');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = trim((string) post('code'));
    $thr  = sg_throttle_bucket('2fa-login', (string) $u['username']);
    if (!sg_throttle_allow($thr, 5, 600, 900)) {
        $error = '尝试次数过多，请 15 分钟后再试';
    } else {
        $row = sg_totp_row($uid);
        $step = null;
        $via = '';
        if ($row) {
            $step = sg_totp_verify((string) $row['secret'], $code, (int) $row['last_step']);
        }
        if ($step !== null) {
            sg_totp_touch_step($uid, $step);
            $via = 'totp';
        } else {
            $used = sg_recovery_code_consume($uid, $code, ['recovery']);
            if ($used) $via = 'recovery';
        }
        if ($via === '') {
            sg_throttle_fail($thr, 5, 600, 900);
            $error = '动态码或恢复码不正确';
        } else {
            sg_throttle_clear($thr);
            unset($_SESSION['sg_2fa_uid'], $_SESSION['sg_2fa_at'], $_SESSION['sg_2fa_next']);
            session_regenerate_id(true);
            try {
                sg_begin_user_session($u);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
            if ($error === '') {
                flash($via === 'recovery'
                    ? '已使用恢复码登录，建议到账号安全重新生成一套恢复码。'
                    : '欢迎回来，' . ($u['display_name'] ?: $u['username']));
                redirect($next);
            }
        }
    }
}

sg_head('两步验证', 'login-2fa', '两步验证');
?>
<div class="auth-stage">
  <div class="auth-card">
    <div class="brandline">
      <span class="logo" style="width:30px;height:30px;font-size:17px">食</span>
      <div><b><?= e(setting('site_name', '食光菜谱')) ?></b><span>SHIGUANG RECIPE</span></div>
    </div>
    <h2>两步验证</h2>
    <p class="sub"><?= e($u['display_name'] ?: $u['username']) ?> 已开启验证器，请输入当前 6 位动态码；验证器丢了就输入一个恢复码。</p>
<?php if ($error !== ''): ?>
    <div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($error) ?></span></div>
<?php endif; ?>
    <form class="auth-form" method="post" action="login-2fa.php">
      <?= csrf_field() ?>
      <div class="field">
        <label>动态码或恢复码</label>
        <input class="input" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="6 位数字，或 10 位恢复码" required autofocus>
      </div>
      <button class="btn primary auth-submit" type="submit"><span class="ico" data-ico="shield"></span>验证并登录</button>
    </form>
    <p class="auth-help">验证器丢了？也可以<a href="<?= e(SG_WEB_BASE) ?>/support.php">联系客服</a>。</p>
    <a class="btn block" href="login-2fa.php?cancel=1"><span class="ico" data-ico="back"></span>返回登录</a>
  </div>
</div>
<?php
sg_foot();
