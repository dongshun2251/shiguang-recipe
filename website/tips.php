<?php
require_once __DIR__ . '/inc/bootstrap.php';

$handbook = sg_cooking_handbook();
$tipRecipes = fetch_all('SELECT id, title, cover, tips FROM `' . T('recipes') . '` WHERE status = "published" AND tips <> "" ORDER BY published_at DESC, id DESC LIMIT 4');

sg_head('新手手册', 'tips', '新手厨房手册');
?>
        <div class="page-head">
          <h1>新手厨房手册</h1>
          <span class="sub desk-only">从开火到收汁，先把这几件事弄明白</span>
        </div>

        <div class="tips-hero">
          <div class="eyebrow">食光 · 新手第一课</div>
          <h2>先学会火候，再记住调味</h2>
          <p>不用背配方。做菜前先检查三件事：食材切好并沥干、调料放在手边、锅烧热再下油。剩下的，按下面的顺序慢慢练。</p>
          <div class="hb-quick">
<?php foreach ($handbook as $sec): $short = preg_split('/[：:]/u', $sec['title'])[0]; ?>
            <a href="#<?= e($sec['id']) ?>"><?= e($sec['no']) ?> <?= e($short) ?></a>
<?php endforeach; ?>
            <a href="#recipe-tips">跟着菜谱学</a>
          </div>
        </div>

<?php foreach ($handbook as $sec): ?>
        <section class="hb-sec" id="<?= e($sec['id']) ?>">
          <div class="section-head"><h2><span class="hb-no"><?= e($sec['no']) ?></span><?= e($sec['title']) ?></h2></div>
          <div class="hb-card">
            <p class="hb-intro"><?= e($sec['intro']) ?></p>
<?php foreach ($sec['items'] as $item): ?>
            <div class="hb-item<?= !empty($item['warn']) ? ' warn' : '' ?>">
              <b><?= e($item['t']) ?></b>
              <p><?= e($item['d']) ?></p>
            </div>
<?php endforeach; ?>
          </div>
        </section>
<?php endforeach; ?>

        <section class="hb-sec" id="recipe-tips">
          <div class="section-head"><h2><span class="hb-no">07</span>跟着菜谱学：具体菜的小贴士</h2></div>
<?php if ($tipRecipes): ?>
          <div class="hb-recipes">
<?php foreach ($tipRecipes as $tr): $lines = array_values(array_filter(array_map('trim', preg_split('/\n+/', (string) $tr['tips'])))); ?>
            <a class="hb-recipe" href="<?= e(recipe_url($tr)) ?>">
              <img src="<?= e(image_url($tr['cover'])) ?>" alt="<?= e($tr['title']) ?>" loading="lazy">
              <div><b><?= e($tr['title']) ?></b><p><?= e(mb_strimwidth($lines[0] ?? '', 0, 86, '…')) ?></p></div>
              <span class="ico" data-ico="chev"></span>
            </a>
<?php endforeach; ?>
          </div>
          <p class="tip-more">每道菜的具体提醒，在菜谱详情页的「制作提示」里；这里只摘最近更新的一小部分。</p>
<?php else: ?>
          <div class="hb-card"><p class="hb-intro" style="margin:0">后台给菜谱填写「制作提示」后，这里会自动出现对应的菜和小贴士。</p></div>
<?php endif; ?>
        </section>
<?php
sg_foot();
