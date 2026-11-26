<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

if (!sg_security_ready()) {
    sg_admin_head('客服工单', 'admin-support', '客服工单');
    echo '<div class="page-head"><h1>客服工单</h1></div><div class="alert err">数据库安全表未建好，先运行安装程序或检查数据库权限。</div>';
    sg_admin_foot();
    exit;
}

$id = (int) get_int('id', 0);
$detail = null;

if ($id) {
    $detail = fetch_one('SELECT * FROM `' . T('support_tickets') . '` WHERE id = ?', [$id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string) post('do');
    $tid = post_int('id');
    $t = $tid ? fetch_one('SELECT * FROM `' . T('support_tickets') . '` WHERE id = ?', [$tid]) : null;
    if (!$t) {
        flash('工单不存在', 'err');
        redirect('support.php');
    }

    if ($do === 'reply') {
        $reply = trim((string) post('reply'));
        $note  = trim((string) post('admin_note'));
        if (mb_strlen($reply) < 2) {
            flash('回复内容不能为空', 'err');
        } else {
            db_update(T('support_tickets'), [
                'reply'      => $reply,
                'admin_note' => $note,
                'status'     => 'answered',
                'handled_by' => (int) (current_admin()['id'] ?? 0),
            ], 'id = ?', [$tid]);
            sg_admin_audit('ticket.reply', 'ticket:' . $t['ticket_no'], '回复工单');
            flash('已回复，用户下次打开工单页能看到。');
        }
    } elseif ($do === 'close') {
        db_update(T('support_tickets'), ['status' => 'closed', 'handled_by' => (int) (current_admin()['id'] ?? 0)], 'id = ?', [$tid]);
        sg_admin_audit('ticket.close', 'ticket:' . $t['ticket_no'], '关闭工单');
        flash('工单已关闭');
    } elseif ($do === 'code') {
        $pass = (string) post('admin_password');
        $a = current_admin();
        if (!$a || !password_verify($pass, $a['password_hash'])) {
            flash('管理员密码不正确，未生成重置码', 'err');
        } else {
            $targetUid = (int) $t['user_id'];
            if ($targetUid <= 0 && trim((string) $t['username']) !== '') {
                $tu = fetch_one('SELECT * FROM `' . T('users') . '` WHERE username = ? AND status = 1', [$t['username']]);
                if ($tu) {
                    $targetUid = (int) $tu['id'];
                    db_update(T('support_tickets'), ['user_id' => $targetUid, 'username' => $tu['username']], 'id = ?', [$tid]);
                }
            }
            if ($targetUid <= 0) {
                flash('工单里的用户名没对上账号，先让用户补一个准确的用户名。', 'err');
            } else {
            $codes = sg_recovery_codes_generate($targetUid, 1, 'admin', 30, (int) $a['id']);
            $_SESSION['sg_admin_reset_codes'][(int) $tid] = $codes[0];
            db_update(T('support_tickets'), [
                'admin_note' => trim((string) $t['admin_note'] . "\n" . date('Y-m-d H:i') . ' 生成一次性重置码，30 分钟内有效。'),
                'handled_by' => (int) $a['id'],
            ], 'id = ?', [$tid]);
            sg_admin_audit('ticket.reset_code', 'ticket:' . $t['ticket_no'], '给用户 ' . (int) $targetUid . ' 生成一次性重置码');
            flash('一次性重置码已生成，只显示一次，请马上发给用户。');
            }
        }
    }
    redirect('support.php?id=' . $tid);
}

$status = (string) get('status', '');
$kw = trim((string) get('kw'));
$where = [];
$params = [];
if (in_array($status, ['open', 'answered', 'closed'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($kw !== '') {
    $where[] = '(ticket_no LIKE ? OR username LIKE ? OR subject LIKE ? OR contact LIKE ?)';
    array_push($params, '%' . $kw . '%', '%' . $kw . '%', '%' . $kw . '%', '%' . $kw . '%');
}
$w = $where ? implode(' AND ', $where) : '1';
$total = (int) fetch_val('SELECT COUNT(*) FROM `' . T('support_tickets') . '` WHERE ' . $w, $params, 0);
$rows = fetch_all('SELECT * FROM `' . T('support_tickets') . '` WHERE ' . $w . ' ORDER BY id DESC LIMIT 200', $params);
$openCnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('support_tickets') . '` WHERE status = ?', ['open'], 0);

$resetCode = '';
if ($id && !empty($_SESSION['sg_admin_reset_codes'][$id])) {
    $resetCode = (string) $_SESSION['sg_admin_reset_codes'][$id];
    unset($_SESSION['sg_admin_reset_codes'][$id]);
}

/* 客服回复模板：页面里可直接查看、填入回复框 */
$replyTplName   = $detail ? trim((string) $detail['username']) : '';
$replyTplTicket = $detail ? (string) $detail['ticket_no'] : '';
if ($replyTplName === '') {
    $replyTplName = '（请填写用户名）';
}
$replyTplCode = $resetCode !== '' ? $resetCode : '【先在上方生成重置码，再点“发放重置码”模板】';
$replyTplVerify = "你好，已收到你的工单（" . $replyTplTicket . "）。账号可以人工重置，但需要先确认是账号本人。请在「我的工单」里找到这张工单，点「补充信息」，填下面 4 项（不要重新开一张工单），不用发密码：\n\n"
    . "1. 完整用户名：" . $replyTplName . "（如不准确请更正）\n"
    . "2. 注册的大致年份和月份：\n"
    . "3. 最近一次登录的设备（手机/电脑）和时间：\n"
    . "4. 最近收藏或评论过的一道菜名：\n\n"
    . "核对无误后，我会给你一个一次性重置码，30 分钟内有效。";
$replyTplReset = "你好，身份已核对。你的账号一次性重置码是：\n\n"
    . $replyTplCode . "\n\n"
    . "有效期 30 分钟，只能使用一次。使用方法：\n"
    . "1. 打开「找回密码」页面\n"
    . "2. 用户名填：" . $replyTplName . "\n"
    . "3. 「动态码 / 恢复码 / 重置码」一栏填上面这串码\n"
    . "4. 点「验证并重设密码」，设置新密码（至少 6 位）\n\n"
    . "重置成功后，验证器会关闭，所有设备都会退出登录。请用新密码重新登录，并到「账号安全」重新绑定验证器、生成新的恢复码。\n"
    . "这串码不要转发给任何人；如果已经过期，请回复我重新生成。";

sg_admin_head($detail ? '工单 ' . $detail['ticket_no'] : '客服工单', 'admin-support', $detail ? '工单详情' : '客服工单');
?>
        <div class="page-head">
          <h1><?= $detail ? '工单 ' . e($detail['ticket_no']) : '客服工单' ?></h1>
          <span class="sub desk-only"><?= $detail ? '处理用户提交的问题' : '共 ' . $total . ' 条，待处理 ' . $openCnt . ' 条' ?></span>
          <div class="actions">
            <?php if ($detail): ?>
            <a class="btn sm" href="support.php"><span class="ico" data-ico="back"></span>回列表</a>
            <?php else: ?>
            <form class="search-field desk-only" method="get" action="support.php" style="height:34px">
              <span class="ico" data-ico="search"></span>
              <input name="kw" value="<?= e($kw) ?>" placeholder="工单号 / 用户名 / 标题">
            </form>
            <?php endif; ?>
          </div>
        </div>

        <?= flash_render() ?>

<?php if ($resetCode !== ''): ?>
        <section class="card sec-admin-code">
          <div class="card-head"><h3>一次性重置码</h3><span class="badge live">30 分钟有效</span></div>
          <p class="muted">只显示这一次。把它发给用户，让用户到「找回密码」页输入；用户重设成功后，该用户的验证器会关闭。</p>
          <div class="sec-reset-code"><code><?= e($resetCode) ?></code><button class="btn sm" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= e($resetCode) ?>').then(()=>{this.textContent='已复制'})">复制</button></div>
        </section>
<?php endif; ?>

<?php if (!$detail): ?>
        <div class="tabs support-tabs">
          <a class="<?= $status === '' ? 'on' : '' ?>" href="support.php">全部</a>
          <a class="<?= $status === 'open' ? 'on' : '' ?>" href="support.php?status=open">待处理<?= $openCnt ? ' ' . $openCnt : '' ?></a>
          <a class="<?= $status === 'answered' ? 'on' : '' ?>" href="support.php?status=answered">已回复</a>
          <a class="<?= $status === 'closed' ? 'on' : '' ?>" href="support.php?status=closed">已关闭</a>
        </div>

        <div class="table-wrap">
          <table class="tb">
            <thead><tr><th>工单</th><th>用户</th><th>标题</th><th>状态</th><th>提交时间</th><th style="width:100px">操作</th></tr></thead>
            <tbody>
<?php foreach ($rows as $t): ?>
              <tr>
                <td><code><?= e($t['ticket_no']) ?></code></td>
                <td><?= e($t['username'] ?: '游客') ?><?php if ($t['contact']): ?><div class="tb-sub"><?= e($t['contact']) ?></div><?php endif; ?></td>
                <td><b><?= e($t['subject']) ?></b></td>
                <td><span class="badge <?= $t['status'] === 'closed' ? 'draft' : ($t['status'] === 'answered' ? 'live' : '') ?>"><?= e(['open' => '待处理', 'answered' => '已回复', 'closed' => '已关闭'][$t['status']] ?? $t['status']) ?></span></td>
                <td><?= e(fmt_date($t['created_at'])) ?></td>
                <td><a class="btn sm" href="support.php?id=<?= (int) $t['id'] ?>">处理</a></td>
              </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
              <tr><td colspan="6"><div class="empty-mini">还没有工单</div></td></tr>
<?php endif; ?>
            </tbody>
          </table>
        </div>

<?php else: ?>
        <div class="support-detail-grid">
          <section class="card">
            <div class="card-head"><h3><?= e($detail['subject']) ?></h3><span class="badge <?= $detail['status'] === 'closed' ? 'draft' : ($detail['status'] === 'answered' ? 'live' : '') ?>"><?= e(['open' => '待处理', 'answered' => '已回复', 'closed' => '已关闭'][$detail['status']] ?? $detail['status']) ?></span></div>
            <div class="support-meta">
              <span>工单号 <code><?= e($detail['ticket_no']) ?></code></span>
              <span>用户 <b><?= e($detail['username'] ?: '游客') ?></b><?= $detail['user_id'] ? ' · ID ' . (int) $detail['user_id'] : '' ?></span>
              <span>联系 <b><?= e($detail['contact'] ?: '—') ?></b></span>
              <span>提交 <b><?= e($detail['created_at']) ?></b></span>
            </div>
            <div class="support-body"><?= nl2br(e($detail['body'])) ?></div>
            <?php if ($detail['reply']): ?>
            <div class="ticket-reply"><span class="sec-label">已回复</span><?= nl2br(e($detail['reply'])) ?></div>
            <?php endif; ?>
            <?php if ($detail['admin_note']): ?>
            <div class="admin-note"><span class="sec-label">内部备注（用户看不到）</span><?= nl2br(e($detail['admin_note'])) ?></div>
            <?php endif; ?>
          </section>

          <section class="card">
            <div class="card-head"><h3>处理</h3><span class="hint">回复后用户能在前台看到</span></div>
            <form method="post" class="auth-form">
              <?= csrf_field() ?><input type="hidden" name="do" value="reply"><input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
              <div class="field">
                <label>回复用户</label>
                <details class="reply-tpl">
                  <summary>回复模板 <span>查看示例，点一下填入</span></summary>
                  <div class="reply-tpl-body">
                    <div class="reply-tpl-item">
                      <div class="reply-tpl-item-head"><b>核实身份</b><button class="btn sm" type="button" data-reply-tpl="verify">填入回复框</button></div>
                      <pre class="reply-tpl-text"><?= e($replyTplVerify) ?></pre>
                    </div>
                    <div class="reply-tpl-item">
                      <div class="reply-tpl-item-head"><b>发放重置码</b><button class="btn sm" type="button" data-reply-tpl="reset">填入回复框</button></div>
                      <pre class="reply-tpl-text"><?= e($replyTplReset) ?></pre>
                    </div>
                    <p class="reply-tpl-note">发送前核对用户名和重置码；重置码只放在“回复用户”里，不要写进内部备注。</p>
                  </div>
                </details>
                <textarea class="input textarea" name="reply" rows="5" placeholder="说明处理结果或下一步" required><?= e($detail['reply'] ?? '') ?></textarea>
              </div>
              <div class="field"><label>内部备注（选填）</label><textarea class="input textarea" name="admin_note" rows="3" placeholder="只给后台看"><?= e($detail['admin_note'] ?? '') ?></textarea></div>
              <button class="btn primary" type="submit">保存回复</button>
            </form>
            <form method="post" class="sec-form" style="margin-top:12px">
              <?= csrf_field() ?><input type="hidden" name="do" value="close"><input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
              <button class="btn" type="submit" data-confirm="确定关闭这张工单吗？">关闭工单</button>
            </form>
          </section>

          <?php if ((int) $detail['user_id'] > 0 || trim((string) $detail['username']) !== ''): ?>
          <section class="card">
            <div class="card-head"><h3>账号兜底</h3><span class="hint">用户验证器与恢复码都丢了</span></div>
            <p class="muted">核对用户身份后，生成一次性重置码，默认 30 分钟有效。工单里有用户名会自动查找账号；找不到就说明用户名不准确。用户用它在「找回密码」页重设密码。</p>
            <form method="post" class="auth-form">
              <?= csrf_field() ?><input type="hidden" name="do" value="code"><input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
              <div class="field"><label>管理员密码（二次确认）</label><input class="input" type="password" name="admin_password" placeholder="输入当前管理员密码" required autocomplete="current-password"></div>
              <button class="btn primary" type="submit" data-confirm="确认给该用户发一次性重置码？">生成一次性重置码</button>
            </form>
          </section>
          <?php else: ?>
          <section class="card">
            <div class="card-head"><h3>账号兜底</h3><span class="hint">未关联账号</span></div>
            <p class="muted">这张工单没有填用户名，也没有关联到账号，不能发重置码。让用户补一个准确的用户名后重新提交，或先在「用户管理」里核对身份。</p>
          </section>
          <?php endif; ?>
        </div>
<?php endif; ?>
<script>
(function () {
  var tpls = <?= json_encode(['verify' => $replyTplVerify, 'reset' => $replyTplReset], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var ta = document.querySelector('textarea[name="reply"]');
  if (!ta) return;
  document.querySelectorAll('[data-reply-tpl]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = tpls[btn.getAttribute('data-reply-tpl')];
      if (!t) return;
      if (ta.value.trim() && !window.confirm('回复框里已有内容，要用模板替换吗？')) return;
      ta.value = t;
      ta.focus();
      ta.setSelectionRange(ta.value.length, ta.value.length);
    });
  });
  if (ta.form) {
    ta.form.addEventListener('submit', function (e) {
      if (/【[^】]*重置码[^】]*】|XXXXXX/.test(ta.value)) {
        e.preventDefault();
        window.alert('回复里还有重置码占位符。先在上方生成重置码，再点“发放重置码”模板填入真实码。');
        ta.focus();
      }
    });
  }
})();
</script>
<?php
sg_admin_foot();
