<?php
/**
 * 后台 · 客户端更新
 * 管理桌面端 / Android 手机 / Android 平板的版本检测信息。
 * 客户端启动时请求 /version.php，这里保存的内容会直接决定是否弹更新。
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$clients = [
    'desktop' => [
        'label' => 'Windows 桌面端',
        'hint'  => '安装包 .exe；更新地址建议用 GitHub Release 直链',
        'icon'  => 'desktop',
    ],
    'android' => [
        'label' => 'Android 手机',
        'hint'  => '安装包 .apk；手机上打开下载链接即可安装',
        'icon'  => 'phone',
    ],
    'tablet' => [
        'label' => 'Android 平板',
        'hint'  => '平板专用 .apk；和手机分开填，版本可独立控制',
        'icon'  => 'tablet',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ((string) post('do') === 'save') {
        foreach ($clients as $key => $meta) {
            $ver   = mb_substr(trim((string) post($key . '_version')), 0, 32);
            $url   = trim((string) post($key . '_url'));
            $notes = mb_substr(trim((string) post($key . '_notes')), 0, 500);
            $on    = post($key . '_enabled') === '1' ? '1' : '0';

            if ($ver !== '' && !preg_match('/^[0-9A-Za-z._-]+$/', $ver)) {
                flash($meta['label'] . ' 的版本号只能包含数字、字母、点、下划线和短横线', 'err');
                redirect('app-versions.php');
            }
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                flash($meta['label'] . ' 的更新地址必须以 http:// 或 https:// 开头', 'err');
                redirect('app-versions.php');
            }

            setting_set('client_update_' . $key . '_version', $ver);
            setting_set('client_update_' . $key . '_url', $url);
            setting_set('client_update_' . $key . '_notes', $notes);
            setting_set('client_update_' . $key . '_enabled', $on);
        }
        flash('三端客户端更新配置已保存');
        redirect('app-versions.php');
    }
}

/* 后台第一次打开时和 /version.php 的默认值保持一致，避免一保存就把三端更新清空 */
$defaults = [
    'desktop' => [
        'version' => '1.0.5',
        'url'     => 'https://aka.doubaocdn.com/s/wHz8D6RlOa',
        'notes'   => '欢迎音频改为客户端本地播放，修复桌面端“音频播放失败”；二维码刷新按钮回到失效遮罩内。',
    ],
    'android' => [
        'version' => '1.0.5',
        'url'     => 'https://aka.doubaocdn.com/s/mQzlBJRPkk',
        'notes'   => '扫码改为中心裁切 jsQR 并把解码移出主线程；二维码刷新按钮回到失效遮罩内。',
    ],
    'tablet' => [
        'version' => '1.0.5',
        'url'     => 'https://aka.doubaocdn.com/s/c1T2CN39rX',
        'notes'   => '平板扫码同步新的识别逻辑，应用名去掉“平板”，二维码刷新按钮回到失效遮罩内。',
    ],
];

$rows = [];
foreach ($clients as $key => $meta) {
    $rows[$key] = [
        'version' => (string) setting('client_update_' . $key . '_version', $defaults[$key]['version']),
        'url'     => (string) setting('client_update_' . $key . '_url', $defaults[$key]['url']),
        'notes'   => (string) setting('client_update_' . $key . '_notes', $defaults[$key]['notes']),
        'enabled' => (string) setting('client_update_' . $key . '_enabled', '1') === '1',
    ];
}

sg_admin_head('客户端更新', 'admin-app-versions', '客户端更新');
?>
        <div class="page-head">
          <h1>客户端更新</h1>
          <span class="sub desk-only">决定桌面 / 手机 / 平板启动时是否弹新版本</span>
          <div class="actions">
            <a class="btn" href="../version.php" target="_blank" rel="noopener"><span class="ico" data-ico="eye"></span>查看接口</a>
          </div>
        </div>

        <?= flash_render() ?>

        <div class="alert info" style="margin-bottom:14px">
          <span class="ico" data-ico="info"></span>
          <span>客户端启动会请求 <b>/version.php</b>，拿这里的版本号和自己已安装的版本比较；这里填的版本<b>高于</b>客户端当前版本时才会弹更新。建议把安装包放到 GitHub Releases 或 CDN，再把直链填进来。APK / EXE 不要上传到本站，InfinityFree 会自动删除。</span>
        </div>

        <form method="post" class="card" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="save">

<?php foreach ($clients as $key => $meta): $r = $rows[$key]; ?>
          <section class="hub-panel" style="margin:0 0 18px">
            <div class="hub-panel-head">
              <h2><?= e($meta['label']) ?></h2>
              <span><?= e($meta['hint']) ?></span>
            </div>
            <div class="card" style="margin:0;background:#FFFDF9">
              <div class="form-grid">
                <div class="field">
                  <label>最新版本号</label>
                  <input class="input" name="<?= e($key) ?>_version" value="<?= e($r['version']) ?>" placeholder="例如：1.0.6" maxlength="32" autocomplete="off">
                  <span class="tip">留空表示不主动提示这一端。</span>
                </div>
                <div class="field">
                  <label>是否启用更新提示</label>
                  <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px;margin-top:9px">
                    <input type="checkbox" name="<?= e($key) ?>_enabled" value="1" style="accent-color:var(--brand)"<?= $r['enabled'] ? ' checked' : '' ?>>
                    启用 <?= e($meta['label']) ?> 的更新检测
                  </label>
                </div>
              </div>
              <div class="field" style="margin-top:12px">
                <label>安装包下载直链</label>
                <input class="input" name="<?= e($key) ?>_url" value="<?= e($r['url']) ?>" placeholder="https://github.com/.../releases/download/v1.0.6/xxx.exe" autocomplete="off">
                <span class="tip">客户端点“去下载”会打开这个地址；GitHub Release 附件直链最稳，不要填仓库文件页面地址。</span>
              </div>
              <div class="field" style="margin-top:12px">
                <label>更新说明</label>
                <textarea class="input" name="<?= e($key) ?>_notes" rows="3" maxlength="500" placeholder="这次更新改了什么；会显示在更新弹窗里。"><?= e($r['notes']) ?></textarea>
              </div>
            </div>
          </section>
<?php endforeach; ?>

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px">
            <button class="btn primary" type="submit"><span class="ico" data-ico="check"></span>保存三端更新配置</button>
            <span class="tip" style="align-self:center">保存后客户端下次启动就会读取新配置；已经是最新版本的客户端不会重复提示。</span>
          </div>
        </form>

<?php sg_admin_foot(); ?>
