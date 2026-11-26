<?php
require_once __DIR__ . '/inc/bootstrap.php';
$u = require_login();
$uid = (int) $u['id'];

$token = trim((string) get('t', ''));
$qr = sg_qr_find($token);
$error = '';
$done = false;
$status = $qr ? (string) $qr['status'] : '';

if (!$qr) {
    $error = '二维码不存在，可能已被使用或链接不完整。';
} elseif ($status === 'approved' && (int) $qr['user_id'] === $uid) {
    $done = true;
} elseif ($status === 'consumed' && (int) $qr['user_id'] === $uid) {
    $done = true;
} elseif ($status === 'consumed') {
    $error = '这个二维码已经被使用，不能再次确认。';
} elseif ($status !== 'pending' || strtotime((string) $qr['expires_at']) <= time()) {
    if ($status === 'pending') {
        q('UPDATE `' . T('qr_logins') . '` SET status = ? WHERE id = ? AND status = ?', ['expired', (int) $qr['id'], 'pending']);
    }
    $error = '二维码已过期，请回到电脑上刷新二维码。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $qr && $status === 'pending' && $error === '') {
    csrf_verify();
    if ((string) post('do') === 'approve') {
        if (sg_qr_approve((int) $qr['id'], $uid)) {
            $done = true;
        } else {
            $error = '确认失败，二维码可能已经过期，请刷新电脑上的二维码。';
        }
    }
}

sg_head('确认扫码登录', 'qr-approve', '确认登录');
?>
<div class="page-narrow">
  <div class="me-subhead">
    <a class="me-back" href="user-security.php" aria-label="返回账号安全"><span class="ico" data-ico="back"></span></a>
    <div><h1>确认扫码登录</h1><span>手机确认后，电脑才会登录</span></div>
  </div>

  <?php if ($error !== ''): ?>
  <div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($error) ?></span></div>
  <?php endif; ?>

  <?php if ($done): ?>
  <section class="card approve-card">
    <div class="approve-big"><span class="ico" data-ico="check"></span></div>
    <h2>已确认登录</h2>
    <p class="muted">电脑会在几秒内进入你的账号。手机这边仍然保持登录，不会被踢下线。</p>
    <div class="meta-box">
      <div class="meta-row"><span class="ico" data-ico="user"></span><span>登录账号</span><b><?= e($u['display_name'] ?: $u['username']) ?></b></div>
      <div class="meta-row"><span class="ico" data-ico="qr"></span><span>请求设备</span><b><?= e($qr['device_name'] ?? '未知设备') ?></b></div>
    </div>
    <a class="btn block" href="user-security.php">返回账号安全</a>
  </section>
  <?php elseif ($qr && $status === 'pending' && $error === ''): ?>
  <section class="card approve-card">
    <div class="approve-big"><span class="ico" data-ico="qr"></span></div>
    <h2>确认在这台电脑登录？</h2>
    <p class="muted">确认后，下面这台设备会以 <b><?= e($u['display_name'] ?: $u['username']) ?></b> 的身份登录本站。请确认这是你自己正在使用的电脑。</p>
    <div class="meta-box">
      <div class="meta-row"><span class="ico" data-ico="wifi"></span><span>请求设备</span><b><?= e($qr['device_name'] ?? '未知设备') ?></b></div>
      <div class="meta-row"><span class="ico" data-ico="clock"></span><span>请求时间</span><b><?= e(date('m-d H:i', strtotime((string) $qr['created_at']))) ?></b></div>
      <?php if (!empty($qr['requester_ip'])): ?><div class="meta-row"><span class="ico" data-ico="info"></span><span>请求 IP</span><b><?= e($qr['requester_ip']) ?></b></div><?php endif; ?>
    </div>
    <form method="post" class="sec-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="approve">
      <button class="btn primary block" type="submit">确认登录</button>
    </form>
    <a class="btn block" href="user-security.php">不是我，取消</a>
  </section>
  <?php else: ?>
  <section class="card approve-card">
    <p class="muted">这个二维码当前不能确认。请回到电脑上刷新二维码后再扫。</p>
    <a class="btn block" href="user-security.php">返回账号安全</a>
  </section>
  <?php endif; ?>
</div>
<?php sg_foot(); ?>
