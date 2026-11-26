<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('user-settings.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $login = trim((string) post('login'));
    $code  = trim((string) post('code'));
    $thr   = sg_throttle_bucket('forgot', $login);

    if (!sg_throttle_allow($thr, 6, 600, 900)) {
        $error = '尝试次数过多，请 15 分钟后再试';
    } else {
        $u = fetch_one('SELECT * FROM `' . T('users') . '` WHERE username = ? AND status = 1', [$login]);
        $ok = false;
        $via = '';
        $codeId = 0;
        if ($u) {
            $uid = (int) $u['id'];
            if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code))) {
                $row = sg_totp_row($uid);
                if ($row && (int) $row['enabled'] === 1) {
                    $step = sg_totp_verify((string) $row['secret'], $code, (int) $row['last_step']);
                    if ($step !== null) {
                        sg_totp_touch_step($uid, $step);
                        $ok = true;
                        $via = 'totp';
                    }
                }
            }
            if (!$ok && strlen(sg_recovery_clean($code)) >= 8) {
                $rc = sg_recovery_code_find($uid, $code, ['recovery', 'admin']);
                if ($rc) {
                    $ok = true;
                    $via = $rc['kind'] === 'admin' ? 'admin' : 'recovery';
                    $codeId = (int) $rc['id'];
                }
            }
        }
        if (!$ok) {
            sg_throttle_fail($thr, 6, 600, 900);
            $error = '账号、动态码或恢复码不正确';
        } else {
            sg_throttle_clear($thr);
            session_regenerate_id(true);
            $_SESSION['sg_reset_uid'] = (int) $u['id'];
            $_SESSION['sg_reset_at'] = time();
            $_SESSION['sg_reset_via'] = $via;
            $_SESSION['sg_reset_code_id'] = $codeId;
            $_SESSION['sg_reset_name'] = (string) ($u['display_name'] ?: $u['username']);
            redirect('reset-password.php');
        }
    }
}

sg_head('找回密码', 'forgot-password', '找回密码');
?>
<div class="auth-stage">
  <div class="auth-card">
    <div class="brandline">
      <span class="logo" style="width:30px;height:30px;font-size:17px">食</span>
      <div><b><?= e(setting('site_name', '食光菜谱')) ?></b><span>SHIGUANG RECIPE</span></div>
    </div>
    <h2>找回密码</h2>
    <p class="sub">不需要邮箱。输入验证器动态码、恢复码，或客服给的一次性重置码。</p>
<?php if ($error !== ''): ?>
    <div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($error) ?></span></div>
<?php endif; ?>
    <form class="auth-form" method="post" action="forgot-password.php">
      <?= csrf_field() ?>
      <div class="field">
        <label>用户名</label>
        <input class="input" name="login" value="<?= e(post('login')) ?>" placeholder="你的用户名" required autofocus>
      </div>
      <div class="field">
        <label>动态码 / 恢复码 / 重置码</label>
        <input class="input" name="code" placeholder="6 位动态码，或 10 位恢复码" required autocomplete="one-time-code">
        <span class="tip">管理员给的重置码也填这里，默认 30 分钟内有效。</span>
      </div>
      <button class="btn primary auth-submit" type="submit"><span class="ico" data-ico="key"></span>验证并重设密码</button>
    </form>
    <p class="auth-help">两个都没有？<a href="support.php">联系客服走人工兜底</a></p>
    <a class="btn block" href="login.php"><span class="ico" data-ico="back"></span>返回登录</a>
  </div>
</div>
<?php
sg_foot();
