<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$id  = get_int('id');
$rec = $id ? fetch_one('SELECT * FROM `' . T('recipes') . '` WHERE id = ?', [$id]) : null;
$ings  = $rec ? recipe_ingredients($id) : [];
$steps = $rec ? recipe_steps($id) : [];
$tags  = $rec ? array_column(recipe_tags($id), 'name') : [];
$cats  = fetch_all('SELECT * FROM `' . T('categories') . '` WHERE status = 1 ORDER BY sort ASC, id ASC');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'ai_draft') {
    csrf_verify();
    [$ok, $draft, $err] = ai_recipe_draft(trim(post('ai_name')), trim(post('ai_ings')), trim(post('ai_style')));
    if ($ok) {
        $_SESSION['sg_ai_draft'] = $draft;
        flash('AI 草稿已生成，检查无误后点「保存菜谱」');
    } else {
        flash($err ?: 'AI 生成失败', 'err');
    }
    redirect('recipe-edit.php?' . ($id ? 'id=' . $id . '&' : '') . 'ai=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'ai_image') {
    csrf_verify();
    $prompt = trim(post('ai_img_prompt'));
    if ($prompt === '') {
        flash('请填写图片描述', 'err');
    } else {
        [$ok, $path, $err] = ai_image_gen($prompt, 'recipes');
        if ($ok) {
            $_SESSION['sg_ai_cover'] = $path;
            flash('封面图已生成，保存菜谱后生效');
        } else {
            flash($err ?: '图片生成失败', 'err');
        }
    }
    redirect('recipe-edit.php?' . ($id ? 'id=' . $id . '&' : '') . 'ai=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $title = trim(post('title'));
    if ($title === '') {
        flash('菜谱名称不能为空', 'err');
        redirect('recipe-edit.php' . ($id ? '?id=' . $id : ''));
    }

    $cover = $rec['cover'] ?? '';
    if (!empty($_FILES['cover']['name'])) {
        try {
            $cover = upload_image($_FILES['cover'], 'recipes');
        } catch (Throwable $ex) {
            flash('封面上传失败：' . $ex->getMessage(), 'err');
            redirect('recipe-edit.php' . ($id ? '?id=' . $id : ''));
        }
    }
    if (post('cover_clear') === '1') {
        $cover = '';
    }
    $coverAi = trim(post('cover_ai'));
    if ($cover === '' && preg_match('#^uploads/recipes/[A-Za-z0-9._-]+$#', $coverAi)) {
        $cover = $coverAi;
    }

    $status = in_array(post('status'), ['published', 'draft', 'pending'], true) ? post('status') : (($rec['status'] ?? '') ?: 'published');
    $data = [
        'title'        => $title,
        'slug'         => trim(post('slug')) ?: slugify($title),
        'summary'      => trim(post('summary')),
        'category_id'  => post_int('category_id'),
        'difficulty'   => in_array(post('difficulty'), ['入门', '初级', '进阶'], true) ? post('difficulty') : '初级',
        'prep_minutes' => post_int('prep'),
        'cook_minutes' => post_int('cook'),
        'servings'     => post('servings', '2 人份'),
        'cover'        => $cover,
        'author_name'  => trim(post('author')),
        'tips'         => trim(post('tips')),
        'nutrition'    => trim(post('nutrition')),
        'status'       => $status,
        'is_featured'  => post('is_featured') === '1' ? 1 : 0,
        'updated_at'   => date('Y-m-d H:i:s'),
    ];

    if ($id) {
        if ($status === 'published' && empty($rec['published_at'])) {
            $data['published_at'] = date('Y-m-d H:i:s');
        } elseif ($status !== 'published') {
            $data['published_at'] = null;
        }
        db_update(T('recipes'), $data, 'id = ?', [$id]);
    } else {
        $data['published_at'] = $status === 'published' ? date('Y-m-d H:i:s') : null;
        $data['rating']       = 5.0;
        $id = db_insert(T('recipes'), $data);
    }

    /* 食材 */
    q('DELETE FROM `' . T('recipe_ingredients') . '` WHERE recipe_id = ?', [$id]);
    $names = $_POST['ing_name'] ?? [];
    $qtys  = $_POST['ing_qty'] ?? [];
    foreach ($names as $i => $n) {
        $n = trim((string) $n);
        if ($n === '') {
            continue;
        }
        db_insert(T('recipe_ingredients'), [
            'recipe_id' => $id,
            'name'      => $n,
            'quantity'  => trim((string) ($qtys[$i] ?? '')),
            'sort'      => $i + 1,
        ]);
    }

    /* 步骤 */
    q('DELETE FROM `' . T('recipe_steps') . '` WHERE recipe_id = ?', [$id]);
    $stTitle  = $_POST['step_title'] ?? [];
    $stBody   = $_POST['step_content'] ?? [];
    $stDur    = $_POST['step_duration'] ?? [];
    $stMin    = $_POST['step_minutes'] ?? [];
    $stHeat   = $_POST['step_heat'] ?? [];
    $stImages = $_POST['step_images'] ?? [];
    $stFiles  = $_FILES['step_image_files'] ?? [];
    $stepImgError = '';
    $no = 0;
    foreach ($stBody as $i => $body) {
        $body = trim((string) $body);
        $ttl  = trim((string) ($stTitle[$i] ?? ''));
        if ($body === '' && $ttl === '') {
            continue;
        }
        $no++;

        /* 保留的旧图：前端提交 JSON 数组 */
        $imgs = [];
        $rawImgs = $stImages[$i] ?? '[]';
        $arrImgs = is_array($rawImgs) ? $rawImgs : json_decode((string) $rawImgs, true);
        if (is_array($arrImgs)) {
            foreach ($arrImgs as $path) {
                $path = trim((string) $path);
                if ($path !== '' && preg_match('#^uploads/[A-Za-z0-9._/-]+$#', $path) && !in_array($path, $imgs, true)) {
                    $imgs[] = $path;
                }
            }
        }

        /* 新上传：每步最多 9 张 */
        $names = $stFiles['name'][$i] ?? [];
        if (!is_array($names)) { $names = [$names]; }
        $tooMany = false;
        foreach ($names as $k => $name) {
            $tmp = (string) ($stFiles['tmp_name'][$i][$k] ?? '');
            if ($tmp === '') { continue; }
            if (count($imgs) >= 9) { $tooMany = true; continue; }
            $one = [
                'name'     => (string) $name,
                'tmp_name' => $tmp,
                'type'     => (string) ($stFiles['type'][$i][$k] ?? ''),
                'error'    => (int) ($stFiles['error'][$i][$k] ?? UPLOAD_ERR_OK),
                'size'     => (int) ($stFiles['size'][$i][$k] ?? 0),
            ];
            try {
                $path = upload_image($one, 'steps');
                if (!in_array($path, $imgs, true)) { $imgs[] = $path; }
            } catch (Throwable $ex) {
                $stepImgError = $ex->getMessage();
            }
        }
        if (count($imgs) > 9) {
            $imgs = array_slice($imgs, 0, 9);
            $stepImgError = '每一步最多 9 张照片，多出的已忽略';
        } elseif ($tooMany) {
            $stepImgError = '每一步最多 9 张照片，新增照片未全部上传';
        }

        $minutes = trim((string) ($stMin[$i] ?? ''));
        $heat    = trim((string) ($stHeat[$i] ?? ''));
        if ($minutes !== '' || $heat !== '') {
            $duration = '';
            if ($minutes !== '' && is_numeric($minutes)) {
                $duration = rtrim(rtrim(number_format((float) $minutes, 1, '.', ''), '0'), '.') . ' 分钟';
            }
            if ($heat !== '') { $duration .= ($duration !== '' ? ' · ' : '') . $heat; }
        } else {
            $duration = trim((string) ($stDur[$i] ?? ''));
        }

        db_insert(T('recipe_steps'), [
            'recipe_id' => $id,
            'step_no'   => $no,
            'title'     => $ttl,
            'content'   => $body,
            'duration'  => $duration,
            'image'     => $imgs[0] ?? '',
            'images'    => $imgs ? json_encode($imgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ]);
    }

    /* 标签 */
    q('DELETE FROM `' . T('recipe_tags') . '` WHERE recipe_id = ?', [$id]);
    $tagList = array_filter(array_map('trim', preg_split('/[,，、\s]+/u', post('tags'))));
    foreach ($tagList as $tn) {
        if (mb_strlen($tn) > 16) {
            continue;
        }
        $row = fetch_one('SELECT id FROM `' . T('tags') . '` WHERE name = ?', [$tn]);
        if ($row) {
            $tid = (int) $row['id'];
        } else {
            $tid = db_insert(T('tags'), ['name' => $tn, 'slug' => slugify($tn)]);
        }
        q('INSERT IGNORE INTO `' . T('recipe_tags') . '` (recipe_id, tag_id) VALUES (?, ?)', [$id, $tid]);
    }

    unset($_SESSION['sg_ai_draft'], $_SESSION['sg_ai_cover']);
    $saveMsg = '菜谱已保存' . ($status === 'published' ? '并发布' : ($status === 'pending' ? '，保持待审核' : '为草稿'));
    if ($stepImgError !== '') { $saveMsg .= '；步骤配图上传失败：' . $stepImgError; }
    flash($saveMsg, $stepImgError !== '' ? 'err' : 'ok');
    redirect('recipe-edit.php?id=' . $id);
}

$isNew = !$rec;

/* 把 AI 草稿并入当前表单（只在本次请求内生效，保存后清掉） */
$aiDraft = $_SESSION['sg_ai_draft'] ?? null;
if (is_array($aiDraft) && !empty($aiDraft['title'])) {
    $rec = is_array($rec) ? $rec : ['status' => 'published', 'rating' => 5.0];
    $rec['title']        = $aiDraft['title'];
    $rec['summary']      = (string) ($aiDraft['summary'] ?? '');
    $rec['servings']     = (string) ($aiDraft['servings'] ?? '2 人份');
    $rec['prep_minutes'] = (int) ($aiDraft['prep_minutes'] ?? 10);
    $rec['cook_minutes'] = (int) ($aiDraft['cook_minutes'] ?? 20);
    $diffMap = ['初级' => '入门', '中级' => '初级', '高级' => '进阶', '入门' => '入门', '进阶' => '进阶'];
    $rec['difficulty']   = $diffMap[$aiDraft['difficulty'] ?? '初级'] ?? '初级';
    $tags                = array_values(array_filter((array) ($aiDraft['tags'] ?? [])));
    $rec['tips']         = implode("\n", array_map('strval', (array) ($aiDraft['tips'] ?? [])));
    if (!empty($aiDraft['nutrition']) && is_array($aiDraft['nutrition'])) {
        $n  = $aiDraft['nutrition'];
        $ns = [];
        foreach (['calories' => '热量', 'protein' => '蛋白质', 'fat' => '脂肪', 'carb' => '碳水'] as $k => $lb) {
            if (!empty($n[$k])) { $ns[] = $lb . ' ' . $n[$k]; }
        }
        if (!empty($n['note'])) { $ns[] = (string) $n['note']; }
        $rec['nutrition'] = implode(' · ', $ns);
    }
    /* 分类按名字匹配 */
    $catName = (string) ($aiDraft['category'] ?? '');
    if ($catName !== '') {
        foreach ($cats as $c) {
            if (mb_strpos($c['name'], $catName) !== false || mb_strpos($catName, $c['name']) !== false) {
                $rec['category_id'] = (int) $c['id'];
                break;
            }
        }
    }
    $ings = [];
    foreach ((array) ($aiDraft['ingredients'] ?? []) as $it) {
        $ings[] = ['name' => (string) ($it['name'] ?? ''), 'quantity' => (string) ($it['quantity'] ?? '')];
    }
    $steps = [];
    foreach ((array) ($aiDraft['steps'] ?? []) as $it) {
        $steps[] = ['title' => (string) ($it['title'] ?? ''), 'content' => (string) ($it['content'] ?? ''), 'duration' => (string) ($it['duration'] ?? ''), 'image' => ''];
    }
}

$aiCover = (string) ($_SESSION['sg_ai_cover'] ?? '');
if ($aiCover !== '' && empty($rec['cover'])) {
    $rec = is_array($rec) ? $rec : [];
    $rec['cover'] = $aiCover;
}

sg_admin_head($isNew ? '新增菜谱' : '编辑菜谱', 'admin-edit', $isNew ? '新增菜谱' : '编辑菜谱');
?>
        <div class="page-head">
          <h1><?= $isNew ? '新增菜谱' : '编辑菜谱' ?></h1>
          <span class="sub desk-only"><?= $isNew ? '填写完成后保存即可发布' : 'ID ' . $id . ' · 最后保存 ' . e(time_ago($rec['updated_at'])) ?></span>
          <div class="actions">
            <a class="btn" href="recipes.php"><span class="ico" data-ico="back"></span>返回列表</a>
            <?php if (!$isNew): ?><a class="btn desk-only" href="../recipe.php?id=<?= $id ?>" target="_blank"><span class="ico" data-ico="eye"></span>前台预览</a><?php endif; ?>
            <button class="btn primary mob-only" type="submit" form="editForm"><span class="ico" data-ico="check"></span>保存</button>
          </div>
        </div>

        <?= flash_render() ?>

        <?php if (ai_enabled()): ?>
        <div class="card ai-box" style="margin-bottom:14px;background:#FBF6EE;border:1px dashed #D8C4A8">
          <div class="card-head" style="padding:0;border:0;margin-bottom:12px">
            <h3>AI 写菜谱</h3><span class="hint">AI 生成草稿，填入下表后由你确认</span>
          </div>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="ai_draft">
            <div class="form-grid" style="grid-template-columns:1fr 1fr">
              <div class="field"><label>菜名</label><input class="input" name="ai_name" value="<?= e($rec['title'] ?? '') ?>" placeholder="例如：番茄牛腩煲"></div>
              <div class="field"><label>口味/风格（选填）</label><input class="input" name="ai_style" placeholder="例如：少油、下饭、给孩子吃"></div>
            </div>
            <div class="field" style="margin-top:12px"><label>手上有的食材（选填）</label><input class="input" name="ai_ings" placeholder="例如：牛腩 500g、番茄 3 个、洋葱半个"></div>
            <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
              <button class="btn primary" type="submit"><span class="ico" data-ico="bolt"></span>生成草稿</button>
              <?php if (ai_img_enabled()): ?>
              <button class="btn" type="submit" name="do" value="ai_image" formnovalidate onclick="this.form.querySelector('[name=ai_img_prompt]').value=(this.form.querySelector('[name=ai_name]').value||'家常菜')+'，美食摄影，暖光，浅色背景'"><span class="ico" data-ico="image"></span>顺带生成封面</button>
              <input type="hidden" name="ai_img_prompt" value="">
              <?php endif; ?>
            </div>
            <p class="tip" style="margin-top:10px">生成会覆盖下方未保存的修改，建议新菜谱先用。约需 20-60 秒，取决于本机模型大小。</p>
          </form>
        </div>
        <?php else: ?>
        <div class="alert" style="margin-bottom:14px"><span class="ico" data-ico="info"></span><span>还没启用本地 AI。到 <a href="settings.php" style="color:var(--brand)">系统设置 → 本地 AI</a> 填服务地址和模型名，就能一键生成菜谱草稿。</span></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="editForm">
          <?= csrf_field() ?>
          <input type="hidden" name="cover_ai" value="<?= e($aiCover) ?>">
          <div class="editor-cols">
            <div>
              <div class="card">
                <div class="card-head"><h3>基本信息</h3><span class="hint">带 <span class="req">*</span> 为必填</span></div>
                <div class="field" style="margin-bottom:14px">
                  <label>菜谱名称 <span class="req">*</span></label>
                  <input class="input" name="title" value="<?= e($rec['title'] ?? '') ?>" placeholder="例如：番茄牛腩煲" required>
                </div>
                <div class="field" style="margin-bottom:14px">
                  <label>一句话简介</label>
                  <input class="input" name="summary" value="<?= e($rec['summary'] ?? '') ?>" maxlength="255" placeholder="一句话说明这道菜的关键与口感">
                </div>
                <div class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:14px">
                  <div class="field"><label>分类 <span class="req">*</span></label>
                    <select class="select" name="category_id" required>
                      <option value="">请选择分类</option>
<?php foreach ($cats as $c): ?>
                      <option value="<?= (int) $c['id'] ?>"<?= (int) ($rec['category_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
<?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>难度</label>
                    <select class="select" name="difficulty">
<?php foreach (['入门', '初级', '进阶'] as $d): ?>
                      <option value="<?= $d ?>"<?= ($rec['difficulty'] ?? '初级') === $d ? ' selected' : '' ?>><?= $d ?></option>
<?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr">
                  <div class="field"><label>准备时间（分钟）</label><input class="input" name="prep" type="number" min="0" value="<?= (int) ($rec['prep_minutes'] ?? 10) ?>"></div>
                  <div class="field"><label>烹饪时间（分钟）</label><input class="input" name="cook" type="number" min="0" value="<?= (int) ($rec['cook_minutes'] ?? 20) ?>"></div>
                  <div class="field"><label>份量</label><input class="input" name="servings" value="<?= e($rec['servings'] ?? '2 人份') ?>"></div>
                </div>
                <div class="field" style="margin-top:14px">
                  <label>标签</label>
                  <input class="input" name="tags" value="<?= e(implode('、', $tags)) ?>" placeholder="用顿号或逗号分隔，例如：下饭菜、炖煮、一锅出">
                </div>
              </div>

              <div class="card" style="margin-top:14px">
                <div class="card-head"><h3>封面图</h3><span class="hint">建议 4:3，宽度 ≥ 1200px，≤ 8MB</span></div>
                <div class="dropzone">
<?php if (!empty($rec['cover'])): ?>
                  <img src="<?= e(image_url($rec['cover'])) ?>" alt="">
<?php endif; ?>
                  <div class="tx" style="flex:1;min-width:0">
                    <b><?= !empty($rec['cover']) ? e(basename($rec['cover'])) : '尚未选择封面' ?></b>
                    <span>支持 JPG / PNG / WebP，系统会自动压缩</span>
                  </div>
                  <label class="btn sm" style="cursor:pointer"><span class="ico" data-ico="upload"></span>选择图片<input type="file" name="cover" accept="image/*" style="display:none" onchange="this.form.querySelector('.dropzone .tx b').textContent=this.files[0]?this.files[0].name:'尚未选择封面'"></label>
                </div>
                <?php if (!empty($rec['cover'])): ?>
                <label class="ckrow" style="margin-top:10px"><input type="checkbox" name="cover_clear" value="1"><span>移除当前封面</span></label>
                <?php endif; ?>
              </div>

              <div class="card" style="margin-top:14px">
                <div class="card-head"><h3>食材清单</h3><span class="hint">圆图来自 <a href="ingredients.php">食材图库</a> 自动匹配</span>
                  <div class="right"><button class="btn sm" type="button" data-add-ing><span class="ico" data-ico="plus"></span>添加</button></div>
                </div>
                <div id="ingList">
<?php
                $ingRows = $ings ?: [['name' => '', 'quantity' => '']];
                foreach ($ingRows as $ing):
?>
                  <div class="ing-edit">
                    <input class="input" name="ing_name[]" value="<?= e($ing['name']) ?>" placeholder="食材名称">
                    <input class="input" name="ing_qty[]" value="<?= e($ing['quantity']) ?>" placeholder="用量">
                    <button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button>
                  </div>
<?php endforeach; ?>
                </div>
              </div>

              <div class="card" style="margin-top:14px">
                <div class="card-head"><h3>制作步骤</h3><span class="hint">配图会作为前台左侧编号块的背景</span>
                  <div class="right"><button class="btn sm" type="button" data-add-step><span class="ico" data-ico="plus"></span>添加步骤</button></div>
                </div>
                <div id="stepList">
<?php
                $stepRows = $steps ?: [['title' => '', 'content' => '', 'duration' => '', 'image' => '', 'images' => '']];
                foreach ($stepRows as $i => $s):
                  $sImgs = step_images($s);
                  [$durMin, $durHeat] = step_duration_parts((string) $s['duration']);
?>
                  <div class="card step-edit-card" style="border:1px solid var(--line-2);padding:12px;margin-bottom:10px;background:#FFFCF7">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                      <span class="idx" style="width:26px;height:26px;border-radius:7px;background:#F1E7D9;color:#B0663D;font-weight:700;display:grid;place-items:center;font-size:12px;flex:0 0 26px" data-step-no>—</span>
                      <input class="input" name="step_title[<?= (int) $i ?>]" value="<?= e($s['title']) ?>" placeholder="步骤标题，例如：牛腩焯水去腥" style="flex:1">
                      <?php if (ai_img_enabled()): ?>
                      <button class="btn sm" type="button" data-ai-step data-prompt="<?= e(trim($s['title'] . ' ' . mb_substr((string) $s['content'], 0, 40))) ?>"><span class="ico" data-ico="image"></span>配图</button>
                      <?php endif; ?>
                      <button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button>
                    </div>

                    <div class="step-photo-list" data-step-photo-list>
<?php foreach ($sImgs as $k => $img): ?>
                      <div class="step-photo" data-path="<?= e($img) ?>">
                        <img src="<?= e(image_url($img)) ?>" alt="">
                        <div class="step-photo-actions">
                          <button type="button" class="step-photo-move" data-step-photo-move="-1" title="前移"<?= $k === 0 ? ' disabled' : '' ?>>&lt;</button>
                          <button type="button" class="step-photo-move" data-step-photo-move="1" title="后移"<?= $k === count($sImgs) - 1 ? ' disabled' : '' ?>>&gt;</button>
                          <button type="button" class="step-photo-del" data-step-photo-del title="移除这张照片">×</button>
                        </div>
                      </div>
<?php endforeach; ?>
                    </div>

                    <textarea class="textarea" name="step_content[<?= (int) $i ?>]" style="min-height:64px" placeholder="这一步具体怎么做，火候与时间写清楚"><?= e($s['content']) ?></textarea>
                    <div class="duration-row">
                      <div class="duration-field">
                        <label>本步用时</label>
                        <div class="input-unit"><input class="input" name="step_minutes[<?= (int) $i ?>]" type="number" min="0" max="999" step="1" value="<?= e($durMin) ?>" placeholder="5"><span>分钟</span></div>
                      </div>
                      <div class="duration-field">
                        <label>火候 / 补充说明</label>
                        <input class="input" name="step_heat[<?= (int) $i ?>]" value="<?= e($durHeat) ?>" placeholder="选填，例如：大火、小火慢炖">
                      </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px">
                      <label class="btn sm" style="cursor:pointer"><span class="ico" data-ico="upload"></span>添加照片<input type="file" name="step_image_files[<?= (int) $i ?>][]" accept="image/*" multiple style="display:none" data-step-files></label>
                      <span class="hint">可多选，每步最多 9 张；JPG / PNG / WebP，单张 8MB 内；上传后可用 &lt; &gt; 调整顺序</span>
                    </div>
                    <input type="hidden" name="step_images[<?= (int) $i ?>]" value="<?= e(json_encode($sImgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-step-images>
                  </div>
<?php endforeach; ?>
                </div>
              </div>

              <div class="card" style="margin-top:14px">
                <div class="card-head"><h3>制作提示</h3><span class="hint">一行一条，前台以提示卡片展示</span></div>
                <div class="field"><textarea class="textarea" name="tips" style="min-height:96px" placeholder="每行一条，例如：&#10;番茄要选熟透的沙瓤品种&#10;焯水后用温水冲洗，冷水会让牛肉变柴"><?= e($rec['tips'] ?? '') ?></textarea></div>
              </div>
            </div>

            <aside>
              <div class="card" style="position:sticky;top:14px">
                <div class="card-head"><h3>发布</h3></div>
                <div class="field" style="margin-bottom:12px"><label>状态</label>
                  <select class="select" name="status">
                    <option value="published"<?= ($rec['status'] ?? 'published') === 'published' ? ' selected' : '' ?>>已发布</option>
                    <option value="pending"<?= ($rec['status'] ?? '') === 'pending' ? ' selected' : '' ?>>待审核（不公开）</option>
                    <option value="draft"<?= ($rec['status'] ?? '') === 'draft' ? ' selected' : '' ?>>草稿</option>
                  </select>
                </div>
                <div class="field" style="margin-bottom:12px"><label>作者</label><input class="input" name="author" value="<?= e($rec['author_name'] ?? (current_admin()['display_name'] ?? '')) ?>"></div>
                <div class="field" style="margin-bottom:12px"><label>URL 别名</label><input class="input" name="slug" value="<?= e($rec['slug'] ?? '') ?>" placeholder="留空自动生成"></div>
                <div class="field" style="margin-bottom:14px"><label>营养参考</label><textarea class="textarea" name="nutrition" style="min-height:60px" placeholder="蛋白质 32 g · 脂肪 21 g（每份）"><?= e($rec['nutrition'] ?? '') ?></textarea></div>
                <label class="ckrow" style="margin-bottom:12px"><input type="checkbox" name="is_featured" value="1"<?= !empty($rec['is_featured']) ? ' checked' : '' ?>><span>设为首页推荐</span></label>
                <button class="btn primary block desk-only" type="submit"><span class="ico" data-ico="check"></span>保存菜谱</button>
                <?php if (!$isNew): ?>
                <a class="btn block" style="margin-top:8px" href="../recipe.php?id=<?= $id ?>" target="_blank"><span class="ico" data-ico="eye"></span>查看前台效果</a>
                <?php endif; ?>
              </div>

              <div class="card" style="margin-top:14px;background:#FBF6EE">
                <div class="card-head"><h3>编辑提示</h3></div>
                <div class="tips">
                  <div class="tip"><span class="ico" data-ico="info"></span><span>步骤标题写清动作，内容写清火候与时间，新手照着做更容易成功。</span></div>
                  <div class="tip warn"><span class="ico" data-ico="alert"></span><span>封面图会裁剪为 4:3 展示，尽量选择主体居中的图片。</span></div>
                </div>
              </div>
            </aside>
          </div>
        </form>

        <div class="edit-foot">
          <a class="btn" href="recipes.php"><span class="ico" data-ico="back"></span>返回列表</a>
          <?php if (!$isNew): ?><a class="btn" href="../recipe.php?id=<?= $id ?>" target="_blank"><span class="ico" data-ico="eye"></span>前台预览</a><?php endif; ?>
          <button class="btn primary" type="submit" form="editForm"><span class="ico" data-ico="check"></span><?= $isNew ? '保存并发布' : '保存修改' ?></button>
        </div>

<script>
(function () {
  var stepNo = function () {
    document.querySelectorAll('#stepList [data-step-no]').forEach(function (el, i) {
      el.textContent = String(i + 1).padStart(2, '0');
    });
  };
  var stepSeq = 0;
  document.querySelectorAll('#stepList [name^="step_title["]').forEach(function (el) {
    var m = el.getAttribute('name').match(/\[(\d+)\]/);
    if (m) { stepSeq = Math.max(stepSeq, Number(m[1]) + 1); }
  });
  var syncStepImages = function (box) {
    var list = box.querySelector('[data-step-photo-list]');
    var hidden = box.querySelector('[data-step-images]');
    if (!list || !hidden) return;
    var photos = [].slice.call(list.querySelectorAll('.step-photo'));
    hidden.value = JSON.stringify(photos.map(function (p) { return p.getAttribute('data-path') || ''; }).filter(Boolean));
    photos.forEach(function (p, k) {
      [].slice.call(p.querySelectorAll('[data-step-photo-move]')).forEach(function (b) {
        var dir = Number(b.getAttribute('data-step-photo-move'));
        b.disabled = (dir < 0 && k === 0) || (dir > 0 && k === photos.length - 1);
      });
    });
  };
  document.addEventListener('click', function (e) {
    var addIng = e.target.closest('[data-add-ing]');
    if (addIng) {
      var row = document.createElement('div');
      row.className = 'ing-edit';
      row.innerHTML = '<input class="input" name="ing_name[]" placeholder="食材名称"><input class="input" name="ing_qty[]" placeholder="用量"><button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button>';
      document.getElementById('ingList').appendChild(row);
      SG_HYDRATE(row);
    }
    var addStep = e.target.closest('[data-add-step]');
    if (addStep) {
      var box = document.createElement('div');
      var n = stepSeq++;
      box.className = 'card step-edit-card';
      box.style.cssText = 'border:1px solid var(--line-2);padding:12px;margin-bottom:10px;background:#FFFCF7';
      box.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">' +
        '<span style="width:26px;height:26px;border-radius:7px;background:#F1E7D9;color:#B0663D;font-weight:700;display:grid;place-items:center;font-size:12px;flex:0 0 26px" data-step-no>—</span>' +
        '<input class="input" name="step_title[' + n + ']" placeholder="步骤标题" style="flex:1">' +
        '<button class="btn sm" type="button" data-ai-step><span class="ico" data-ico="image"></span>配图</button>' +
        '<button class="row-del" type="button" data-del-row><span class="ico" data-ico="trash"></span></button></div>' +
        '<div class="step-photo-list" data-step-photo-list></div>' +
        '<textarea class="textarea" name="step_content[' + n + ']" style="min-height:64px" placeholder="这一步具体怎么做"></textarea>' +
        '<div class="duration-row">' +
        '<div class="duration-field"><label>本步用时</label><div class="input-unit"><input class="input" name="step_minutes[' + n + ']" type="number" min="0" max="999" step="1" placeholder="5"><span>分钟</span></div></div>' +
        '<div class="duration-field"><label>火候 / 补充说明</label><input class="input" name="step_heat[' + n + ']" placeholder="选填，例如：大火、小火慢炖"></div>' +
        '</div>' +
        '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px">' +
        '<label class="btn sm" style="cursor:pointer"><span class="ico" data-ico="upload"></span>添加照片<input type="file" name="step_image_files[' + n + '][]" accept="image/*" multiple style="display:none" data-step-files></label>' +
        '<span class="hint">可多选，每步最多 9 张；单张 8MB 内；上传后可用 &lt; &gt; 调整顺序</span></div>' +
        '<input type="hidden" name="step_images[' + n + ']" value="[]" data-step-images>';
      document.getElementById('stepList').appendChild(box);
      SG_HYDRATE(box);
      stepNo();
    }
    var del = e.target.closest('[data-del-row]');
    if (del) {
      var parent = del.closest('.ing-edit') || del.closest('.card');
      if (parent) { parent.remove(); stepNo(); }
    }
  });
  /* AI 给步骤配图 */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ai-step]');
    if (!b || b.dataset.busy === '1') return;
    var box = b.closest('.card');
    if (!box) return;
    var titleEl = box.querySelector('[name^="step_title["]');
    var bodyEl = box.querySelector('[name^="step_content["]');
    var prompt = b.getAttribute('data-prompt') || ((titleEl ? titleEl.value : '') + ' ' + (bodyEl ? bodyEl.value.slice(0, 40) : ''));
    if (!prompt.trim()) { SG_TOAST('先写步骤标题或内容再配图'); return; }
    b.dataset.busy = '1';
    var old = b.innerHTML;
    b.innerHTML = '生成中…';
    var fd = new FormData();
    fd.append('prompt', prompt);
    fd.append('kind', 'step');
    fd.append('_token', window.SG_CSRF || '');
    fetch((window.SG_API || 'api/') + 'ai-image.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { SG_TOAST(j.msg || '生成失败'); return; }
        var hidden = box.querySelector('[data-step-images]');
        var list = box.querySelector('[data-step-photo-list]');
        var imgs = [];
        try { imgs = JSON.parse(hidden ? hidden.value : '[]') || []; } catch (err) { imgs = []; }
        if (imgs.indexOf(j.path) !== -1) { SG_TOAST('这张照片已经在当前步骤里'); return; }
        if (imgs.length >= 9) { SG_TOAST('每步最多 9 张，先删掉一张再加'); return; }
        imgs.push(j.path);
        if (hidden) hidden.value = JSON.stringify(imgs);
        if (list) {
          var ph = document.createElement('div');
          ph.className = 'step-photo';
          ph.setAttribute('data-path', j.path);
          ph.innerHTML = '<img src="' + j.url + '" alt=""><div class="step-photo-actions"><button type="button" class="step-photo-move" data-step-photo-move="-1" title="前移">&lt;</button><button type="button" class="step-photo-move" data-step-photo-move="1" title="后移">&gt;</button><button type="button" class="step-photo-del" data-step-photo-del title="移除这张照片">×</button></div>';
          list.appendChild(ph);
          syncStepImages(box);
        }
        SG_TOAST('配图已生成');
      })
      .catch(function () { SG_TOAST('生成失败，检查图像接口'); })
      .finally(function () { b.dataset.busy = '0'; b.innerHTML = old; SG_HYDRATE(b); });
  });

  /* 选图后只提示数量，保存时再统一上传 */
  document.addEventListener('change', function (e) {
    var fileInput = e.target.closest('[data-step-files]');
    if (!fileInput) return;
    var n = fileInput.files ? fileInput.files.length : 0;
    var hint = fileInput.closest('div').querySelector('.hint');
    if (hint) hint.textContent = n ? ('已选择 ' + n + ' 张，保存菜谱后上传') : '可多选，每步最多 9 张；单张 8MB 内；上传后可用 < > 调整顺序';
  });

  /* 逐张移除步骤照片：同步更新隐藏 JSON */
  document.addEventListener('click', function (e) {
    var del = e.target.closest('[data-step-photo-del]');
    if (!del) return;
    var photo = del.closest('.step-photo');
    var box = del.closest('.step-edit-card');
    if (!photo || !box) return;
    var path = photo.getAttribute('data-path') || '';
    var hidden = box.querySelector('[data-step-images]');
    var imgs = [];
    try { imgs = JSON.parse(hidden ? hidden.value : '[]') || []; } catch (err) { imgs = []; }
    imgs = imgs.filter(function (p) { return p !== path; });
    if (hidden) hidden.value = JSON.stringify(imgs);
    photo.remove();
    syncStepImages(box);
  });

  /* 步骤照片排序：← → 只在当前步骤内移动，顺序写入隐藏 JSON */
  document.addEventListener('click', function (e) {
    var move = e.target.closest('[data-step-photo-move]');
    if (!move) return;
    var photo = move.closest('.step-photo');
    var list = move.closest('[data-step-photo-list]');
    if (!photo || !list) return;
    var dir = Number(move.getAttribute('data-step-photo-move'));
    if (dir < 0 && photo.previousElementSibling) {
      list.insertBefore(photo, photo.previousElementSibling);
    } else if (dir > 0 && photo.nextElementSibling) {
      list.insertBefore(photo, photo.nextElementSibling.nextElementSibling);
    }
    syncStepImages(move.closest('.step-edit-card'));
  });

  stepNo();
})();
</script>
<?php
sg_admin_foot();
