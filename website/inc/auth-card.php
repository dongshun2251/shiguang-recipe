<?php
/* 登录 / 注册 / 扫码共用的大卡片。$authFace 为当前正面：login、register 或 qr。 */
$authFace  = in_array($authFace ?? 'login', ['register', 'qr'], true) ? $authFace : 'login';
$authError = (string) ($authError ?? '');
$authNext  = (string) ($authNext ?? '');
$authAuto  = ($_SERVER['REQUEST_METHOD'] !== 'POST' && $authError === '') ? '1' : '0';
if ($authFace === 'qr') $authAuto = '0';
$authFlash = flash_render();
$onLogin   = $authFace === 'login';
$onReg     = $authFace === 'register';
$siteName  = setting('site_name', '食光菜谱');
$authQrPanel = '';
if ($authFace !== 'register') {
    ob_start();
    require __DIR__ . '/qr-login-panel.php';
    $authQrPanel = ob_get_clean();
}
?>
<div class="auth-stage">
  <div class="auth-bigcard" data-auth-flip data-face="<?= e($authFace) ?>" data-auto="<?= e($authAuto) ?>">
    <aside class="auth-v2-side">
      <div class="auth-v2-copy">
      <span class="auth-kicker">SHIGUANG · 家常菜谱库</span>
      <h2>把每一顿饭，<br>做成值得记下的味道。</h2>
      <p>收藏常做的菜、把食材整理成采购清单，也可以把自己的拿手菜写下来。</p>
      <ul class="auth-points">
        <li>收藏菜谱，下次做饭不用再找</li>
        <li>按食材生成采购清单</li>
        <li>投稿、评论，留下自己的厨房足迹</li>
      </ul>
      <div class="auth-v2-side-foot">每天好好吃饭</div>
      </div>
    </aside>

    <div class="auth-v2-main">
      <div class="auth-v2-head">
        <div class="auth-brandline">
          <span class="logo" style="width:30px;height:30px;font-size:17px">食</span>
          <div><b><?= e($siteName) ?></b><span>SHIGUANG RECIPE</span></div>
        </div>
      </div>

      <div class="auth-flip">
        <section class="auth-face auth-face-login" data-auth-face="login" aria-hidden="<?= $onLogin ? 'false' : 'true' ?>">
          <h2>欢迎回来</h2>
          <p class="auth-sub">登录后可以收藏菜谱、记录浏览足迹</p>
          <?php if ($onLogin && $authError !== ''): ?>
          <div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($authError) ?></span></div>
          <?php endif; ?>
          <?php if ($onLogin) echo $authFlash; ?>

          <form class="auth-form" method="post" action="login.php">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($authNext) ?>">
            <div class="field">
              <label>用户名</label>
              <input class="input" name="login" value="<?= e($onLogin ? post('login') : '') ?>" placeholder="请输入用户名" required autocomplete="username">
            </div>
            <div class="field">
              <label>密码</label>
              <input class="input" name="password" type="password" placeholder="请输入密码" required autocomplete="current-password">
            </div>
            <div class="auth-actions">
              <button class="btn primary auth-submit" type="submit"><span class="ico" data-ico="lock"></span>登录</button>
              <button class="btn auth-submit" type="button" data-auth-go="register"><span class="ico" data-ico="user"></span>注册</button>
            </div>
          </form>
          <p class="auth-help"><a href="forgot-password.php">忘记密码</a><span>·</span><a href="support.php">联系客服</a></p>
          <button class="btn block auth-qr-entry" type="button" data-auth-go="qr"><span class="ico" data-ico="qr"></span>用手机扫码登录</button>
          <p class="auth-qr-note desk-only">手机已登录时，用相机扫电脑上的二维码，确认后电脑直接进入；手机不受影响。</p>

          <div class="auth-or"><span></span><em>或</em><span></span></div>
          <a class="btn block" href="index.php"><span class="ico" data-ico="home"></span>先随便逛逛</a>
          <p class="auth-legal">登录即表示同意《用户协议》与《隐私政策》</p>
        </section>

        <section class="auth-face auth-face-register" data-auth-face="register" aria-hidden="<?= $onReg ? 'false' : 'true' ?>">
          <h2>创建账号</h2>
          <p class="auth-sub">收藏喜欢的菜谱，保存你的厨房足迹</p>
          <?php if ($onReg && $authError !== ''): ?>
          <div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($authError) ?></span></div>
          <?php endif; ?>
          <?php if ($onReg) echo $authFlash; ?>

          <form class="auth-form" method="post" action="register.php">
            <?= csrf_field() ?>
            <div class="field">
              <label>用户名 <span class="req">*</span></label>
              <input class="input" name="username" value="<?= e($onReg ? post('username') : '') ?>" placeholder="字母、数字或下划线，3–20 位" required autocomplete="username">
            </div>
            <div class="field">
              <label>昵称</label>
              <input class="input" name="display_name" value="<?= e($onReg ? post('display_name') : '') ?>" placeholder="显示在评论里的名字">
            </div>
            <div class="auth-form-two">
              <div class="field">
                <label>密码 <span class="req">*</span></label>
                <input class="input" name="password" type="password" required autocomplete="new-password">
                <span class="tip">至少 6 位</span>
              </div>
              <div class="field">
                <label>确认密码 <span class="req">*</span></label>
                <input class="input" name="password2" type="password" required autocomplete="new-password">
              </div>
            </div>
            <div class="auth-actions">
              <button class="btn primary auth-submit" type="submit"><span class="ico" data-ico="user"></span>注册并登录</button>
              <button class="btn auth-submit" type="button" data-auth-go="login"><span class="ico" data-ico="lock"></span>返回登录</button>
            </div>
          </form>
        </section>

        <section class="auth-face auth-face-qr" data-auth-face="qr" aria-hidden="<?= $onQr ? 'false' : 'true' ?>">
          <?php if ($onQr) echo $authFlash; ?>
          <?= $authQrPanel ?>
        </section>
      </div>
    </div>
  </div>
</div>
