<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u = current_user();

sg_head('账号信息', 'user-account', '账号信息');
?>
        <div class="me-subhead">
          <a class="me-back" href="user-settings.php" aria-label="返回账号设置"><span class="ico" data-ico="back"></span></a>
          <div><h1>账号信息</h1><span>用户名和注册信息</span></div>
        </div>

        <div class="card">
          <div class="card-head"><h3>当前账号</h3></div>
          <div class="meta-box">
            <div class="meta-row"><span class="ico" data-ico="user"></span><span>用户名</span><b><?= e($u['username']) ?></b></div>
            <div class="meta-row"><span class="ico" data-ico="calendar"></span><span>注册时间</span><b><?= e(fmt_date($u['created_at'])) ?></b></div>
            <div class="meta-row"><span class="ico" data-ico="shield"></span><span>账号状态</span><span class="badge live" style="margin-left:auto">正常</span></div>
          </div>
          <a class="btn block" style="margin-top:14px" href="logout.php"><span class="ico" data-ico="logout"></span>退出登录</a>
        </div>
<?php
sg_foot();
