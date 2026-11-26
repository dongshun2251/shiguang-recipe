<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u   = current_user();
$uid = (int) $u['id'];
$favRows = fetch_all('SELECT f.created_at AS fav_at, r.* FROM `' . T('favorites') . '` f
    JOIN `' . T('recipes') . '` r ON r.id = f.recipe_id
    WHERE f.user_id = ? AND r.status = "published" ORDER BY f.id DESC', [$uid]);

sg_head('我的收藏', 'user-favorites', '我的收藏');
?>
        <div class="me-subhead">
          <a class="me-back" href="user.php" aria-label="返回个人中心"><span class="ico" data-ico="back"></span></a>
          <div><h1>我的收藏</h1><span>共 <?= count($favRows) ?> 道菜谱</span></div>
        </div>

<?php if ($favRows): ?>
        <div class="rgrid">
<?php foreach ($favRows as $r): recipe_card($r); endforeach; ?>
        </div>
<?php else: ?>
        <div class="empty" style="margin-top:10px">
          <div class="ico" data-ico="heart"></div>
          <b>还没有收藏任何菜谱</b>
          <p>在菜谱卡片或详情页点击心形按钮，就会出现在这里。<a href="recipes.php" style="color:var(--brand)">去菜谱库看看 →</a></p>
        </div>
<?php endif; ?>
<?php
sg_foot();
