<?php
require_once __DIR__ . '/../inc/bootstrap.php';

if (current_admin()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $user = trim(post('username'));
    $pass = (string) post('password');

    $a = fetch_one('SELECT * FROM `' . T('admins') . '` WHERE username = ? AND status = 1', [$user]);
    if (!$a || !password_verify($pass, $a['password_hash'])) {
        $error = '管理员账号或密码不正确';
    } else {
        session_regenerate_id(true);
        try {
            sg_begin_admin_session($a);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $u = null;
        }
        if ($error === '') {
            flash('欢迎回来，' . ($a['display_name'] ?: $a['username']));
            redirect('dashboard.php');
        }
    }
}

sg_admin_head('管理员登录', 'admin-login', '后台登录');
?>
      <div class="auth-wrap">
        <div class="auth-card">
          <div class="brandline">
            <span class="logo" style="width:30px;height:30px;font-size:17px">食</span>
            <div><b style="font-size:14px"><?= e(setting('site_name', '食光菜谱')) ?> · 管理后台</b><br><span style="font-size:10.5px;color:var(--muted);letter-spacing:.1em">ADMIN CONSOLE</span></div>
          </div>
          <h2>后台登录</h2>
          <p class="sub">仅限站点管理员使用</p>

<?php if ($error !== ''): ?>
          <div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($error) ?></span></div>
<?php endif; ?>
          <?= flash_render() ?>

          <form method="post">
            <?= csrf_field() ?>
            <div class="field" style="margin-bottom:12px"><label>管理员账号</label><input class="input" name="username" value="<?= e(post('username')) ?>" required autocomplete="username"></div>
            <div class="field" style="margin-bottom:16px"><label>密码</label><input class="input" name="password" type="password" required autocomplete="current-password"></div>
            <button class="btn primary block" style="height:40px" type="submit"><span class="ico" data-ico="lock"></span>登录后台</button>
          </form>

          <p style="margin:18px 0 0;font-size:11px;color:var(--muted);text-align:center">
            <a href="../index.php" style="color:var(--brand)">返回前台首页</a>
          </p>
        </div>
      </div>
<?php
sg_admin_foot();
