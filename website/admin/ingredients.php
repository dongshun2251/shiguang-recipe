<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = post('do');
    $id = post_int('id');

    if ($do === 'delete' && $id) {
        $old = fetch_one('SELECT image FROM `' . T('ingredients') . '` WHERE id = ?', [$id]);
        q('DELETE FROM `' . T('ingredients') . '` WHERE id = ?', [$id]);
        if ($old && !empty($old['image'])) {
            $f = SG_ROOT . '/' . ltrim($old['image'], '/');
            if (is_file($f) && strpos($old['image'], 'uploads/ingredients/') === 0) { @unlink($f); }
        }
        flash('食材图片已删除');
        redirect('ingredients.php');
    }

    $name = trim(post('name'));
    $sort = post_int('sort');
    $image = '';

    try {
        if (!empty($_FILES['image']['tmp_name'])) {
            $image = upload_image($_FILES['image'], 'ingredients');
        } elseif (preg_match('#^uploads/ingredients/[A-Za-z0-9._-]+$#', (string) post('image_ai'))) {
            $image = (string) post('image_ai');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        redirect('ingredients.php');
    }

    if ($name === '') {
        flash('食材名称不能为空', 'err');
        redirect('ingredients.php');
    }

    $exists = fetch_one('SELECT id, image FROM `' . T('ingredients') . '` WHERE name = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$name, $id] : [$name]);
    if ($exists) {
        flash('同名食材已存在，可直接在原条目上替换图片', 'err');
        redirect('ingredients.php');
    }

    if ($id) {
        $old = fetch_one('SELECT image FROM `' . T('ingredients') . '` WHERE id = ?', [$id]);
        $data = ['name' => $name, 'sort' => $sort];
        if ($image !== '') {
            $data['image'] = $image;
            if ($old && !empty($old['image']) && strpos($old['image'], 'uploads/ingredients/') === 0) {
                $f = SG_ROOT . '/' . ltrim($old['image'], '/');
                if (is_file($f)) { @unlink($f); }
            }
        }
        db_update(T('ingredients'), $data, 'id = ?', [$id]);
        flash('食材已更新');
    } else {
        db_insert(T('ingredients'), ['name' => $name, 'image' => $image, 'sort' => $sort]);
        flash('食材已加入图库');
    }
    redirect('ingredients.php');
}

$rows = fetch_all('SELECT * FROM `' . T('ingredients') . '` ORDER BY sort ASC, id ASC');

sg_admin_head('食材图库', 'admin-ingredients', '食材图库');
?>
        <div class="page-head">
          <h1>食材图库</h1>
          <span class="sub desk-only">共 <?= count($rows) ?> 种 · 前台和长图按食材名自动匹配配图</span>
          <div class="actions">
            <button class="btn primary" type="button" onclick="document.getElementById('newIng').scrollIntoView({behavior:'smooth'})"><span class="ico" data-ico="plus"></span>添加食材</button>
          </div>
        </div>

        <?= flash_render() ?>

        <div class="alert"><span class="ico" data-ico="info"></span><span>这里维护的是<b>全局食材字典</b>：给「黑鱼、酸菜、姜、蒜……」配上图后，所有菜谱里出现同名食材时，详情页食材圆图和导出的长图都会自动用上。名称请和菜谱里写的一致（忽略空格和大小写）。</span></div>

        <div class="table-wrap">
          <table class="tb">
            <thead><tr><th style="width:72px">配图</th><th>食材名称</th><th style="width:70px">排序</th><th style="width:120px">操作</th></tr></thead>
            <tbody>
<?php foreach ($rows as $r): ?>
              <tr>
                <td>
<?php if (!empty($r['image'])): ?>
                  <img src="<?= e(image_url($r['image'])) ?>" alt="<?= e($r['name']) ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:1px solid var(--line)">
<?php else: ?>
                  <span style="display:inline-flex;width:52px;height:52px;border-radius:50%;background:#EFE6D6;align-items:center;justify-content:center;color:var(--muted);font-size:18px"><?= e(mb_substr($r['name'], 0, 1)) ?></span>
<?php endif; ?>
                </td>
                <td><input class="input" form="if<?= (int) $r['id'] ?>" name="name" value="<?= e($r['name']) ?>" style="height:28px;font-size:12px"></td>
                <td><input class="input" form="if<?= (int) $r['id'] ?>" name="sort" type="number" value="<?= (int) $r['sort'] ?>" style="height:28px;width:60px;padding:0 6px;font-size:12px"></td>
                <td><div class="row-act">
                  <?php if (ai_img_enabled()): ?>
                  <button class="iconbtn" type="button" title="AI 生成配图" data-ai-ing data-name="<?= e($r['name']) ?>" data-form="if<?= (int) $r['id'] ?>"><span class="ico" data-ico="bolt"></span></button>
                  <?php endif; ?>
                  <label class="iconbtn" title="替换图片" style="cursor:pointer"><span class="ico" data-ico="image"></span><input type="file" form="if<?= (int) $r['id'] ?>" name="image" accept="image/*" style="display:none" onchange="this.form.submit()"></label>
                  <button class="iconbtn" type="submit" form="if<?= (int) $r['id'] ?>" title="保存名称"><span class="ico" data-ico="check"></span></button>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="iconbtn" type="submit" data-confirm="确定删除食材「<?= e($r['name']) ?>」的配图？" title="删除"><span class="ico" data-ico="trash"></span></button>
                  </form>
                </div></td>
              </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
              <tr><td colspan="4"><div class="empty" style="padding:30px 0"><div class="ico" data-ico="image"></div><b>图库还是空的</b><p>在下方添加常用食材并配图，长图就能排成圆形食材图。</p></div></td></tr>
<?php endif; ?>
            </tbody>
          </table>
        </div>

        <div style="display:none">
<?php foreach ($rows as $r): ?>
          <form method="post" id="if<?= (int) $r['id'] ?>" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="image_ai" value="">
          </form>
<?php endforeach; ?>
        </div>

        <div class="section-head" style="margin-top:22px" id="newIng"><h2>添加食材</h2></div>
        <div class="card">
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save">
            <div class="form-grid">
              <div class="field"><label>食材名称 <span class="req">*</span></label><input class="input" name="name" placeholder="例如：黑鱼" required></div>
              <div class="field"><label>排序值</label><input class="input" name="sort" type="number" value="<?= count($rows) + 1 ?>"></div>
              <div class="field"><label>配图</label>
                <label class="btn sm" style="cursor:pointer"><span class="ico" data-ico="upload"></span>选择图片<input type="file" name="image" accept="image/*" style="display:none" onchange="this.form.querySelector('input[type=file]').closest('.field').querySelector('span.ing-fname').textContent=this.files[0]?this.files[0].name:'未选择'"></label>
                <span class="ing-fname" style="font-size:11.5px;color:var(--muted);margin-left:8px">未选择（也可以先建条目，之后再补图）</span>
              </div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;margin-top:14px">
              <button class="btn primary" type="submit" style="margin-left:auto"><span class="ico" data-ico="plus"></span>加入图库</button>
            </div>
          </form>
        </div>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-ai-ing]');
  if (!b || b.dataset.busy === '1') return;
  var name = b.getAttribute('data-name') || '';
  var form = document.getElementById(b.getAttribute('data-form') || '');
  if (!name || !form) return;
  b.dataset.busy = '1'; b.innerHTML = '…';
  var fd = new FormData();
  fd.append('prompt', name + ' 食材');
  fd.append('kind', 'ing');
  fd.append('_token', window.SG_CSRF || '');
  fetch((window.SG_API || 'api/') + 'ai-image.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j.ok) { SG_TOAST(j.msg || '生成失败'); b.dataset.busy = '0'; b.innerHTML = '<span class="ico" data-ico="bolt"></span>'; SG_HYDRATE(b); return; }
      form.querySelector('[name="image_ai"]').value = j.path;
      SG_TOAST('图片已生成，正在保存');
      form.submit();
    })
    .catch(function () { SG_TOAST('生成失败，检查图像接口'); b.dataset.busy = '0'; });
});
</script>
<?php
sg_admin_foot();
