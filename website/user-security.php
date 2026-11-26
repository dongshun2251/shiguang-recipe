<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u = current_user();
$uid = (int) $u['id'];
$totp = sg_totp_row($uid);
$enabled = sg_totp_enabled($uid);
$left = sg_recovery_codes_left($uid);
$error = '';
$notice = '';
$newCodes = [];
$currentSid = (int) ($_SESSION['sg_sid'] ?? 0);
if ($currentSid <= 0 && !empty($_SESSION['sg_utok'])) {
    $cur = sg_session_find((string) $_SESSION['sg_utok'], $uid);
    if ($cur) { $currentSid = (int) $cur['id']; $_SESSION['sg_sid'] = $currentSid; }
}

if (!empty($_SESSION['sg_new_recovery_codes'])) {
    $newCodes = $_SESSION['sg_new_recovery_codes'];
    unset($_SESSION['sg_new_recovery_codes']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) post('action');
    $pass = (string) post('password');

    if ($action === 'revoke_one') {
        $sid = (int) post('sid');
        if ($currentSid > 0 && $sid === $currentSid) {
            $error = '当前设备请用页面底部的「退出登录」，不能在这里踢掉自己。';
        } elseif ($sid > 0) {
            sg_session_revoke($sid, $uid);
            $notice = '这台设备已退出登录。';
        }
    } elseif ($action === 'revoke_others') {
        if ($currentSid > 0) {
            sg_sessions_revoke_all($uid, $currentSid);
            $notice = '其他设备已全部退出，本机仍保持登录。';
        } else {
            $error = '找不到当前设备会话，请重新登录后再试。';
        }
    }

    if (in_array($action, ['disable', 'regen', 'codes_only'], true)) {
        if (!password_verify($pass, $u['password_hash'])) {
            $error = '当前密码不正确';
        }
    }

    if ($action === 'start' && $error === '') {
        if ($enabled) {
            $error = '验证器已经开启';
        } else {
            $secret = sg_base32_encode(random_bytes(20));
            $_SESSION['sg_totp_pending'] = ['secret' => $secret, 'at' => time()];
            $notice = '已生成密钥，请用验证器 App 扫码或手动输入，再填写 6 位动态码确认。';
        }
    } elseif ($action === 'confirm' && $error === '') {
        $pending = $_SESSION['sg_totp_pending'] ?? null;
        if (!$pending || (int) ($pending['at'] ?? 0) < time() - 600) {
            $error = '绑定已过期，请重新生成密钥';
            unset($_SESSION['sg_totp_pending']);
        } else {
            $secret = (string) $pending['secret'];
            $code = trim((string) post('code'));
            $step = sg_totp_verify($secret, $code, 0);
            if ($step === null) {
                $error = '动态码不正确，请检查手机时间后重试';
            } else {
                try {
                    sg_totp_save($uid, $secret, true, $step);
                    unset($_SESSION['sg_totp_pending']);
                    $newCodes = sg_recovery_codes_generate($uid, 8, 'recovery', 0, 0);
                    $_SESSION['sg_new_recovery_codes'] = $newCodes;
                    $enabled = true;
                    $left = 8;
                    $notice = '验证器已开启，请现在保存下面 8 个恢复码。';
                } catch (Throwable $e) {
                    $error = '保存失败：' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'disable' && $error === '') {
        if (!$enabled) {
            $error = '验证器还没开启';
        } else {
            q('DELETE FROM `' . T('user_totp') . '` WHERE user_id = ?', [$uid]);
            q('DELETE FROM `' . T('recovery_codes') . '` WHERE user_id = ? AND kind = ?', [$uid, 'recovery']);
            unset($_SESSION['sg_new_recovery_codes']);
            $newCodes = [];
            $enabled = false;
            $totp = null;
            $left = 0;
            $notice = '验证器已关闭，恢复码也一并作废。';
        }
    } elseif ($action === 'regen' && $error === '') {
        $newCodes = sg_recovery_codes_generate($uid, 8, 'recovery', 0, 0);
        $_SESSION['sg_new_recovery_codes'] = $newCodes;
        $left = 8;
        $notice = '新恢复码已生成，旧恢复码全部作废。';
    } elseif ($action === 'codes_only' && $error === '') {
        $newCodes = sg_recovery_codes_generate($uid, 8, 'recovery', 0, 0);
        $_SESSION['sg_new_recovery_codes'] = $newCodes;
        $left = 8;
        $notice = '已生成 8 个恢复码。即使没开验证器，也可以用恢复码找回密码。';
    }
}

/* 恢复码使用状态：只展示匿名序号和状态，不回显明文码 */
$recoveryRows = [];
if (sg_security_ready()) {
    $recoveryRows = fetch_all('SELECT id, used_at, expires_at FROM `' . T('recovery_codes') . '`
        WHERE user_id = ? AND kind = "recovery" ORDER BY id ASC', [$uid]);
}

if (!empty($_SESSION['sg_new_recovery_codes']) && !$newCodes) {
    $newCodes = $_SESSION['sg_new_recovery_codes'];
    unset($_SESSION['sg_new_recovery_codes']);
}

$pending = $_SESSION['sg_totp_pending'] ?? null;
if ($pending && (int) ($pending['at'] ?? 0) < time() - 600) {
    unset($_SESSION['sg_totp_pending']);
    $pending = null;
}

sg_head('账号安全', 'user-security', '账号安全');
?>
<div class="me-subhead">
  <a class="me-back" href="user-settings.php" aria-label="返回账号设置"><span class="ico" data-ico="back"></span></a>
  <div><h1>账号安全</h1><span>验证器、恢复码和账号兜底</span></div>
</div>

<?php if ($error !== ''): ?>
<div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
<div class="alert ok"><span class="ico" data-ico="check"></span><span><?= e($notice) ?></span></div>
<?php endif; ?>

<?php if (!sg_security_ready()): ?>
<div class="card"><div class="card-head"><h3>账号安全暂不可用</h3></div><div class="pad muted">数据库缺少安全相关表，请先重新运行安装程序或检查数据库权限。</div></div>
<?php else: ?>

<?php if ($newCodes): ?>
<section class="card sec-codes-card">
  <div class="card-head"><h3>请现在保存恢复码</h3><span class="hint">只显示这一次</span></div>
  <div class="sec-code-grid">
    <?php foreach ($newCodes as $c): ?><code><?= e($c) ?></code><?php endforeach; ?>
  </div>
  <div class="sec-code-actions">
    <button class="btn sm" type="button" data-copy-codes>复制全部</button>
    <button class="btn sm" type="button" data-download-codes>下载文本</button>
  </div>
  <p class="muted sec-tip">每个恢复码只能用一次。丢了验证器时，在找回密码页输入其中一个。</p>
  <script>
  (function () {
    var codes = <?= json_encode($newCodes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var box = document.querySelector('.sec-codes-card');
    if (!box) return;
    box.querySelector('[data-copy-codes]').addEventListener('click', function () {
      var text = '食光菜谱恢复码\n' + codes.join('\n') + '\n每个只能用一次，请离线保存。';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { this.textContent = '已复制'; }.bind(this));
      } else {
        var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); this.textContent = '已复制'; } catch (e) {}
        t.remove();
      }
    });
    box.querySelector('[data-download-codes]').addEventListener('click', function () {
      var text = '食光菜谱恢复码\n' + codes.join('\n') + '\n每个只能用一次，请离线保存。';
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([text], {type: 'text/plain;charset=utf-8'}));
      a.download = '食光菜谱_恢复码.txt'; a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    });
  })();
  </script>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h3>验证器 App</h3><span class="badge <?= $enabled ? 'live' : 'draft' ?>"><?= $enabled ? '已开启' : '未开启' ?></span></div>
  <?php if ($enabled): ?>
  <div class="meta-box">
    <div class="meta-row"><span class="ico" data-ico="shield"></span><span>登录保护</span><b>密码 + 6 位动态码</b></div>
    <div class="meta-row"><span class="ico" data-ico="key"></span><span>恢复码剩余</span><b><?= (int) $left ?> 个</b></div>
  </div>
  <p class="muted sec-tip">验证器丢了可以用恢复码登录；恢复码也没了，请联系客服走人工兜底。</p>
  <form method="post" class="sec-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="disable">
    <div class="field"><label>当前密码</label><input class="input" type="password" name="password" placeholder="关闭前确认身份" required></div>
    <button class="btn" type="submit" data-confirm="关闭验证器后，恢复码也会一起作废，确定吗？">关闭验证器</button>
  </form>
  <?php elseif ($pending): ?>
  <p class="muted sec-tip">用验证器 App 扫下面的二维码，或手动输入密钥，然后填写当前的 6 位动态码。</p>
  <div class="sec-setup">
    <div class="sec-qr" id="totpQr" data-uri="<?= e(sg_totp_uri((string) $pending['secret'], (string) $u['username'])) ?>"></div>
    <div class="sec-setup-main">
      <span class="sec-label">手动密钥</span>
      <code class="sec-secret" id="totpSecret"><?= e($pending['secret']) ?></code>
      <button class="btn sm" type="button" data-copy-secret>复制密钥</button>
      <form method="post" class="sec-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm">
        <div class="field"><label>6 位动态码</label><input class="input" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required></div>
        <button class="btn primary" type="submit">确认开启</button>
      </form>
    </div>
  </div>
  <script src="<?= e(SG_WEB_BASE) ?>/assets/qrcode.min.js"></script>
  <script>
  (function () {
    var box = document.getElementById('totpQr');
    if (box && window.QRCode) {
      new QRCode(box, {text: box.getAttribute('data-uri'), width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M});
    }
    var b = document.querySelector('[data-copy-secret]');
    if (b) b.addEventListener('click', function () {
      var t = document.getElementById('totpSecret').textContent.trim();
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(function(){ b.textContent = '已复制'; });
      } else {
        var x=document.createElement('textarea'); x.value=t; document.body.appendChild(x); x.select();
        try { document.execCommand('copy'); b.textContent='已复制'; } catch(e) {}
        x.remove();
      }
    });
  })();
  </script>
  <?php else: ?>
  <div class="sec-steps">
    <b>验证器 App 怎么用</b>
    <ol>
      <li>在手机应用商店装一个验证器：Google Authenticator、Microsoft Authenticator、1Password 等都可以。</li>
      <li>点「生成密钥并开启」，页面会出现二维码，用 App 扫它；不能扫码就选「手动输入」，把密钥粘进去，账号名写「食光菜谱」。</li>
      <li>把 App 里显示的 6 位数字填到确认框，点确认。之后每次登录，打开 App 看当前 6 位数字即可。</li>
    </ol>
  </div>
  <p class="muted sec-tip">开启后，登录需要密码再输入验证器里的 6 位动态码。验证器丢了可以用恢复码，恢复码也没了联系客服。</p>
  <form method="post" class="sec-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="start">
    <button class="btn primary" type="submit">生成密钥并开启</button>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h3>恢复码</h3><span class="hint">备用钥匙，不依赖手机</span></div>
  <p class="muted sec-tip">即使不开验证器，也可以单独生成一套恢复码。忘记密码时用它重设，不用邮箱。</p>
  <?php if ($recoveryRows): ?>
  <div class="sec-code-board">
    <div class="sec-code-board-head">
      <b>使用情况</b>
      <span>剩余 <?= (int) $left ?> 个 · 已失效 <?= max(0, count($recoveryRows) - (int) $left) ?> 个</span>
    </div>
    <div class="sec-code-slots">
      <?php for ($i = 0; $i < 8; $i++): ?>
        <?php
          $row = $recoveryRows[$i] ?? null;
          $isDead = !$row || !empty($row['used_at']) || (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time());
        ?>
        <div class="sec-code-slot<?= $isDead ? ' is-dead' : '' ?>">
          <span>恢复码 <?= $i + 1 ?></span>
          <b><?= $row ? ($isDead ? '已失效' : '未使用') : '已作废' ?></b>
        </div>
      <?php endfor; ?>
    </div>
    <p class="muted sec-tip">这里只显示状态，不显示原码；原码只在生成时显示一次。</p>
  </div>
  <?php endif; ?>
  <?php if ($enabled): ?>
  <form method="post" class="sec-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="regen">
    <div class="field"><label>当前密码</label><input class="input" type="password" name="password" placeholder="重新生成前确认身份" required></div>
    <button class="btn" type="submit" data-confirm="重新生成会让旧恢复码全部作废，确定吗？">重新生成 8 个恢复码</button>
  </form>
  <?php else: ?>
  <form method="post" class="sec-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="codes_only">
    <div class="field"><label>当前密码</label><input class="input" type="password" name="password" placeholder="生成前确认身份" required></div>
    <button class="btn" type="submit">生成 8 个恢复码</button>
  </form>
  <?php endif; ?>
</section>

<section class="card sec-devices-card">
  <div class="card-head"><h3>登录设备</h3><span class="hint">最多 <?= (int) sg_session_limit() ?> 台同时在线</span></div>
  <p class="muted sec-tip">手机和电脑可以同时登录。新设备登录超过上限时，最久没用的那台会自动退出。</p>
  <?php $sessions = sg_sessions_active($uid); ?>
  <?php if (!$sessions): ?>
  <p class="muted sec-tip">暂时没有可显示的设备会话。</p>
  <?php else: ?>
  <div class="sec-device-list">
    <?php foreach ($sessions as $s): $isCur = (int) $s['id'] === $currentSid; ?>
    <div class="sec-device<?= $isCur ? ' is-current' : '' ?>">
      <div class="sec-device-ico"><span class="ico" data-ico="wifi"></span></div>
      <div class="sec-device-main">
        <b><?= e($s['device_name']) ?><?php if ($isCur): ?><span class="badge live sec-device-badge">本机</span><?php endif; ?></b>
        <small>最后活跃 <?= e(date('m-d H:i', strtotime((string) $s['last_seen_at']))) ?><?php if (!empty($s['ip'])): ?> · IP <?= e($s['ip']) ?><?php endif; ?></small>
      </div>
      <?php if (!$isCur): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="revoke_one">
        <input type="hidden" name="sid" value="<?= (int) $s['id'] ?>">
        <button class="btn sm" type="submit" data-confirm="退出这台设备后，它需要重新登录，确定吗？">退出</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($sessions) > 1): ?>
  <form method="post" class="sec-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="revoke_others">
    <button class="btn" type="submit" data-confirm="其他设备会全部退出，本机保持登录，确定吗？">退出其他设备</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
  <p class="muted sec-tip">小提示：在手机上打开本站并登录，再扫电脑上的登录二维码，就能让两台设备同时在线。</p>
</section>

<section class="card">
  <div class="card-head"><h3>都不行了怎么办</h3></div>
  <p class="muted sec-tip">验证器和恢复码都丢失时，通过客服工单联系运营。运营核对身份后发一次性重置码，不会直接知道你的新密码。</p>
  <a class="btn block" href="<?= e(SG_WEB_BASE) ?>/support.php"><span class="ico" data-ico="comment"></span>联系客服</a>
</section>

<?php endif; ?>
<?php
sg_foot();
