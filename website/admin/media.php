<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$rows = fetch_all('SELECT id, title, cover, updated_at FROM `' . T('recipes') . '` WHERE cover <> "" ORDER BY id DESC');
$disk = 0;
foreach ($rows as $r) {
    $f = SG_ROOT . '/' . ltrim($r['cover'], '/');
    if (is_file($f)) {
        $disk += filesize($f);
    }
}

sg_admin_head('媒体库', 'admin-media', '媒体库');
?>
        <div class="page-head">
          <h1>媒体库</h1>
          <span class="sub desk-only"><?= count($rows) ?> 张菜谱封面 · 占用 <?= $disk > 1048576 ? round($disk / 1048576, 1) . ' MB' : round($disk / 1024) . ' KB' ?></span>
          <div class="actions">
            <a class="btn primary" href="recipe-edit.php"><span class="ico" data-ico="plus"></span>上传到新菜谱</a>
          </div>
        </div>

        <div class="alert"><span class="ico" data-ico="info"></span><span>这里只收<b>菜谱封面</b>，图片随菜谱保存在 <b>/uploads/recipes/</b>，上传时自动压缩到最长边 1280px。删除菜谱不会自动删除图片。<br>欢迎音频在「系统设置 → 欢迎音频」里管理；食材圆图在「食材图库」里维护。</span></div>

        <div class="rgrid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
<?php foreach ($rows as $r): ?>
          <div class="rcard" style="cursor:default">
            <div class="rcard-media"><img src="<?= e(image_url($r['cover'])) ?>" alt="<?= e($r['title']) ?>"></div>
            <div class="rcard-body">
              <h3 style="font-size:13px"><?= e($r['title']) ?></h3>
              <p style="font-size:11px"><?= e(basename($r['cover'])) ?></p>
              <div class="rcard-foot">
                <span><?= is_file(SG_ROOT . '/' . ltrim($r['cover'], '/')) ? round(filesize(SG_ROOT . '/' . ltrim($r['cover'], '/')) / 1024) . ' KB' : '文件缺失' ?></span>
                <a class="sp" href="recipe-edit.php?id=<?= (int) $r['id'] ?>" style="color:var(--brand)">替换</a>
              </div>
            </div>
          </div>
<?php endforeach; ?>
<?php if (!$rows): ?>
          <div class="empty" style="grid-column:1/-1"><div class="ico" data-ico="image"></div><b>还没有图片</b><p>在菜谱编辑页上传封面后，会出现在这里。</p></div>
<?php endif; ?>
        </div>
<?php
sg_admin_foot();
