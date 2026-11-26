<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();
sg_categories_ensure_bg();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = post('do');
    $id = post_int('id');

    if ($do === 'icon_add') {
        $iname = trim(post('icon_name'));
        $inote = trim(post('icon_note'));
        if ($iname === '' || $inote === '') {
            flash('图标名称和「适用类型」都要填：类型会显示给其他管理员，方便认图标。', 'err');
            redirect('categories.php');
        }
        try {
            sg_icons_ensure_table();
            $path = upload_icon($_FILES['icon_file'] ?? []);
            $newId = db_insert(T('icons'), [
                'name' => mb_substr($iname, 0, 32),
                'note' => mb_substr($inote, 0, 80),
                'file' => $path,
            ]);
            $assign = post_int('assign_cat');
            if ($assign > 0) {
                db_update(T('categories'), ['icon' => 'c' . $newId], 'id = ?', [$assign]);
                flash('自定义图标「' . $iname . '」已添加，并设为当前分类的图标');
            } else {
                flash('自定义图标「' . $iname . '」已添加，可在分类里选用');
            }
        } catch (Throwable $e) {
            if (!empty($path) && strpos($path, 'uploads/icons/') === 0 && is_file(SG_ROOT . '/' . $path)) {
                @unlink(SG_ROOT . '/' . $path);
            }
            flash($e->getMessage(), 'err');
        }
        redirect('categories.php');
    }

    if ($do === 'icon_add_remote') {
        $iconId = trim(post('icon_id'));
        $label  = trim(post('icon_label'));
        $assign = post_int('assign_cat');
        [$newId, $newPath, $err] = sg_iconify_icon_add($iconId, $label);
        if ($newId <= 0) {
            flash('图标添加失败：' . $err, 'err');
        } else {
            $code = 'c' . $newId;
            if ($assign > 0) {
                db_update(T('categories'), ['icon' => $code], 'id = ?', [$assign]);
                flash('已添加「' . ($label !== '' ? $label : $iconId) . '」，并设为当前分类的图标');
            } else {
                flash('已添加「' . ($label !== '' ? $label : $iconId) . '」，可在分类里选用');
            }
        }
        redirect('categories.php');
    }

    if ($do === 'icon_del') {
        sg_icons_ensure_table();
        $icon = fetch_one('SELECT * FROM `' . T('icons') . '` WHERE id = ?', [$id]);
        if ($icon) {
            $code = 'c' . (int) $id;
            $used = (int) fetch_val('SELECT COUNT(*) FROM `' . T('categories') . '` WHERE icon = ?', [$code], 0);
            q('UPDATE `' . T('categories') . '` SET icon = "" WHERE icon = ?', [$code]);
            q('DELETE FROM `' . T('icons') . '` WHERE id = ?', [$id]);
            $f = SG_ROOT . '/' . ltrim((string) $icon['file'], '/');
            if (strpos((string) $icon['file'], 'uploads/icons/') === 0 && is_file($f)) { @unlink($f); }
            flash('自定义图标「' . $icon['name'] . '」已删除' . ($used ? '，' . $used . ' 个分类的图标已清空' : ''));
        }
        redirect('categories.php');
    }

    if ($do === 'bg_set') {
        $cid = post_int('assign_cat');
        if ($cid <= 0) {
            flash('没有选中分类', 'err');
            redirect('categories.php');
        }
        $cat = fetch_one('SELECT * FROM `' . T('categories') . '` WHERE id = ?', [$cid]);
        if (!$cat) {
            flash('分类不存在', 'err');
            redirect('categories.php');
        }
        try {
            $path = upload_image($_FILES['bg_file'] ?? [], 'categories');
            $old = trim((string) ($cat['bg'] ?? ''));
            db_update(T('categories'), ['bg' => $path], 'id = ?', [$cid]);
            if ($old !== '' && strpos($old, 'uploads/categories/') === 0) {
                $f = SG_ROOT . '/' . ltrim($old, '/');
                if (is_file($f)) { @unlink($f); }
            }
            flash('「' . $cat['name'] . '」的背景图已更新');
        } catch (Throwable $e) {
            flash('背景图上传失败：' . $e->getMessage(), 'err');
        }
        redirect('categories.php');
    }

    if ($do === 'bg_clear') {
        $cid = post_int('assign_cat');
        $cat = $cid > 0 ? fetch_one('SELECT * FROM `' . T('categories') . '` WHERE id = ?', [$cid]) : null;
        if ($cat) {
            $old = trim((string) ($cat['bg'] ?? ''));
            db_update(T('categories'), ['bg' => ''], 'id = ?', [$cid]);
            if ($old !== '' && strpos($old, 'uploads/categories/') === 0) {
                $f = SG_ROOT . '/' . ltrim($old, '/');
                if (is_file($f)) { @unlink($f); }
            }
            flash('已清除「' . $cat['name'] . '」的背景图，会回退到该分类第一道菜的封面');
        }
        redirect('categories.php');
    }

    if ($do === 'batch') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $ids = array_values(array_filter($ids, function ($i) { return $i > 0; }));
        $act = (string) post('batch_action');
        if (!$ids) {
            flash('先勾选要处理的分类', 'err');
            redirect('categories.php');
        }
        if ($act === 'delete') {
            $done = 0;
            foreach ($ids as $cid) {
                $cat = fetch_one('SELECT * FROM `' . T('categories') . '` WHERE id = ?', [$cid]);
                if (!$cat) { continue; }
                q('UPDATE `' . T('recipes') . '` SET category_id = 0 WHERE category_id = ?', [$cid]);
                q('DELETE FROM `' . T('categories') . '` WHERE id = ?', [$cid]);
                $bg = trim((string) ($cat['bg'] ?? ''));
                if ($bg !== '' && strpos($bg, 'uploads/categories/') === 0) {
                    $f = SG_ROOT . '/' . ltrim($bg, '/');
                    if (is_file($f)) { @unlink($f); }
                }
                $done++;
            }
            flash('已删除 ' . $done . ' 个分类，分类下的菜谱已移到「未分类」');
        } elseif ($act === 'show' || $act === 'hide') {
            $status = $act === 'show' ? 1 : 0;
            foreach ($ids as $cid) {
                db_update(T('categories'), ['status' => $status], 'id = ?', [$cid]);
            }
            flash('已把 ' . count($ids) . ' 个分类设为' . ($status ? '显示' : '隐藏'));
        }
        redirect('categories.php');
    }

    if ($do === 'delete' && $id) {
        $cnt = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` WHERE category_id = ?', [$id], 0);
        $delCat = fetch_one('SELECT * FROM `' . T('categories') . '` WHERE id = ?', [$id]);
        q('UPDATE `' . T('recipes') . '` SET category_id = 0 WHERE category_id = ?', [$id]);
        q('DELETE FROM `' . T('categories') . '` WHERE id = ?', [$id]);
        $delBg = trim((string) ($delCat['bg'] ?? ''));
        if ($delBg !== '' && strpos($delBg, 'uploads/categories/') === 0) {
            $f = SG_ROOT . '/' . ltrim($delBg, '/');
            if (is_file($f)) { @unlink($f); }
        }
        flash($cnt ? '分类已删除，' . $cnt . ' 道菜谱已移到「未分类」' : '分类已删除');
        redirect('categories.php');
    }

    $name = trim(post('name'));
    $slug = trim(post('slug')) ?: slugify($name);
    $icon = preg_replace('/[^a-z0-9_-]/i', '', post('icon', '')) ?: '';
    $sort = post_int('sort');
    $status = post('status') === '0' ? 0 : 1;

    if ($name === '') {
        flash('分类名称不能为空', 'err');
    } else {
        $exists = fetch_one('SELECT id FROM `' . T('categories') . '` WHERE slug = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$slug, $id] : [$slug]);
        if ($exists) {
            $slug .= '-' . substr(md5(microtime()), 0, 4);
        }
        if ($id) {
            db_update(T('categories'), ['name' => $name, 'slug' => $slug, 'icon' => $icon, 'sort' => $sort, 'status' => $status], 'id = ?', [$id]);
            flash('分类已更新');
        } else {
            db_insert(T('categories'), ['name' => $name, 'slug' => $slug, 'icon' => $icon, 'sort' => $sort, 'status' => $status]);
            flash('分类已添加');
        }
    }
    redirect('categories.php');
}

$rows = fetch_all('SELECT c.*, (SELECT COUNT(*) FROM `' . T('recipes') . '` r WHERE r.category_id = c.id) AS cnt,
                   (SELECT COUNT(*) FROM `' . T('recipes') . '` r WHERE r.category_id = c.id AND r.status = "published") AS pcnt
                   FROM `' . T('categories') . '` c ORDER BY c.sort ASC, c.id ASC');

$customIcons = sg_custom_icons();
$iconUse = [];
foreach ($rows as $r) { $iconUse[(string) $r['icon']] = ($iconUse[(string) $r['icon']] ?? 0) + 1; }

sg_admin_head('分类管理', 'admin-categories', '分类管理');
?>
        <div class="page-head">
          <h1>分类管理</h1>
          <span class="sub desk-only">共 <?= count($rows) ?> 个分类 · 前台导航按排序值升序</span>

        </div>

        <?= flash_render() ?>

        <div class="section-head cat-head"><h2>分类列表</h2><span class="hint">可直接修改后保存</span><button class="rc-sel-toggle" type="button" data-cat-toggle>批量</button></div>

        <form method="post" id="catBulk" class="bulkbar">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="batch">
          <span class="checkbox" data-check-all><span class="ico" data-ico="check"></span></span>
          <span class="bulk-txt">全选</span>
          <span class="desk-only bulk-cnt">共 <b><?= count($rows) ?></b> 个</span>
          <div class="bulk-acts">
            <button class="btn sm" type="submit" name="batch_action" value="show"><span class="ico" data-ico="eye"></span>显示</button>
            <button class="btn sm" type="submit" name="batch_action" value="hide">隐藏</button>
            <button class="btn sm danger" type="submit" name="batch_action" value="delete" data-confirm="删除选中的分类？分类下的菜谱会移到未分类。">删除</button>
          </div>
        </form>

<?php if (sg_is_mobile_ua()): ?>
        <div class="cat-mlist">
<?php foreach ($rows as $c): ?>
          <div class="cat-mcard">
            <div class="cm-head">
              <input class="cat-check" type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" form="catBulk" aria-label="选择 <?= e($c['name']) ?>">
              <input class="input" form="cf<?= (int) $c['id'] ?>" name="name" value="<?= e($c['name']) ?>" aria-label="分类名称">
              <select class="select" form="cf<?= (int) $c['id'] ?>" name="status" aria-label="显示状态">
                <option value="1"<?= $c['status'] ? ' selected' : '' ?>>显示</option>
                <option value="0"<?= !$c['status'] ? ' selected' : '' ?>>隐藏</option>
              </select>
            </div>
            <div class="cm-icons-edit" id="cmIcon<?= (int) $c['id'] ?>" hidden>
              <?= sg_icon_picker('icon', (string) $c['icon'], 'cf' . (int) $c['id']) ?>
              <div class="icon-detail-foot"><button class="btn sm" type="button" data-icon-toggle="cmIcon<?= (int) $c['id'] ?>">收起</button></div>
            </div>
            <div class="cm-foot">
              <span class="cm-sort">排序 <input class="input" form="cf<?= (int) $c['id'] ?>" name="sort" type="number" value="<?= (int) $c['sort'] ?>"></span>
              <span class="cm-cnt"><?= (int) $c['pcnt'] ?> 已发布 / <?= (int) $c['cnt'] ?> 总数</span>
              <button class="cat-bg-btn cat-bg-btn-m" type="button" data-bg-open data-cat="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-bg="<?= !empty($c['bg']) ? e(image_url((string) $c['bg'])) : '' ?>" title="设置分类背景图" aria-label="设置「<?= e($c['name']) ?>」的背景图">
                <?php if (!empty($c['bg'])): ?><img src="<?= e(image_url((string) $c['bg'])) ?>" alt=""><?php else: ?><span class="ico" data-ico="image"></span><?php endif; ?>
              </button>
              <button class="icon-now icon-now-btn cm-icon-inline" type="button" data-icon-toggle="cmIcon<?= (int) $c['id'] ?>" title="点击更换图标" aria-label="更换图标：<?= e(sg_icon_name((string) $c['icon']) ?: '未设置') ?>">
                <?= sg_cat_icon((string) $c['icon']) ?>
                <span class="icon-now-dot"><span class="ico" data-ico="edit"></span></span>
              </button>
              <span class="cm-icon-name"><?= e(sg_icon_name((string) $c['icon']) ?: '未设置') ?></span>
              <input type="hidden" form="cf<?= (int) $c['id'] ?>" name="slug" value="<?= e($c['slug']) ?>">
              <button class="btn primary sm" type="submit" form="cf<?= (int) $c['id'] ?>">保存</button>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="iconbtn" type="submit" data-confirm="确定删除分类「<?= e($c['name']) ?>」？分类下的菜谱会移到未分类。" title="删除"><span class="ico" data-ico="trash"></span></button>
              </form>
            </div>
          </div>
<?php endforeach; ?>
<?php if (!$rows): ?>
          <div class="empty"><div class="ico" data-ico="folder"></div><b>还没有分类</b><p>先建一个分类，菜谱就能按分类归位。<button class="cat-empty-add" type="button" data-toggle-new>去新增</button></p></div>
<?php endif; ?>
        </div>
<?php else: ?>
        <div class="table-wrap">
          <table class="tb">
            <thead><tr><th style="width:38px"></th><th style="width:52px">排序</th><th>名称</th><th>别名</th><th style="width:160px">图标</th><th style="width:82px">背景</th><th>状态</th><th>菜谱数</th><th style="width:96px">操作</th></tr></thead>
            <tbody>
<?php foreach ($rows as $c): ?>
              <tr>
                <td><input class="cat-check" type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" form="catBulk" aria-label="选择 <?= e($c['name']) ?>"></td>
                <td><input class="input" form="cf<?= (int) $c['id'] ?>" name="sort" value="<?= (int) $c['sort'] ?>" style="height:28px;width:46px;padding:0 6px;font-size:12px"></td>
                <td><input class="input" form="cf<?= (int) $c['id'] ?>" name="name" value="<?= e($c['name']) ?>" style="height:28px;font-size:12px"></td>
                <td><input class="input" form="cf<?= (int) $c['id'] ?>" name="slug" value="<?= e($c['slug']) ?>" style="height:28px;font-size:12px"></td>
                <td>
                  <div class="icon-cell">
                    <button class="icon-now icon-now-btn" type="button" data-icon-toggle="iconRow<?= (int) $c['id'] ?>" title="点击更换图标" aria-label="更换图标：<?= e(sg_icon_name((string) $c['icon']) ?: '未设置') ?>">
                      <?= sg_cat_icon((string) $c['icon']) ?>
                      <span class="icon-now-dot"><span class="ico" data-ico="edit"></span></span>
                    </button>
                    <span class="cm-icon-hint">点击更换</span>
                  </div>
                </td>
                <td>
                  <button class="cat-bg-btn" type="button" data-bg-open data-cat="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-bg="<?= !empty($c['bg']) ? e(image_url((string) $c['bg'])) : '' ?>" title="设置分类背景图" aria-label="设置「<?= e($c['name']) ?>」的背景图">
                    <?php if (!empty($c['bg'])): ?><img src="<?= e(image_url((string) $c['bg'])) ?>" alt=""><?php else: ?><span class="ico" data-ico="image"></span><?php endif; ?>
                  </button>
                  <span class="cm-icon-hint">点击设置</span>
                </td>
                <td>
                  <select class="select" form="cf<?= (int) $c['id'] ?>" name="status" style="height:28px;font-size:12px;width:80px">
                    <option value="1"<?= $c['status'] ? ' selected' : '' ?>>显示</option>
                    <option value="0"<?= !$c['status'] ? ' selected' : '' ?>>隐藏</option>
                  </select>
                </td>
                <td><?= (int) $c['cnt'] ?></td>
                <td><div class="row-act">
                  <button class="btn sm primary" type="submit" form="cf<?= (int) $c['id'] ?>" title="保存这一行">保存</button>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button class="iconbtn" type="submit" data-confirm="确定删除分类「<?= e($c['name']) ?>」？分类下的菜谱会移到未分类。" title="删除"><span class="ico" data-ico="trash"></span></button>
                  </form>
                </div></td>
              </tr>
              <tr class="icon-detail" id="iconRow<?= (int) $c['id'] ?>" hidden>
                <td colspan="9">
                  <div class="icon-detail-box">
                    <div class="icon-detail-head"><b>选择「<?= e($c['name']) ?>」的图标</b><span>选中后点这一行的「保存」生效</span></div>
                    <?= sg_icon_picker('icon', (string) $c['icon'], 'cf' . (int) $c['id']) ?>
                    <div class="icon-detail-foot"><button class="btn sm" type="button" data-icon-toggle="iconRow<?= (int) $c['id'] ?>">收起</button></div>
                  </div>
                </td>
              </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
              <tr><td colspan="9" style="text-align:center;padding:36px;color:var(--muted)">还没有分类，<button class="cat-empty-add" type="button" data-toggle-new>去新增</button></td></tr>
<?php endif; ?>
            </tbody>
          </table>
        </div>
<?php endif; ?>
<?php if ($rows): ?>
        <div class="cat-add-row">
          <button class="btn" type="button" data-toggle-new aria-controls="newCatBox"><span class="ico" data-ico="plus"></span>新增分类</button>
        </div>
<?php endif; ?>
        <div style="display:none">
<?php foreach ($rows as $c): ?>
          <form method="post" id="cf<?= (int) $c['id'] ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          </form>
<?php endforeach; ?>
        </div>

        <div id="newCatBox" hidden>
        <div class="section-head" style="margin-top:22px" id="newCat"><h2>新增分类</h2></div>
        <div class="card">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save">
            <div class="form-grid">
              <div class="field"><label>分类名称 <span class="req">*</span></label><input class="input" name="name" placeholder="例如：家常热菜" required></div>
              <div class="field"><label>别名（英文/拼音）</label><input class="input" name="slug" placeholder="留空自动生成"></div>
              <div class="field"><label>排序值</label><input class="input" name="sort" type="number" value="<?= count($rows) + 1 ?>"></div>
            </div>
            <div class="field" style="margin-top:12px"><label>图标样式</label>
              <?= sg_icon_picker('icon', '') ?>
            </div>
            <div style="display:flex;gap:10px;align-items:center;margin-top:14px">
              <label style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px"><input type="checkbox" name="status" value="1" checked style="accent-color:var(--brand)">前台显示</label>
              <button class="btn" type="button" data-toggle-new style="margin-left:auto">取消</button>
              <button class="btn primary" type="submit"><span class="ico" data-ico="plus"></span>添加分类</button>
            </div>
          </form>
        </div>
        </div>
        <div class="ip-modal" id="iconModal" hidden>
          <div class="ip-modal-mask" data-iconmodal-close></div>
          <div class="ip-modal-panel" role="dialog" aria-modal="true" aria-label="图标库">
            <div class="ip-modal-head"><b>图标库</b><button class="ip-modal-x" type="button" data-iconmodal-close aria-label="关闭">×</button></div>
            <p class="ip-modal-sub">可以直接搜在线图标，也可以上传自己的图片；选中的图标会保存到本站，不依赖外链。</p>
            <div class="if-lib">
              <div class="if-lib-head"><b>在线图标库</b><span>Iconify · 免下载 · 搜到直接点</span></div>
              <div class="if-search-row">
                <input class="input" id="ifQuery" placeholder="输入中文或英文，例如：火锅、soup" autocomplete="off">
                <button class="btn sm" type="button" id="ifSearch"><span class="ico" data-ico="search"></span>搜索</button>
              </div>
              <div class="if-msg" id="ifMsg">中文会自动换成常用英文词；找不到时可以直接输英文，例如 hot pot、noodles、cake。</div>
              <div class="if-results" id="ifResults"></div>
            </div>
<?php if ($customIcons): ?>
            <div class="ip-modal-list">
<?php foreach ($customIcons as $ci): $ccode = 'c' . (int) $ci['id']; ?>
              <div class="ip-modal-item">
                <span class="ip-modal-thumb"><?= sg_cat_icon($ccode) ?></span>
                <div class="ip-modal-meta"><b><?= e($ci['name']) ?></b><span><?= e($ci['note']) ?></span><em><?= (int) ($iconUse[$ccode] ?? 0) ?> 个分类在用</em></div>
                <form method="post" class="ip-modal-del">
                  <?= csrf_field() ?><input type="hidden" name="do" value="icon_del"><input type="hidden" name="id" value="<?= (int) $ci['id'] ?>">
                  <button class="iconbtn" type="submit" data-confirm="删除图标「<?= e($ci['name']) ?>」？正在用它的分类的图标会被清空。" title="删除这个图标"><span class="ico" data-ico="trash"></span></button>
                </form>
              </div>
<?php endforeach; ?>
            </div>
<?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="ip-modal-form">
              <?= csrf_field() ?><input type="hidden" name="do" value="icon_add"><input type="hidden" name="assign_cat" value="0">
              <label class="ip-file"><span class="ico" data-ico="plus"></span><b>选择图标图片</b><input type="file" name="icon_file" accept="image/png,image/jpeg,image/webp" required></label>
              <p class="ip-modal-hint">PNG / WebP / JPG，单张 ≤1 MB。上传后统一裁成 <b>256×256</b> 正方形；PNG / WebP 保留透明底，JPG 补白底。</p>
              <div class="form-grid">
                <div class="field"><label>图标名称 <span class="req">*</span></label><input class="input" name="icon_name" maxlength="32" placeholder="例如：火锅" required></div>
                <div class="field"><label>适用类型 <span class="req">*</span></label><input class="input" name="icon_note" maxlength="80" placeholder="例如：火锅 / 砂锅 / 干锅" required></div>
              </div>
              <div class="ip-modal-foot"><button class="btn" type="button" data-iconmodal-close>取消</button><button class="btn primary" type="submit"><span class="ico" data-ico="plus"></span>上传并使用</button></div>
            </form>
          </div>
        </div>
        <div class="ip-modal" id="bgModal" hidden>
          <div class="ip-modal-mask" data-bgmodal-close></div>
          <div class="ip-modal-panel" role="dialog" aria-modal="true" aria-label="分类背景图">
            <div class="ip-modal-head"><b>分类背景图</b><button class="ip-modal-x" type="button" data-bgmodal-close aria-label="关闭">×</button></div>
            <p class="ip-modal-sub">用在首页分类卡片上。不上传时，会自动用「<span id="bgModalName">这个分类</span>」下第一道有封面的菜；两者都没有就显示浅色底。</p>
            <div class="bg-modal-preview">
              <img id="bgModalPreview" src="" alt="" hidden>
              <div class="bg-modal-empty" id="bgModalEmpty"><span class="ico" data-ico="image"></span><b>还没有手动背景</b><span>保存后优先显示这张图</span></div>
            </div>
            <form method="post" enctype="multipart/form-data" class="ip-modal-form">
              <?= csrf_field() ?><input type="hidden" name="do" value="bg_set"><input type="hidden" name="assign_cat" value="0">
              <label class="ip-file"><span class="ico" data-ico="plus"></span><b>选择背景图片</b><input type="file" name="bg_file" accept="image/jpeg,image/png,image/webp" required></label>
              <p class="ip-modal-hint">JPG / PNG / WebP，单张 ≤8 MB。建议横图，宽度 1200px 以上；会上传到 <b>uploads/categories</b>。</p>
              <div class="ip-modal-foot"><button class="btn" type="button" data-bgmodal-close>取消</button><button class="btn primary" type="submit"><span class="ico" data-ico="upload"></span>上传并设置</button></div>
            </form>
            <form method="post" class="bg-modal-clear">
              <?= csrf_field() ?><input type="hidden" name="do" value="bg_clear"><input type="hidden" name="assign_cat" value="0">
              <button class="btn sm" type="submit" data-confirm="清除这个分类的手动背景图？会回退到第一道菜的封面。">清除手动背景</button>
            </form>
          </div>
        </div>
<script>
function closeIconModal() {
  var m = document.getElementById('iconModal');
  if (m) { m.hidden = true; document.body.classList.remove('ip-modal-open'); }
}
function closeBgModal() {
  var m = document.getElementById('bgModal');
  if (m) { m.hidden = true; document.body.classList.remove('ip-modal-open'); }
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') { closeIconModal(); closeBgModal(); }
});
document.addEventListener('click', function (e) {
  var bo = e.target.closest('[data-bg-open]');
  if (bo) {
    closeIconModal();
    var bm = document.getElementById('bgModal');
    if (!bm) return;
    var cid = bo.getAttribute('data-cat') || '0';
    bm.querySelectorAll('[name="assign_cat"]').forEach(function (x) { x.value = cid; });
    var nm = document.getElementById('bgModalName');
    if (nm) nm.textContent = bo.getAttribute('data-name') || '这个分类';
    var src = bo.getAttribute('data-bg') || '';
    var im = document.getElementById('bgModalPreview');
    var emp = document.getElementById('bgModalEmpty');
    if (im) {
      if (src) { im.src = src; im.hidden = false; } else { im.removeAttribute('src'); im.hidden = true; }
    }
    if (emp) emp.hidden = !!src;
    bm.hidden = false;
    document.body.classList.add('ip-modal-open');
    var fi = bm.querySelector('input[name="bg_file"]');
    if (fi) setTimeout(function () { fi.focus(); }, 200);
    return;
  }
  if (e.target.closest('[data-bgmodal-close]')) {
    closeBgModal();
    return;
  }

  var io = e.target.closest('[data-iconlib-open]');
  if (io) {
    var modal = document.getElementById('iconModal');
    if (!modal) return;
    var assign = modal.querySelector('[name="assign_cat"]');
    var cat = io.getAttribute('data-cat') || '0';
    if (assign) assign.value = cat;
    modal.setAttribute('data-assign-cat', cat);
    modal.hidden = false;
    document.body.classList.add('ip-modal-open');
    var fi = modal.querySelector('input[name="icon_file"]');
    if (fi) setTimeout(function () { fi.focus(); }, 200);
    return;
  }

  if (e.target.closest('[data-iconmodal-close]')) {
    var mm = document.getElementById('iconModal');
    if (mm) { mm.hidden = true; document.body.classList.remove('ip-modal-open'); }
    return;
  }

  var ib = e.target.closest('[data-icon-toggle]');
  if (ib) {
    var ir = document.getElementById(ib.getAttribute('data-icon-toggle'));
    if (ir) {
      var willOpen = ir.hidden;
      if (willOpen) {
        var scope = ir.closest('.cat-mlist');
        if (scope) scope.querySelectorAll('.cm-icons-edit').forEach(function (x) { x.hidden = true; });
      }
      ir.hidden = !willOpen;
      if (!ir.hidden) setTimeout(function () { ir.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 60);
    }
    return;
  }

  var b = e.target.closest('[data-toggle-new]');
  if (!b) return;
  var box = document.getElementById('newCatBox');
  var open = box.hasAttribute('hidden');
  if (open) box.removeAttribute('hidden'); else box.setAttribute('hidden', '');
  var top = document.querySelector('.cat-add-row [data-toggle-new]') || document.querySelector('.page-head [data-toggle-new]');
  if (top) top.innerHTML = (open ? '收起表单' : '<span class="ico" data-ico="plus"></span>新增分类');
  if (window.SG_HYDRATE) SG_HYDRATE(top);
  if (open) { box.style.scrollMarginTop = '80px'; box.scrollIntoView({ behavior: 'smooth', block: 'start' }); var i = box.querySelector('input[name="name"]'); if (i) setTimeout(function () { i.focus(); }, 300); }
});
</script>
<script>
(function () {
  var tg = document.querySelector('[data-cat-toggle]');
  if (!tg) return;
  tg.addEventListener('click', function () {
    var on = document.body.classList.toggle('cat-selecting');
    tg.textContent = on ? '完成' : '批量';
    if (!on) {
      document.querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = false; });
      document.querySelectorAll('[data-check-all]').forEach(function (m) { m.classList.remove('on'); });
      var cnt = document.querySelector('[data-sel-count]');
      if (cnt) cnt.textContent = '0';
    }
  });
})();
</script>
<script>
(function () {
  var modal   = document.getElementById('iconModal');
  var query   = document.getElementById('ifQuery');
  var btn     = document.getElementById('ifSearch');
  var msg     = document.getElementById('ifMsg');
  var results = document.getElementById('ifResults');
  if (!modal || !query || !btn || !msg || !results) return;
  var timer = null, busy = false, lastQ = '';

  var MAP = {
    '家常菜': 'cooking', '家常热菜': 'cooking', '热菜': 'cooking', '炒菜': 'wok', '炒': 'wok',
    '汤羹': 'soup', '汤': 'soup', '凉菜': 'salad', '沙拉': 'salad',
    '主食': 'rice bowl', '米饭': 'rice', '面食': 'noodles', '面条': 'noodles', '饺子': 'dumpling',
    '烘焙': 'bakery', '面包': 'bread', '蛋糕': 'cake', '甜点': 'dessert', '甜品': 'dessert',
    '小吃': 'snack', '素菜': 'vegetable', '蔬菜': 'vegetable', '肉类': 'meat',
    '猪肉': 'pork', '牛肉': 'beef', '鸡肉': 'chicken', '海鲜': 'seafood', '鱼': 'fish', '虾': 'shrimp',
    '早餐': 'breakfast', '饮品': 'drink', '饮料': 'drink', '茶': 'tea', '咖啡': 'coffee',
    '火锅': 'hot pot', '烧烤': 'grill', '蒸': 'steamer', '炖': 'stew', '辣': 'chili',
    '粥': 'porridge', '包子': 'baozi', '卤味': 'braised', '下饭菜': 'rice bowl'
  };
  function translate(q) {
    if (MAP[q]) return MAP[q];
    var out = q;
    Object.keys(MAP).forEach(function (k) {
      if (q.indexOf(k) !== -1 && out === q) out = MAP[k];
    });
    return out;
  }
  function setMsg(t, bad) {
    msg.textContent = t;
    msg.className = 'if-msg' + (bad ? ' is-bad' : '');
  }
  function addResult(id) {
    var parts = id.split(':');
    if (parts.length !== 2) return;
    var label = (lastQ && lastQ.length <= 24) ? lastQ : parts[1];
    var f = document.createElement('form');
    f.method = 'post';
    f.className = 'if-item';
    var fields = {
      '_token': window.SG_CSRF || '',
      'do': 'icon_add_remote',
      'icon_id': id,
      'icon_label': label,
      'assign_cat': modal.getAttribute('data-assign-cat') || '0'
    };
    Object.keys(fields).forEach(function (k) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = fields[k];
      f.appendChild(i);
    });
    var b = document.createElement('button');
    b.type = 'submit';
    b.title = '使用 ' + id;
    var img = document.createElement('img');
    img.src = 'https://api.iconify.design/' + parts[0] + '/' + parts[1] + '.svg?height=48&color=%23B0663D';
    img.alt = label;
    img.loading = 'lazy';
    var sp = document.createElement('span');
    sp.textContent = parts[1];
    b.appendChild(img); b.appendChild(sp);
    f.appendChild(b);
    results.appendChild(f);
  }
  function search() {
    if (busy) return;
    var raw = query.value.trim();
    if (!raw) { setMsg('先输入一个名字，例如：火锅、soup、cake。', true); return; }
    var q = translate(raw);
    busy = true;
    btn.disabled = true;
    lastQ = raw;
    results.innerHTML = '';
    setMsg('正在搜索「' + q + '」…');
    var url = 'https://api.iconify.design/search?query=' + encodeURIComponent(q)
      + '&limit=48&prefixes=' + encodeURIComponent('mdi,tabler,ph,lucide,icon-park-outline');
    fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var list = (j && Array.isArray(j.icons)) ? j.icons : [];
        if (!list.length) {
          setMsg('没找到「' + raw + '」。换个更通用的词，或直接输英文试试。', true);
          return;
        }
        list.forEach(addResult);
        var total = Number(j.total || list.length);
        setMsg('找到 ' + total + ' 个，显示前 ' + list.length + ' 个。点图标就会保存并设为当前分类图标。');
      })
      .catch(function () {
        setMsg('搜索失败：当前网络可能访问不了图标库，请稍后再试。', true);
      })
      .finally(function () {
        busy = false;
        btn.disabled = false;
      });
  }
  btn.addEventListener('click', search);
  query.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); search(); }
  });
  query.addEventListener('input', function () {
    clearTimeout(timer);
    var v = query.value.trim();
    if (v.length >= 1) timer = setTimeout(search, 650);
  });
})();
</script>
<?php
sg_admin_foot();
