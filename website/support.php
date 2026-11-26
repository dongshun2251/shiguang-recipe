<?php
require_once __DIR__ . '/inc/bootstrap.php';

$u = current_user();
$error = '';
$successNo = '';
$followed = false;
$myTickets = [];

if (!sg_security_ready()) {
    $error = '工单功能暂不可用：数据库安全表未建好，请管理员重新运行安装程序。';
} else {
    if ($u) {
        $myTickets = fetch_all('SELECT * FROM `' . T('support_tickets') . '` WHERE user_id = ? ORDER BY id DESC LIMIT 8', [(int) $u['id']]);
    } elseif (!empty($_SESSION['sg_ticket_nos']) && is_array($_SESSION['sg_ticket_nos'])) {
        $nos = array_slice(array_values(array_unique(array_filter($_SESSION['sg_ticket_nos'], 'is_string'))), -8);
        if ($nos) {
            $in = implode(',', array_fill(0, count($nos), '?'));
            $myTickets = fetch_all('SELECT * FROM `' . T('support_tickets') . '` WHERE ticket_no IN (' . $in . ') ORDER BY id DESC', $nos);
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $do = (string) post('do', 'new');
        if ($do === 'follow') {
            $no     = trim((string) post('follow_no'));
            $follow = trim((string) post('follow_body'));
            $target = null;
            foreach ($myTickets as $t) {
                if (hash_equals((string) $t['ticket_no'], $no)) {
                    $target = $t;
                    break;
                }
            }
            $thr = sg_throttle_bucket('support_follow', $u ? (string) $u['username'] : (string) client_ip());
            if (!$target) {
                $error = '找不到这张工单，或它不属于当前账号/浏览器';
            } elseif ($target['status'] === 'closed') {
                $error = '这张工单已经关闭，不能再补充；请重新提交一张工单';
            } elseif (!sg_throttle_allow($thr, 6, 3600, 1800)) {
                $error = '补充太频繁，请半小时后再试';
            } elseif (mb_strlen($follow) < 5 || mb_strlen($follow) > 2000) {
                $error = '补充内容写 5 到 2000 个字';
            } else {
                $append = "\n\n--- 用户补充 " . date('Y-m-d H:i') . " ---\n" . $follow;
                db_update(T('support_tickets'), [
                    'body'   => rtrim((string) $target['body']) . $append,
                    'status' => 'open',
                ], 'id = ?', [(int) $target['id']]);
                sg_throttle_clear($thr);
                redirect('support.php?no=' . urlencode($no) . '&followed=1');
            }
        } else {
            $subject = trim((string) post('subject'));
            $body    = trim((string) post('body'));
            $contact = trim((string) post('contact'));
            $who     = trim((string) post('username'));
            $thr     = sg_throttle_bucket('support', $u ? (string) $u['username'] : (string) client_ip());
            if (!sg_throttle_allow($thr, 4, 3600, 1800)) {
                $error = '提交太频繁，请半小时后再试';
            } elseif (mb_strlen($subject) < 2 || mb_strlen($subject) > 100) {
                $error = '标题写 2 到 100 个字';
            } elseif (mb_strlen($body) < 5 || mb_strlen($body) > 2000) {
                $error = '问题描述写 5 到 2000 个字';
            } elseif (!$u && mb_strlen($contact) < 3) {
                $error = '没登录的话，请留一个联系方式';
            } else {
                try {
                    $no = sg_ticket_new_no();
                    db_insert(T('support_tickets'), [
                        'ticket_no' => $no,
                        'user_id'   => $u ? (int) $u['id'] : 0,
                        'username'  => $u ? (string) $u['username'] : $who,
                        'contact'   => $contact,
                        'subject'   => $subject,
                        'body'      => $body,
                        'status'    => 'open',
                    ]);
                    sg_throttle_clear($thr);
                    $sessionNos = $_SESSION['sg_ticket_nos'] ?? [];
                    if (!is_array($sessionNos)) $sessionNos = [];
                    $sessionNos[] = $no;
                    $_SESSION['sg_ticket_nos'] = array_slice(array_values(array_unique($sessionNos)), -8);
                    redirect('support.php?no=' . urlencode($no));
                } catch (Throwable $e) {
                    $error = '提交失败：' . $e->getMessage();
                }
            }
        }
    }
    $successNo = trim((string) get('no'));
    $followed  = (string) get('followed') === '1';
}

sg_head('帮助与客服', 'support', '帮助与客服');
?>
<div class="page-head">
  <h1>帮助与客服</h1>
  <span class="sub">账号、菜谱、投稿问题都可以在这里留工单</span>
  <?php if ($u): ?>
  <div class="actions">
    <a class="btn sm" href="user-settings.php"><span class="ico" data-ico="settings"></span>账号设置</a>
  </div>
  <?php endif; ?>
</div>

<?= flash_render() ?>

<?php if ($successNo !== ''): ?>
<section class="card support-success">
  <div class="support-success-ico"><span class="ico" data-ico="check"></span></div>
  <h2><?= $followed ? '补充信息已提交' : '工单已收到' ?></h2>
  <?php if ($followed): ?>
  <p>已追加到工单 <code><?= e($successNo) ?></code>，运营会继续处理。回来刷新这一页就能看到后续回复。</p>
  <?php else: ?>
  <p>工单号 <code><?= e($successNo) ?></code>。运营会按顺序处理，回复会显示在下面「我的工单」里，回来刷新就能看到。</p>
  <?php endif; ?>
  <p class="muted">这里不发邮件，也不会打电话；请自己回来查回复。</p>
</section>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="alert err"><span class="ico" data-ico="alert"></span><span><?= e($error) ?></span></div>
<?php endif; ?>

<div class="support-grid">
  <section class="card">
    <div class="card-head"><h3>账号问题</h3><span class="hint">不用邮箱也能找回</span></div>
    <div class="me-list">
      <a class="me-row" href="forgot-password.php"><span class="me-row-ic"><span class="ico" data-ico="key"></span></span><div><b>找回密码</b><span>验证器动态码 / 恢复码 / 客服重置码</span></div><span class="me-row-go"><span class="ico" data-ico="chev"></span></span></a>
      <?php if ($u): ?>
      <a class="me-row" href="user-security.php"><span class="me-row-ic"><span class="ico" data-ico="shield"></span></span><div><b>账号安全</b><span>开启验证器、生成恢复码</span></div><span class="me-row-go"><span class="ico" data-ico="chev"></span></span></a>
      <?php endif; ?>
      <div class="me-note"><span class="ico" data-ico="info"></span><span>验证器和恢复码都丢了，就在下面提交工单，运营核对身份后发一次性重置码。</span></div>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h3>提交工单</h3><span class="hint">通常 1 个工作日内处理</span></div>
    <form class="auth-form support-form" method="post" action="support.php">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="new">
      <?php if (!$u): ?>
      <div class="field"><label>你的用户名（账号问题必填）</label><input class="input" name="username" value="<?= e(post('username')) ?>" placeholder="忘了密码也要填对用户名"><span class="tip">运营靠它定位账号；普通问题可以不填。</span></div>
      <?php endif; ?>
      <div class="field">
        <label>标题</label>
        <input class="input" name="subject" value="<?= e(post('subject')) ?>" placeholder="例如：验证器丢了，想重置密码" maxlength="100" required>
      </div>
      <div class="field">
        <label>具体情况</label>
        <textarea class="input textarea" name="body" rows="6" placeholder="账号、遇到的问题、已经试过的方法。账号密码不用写进来。" maxlength="2000" required><?= e(post('body')) ?></textarea>
      </div>
      <div class="field">
        <label>联系方式 <?= $u ? '' : '<span class="req">*</span>' ?></label>
        <input class="input" name="contact" value="<?= e(post('contact')) ?>" placeholder="手机 / 微信号，方便运营联系你">
        <?php if ($u): ?><span class="tip">选填；不填就在工单回复里联系你，账号 @<?= e($u['username']) ?>。</span><?php endif; ?>
      </div>
      <button class="btn primary auth-submit" type="submit"><span class="ico" data-ico="comment"></span>提交工单</button>
    </form>
  </section>
</div>

<?php if ($myTickets): ?>
<section class="card">
  <div class="card-head"><h3>我的工单</h3><span class="hint">本浏览器最近 8 条</span></div>
  <div class="ticket-list">
    <?php foreach ($myTickets as $t): ?>
    <div class="ticket-row">
      <div class="ticket-top"><code><?= e($t['ticket_no']) ?></code><span class="badge <?= $t['status'] === 'closed' ? 'draft' : ($t['status'] === 'answered' ? 'live' : '') ?>"><?= e(['open' => '待处理', 'answered' => '已回复', 'closed' => '已关闭'][$t['status']] ?? $t['status']) ?></span></div>
      <b><?= e($t['subject']) ?></b>
      <p class="muted"><?= nl2br(e($t['body'])) ?></p>
      <?php if ($t['reply']): ?>
      <div class="ticket-reply"><span class="sec-label">运营回复</span><?= nl2br(e($t['reply'])) ?></div>
      <?php endif; ?>
      <span class="ticket-time"><?= e($t['created_at']) ?></span>
      <?php if ($t['status'] !== 'closed'): ?>
      <details class="ticket-follow">
        <summary>补充信息 <span>继续回复这张工单</span></summary>
        <form class="auth-form ticket-follow-form" method="post" action="support.php">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="follow">
          <input type="hidden" name="follow_no" value="<?= e($t['ticket_no']) ?>">
          <div class="field">
            <label>补充内容</label>
            <textarea class="input textarea" name="follow_body" rows="3" maxlength="2000" placeholder="把运营要的信息填在这里；不要写密码" required></textarea>
          </div>
          <button class="btn sm primary" type="submit">提交补充</button>
        </form>
      </details>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php
sg_foot();
