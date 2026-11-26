<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = post('do');
    $id = post_int('id');

    if ($do === 'status' && $id) {
        $cur = (int) fetch_val('SELECT status FROM `' . T('users') . '` WHERE id = ?', [$id], 1);
        db_update(T('users'), ['status' => $cur ? 0 : 1], 'id = ?', [$id]);
        flash($cur ? '用户已禁用' : '用户已启用');
    } elseif ($do === 'delete' && $id) {
        q('DELETE FROM `' . T('users') . '` WHERE id = ?', [$id]);
        q('DELETE FROM `' . T('favorites') . '` WHERE user_id = ?', [$id]);
        flash('用户已删除');
    } elseif ($do === 'reset') {
        $pwd = (string) post('password');
        $adminPass = (string) post('admin_password');
        $a = current_admin();
        if (!$a || !password_verify($adminPass, $a['password_hash'])) {
            flash('管理员密码不正确，未重置', 'err');
        } elseif (strlen($pwd) < 6) {
            flash('新密码至少 6 位', 'err');
        } else {
            try {
                sg_sessions_revoke_all($id);
                db_update(T('users'), ['password_hash' => password_hash($pwd, PASSWORD_DEFAULT)], 'id = ?', [$id]);
                q('DELETE FROM `' . T('user_totp') . '` WHERE user_id = ?', [$id]);
                q('DELETE FROM `' . T('recovery_codes') . '` WHERE user_id = ?', [$id]);
                sg_admin_audit('user.reset_password', 'user:' . $id, '管理员重置密码，强制所有设备退出并关闭验证器');
                flash('密码已重置，该用户所有设备已退出，验证器已关闭');
            } catch (Throwable $e) {
                flash('重置失败：' . $e->getMessage(), 'err');
            }
        }
    }
    redirect('users.php' . (get('kw') ? '?kw=' . urlencode(get('kw')) : ''));
}

$kw   = trim(get('kw'));
$page = max(1, get_int('page', 1));
$per  = 12;
$w = $kw !== '' ? '(username LIKE ? OR display_name LIKE ?)' : '1';
$p = $kw !== '' ? ['%' . $kw . '%', '%' . $kw . '%'] : [];

$total = (int) fetch_val('SELECT COUNT(*) FROM `' . T('users') . '` WHERE ' . $w, $p, 0);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$rows  = fetch_all('SELECT u.*, (SELECT COUNT(*) FROM `' . T('favorites') . '` f WHERE f.user_id = u.id) AS favs,
                    (SELECT COUNT(*) FROM `' . T('comments') . '` c WHERE c.user_id = u.id) AS cmts,
                    (SELECT MAX(created_at) FROM `' . T('comments') . '` c WHERE c.user_id = u.id) AS last_cmt
                    FROM `' . T('users') . '` u WHERE ' . $w . ' ORDER BY u.id DESC LIMIT ' . (($page - 1) * $per) . ', ' . $per, $p);

sg_admin_head('用户管理', 'admin-users', '用户管理');
?>
        <div class="page-head">
          <h1>用户管理</h1>
          <span class="sub desk-only">共 <?= $total ?> 位注册用户</span>
          <div class="actions">
            <form class="search-field desk-only" method="get" action="users.php" style="height:34px">
              <span class="ico" data-ico="search"></span>
              <input name="kw" value="<?= e($kw) ?>" placeholder="搜索用户名 / 昵称">
            </form>
          </div>
        </div>

        <?= flash_render() ?>

        <div class="table-wrap">
          <table class="tb">
            <thead><tr><th>用户</th><th>收藏</th><th>评论</th><th>状态</th><th>注册时间</th><th>最后登录</th><th style="width:120px">操作</th></tr></thead>
            <tbody>
<?php foreach ($rows as $u): ?>
              <tr>
                <td><div class="tb-title"><?= sg_avatar((string) ($u['avatar'] ?? ''), (string) ($u['display_name'] ?: $u['username'])) ?><div><b><?= e($u['display_name'] ?: $u['username']) ?></b><span>@<?= e($u['username']) ?> · ID <?= (int) $u['id'] ?></span></div></div></td>
                <td><?= (int) $u['favs'] ?></td>
                <td><?= (int) $u['cmts'] ?></td>
                <td><span class="badge <?= $u['status'] ? 'live' : 'draft' ?>"><?= $u['status'] ? '正常' : '已禁用' ?></span></td>
                <td><?= e(fmt_date($u['created_at'])) ?></td>
                <td><?= e($u['last_login_at'] ? time_ago($u['last_login_at']) : '从未登录') ?></td>
                <td><div class="row-act">
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="iconbtn" type="submit" title="<?= $u['status'] ? '禁用' : '启用' ?>"><span class="ico" data-ico="<?= $u['status'] ? 'lock' : 'check' ?>"></span></button></form>
                  <form method="post" style="display:inline" onsubmit="var v=prompt('输入新密码（至少 6 位）：'); if(!v) return false; var a=prompt('再输入你的管理员密码确认：'); if(!a) return false; this.password.value=v; this.admin_password.value=a; return true;">
                    <?= csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="password" value=""><input type="hidden" name="admin_password" value="">
                    <button class="iconbtn" type="submit" title="重置密码"><span class="ico" data-ico="refresh"></span></button>
                  </form>
                  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="iconbtn" type="submit" data-confirm="删除用户「<?= e($u['username']) ?>」及其收藏？" title="删除" style="color:#B04624"><span class="ico" data-ico="trash"></span></button></form>
                </div></td>
              </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
              <tr><td colspan="8" style="text-align:center;padding:36px;color:var(--muted)"><?= $kw !== '' ? '没有匹配的用户' : '还没有注册用户' ?></td></tr>
<?php endif; ?>
            </tbody>
          </table>
        </div>
        <?= pager($page, $total, $per, 'users.php?' . http_build_query(array_filter(['kw' => $kw]))) ?>
<?php
sg_admin_foot();
