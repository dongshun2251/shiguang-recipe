<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u = current_user();

sg_head('账号设置', 'user-settings', '账号设置');
?>
        <div class="me-subhead">
          <a class="me-back" href="user.php" aria-label="返回个人中心"><span class="ico" data-ico="back"></span></a>
          <div><h1>账号设置</h1><span>每项设置单独进入，不挤在同一页</span></div>
        </div>

        <div class="me-account-sum">
          <?= sg_avatar((string) ($u['avatar'] ?? ''), (string) ($u['display_name'] ?: $u['username'])) ?>
          <div><b><?= e($u['display_name'] ?: $u['username']) ?></b><span>@<?= e($u['username']) ?></span></div>
        </div>

        <section class="me-panel">
          <div class="me-panel-head"><h2>资料与安全</h2><span>点一项进入一项</span></div>
          <div class="me-list">
            <a class="me-row" href="user-profile.php">
              <span class="me-row-ic"><span class="ico" data-ico="user"></span></span>
              <div><b>个人资料</b><span>头像、昵称</span></div>
              <span class="me-row-go"><span class="ico" data-ico="chev"></span></span>
            </a>
            <a class="me-row" href="user-password.php">
              <span class="me-row-ic"><span class="ico" data-ico="lock"></span></span>
              <div><b>登录密码</b><span>修改当前账号密码</span></div>
              <span class="me-row-go"><span class="ico" data-ico="chev"></span></span>
            </a>
            <a class="me-row" href="user-security.php">
              <span class="me-row-ic"><span class="ico" data-ico="shield"></span></span>
              <div><b>账号安全</b><span>验证器、恢复码、人工兜底</span></div>
              <span class="me-row-go"><span class="ico" data-ico="chev"></span></span>
            </a>
            <a class="me-row" href="user-account.php">
              <span class="me-row-ic"><span class="ico" data-ico="shield"></span></span>
              <div><b>账号信息</b><span>用户名、注册时间、账号状态</span></div>
              <span class="me-row-go"><span class="ico" data-ico="chev"></span></span>
            </a>
          </div>
        </section>

        <section class="me-panel">
          <div class="me-panel-head"><h2>帮助</h2><span>遇到问题不用找邮箱</span></div>
          <div class="me-list">
            <a class="me-row" href="support.php">
              <span class="me-row-ic"><span class="ico" data-ico="comment"></span></span>
              <div><b>帮助与客服</b><span>提交工单，查看运营回复</span></div>
              <span class="me-row-go"><span class="ico" data-ico="chev"></span></span>
            </a>
          </div>
        </section>
<?php
sg_foot();
