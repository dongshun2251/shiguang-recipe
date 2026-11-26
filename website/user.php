<?php
require_once __DIR__ . '/inc/bootstrap.php';

require_login();

/* 旧标签页链接兼容：统一跳到独立页面 */
$legacyTab = (string) get('tab', '');
$legacyMap = [
    'fav'     => 'user-favorites.php',
    'history' => 'user-history.php',
    'mine'    => 'user-recipes.php',
    'profile' => 'user-settings.php',
];
if ($legacyTab !== '' && isset($legacyMap[$legacyTab])) {
    redirect($legacyMap[$legacyTab]);
}

$u   = current_user();
$uid = (int) $u['id'];

$favCount      = (int) fetch_val('SELECT COUNT(*) FROM `' . T('favorites') . '` f JOIN `' . T('recipes') . '` r ON r.id = f.recipe_id WHERE f.user_id = ? AND r.status = "published"', [$uid], 0);
$historyCount  = count($_SESSION['history'] ?? []);
$myRecipeCount = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE user_id = ? OR author_name = ?', [$uid, $u['display_name'] ?: $u['username']], 0);
$myCommentCount= (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '` WHERE user_id = ?', [$uid], 0);

sg_head('个人中心', 'user', '个人中心');
?>
        <div class="page-head">
          <h1>个人中心</h1>
          <span class="sub desk-only">收藏、投稿和账号功能都从下面单独进入</span>
        </div>

        <div class="profile-hero">
          <?= sg_avatar((string) ($u['avatar'] ?? ''), (string) ($u['display_name'] ?: $u['username'])) ?>
          <div class="ph-name">
            <b><?= e($u['display_name'] ?: $u['username']) ?></b>
            <span>家庭主厨 · 加入 <?= max(1, (int) ((time() - strtotime($u['created_at'])) / 86400)) ?> 天</span>
          </div>
          <div class="nums">
            <div><b><?= $favCount ?></b><span>收藏</span></div>
            <div><b><?= $historyCount ?></b><span>浏览</span></div>
            <div><b><?= $myCommentCount ?></b><span>评论</span></div>
          </div>
        </div>

        <section class="me-panel">
          <div class="me-panel-head">
            <h2>我的内容</h2>
            <span>每个入口单独打开一页</span>
          </div>
          <div class="me-grid">
            <a class="me-cell" href="user-favorites.php">
              <span class="me-cell-ic"><span class="ico" data-ico="heart"></span></span>
              <div><b>我的收藏</b><span><?= $favCount ?> 道菜谱</span></div>
              <span class="me-cell-go"><span class="ico" data-ico="chev"></span></span>
            </a>
            <a class="me-cell" href="user-history.php">
              <span class="me-cell-ic"><span class="ico" data-ico="clock"></span></span>
              <div><b>浏览历史</b><span><?= $historyCount ?> 条记录</span></div>
              <span class="me-cell-go"><span class="ico" data-ico="chev"></span></span>
            </a>
            <a class="me-cell" href="user-recipes.php">
              <span class="me-cell-ic"><span class="ico" data-ico="book"></span></span>
              <div><b>我的菜谱</b><span><?= $myRecipeCount ?> 篇投稿</span></div>
              <span class="me-cell-go"><span class="ico" data-ico="chev"></span></span>
            </a>
            <a class="me-cell" href="shopping.php">
              <span class="me-cell-ic"><span class="ico" data-ico="list"></span></span>
              <div><b>采购清单</b><span>已加入的食材</span></div>
              <span class="me-cell-go"><span class="ico" data-ico="chev"></span></span>
            </a>
          </div>
        </section>

        <section class="me-panel">
          <div class="me-panel-head">
            <h2>创作与账号</h2>
            <span>资料、密码、退出</span>
          </div>
          <div class="me-list">
            <a class="me-row" href="submit.php">
              <span class="me-row-ic"><span class="ico" data-ico="chef"></span></span>
              <div><b>投稿菜谱</b><span>写菜名、食材和步骤</span></div>
              <span class="me-row-go"><span class="ico" data-ico="chev"></span></span>
            </a>
            <a class="me-row" href="user-settings.php">
              <span class="me-row-ic"><span class="ico" data-ico="settings"></span></span>
              <div><b>账号设置</b><span>头像、昵称、密码</span></div>
              <span class="me-row-go"><span class="ico" data-ico="chev"></span></span>
            </a>
            <a class="me-row" href="logout.php">
              <span class="me-row-ic"><span class="ico" data-ico="logout"></span></span>
              <div><b>退出登录</b><span>退出当前账号</span></div>
              <span class="me-row-go"><span class="ico" data-ico="chev"></span></span>
            </a>
          </div>
        </section>
<?php
sg_foot();
