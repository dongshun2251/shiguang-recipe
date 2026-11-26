<?php
/**
 * 后台 · App 下载
 * 管理下载页的安装包卡片：本站上传或任意 http(s) 外链。
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$entryOptions = [
    'site' => ['网站优先（默认）', '打开网站首页，下载页只作为独立入口，不拦截正常访问。'],
    'download_once' => ['首次访问先看下载页', '第一次打开网站首页先到下载页；点“进入网站”后，30 天内不再拦截。'],
];

$editId = get_int('edit');
$items  = sg_app_downloads(false);
$editing = null;
foreach ($items as $it) {
    if ((int) $it['id'] === $editId) { $editing = $it; break; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string) post('do');

    if ($do === 'entry') {
        $entry = (string) post('entry');
        if (!isset($entryOptions[$entry])) { $entry = 'site'; }
        setting_set('app_download_entry', $entry);
        setting_set('app_download_title', mb_substr(trim((string) post('title', '把食光，装进口袋。')), 0, 60));
        setting_set('app_download_sub', mb_substr(trim((string) post('sub', '手机、平板和电脑，同一本家常菜谱。')), 0, 100));
        setting_set('app_download_note', mb_substr(trim((string) post('note', '私人小厨房 · 熟人自用，不上架应用商店。')), 0, 120));
        flash('下载页入口与文案已保存');
        redirect('app-downloads.php');
    }

    if ($do === 'delete') {
        $id = post_int('id');
        $old = fetch_one('SELECT * FROM `' . T('app_downloads') . '` WHERE id = ?', [$id]);
        if ($old) {
            sg_app_package_delete((string) $old['url']);
            q('DELETE FROM `' . T('app_downloads') . '` WHERE id = ?', [$id]);
            flash('已删除「' . $old['title'] . '」下载卡片');
        } else {
            flash('这条下载不存在', 'err');
        }
        redirect('app-downloads.php');
    }

    if ($do === 'save') {
        $id      = post_int('id');
        $platform = (string) post('platform', 'other');
        if (!in_array($platform, ['android', 'tablet', 'windows', 'ios', 'mac', 'web', 'other'], true)) {
            $platform = 'other';
        }
        $title = trim((string) post('title'));
        $ver   = mb_substr(trim((string) post('ver')), 0, 32);
        $note  = mb_substr(trim((string) post('note')), 0, 255);
        $url   = trim((string) post('url'));
        $appLink = trim((string) post('app_link'));
        $size  = mb_substr(trim((string) post('size')), 0, 32);
        $sort  = post_int('sort');
        $status = post('status') === '1' ? 1 : 0;
        $old = $id ? fetch_one('SELECT * FROM `' . T('app_downloads') . '` WHERE id = ?', [$id]) : null;

        try {
            if (!empty($_FILES['package']['name'])) {
                $uploaded = upload_app_package($_FILES['package']);
                $url  = $uploaded['url'];
                $size = $uploaded['size'];
            }
            if ($appLink === '') {
                if (in_array($platform, ['android', 'windows'], true)) { $appLink = 'shiguang://open'; }
                if ($platform === 'tablet') { $appLink = 'shiguangtablet://open'; }
            }
            if ($appLink !== '' && !preg_match('#^(shiguangtablet://|shiguang://|intent://|https?://)#i', $appLink)) {
                throw new RuntimeException('打开 App 链接只支持 shiguangtablet://、shiguang://、intent:// 或 http(s)://');
            }
            if ($title === '') { $title = sg_app_platform_meta($platform)['label']; }
            if ($url === '') {
                throw new RuntimeException('请上传安装包，或填写一个下载链接');
            }
            if (!preg_match('#^(https?://|uploads/|/)#i', $url)) {
                /* 允许直接粘贴不带协议的网盘地址，例如 pan.baidu.com/s/xxx */
                if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
                    $url = 'https://' . ltrim($url);
                } else {
                    throw new RuntimeException('链接只支持 http://、https://、站内 uploads/ 路径，或直接粘贴域名');
                }
            }
            if ($size === '') { $size = preg_match('#^https?://#i', $url) ? '外部链接' : ''; }

            $data = [
                'platform' => $platform,
                'title'    => mb_substr($title, 0, 80),
                'ver'      => $ver,
                'note'     => $note,
                'url'      => $url,
                'app_link' => mb_substr($appLink, 0, 255),
                'size'     => $size,
                'sort'     => $sort,
                'status'   => $status,
            ];
            if ($id) {
                db_update(T('app_downloads'), $data, 'id = ?', [$id]);
                if ($old && $old['url'] !== $url) { sg_app_package_delete((string) $old['url']); }
                flash('下载卡片已更新');
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                db_insert(T('app_downloads'), $data);
                flash('下载卡片已添加');
            }
        } catch (Throwable $ex) {
            flash($ex->getMessage(), 'err');
            redirect('app-downloads.php' . ($id ? '?edit=' . $id : ''));
        }
        redirect('app-downloads.php');
    }
}

$entry = (string) setting('app_download_entry', 'site');
if (!isset($entryOptions[$entry])) { $entry = 'site'; }
$dlTitle = (string) setting('app_download_title', '把食光，装进口袋。');
$dlSub   = (string) setting('app_download_sub', '手机、平板和电脑，同一本家常菜谱。');
$dlNote  = (string) setting('app_download_note', '私人小厨房 · 熟人自用，不上架应用商店。');
$maxUpload = ini_get('upload_max_filesize');

$form = [
    'id'       => $editing ? (int) $editing['id'] : 0,
    'platform' => $editing['platform'] ?? 'android',
    'title'    => $editing['title'] ?? '',
    'ver'      => $editing['ver'] ?? '',
    'note'     => $editing['note'] ?? '',
    'url'      => $editing['url'] ?? '',
    'app_link' => $editing['app_link'] ?? (in_array(($editing['platform'] ?? 'android'), ['android', 'windows'], true) ? 'shiguang://open' : (($editing['platform'] ?? 'android') === 'tablet' ? 'shiguangtablet://open' : '')),
    'size'     => $editing['size'] ?? '',
    'sort'     => $editing['sort'] ?? ((int) fetch_val('SELECT MAX(sort) FROM `' . T('app_downloads') . '`', [], 0) + 10),
    'status'   => isset($editing['status']) ? (int) $editing['status'] : 1,
];

sg_admin_head('App 下载', 'admin-app-downloads', 'App 下载');
?>
        <div class="page-head">
          <h1>App 下载</h1>
          <span class="sub desk-only">管理独立下载页的安装包与链接</span>
          <div class="actions">
            <a class="btn" href="../download.php" target="_blank" rel="noopener"><span class="ico" data-ico="eye"></span>预览下载页</a>
          </div>
        </div>

        <?= flash_render() ?>

        <div class="alert info" style="margin-bottom:14px">
          <span class="ico" data-ico="info"></span>
          <span><b>APK / EXE 不要在本站上传</b>：InfinityFree 免费主机会自动删除这两类文件，上传后前台会变成 404。请把 APK / EXE 放到网盘或 CDN，把直链填到“下载链接”。ZIP、MSI、DMG、IPA、PKG 可尝试上传；当前 PHP 上传上限约 <b><?= e($maxUpload) ?></b>。</span>
        </div>

        <section class="hub-panel">
          <div class="hub-panel-head"><h2>页面入口</h2><span>决定打开网站首页时，先看到网站还是下载页</span></div>
          <form method="post" class="card" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="entry">
            <div class="dl-entry-opts">
<?php foreach ($entryOptions as $k => $opt): ?>
              <label class="dl-entry-opt">
                <input type="radio" name="entry" value="<?= e($k) ?>"<?= $entry === $k ? ' checked' : '' ?> style="accent-color:var(--brand)">
                <b><?= e($opt[0]) ?></b>
                <span><?= e($opt[1]) ?></span>
              </label>
<?php endforeach; ?>
            </div>
            <div class="form-grid" style="margin-top:16px">
              <div class="field"><label>下载页大标题</label><input class="input" name="title" value="<?= e($dlTitle) ?>" maxlength="60"></div>
              <div class="field"><label>副标题</label><input class="input" name="sub" value="<?= e($dlSub) ?>" maxlength="100"></div>
            </div>
            <div class="field" style="margin-top:12px"><label>页脚小字</label><input class="input" name="note" value="<?= e($dlNote) ?>" maxlength="120"></div>
            <div style="margin-top:14px"><button class="btn primary" type="submit"><span class="ico" data-ico="check"></span>保存入口与文案</button></div>
          </form>
        </section>

        <section class="hub-panel" style="margin-top:18px">
          <div class="hub-panel-head"><h2>下载卡片</h2><span><?= count($items) ?> 张 · 前台按排序从小到大显示</span></div>
<?php if (!$items): ?>
          <div class="card dl-admin-empty"><span class="ico" data-ico="down"></span><div><b>还没有下载卡片</b><span>在下面添加第一张，前台下载页会立刻出现。</span></div></div>
<?php else: ?>
          <div class="card" style="padding:0;overflow:hidden">
<?php foreach ($items as $it):
    $meta = sg_app_platform_meta((string) $it['platform']);
?>
            <div class="dl-admin-row">
              <span class="dl-admin-ic"><span class="ico" data-ico="<?= e($meta['icon']) ?>"></span></span>
              <div class="dl-admin-main">
                <b><?= e($it['title']) ?><?php if (!$it['status']): ?><i class="dl-off">已隐藏</i><?php endif; ?></b>
                <span><?= e($meta['label']) ?><?= $it['ver'] !== '' ? ' · ' . e($it['ver']) : '' ?><?= $it['size'] !== '' ? ' · ' . e($it['size']) : '' ?><?= ($it['app_link'] ?? '') !== '' ? ' · 可直接打开 App' : '' ?> · 排序 <?= (int) $it['sort'] ?></span>
                <em><?= e($it['url']) ?></em>
              </div>
              <div class="dl-admin-acts">
                <a class="btn sm" href="app-downloads.php?edit=<?= (int) $it['id'] ?>#edit">编辑</a>
                <form method="post" data-confirm="确定删除「<?= e($it['title']) ?>」吗？本站上传的安装包会一起删除，外链不会。">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                  <button class="btn sm danger" type="submit">删除</button>
                </form>
              </div>
            </div>
<?php endforeach; ?>
          </div>
<?php endif; ?>
        </section>

        <section class="hub-panel" id="edit" style="margin-top:18px">
          <div class="hub-panel-head"><h2><?= $editing ? '编辑下载卡片' : '添加下载卡片' ?></h2><span>上传文件和填写链接任选其一，上传优先</span></div>
          <form method="post" enctype="multipart/form-data" class="card" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <div class="form-grid">
              <div class="field"><label>平台</label>
                <select class="select" name="platform">
<?php foreach (['android' => 'Android 手机', 'tablet' => 'Android 平板', 'windows' => 'Windows 电脑', 'ios' => 'iPhone / iPad', 'mac' => 'Mac 电脑', 'web' => '网页版', 'other' => '其他'] as $k => $lb): ?>
                  <option value="<?= e($k) ?>"<?= $form['platform'] === $k ? ' selected' : '' ?>><?= e($lb) ?></option>
<?php endforeach; ?>
                </select>
              </div>
              <div class="field"><label>卡片标题</label><input class="input" name="title" value="<?= e($form['title']) ?>" placeholder="例如：Android 手机版" maxlength="80"></div>
            </div>
            <div class="form-grid" style="margin-top:12px">
              <div class="field"><label>版本号</label><input class="input" name="ver" value="<?= e($form['ver']) ?>" placeholder="1.0.0" maxlength="32"></div>
              <div class="field"><label>文件大小（外链时手填）</label><input class="input" name="size" value="<?= e($form['size']) ?>" placeholder="例如：74 MB；上传会自动填" maxlength="32"></div>
            </div>
            <div class="field" style="margin-top:12px"><label>一句话说明</label><input class="input" name="note" value="<?= e($form['note']) ?>" placeholder="例如：安卓 6.0 以上，扫码或点按钮安装" maxlength="255"></div>
            <div class="field" style="margin-top:12px"><label>下载链接</label>
              <input class="input" name="url" value="<?= e($form['url']) ?>" placeholder="APK 直链 / 网盘链接 / https://..." autocomplete="off">
              <span class="tip">APK、EXE 请填外部直链或网盘链接；支持任意 http(s) 链接，也可以直接粘贴不带协议的域名。只有 ZIP / MSI / DMG / IPA / PKG 才建议走下面的本站上传。</span>
            </div>
            <div class="field" style="margin-top:12px"><label>打开 App 链接（可选）</label>
              <input class="input" name="app_link" value="<?= e($form['app_link']) ?>" placeholder="shiguang://open" autocomplete="off">
              <span class="tip">Android 手机 / Windows 填 <b>shiguang://open</b>；Android 平板单独填 <b>shiguangtablet://open</b>。已安装会先直接打开，没装则自动转到下面的安装包下载。</span>
            </div>
            <div class="field" style="margin-top:12px"><label for="dlPackageInput">或上传安装包（APK / EXE 请用外链）</label>
              <label class="file-field" for="dlPackageInput" data-file-field>
                <input class="file-native" id="dlPackageInput" type="file" name="package" accept=".msi,.zip,.dmg,.ipa,.pkg">
                <span class="file-pick"><span class="ico" data-ico="folder"></span><span>选择文件</span></span>
                <span class="file-name" data-file-name>未选择任何文件</span>
              </label>
              <span class="tip">InfinityFree 会删除 .apk / .exe，上传这两类会直接提示失败；请改用上面的“下载链接”。可上传 MSI / ZIP / DMG / IPA / PKG，单文件上限受 PHP 配置限制（当前 <?= e($maxUpload) ?>）。</span>
            </div>
            <div class="form-grid" style="margin-top:12px">
              <div class="field"><label>排序</label><input class="input" type="number" name="sort" value="<?= (int) $form['sort'] ?>" step="10"></div>
              <div class="field"><label>前台显示</label><label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px;margin-top:9px"><input type="checkbox" name="status" value="1" style="accent-color:var(--brand)"<?= $form['status'] ? ' checked' : '' ?>> 在下载页显示这张卡片</label></div>
            </div>
            <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
              <button class="btn primary" type="submit"><span class="ico" data-ico="check"></span><?= $editing ? '保存修改' : '添加卡片' ?></button>
<?php if ($editing): ?><a class="btn" href="app-downloads.php">取消编辑</a><?php endif; ?>
            </div>
          </form>
        </section>

<script>
(function () {
  document.querySelectorAll('[data-file-field]').forEach(function (wrap) {
    var input = wrap.querySelector('input[type="file"]');
    var name = wrap.querySelector('[data-file-name]');
    if (!input || !name) return;
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      name.textContent = file ? file.name : '未选择任何文件';
      name.classList.toggle('has-file', !!file);
    });
  });
})();
</script>

<script>
(function () {
  var p = document.querySelector('select[name="platform"]');
  var a = document.querySelector('input[name="app_link"]');
  if (!p || !a) return;
  var map = {android: 'shiguang://open', windows: 'shiguang://open', tablet: 'shiguangtablet://open'};
  var prev = p.value;
  p.addEventListener('change', function () {
    var oldDefault = map[prev] || '';
    if (!a.value || a.value === oldDefault) {
      a.value = map[p.value] || '';
    }
    prev = p.value;
  });
})();
</script>

<style>
.dl-entry-opts{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.dl-entry-opt{display:flex;flex-direction:column;gap:6px;padding:13px 14px;border:1px solid var(--line);border-radius:10px;background:var(--surface);cursor:pointer}
.dl-entry-opt b{font-size:12.5px}.dl-entry-opt span{font-size:11.5px;line-height:1.7;color:var(--muted)}
.dl-entry-opt:has(input:checked){border-color:var(--brand);background:var(--brand-soft)}
.dl-admin-empty{display:flex;align-items:center;gap:12px;color:var(--muted);font-size:12.5px}
.dl-admin-empty .ico{width:28px;height:28px}.dl-admin-empty b{display:block;color:var(--ink);font-size:13px}
.dl-admin-row{display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--line-2)}
.dl-admin-row:last-child{border-bottom:0}
.dl-admin-ic{width:36px;height:36px;flex:0 0 36px;border-radius:9px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center}
.dl-admin-ic .ico{width:18px;height:18px}
.dl-admin-main{flex:1;min-width:0}.dl-admin-main b{display:flex;align-items:center;gap:8px;font-size:13px}
.dl-admin-main span{display:block;font-size:11.5px;color:var(--muted);margin-top:2px}
.dl-admin-main em{display:block;font-style:normal;font-size:10.5px;color:#A39A8D;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dl-off{font-style:normal;font-size:10px;color:#A63C1C;background:#FBE9E3;border-radius:4px;padding:1px 5px}
.dl-admin-acts{display:flex;align-items:center;gap:6px;flex:0 0 auto}.dl-admin-acts form{margin:0}
@media(max-width:760px){.dl-entry-opts{grid-template-columns:1fr}.dl-admin-row{align-items:flex-start;flex-wrap:wrap}.dl-admin-main{flex-basis:calc(100% - 52px)}.dl-admin-acts{margin-left:48px}}
@media(max-width:520px){.dl-admin-acts{margin-left:0}.dl-admin-acts .btn{flex:1}}
</style>

<?php sg_admin_foot(); ?>
