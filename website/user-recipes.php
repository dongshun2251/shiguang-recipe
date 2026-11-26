<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u   = current_user();
$uid = (int) $u['id'];
$myRecipes = fetch_all('SELECT * FROM `' . T('recipes') . '` WHERE user_id = ? OR author_name = ? ORDER BY id DESC LIMIT 30', [$uid, $u['display_name'] ?: $u['username']]);

sg_head('我的菜谱', 'user-recipes', '我的菜谱');
?>
        <div class="me-subhead">
          <a class="me-back" href="user.php" aria-label="返回个人中心"><span class="ico" data-ico="back"></span></a>
          <div><h1>我的菜谱</h1><span>待审和已发布都在这儿</span></div>
          <a class="btn sm primary me-subhead-act" href="submit.php"><span class="ico" data-ico="plus"></span>投稿菜谱</a>
        </div>

<?php if ($myRecipes): ?>
        <div class="rgrid">
<?php foreach ($myRecipes as $r): ?>
          <div class="my-recipe">
<?php if ($r['status'] === 'published'): recipe_card($r); else: ?>
            <div class="rcard">
              <div class="rcard-media"><img src="<?= e(image_url($r['cover'])) ?>" alt=""></div>
              <div class="rcard-body">
                <h3><?= e($r['title']) ?></h3>
                <p><?= e($r['summary']) ?></p>
                <div class="rcard-foot"><span><?= badge_status($r['status']) ?></span><span><?= e(time_ago($r['updated_at'])) ?></span></div>
              </div>
            </div>
<?php endif; ?>
            <a class="btn sm my-recipe-edit" href="submit.php?edit=<?= (int) $r['id'] ?>"><span class="ico" data-ico="edit"></span><?= $r['status'] === 'published' ? '编辑并重新送审' : '编辑投稿' ?></a>
          </div>
<?php endforeach; ?>
        </div>
<?php else: ?>
        <div class="empty"><div class="ico" data-ico="chef"></div><b>还没有投稿过菜谱</b><p>把你的拿手菜写下来，管理员审核通过后就会出现在菜谱库。<a href="submit.php" style="color:var(--brand)">去投稿 →</a></p></div>
<?php endif; ?>
<?php
sg_foot();
