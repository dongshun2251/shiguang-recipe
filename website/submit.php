<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u    = current_user();
$uid  = (int) $u['id'];
$cats = fetch_all('SELECT * FROM `' . T('categories') . '` WHERE status = 1 ORDER BY sort ASC, id ASC');

$editId = get_int('edit');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = post_int('edit_id') ?: $editId;
}
$editRec = null;
if ($editId > 0) {
    $editRec = fetch_one('SELECT * FROM `' . T('recipes') . '` WHERE id = ? AND user_id = ?', [$editId, $uid]);
    if (!$editRec) {
        flash('找不到这篇投稿，或你没有修改权限。', 'err');
        redirect('user-recipes.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title = trim(post('title'));
    $errs  = [];
    if ($title === '') { $errs[] = '菜名不能为空'; }
    if (mb_strlen($title) > 40) { $errs[] = '菜名不超过 40 字'; }
    $ingNames = array_filter(array_map('trim', (array) ($_POST['ing_name'] ?? [])));
    if (!$ingNames) { $errs[] = '至少填一样食材'; }
    $stepBodies = array_filter(array_map('trim', (array) ($_POST['step_content'] ?? [])));
    if (!$stepBodies) { $errs[] = '至少写一个步骤'; }

    $cover = (string) ($editRec['cover'] ?? '');
    if (!empty($_FILES['cover']['tmp_name'])) {
        try {
            $cover = upload_image($_FILES['cover'], 'recipes');
        } catch (Throwable $e) {
            $errs[] = '封面上传失败：' . $e->getMessage();
        }
    }

    if ($errs) {
        foreach ($errs as $e) { flash($e, 'err'); }
        redirect('submit.php' . ($editId > 0 ? '?edit=' . $editId : ''));
    }

    $base = [
        'title'        => $title,
        'summary'      => mb_substr(trim(post('summary')), 0, 255),
        'category_id'  => post_int('category_id'),
        'difficulty'   => in_array(post('difficulty'), ['入门', '初级', '进阶'], true) ? post('difficulty') : '初级',
        'prep_minutes' => max(0, min(600, post_int('prep'))),
        'cook_minutes' => max(0, min(600, post_int('cook'))),
        'servings'     => trim(post('servings')) ?: '2 人份',
        'cover'        => $cover,
        'author_name'  => $u['display_name'] ?: $u['username'],
        'tips'         => mb_substr(trim(post('tips')), 0, 1000),
        'status'       => 'pending',
        'published_at' => null,
        'updated_at'   => date('Y-m-d H:i:s'),
    ];

    if ($editRec) {
        $rid = (int) $editRec['id'];
        $base['slug'] = (string) ($editRec['slug'] ?: slugify($title));
        db_update(T('recipes'), $base, 'id = ?', [$rid]);
        /* 用户修改已发布菜谱时先下架，等管理员重新审核通过再公开 */
        $saveMsg = $editRec['status'] === 'published' ? '修改已提交，旧版本已下架，重新审核通过后才会公开。' : '修改已提交，已重新进入审核。';
    } else {
        $slug = slugify($title);
        if (fetch_one('SELECT id FROM `' . T('recipes') . '` WHERE slug = ?', [$slug])) {
            $slug .= '-' . substr(md5(microtime()), 0, 4);
        }
        $base['slug'] = $slug;
        $base['user_id'] = $uid;
        $base['rating'] = 0;
        $rid = db_insert(T('recipes'), $base);
        $saveMsg = '投稿已提交，管理员审核通过后会出现在菜谱库。';
    }

    /* 食材 */
    q('DELETE FROM `' . T('recipe_ingredients') . '` WHERE recipe_id = ?', [$rid]);
    $qtys = $_POST['ing_qty'] ?? [];
    $i = 0;
    foreach ($ingNames as $k => $n) {
        $i++;
        db_insert(T('recipe_ingredients'), [
            'recipe_id' => $rid, 'name' => mb_substr($n, 0, 32),
            'quantity'  => mb_substr(trim((string) ($qtys[$k] ?? '')), 0, 32), 'sort' => $i,
        ]);
    }

    /* 步骤：保留原步骤配图（前台不能换图，但不能把已有图弄丢） */
    $oldSteps = $editRec ? fetch_all('SELECT image FROM `' . T('recipe_steps') . '` WHERE recipe_id = ? ORDER BY step_no ASC, id ASC', [$rid]) : [];
    q('DELETE FROM `' . T('recipe_steps') . '` WHERE recipe_id = ?', [$rid]);
    $stTitles = $_POST['step_title'] ?? [];
    $stDurs   = $_POST['step_duration'] ?? [];
    $no = 0;
    foreach ($stepBodies as $k => $body) {
        $no++;
        db_insert(T('recipe_steps'), [
            'recipe_id' => $rid, 'step_no' => $no,
            'title'     => mb_substr(trim((string) ($stTitles[$k] ?? '')), 0, 40),
            'content'   => mb_substr($body, 0, 2000),
            'duration'  => mb_substr(trim((string) ($stDurs[$k] ?? '')), 0, 32),
            'image'     => (string) ($oldSteps[$k]['image'] ?? ''),
        ]);
    }

    /* 标签 */
    q('DELETE FROM `' . T('recipe_tags') . '` WHERE recipe_id = ?', [$rid]);
    $tagList = array_filter(array_map('trim', preg_split('/[,，、\s]+/u', post('tags'))));
    foreach (array_slice($tagList, 0, 6) as $tn) {
        if (mb_strlen($tn) > 12) { continue; }
        $row = fetch_one('SELECT id FROM `' . T('tags') . '` WHERE name = ?', [$tn]);
        $tid = $row ? (int) $row['id'] : db_insert(T('tags'), ['name' => $tn, 'slug' => slugify($tn)]);
        q('INSERT IGNORE INTO `' . T('recipe_tags') . '` (recipe_id, tag_id) VALUES (?, ?)', [$rid, $tid]);
    }

    flash($saveMsg);
    redirect('submit.php?done=' . ($editRec ? 'edit' : '1'));
}

$doneParam = get('done');
$done = in_array($doneParam, ['1', 'edit'], true);
$doneEdit = $doneParam === 'edit';
$mine  = fetch_all('SELECT id, title, status, updated_at FROM `' . T('recipes') . '` WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$uid]);
$meta  = $done ? ($doneEdit ? ['修改已提交', '重新审核通过后恢复公开'] : ['投稿已提交', '审核通过后自动上线']) : ($editRec ? ['修改投稿', '修改后会重新进入审核'] : ['投稿菜谱', '写清楚步骤，管理员审核后发布']);
$editIngs  = $editRec ? fetch_all('SELECT name, quantity FROM `' . T('recipe_ingredients') . '` WHERE recipe_id = ? ORDER BY sort ASC, id ASC', [(int) $editRec['id']]) : [];
$editSteps = $editRec ? fetch_all('SELECT title, content, duration FROM `' . T('recipe_steps') . '` WHERE recipe_id = ? ORDER BY step_no ASC, id ASC', [(int) $editRec['id']]) : [];
$editTagRows = $editRec ? fetch_all('SELECT t.name FROM `' . T('recipe_tags') . '` rt JOIN `' . T('tags') . '` t ON t.id = rt.tag_id WHERE rt.recipe_id = ? ORDER BY t.id ASC', [(int) $editRec['id']]) : [];
$formTags = implode('、', array_map(function ($x) { return (string) $x['name']; }, $editTagRows));
$formTitle    = (string) ($editRec['title'] ?? '');
$formSummary  = (string) ($editRec['summary'] ?? '');
$formCat      = (int) ($editRec['category_id'] ?? 0);
$formDiff     = (string) ($editRec['difficulty'] ?? '初级');
$formPrep     = (int) ($editRec['prep_minutes'] ?? 10);
$formCook     = (int) ($editRec['cook_minutes'] ?? 20);
$formServings = (string) ($editRec['servings'] ?? '2 人份');
$formTips     = (string) ($editRec['tips'] ?? '');
$formCover    = (string) ($editRec['cover'] ?? '');

sg_head($meta[0], 'submit', $meta[0]);
?>
        <div class="page-head">
          <h1><?= e($meta[0]) ?></h1>
          <span class="sub desk-only"><?= e($meta[1]) ?></span>
          <div class="actions">
            <a class="btn" href="user-recipes.php"><span class="ico" data-ico="back"></span>我的菜谱</a>
          </div>
        </div>

        <?= flash_render() ?>

<?php if ($done): ?>
        <div class="card" style="text-align:center;padding:40px 20px">
          <div class="ico" data-ico="check" style="width:46px;height:46px;margin:0 auto 12px;color:var(--brand)"></div>
          <h2 style="margin:0 0 8px;font-family:var(--font-serif)"><?= $doneEdit ? '修改已提交' : '投稿已提交' ?></h2>
          <p style="color:var(--muted);font-size:13px;margin:0 0 18px"><?= $doneEdit ? '修改已提交，重新审核通过后才会公开。审核期间可以在「我的菜谱」查看状态。' : '管理员审核通过后，菜谱会自动出现在菜谱库。审核期间可以在「我的菜谱」查看状态。' ?></p>
          <a class="btn primary" href="user-recipes.php">查看我的菜谱</a>
          <a class="btn" href="submit.php" style="margin-left:8px"><?= $doneEdit ? '继续投稿' : '再投一道' ?></a>
        </div>
<?php else: ?>
<?php if ($editRec): ?>
        <div class="alert err" style="margin-bottom:14px"><span class="ico" data-ico="alert"></span><span>你正在修改<b>已投稿的菜谱</b>。<?= $editRec['status'] === 'published' ? '保存后旧版本会先下架，并重新进入' : '保存后会重新进入' ?><b>待审核</b>；管理员通过后才会公开。步骤配图会保留。</span></div>
<?php else: ?>
        <div class="alert" style="margin-bottom:14px"><span class="ico" data-ico="info"></span><span>投稿会进入<b>待审核</b>，通过后才对所有人可见。图片建议 4:3，主体居中。</span></div>
<?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="form-shell">
          <div class="form-block">
            <div class="form-block-head"><h2>基本资料</h2><span>先写清菜名、分类和时间</span></div>
          <?= csrf_field() ?>
<?php if ($editRec): ?><input type="hidden" name="edit_id" value="<?= (int) $editRec['id'] ?>"><?php endif; ?>
          <div class="field" style="margin-bottom:12px"><label>菜名 <span class="req">*</span></label><input class="input" name="title" maxlength="40" required value="<?= e($formTitle) ?>" placeholder="例如：外婆的红烧肉"></div>
          <div class="field" style="margin-bottom:12px"><label>一句话简介</label><input class="input" name="summary" maxlength="120" value="<?= e($formSummary) ?>" placeholder="这道菜的关键和口感"></div>
          <div class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
            <div class="field"><label>分类</label>
              <select class="select" name="category_id">
                <option value="0">未分类</option>
<?php foreach ($cats as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= $formCat === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>难度</label>
              <select class="select" name="difficulty">
<?php foreach (['入门', '初级', '进阶'] as $d): ?>
                <option value="<?= $d ?>"<?= $formDiff === $d ? ' selected' : '' ?>><?= $d ?></option>
<?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-grid submit-times" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:12px">
            <div class="field"><label>准备（分钟）</label><input class="input" name="prep" type="number" min="0" max="600" value="<?= (int) $formPrep ?>"></div>
            <div class="field"><label>烹饪（分钟）</label><input class="input" name="cook" type="number" min="0" max="600" value="<?= (int) $formCook ?>"></div>
            <div class="field"><label>份量</label><input class="input" name="servings" value="<?= e($formServings) ?>"></div>
          </div>
          </div>

          <div class="form-block">
            <div class="form-block-head"><h2>封面图</h2><span>建议 4:3，主体居中</span></div>
          <div class="field" style="margin-bottom:6px"><label>封面图</label>
<?php if ($formCover !== ''): ?>
            <div style="display:flex;align-items:center;gap:9px;margin-bottom:8px">
              <img src="<?= e(image_url($formCover)) ?>" alt="当前封面" style="width:78px;height:58px;object-fit:cover;border-radius:8px;border:1px solid var(--line-2)">
              <span style="font-size:11.5px;color:var(--muted)">当前封面；不选新图会保留</span>
            </div>
<?php endif; ?>
            <label class="btn sm" style="cursor:pointer"><span class="ico" data-ico="upload"></span><?= $formCover !== '' ? '更换图片' : '选择图片' ?><input type="file" name="cover" accept="image/*" style="display:none" onchange="this.closest('.field').querySelector('.fname').textContent=this.files[0]?this.files[0].name:'未选择'"></label>
            <span class="fname" style="font-size:11.5px;color:var(--muted);margin-left:8px">未选择</span>
          </div>

          </div>

          <div class="form-block">
            <div class="form-block-head"><h2>食材清单</h2><span>至少 1 项，写清用量</span>
              <button class="btn sm ghost" type="button" data-add-row="ing" style="margin-left:auto"><span class="ico" data-ico="plus"></span>加一行</button>
            </div>
          <div id="ingBox">
<?php if ($editIngs): ?>
<?php foreach ($editIngs as $ing): ?>
            <div class="ing-edit"><input class="input" name="ing_name[]" value="<?= e($ing['name']) ?>" placeholder="食材名称"><input class="input" name="ing_qty[]" value="<?= e($ing['quantity']) ?>" placeholder="用量，例如 500g"><button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button></div>
<?php endforeach; ?>
<?php else: ?>
            <div class="ing-edit"><input class="input" name="ing_name[]" placeholder="食材名称"><input class="input" name="ing_qty[]" placeholder="用量，例如 500g"><button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button></div>
            <div class="ing-edit"><input class="input" name="ing_name[]" placeholder="食材名称"><input class="input" name="ing_qty[]" placeholder="用量"><button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button></div>
<?php endif; ?>
          </div>

          </div>

          <div class="form-block">
            <div class="form-block-head"><h2>制作步骤</h2><span>至少 1 步，写清火候和时间</span>
              <button class="btn sm ghost" type="button" data-add-row="step" style="margin-left:auto"><span class="ico" data-ico="plus"></span>加一步</button>
            </div>
          <div id="stepBox">
<?php if ($editSteps): ?>
<?php foreach ($editSteps as $st): ?>
            <div class="card" style="border:1px solid var(--line-2);padding:12px;margin-bottom:10px;background:#FFFCF7">
              <div style="display:flex;gap:8px;margin-bottom:8px"><input class="input" name="step_title[]" value="<?= e($st['title']) ?>" placeholder="步骤标题" style="flex:1"><button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button></div>
              <textarea class="textarea" name="step_content[]" style="min-height:64px" placeholder="这一步怎么做"><?= e($st['content']) ?></textarea>
              <input class="input" name="step_duration[]" value="<?= e($st['duration']) ?>" placeholder="时间与火候，例如：约 8 分钟 · 中小火" style="margin-top:8px">
            </div>
<?php endforeach; ?>
<?php else: ?>
            <div class="card" style="border:1px solid var(--line-2);padding:12px;margin-bottom:10px;background:#FFFCF7">
              <div style="display:flex;gap:8px;margin-bottom:8px"><input class="input" name="step_title[]" placeholder="步骤标题" style="flex:1"><button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button></div>
              <textarea class="textarea" name="step_content[]" style="min-height:64px" placeholder="这一步怎么做"></textarea>
              <input class="input" name="step_duration[]" placeholder="时间与火候，例如：约 8 分钟 · 中小火" style="margin-top:8px">
            </div>
<?php endif; ?>
          </div>

          </div>

          <div class="form-block">
            <div class="form-block-head"><h2>标签与小贴士</h2><span>帮助别人更快找到、一次做成功</span></div>
          <div class="field" style="margin-top:0"><label>标签</label><input class="input" name="tags" value="<?= e($formTags) ?>" placeholder="用顿号分隔，最多 6 个，例如：下饭菜、炖煮"></div>
          <div class="field" style="margin-top:12px"><label>小贴士</label><textarea class="textarea" name="tips" style="min-height:80px" placeholder="一行一条，容易失败的地方写在这里"><?= e($formTips) ?></textarea></div>

          <div style="display:flex;gap:10px;margin-top:18px">
            <button class="btn primary" type="submit" style="margin-left:auto"><span class="ico" data-ico="check"></span><?= $editRec ? '保存并重新提交审核' : '提交审核' ?></button>
          </div>
          </div>
        </form>
<?php endif; ?>

<?php if ($mine): ?>
        <div class="section-head" style="margin-top:22px"><h2>我投稿过的</h2><span class="hint">最多显示 10 条</span></div>
        <div class="listcard">
<?php foreach ($mine as $m): ?>
          <div class="lrow" style="cursor:default">
            <div class="m"><b><?= e($m['title']) ?></b><span><?= e(time_ago($m['updated_at'])) ?> 更新</span></div>
            <span class="t"><?= badge_status($m['status']) ?></span>
            <a class="btn sm" href="submit.php?edit=<?= (int) $m['id'] ?>">编辑</a>
          </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>

<script>
document.addEventListener('click', function (e) {
  var add = e.target.closest('[data-add-row]');
  if (add) {
    var kind = add.getAttribute('data-add-row');
    if (kind === 'ing') {
      var row = document.createElement('div');
      row.className = 'ing-edit';
      row.innerHTML = '<input class="input" name="ing_name[]" placeholder="食材名称"><input class="input" name="ing_qty[]" placeholder="用量"><button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button>';
      document.getElementById('ingBox').appendChild(row);
    } else {
      var box = document.createElement('div');
      box.className = 'card';
      box.style.cssText = 'border:1px solid var(--line-2);padding:12px;margin-bottom:10px;background:#FFFCF7';
      box.innerHTML = '<div style="display:flex;gap:8px;margin-bottom:8px"><input class="input" name="step_title[]" placeholder="步骤标题" style="flex:1"><button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button></div><textarea class="textarea" name="step_content[]" style="min-height:64px" placeholder="这一步怎么做"></textarea><input class="input" name="step_duration[]" placeholder="时间与火候" style="margin-top:8px">';
      document.getElementById('stepBox').appendChild(box);
    }
    if (window.SG_HYDRATE) SG_HYDRATE(row || box);
  }
  var del = e.target.closest('[data-del-row]');
  if (del) { var p = del.closest('.ing-edit') || del.closest('.card'); if (p) p.remove(); }
});
</script>
<?php
sg_foot();
