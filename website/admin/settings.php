<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_admin();

$sec = trim((string) get('sec', ''));
$setSections = [
    'site'  => ['站点信息', '名称、时区', 'settings'],
    'list'  => ['列表与内容', '列表、审核', 'list'],
    'session' => ['登录与安全', '多设备上限', 'shield'],
    'audio' => ['欢迎音频', '登录前 / 登录后', 'music'],
    'theme' => ['外观主题', '主色与预设色', 'grid'],
    'ai'    => ['AI 接入', '文本与图像', 'bolt'],
    'env'   => ['运行环境', '数据库状态', 'database'],
    'pass'  => ['管理员密码', '修改登录密码', 'lock'],
];
if ($sec !== '' && !isset($setSections[$sec])) {
    $sec = '';
}
$setBack = 'settings.php' . ($sec !== '' ? '?sec=' . $sec : '');
$setTitle = $sec !== '' ? $setSections[$sec][0] : '系统设置';
$setSub = $sec !== '' ? $setSections[$sec][1] : '站点、内容、登录、音频、AI 与密码';

$keys = [
    'site_name', 'site_sub', 'site_desc', 'site_icp', 'site_timezone',
    'per_page', 'theme_primary', 'comment_review',
     'home_notice', 'home_notice_on', 'home_notice_link', 'home_update_version', 'home_update_text', 'home_update_on', 'audio_embed',
     'mobile_view', 'backup_freq', 'session_limit',
    'ai_enabled', 'ai_kind', 'ai_base', 'ai_model', 'ai_key', 'ai_timeout',
    'ai_img_base', 'ai_img_model', 'ai_img_key',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (post('do') === 'save') {
        /* 版本号或说明变了，先把上一条存进历史，前台“更新”可点开查看 */
        $oldUpdateVersion = trim((string) setting('home_update_version', ''));
        $oldUpdateText = trim((string) setting('home_update_text', ''));
        $newUpdateVersion = trim((string) post('home_update_version', $oldUpdateVersion));
        $newUpdateText = trim((string) post('home_update_text', $oldUpdateText));
        $sgLegacyUpdateTexts = [
            '今日推荐会显示点赞最高的厨友评价。',
            '云端同步已支持 InfinityFree 自动验证；版本更新内容可在后台填写。',
            '云端同步已支持 InfinityFree 自动验证；首页搜索框移到标题上方；版本号和更新内容可在后台填写。',
        ];
        if ($oldUpdateText !== '' && !in_array($oldUpdateText, $sgLegacyUpdateTexts, true)
            && ($oldUpdateText !== $newUpdateText || $oldUpdateVersion !== $newUpdateVersion)) {
            home_update_archive($oldUpdateVersion !== '' ? $oldUpdateVersion : ('v' . SG_VERSION), $oldUpdateText);
        }
        foreach ($keys as $k) {
            if (in_array($k, ['comment_review', 'ai_enabled', 'home_notice_on', 'home_update_on', 'audio_embed'], true)) {
                setting_set($k, post($k) === '1' ? '1' : '0');
            } elseif ($k === 'session_limit') {
                setting_set($k, (string) max(1, min(20, (int) post($k, setting($k, '5')))));
            } elseif ($k === 'theme_primary') {
                $color = theme_color_clean((string) post($k, setting($k)));
                setting_set($k, $color !== '' ? $color : '#C8502B');
            } else {
                setting_set($k, post($k, setting($k)));
            }
        }
        /* 手动输入的主色保存后，同时进入下方色块预设 */
        $palette = theme_palette();
        $current = theme_color_clean((string) setting('theme_primary', '#C8502B'));
        if ($current !== '' && !in_array($current, $palette, true)) {
            $palette[] = $current;
        }
        theme_palette_save($palette);
        flash('设置已保存');
        redirect($setBack);
    }

    if (post('do') === 'theme_palette_delete') {
        $color = theme_color_clean((string) post('color', ''));
        if ($color === '') {
            flash('色块颜色不合法', 'err');
            redirect('settings.php?sec=theme#sec-theme');
        }
        $colors = theme_palette();
        $wasCurrent = theme_color_clean((string) setting('theme_primary', '#C8502B')) === $color;
        $colors = array_values(array_filter($colors, function ($c) use ($color) {
            return $c !== $color;
        }));
        $next = $colors[0] ?? '#C8502B';
        if ($wasCurrent) {
            setting_set('theme_primary', $next);
        }
        theme_palette_save($colors);
        if ($wasCurrent) {
            flash($colors ? '已删除当前主色色块，主色已切换到 ' . $next : '已删除最后一个色块，主色恢复默认 ' . $next);
        } else {
            flash('已删除色块 ' . $color);
        }
        redirect('settings.php?sec=theme#sec-theme');
    }

    /* 欢迎音频：一个区块里分别设置登录前（游客）与登录后（已登录）两条，功能各自独立 */
    $audioSlot = post('slot') === 'member' ? 'member' : 'guest';
    $audioKey  = audio_slot_keys($audioSlot);

    if (post('do') === 'audio_save') {
        if (empty($_FILES['audio']['name'])) {
            flash('请先选择一个 MP3 文件', 'err');
            redirect('settings.php?sec=audio#sec-audio');
        }
        try {
            $new = upload_audio($_FILES['audio']);
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
            redirect('settings.php?sec=audio#sec-audio');
        }
        setting_set($audioKey['file'], $new);
        setting_set($audioKey['enabled'], '1');
        flash($audioKey['label'] . '音频已上传并开启播放；旧文件留在音频库里');
        redirect('settings.php?sec=audio#sec-audio');
    }

    if (post('do') === 'audio_toggle') {
        $on = post('enabled') === '1' ? '1' : '0';
        $st = audio_slot_state($audioSlot);
        if ($on === '1' && !$st['ok']) {
            flash($audioKey['label'] . '音频还没有可用的 MP3，先上传一条', 'err');
            redirect('settings.php?sec=audio#sec-audio');
        }
        setting_set($audioKey['enabled'], $on);
        flash($audioKey['label'] . '音频已' . ($on === '1' ? '开启' : '关闭') . '播放');
        redirect('settings.php?sec=audio#sec-audio');
    }

    if (post('do') === 'audio_use') {
        $file = (string) post('file', '');
        if (!audio_path_ok($file)) {
            flash('这个音频文件不存在，请刷新后再试', 'err');
            redirect('settings.php?sec=audio#sec-audio');
        }
        setting_set($audioKey['file'], $file);
        setting_set($audioKey['enabled'], '1');
        flash('已把这条音频设为' . $audioKey['label'] . '音频，并开启播放');
        redirect('settings.php?sec=audio#sec-audio');
    }

    if (post('do') === 'audio_file_delete') {
        $file = (string) post('file', '');
        if (!audio_path_ok($file)) {
            flash('这个音频文件不存在，可能已经被删除', 'err');
            redirect('settings.php?sec=audio#sec-audio');
        }
        $cleared = [];
        foreach (['guest', 'member'] as $sl) {
            $k = audio_slot_keys($sl);
            if (setting($k['file'], '') === $file) {
                setting_set($k['file'], '');
                setting_set($k['enabled'], '0');
                $cleared[] = $k['label'];
            }
        }
        delete_audio_file($file);
        flash($cleared ? '音频已删除；' . implode(' / ', $cleared) . '音频位已清空' : '音频已删除');
        redirect('settings.php?sec=audio#sec-audio');
    }

    if (post('do') === 'audio_delete') {
        setting_set($audioKey['enabled'], '0');
        setting_set($audioKey['file'], '');
        flash('已移除' . $audioKey['label'] . '音频，文件仍保留在下方列表里');
        redirect('settings.php?sec=audio#sec-audio');
    }

    if (post('do') === 'password') {
        $old = (string) post('old_pass');
        $new = (string) post('new_pass');
        $a   = current_admin();
        if (!password_verify($old, $a['password_hash'])) {
            flash('当前密码不正确', 'err');
        } elseif (strlen($new) < 6) {
            flash('新密码至少 6 位', 'err');
        } else {
            db_update(T('admins'), ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [(int) $a['id']]);
            flash('管理员密码已更新');
        }
        redirect($setBack);
    }
}

/* 数据库备份（导出 SQL） */
if (get('backup') === '1') {
    $pdo = db();
    $dbName = $GLOBALS['SG_CFG']['db']['name'];
    $out = "-- 食光菜谱 数据库备份\n-- 数据库：{$dbName}\n-- 时间：" . date('Y-m-d H:i:s') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach (fetch_all('SHOW TABLES') as $row) {
        $tbl = array_values($row)[0];
        $create = fetch_one('SHOW CREATE TABLE `' . $tbl . '`');
        $out .= "DROP TABLE IF EXISTS `{$tbl}`;\n" . ($create['Create Table'] ?? '') . ";\n\n";
        foreach (fetch_all('SELECT * FROM `' . $tbl . '`') as $r) {
            $vals = array_map(function ($v) use ($pdo) {
                return $v === null ? 'NULL' : $pdo->quote((string) $v);
            }, $r);
            $out .= "INSERT INTO `{$tbl}` VALUES (" . implode(', ', $vals) . ");\n";
        }
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="shiguang-backup-' . date('Ymd-Hi') . '.sql"');
    echo $out;
    exit;
}

/* AI 连通性测试 */
if (get('ai_test') === '1') {
    [$ok, $text, $err] = ai_chat([
        ['role' => 'user', 'content' => '只回复两个字：可用'],
    ], ['temperature' => 0, 'max_tokens' => 20]);
    flash($ok ? 'AI 连接成功，模型回复：' . ($text !== '' ? $text : '(空)') : $err, $ok ? '' : 'err');
    redirect('settings.php?sec=ai');
}

$audioGuest   = audio_slot_state('guest');
$audioMember  = audio_slot_state('member');
$audioList    = audio_files();
$themePalette = theme_palette();

$stats = [
    'recipes'  => (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '`', [], 0),
    'users'    => (int) fetch_val('SELECT COUNT(*) FROM `' . T('users') . '`', [], 0),
    'comments' => (int) fetch_val('SELECT COUNT(*) FROM `' . T('comments') . '`', [], 0),
];

sg_admin_head($setTitle, 'admin-settings', $setTitle);
?>
        <div class="page-head">
          <h1><?= e($setTitle) ?></h1>
          <span class="sub desk-only"><?= e($setSub) ?></span>
          <div class="actions">
<?php if ($sec !== ''): ?>
            <a class="btn" href="settings.php"><span class="ico" data-ico="back"></span>返回设置</a>
            <button class="btn primary" type="submit" form="setForm"><span class="ico" data-ico="check"></span>保存设置</button>
<?php else: ?>
            <a class="btn desk-only" href="backup.php"><span class="ico" data-ico="database"></span>数据备份</a>
<?php endif; ?>
          </div>
        </div>

        <?= flash_render() ?>

<?php if ($sec === ''): ?>
        <section class="hub-panel">
          <div class="hub-panel-head"><h2>设置分区</h2><span>每项设置单独进入一页</span></div>
          <div class="hub-grid">
<?php foreach ($setSections as $key => $meta): ?>
            <a class="hub-tile" href="settings.php?sec=<?= e($key) ?>">
              <span class="hub-tile-ic"><span class="ico" data-ico="<?= e($meta[2]) ?>"></span></span>
              <div><b><?= e($meta[0]) ?></b><span><?= e($meta[1]) ?></span></div>
              <span class="hub-go"><span class="ico" data-ico="chev"></span></span>
            </a>
<?php endforeach; ?>
            <a class="hub-tile" href="backup.php">
              <span class="hub-tile-ic"><span class="ico" data-ico="database"></span></span>
              <div><b>数据备份</b><span>导出 SQL 文件</span></div>
              <span class="hub-go"><span class="ico" data-ico="chev"></span></span>
            </a>
          </div>
        </section>
<?php endif; ?>

        <form method="post" id="setForm">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="save">
          <input type="hidden" name="sec" value="<?= e($sec) ?>">
        </form>

        <div class="set-cols<?= $sec === '' ? ' is-hidden' : ' is-focus' ?>">
          <div>
            <div class="card set-section<?= $sec === 'site' ? ' is-on' : '' ?>" id="sec-site">
              <div class="card-head"><h3>站点信息</h3><span class="hint">展示在标题栏、导航与页脚</span></div>
              <div class="form-grid">
                <div class="field"><label>站点名称</label><input class="input" form="setForm" name="site_name" value="<?= e(setting('site_name', '食光菜谱')) ?>"></div>
                <div class="field"><label>副标题</label><input class="input" form="setForm" name="site_sub" value="<?= e(setting('site_sub', '家常菜谱库')) ?>"></div>
              </div>
              <div class="field" style="margin-top:14px"><label>站点描述</label><textarea class="textarea" form="setForm" name="site_desc" style="min-height:64px"><?= e(setting('site_desc', '')) ?></textarea></div>
              <div class="form-grid" style="margin-top:14px">
                <div class="field"><label>时区</label>
                  <select class="select" form="setForm" name="site_timezone">
<?php foreach (['Asia/Shanghai' => 'Asia/Shanghai (UTC+8)', 'UTC' => 'UTC', 'Asia/Tokyo' => 'Asia/Tokyo (UTC+9)', 'America/New_York' => 'America/New_York'] as $tz => $lb): ?>
                    <option value="<?= $tz ?>"<?= setting('site_timezone', 'Asia/Shanghai') === $tz ? ' selected' : '' ?>><?= $lb ?></option>
<?php endforeach; ?>
                  </select>
                </div>
                <div class="field"><label>ICP 备案号</label><input class="input" form="setForm" name="site_icp" value="<?= e(setting('site_icp', '')) ?>" placeholder="选填"></div>
              </div>
            </div>

            <div class="card set-section<?= $sec === 'list' ? ' is-on' : '' ?>" style="margin-top:14px" id="sec-list">
              <div class="card-head"><h3>列表与内容</h3><span class="hint">影响前台每页条数</span></div>
              <div class="form-grid">
                <div class="field"><label>列表每页条数</label>
                  <select class="select" form="setForm" name="per_page">
<?php foreach ([8, 12, 16, 20, 30] as $n): ?>
                    <option value="<?= $n ?>"<?= (int) setting('per_page', '12') === $n ? ' selected' : '' ?>><?= $n ?> 条</option>
<?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="field" style="margin-top:14px"><label>首页公告栏</label>
                <textarea class="textarea" form="setForm" name="home_notice" style="min-height:56px" placeholder="欢迎来到食光菜谱，今天也要好好吃饭。"><?= e(setting('home_notice', '欢迎来到食光菜谱，今天也要好好吃饭。')) ?></textarea>
                <span class="tip">显示在首页导航下方的播报条里；推荐会自动补，更新内容在下方“版本更新说明”填写；注册用户评论单独显示在下方评论栏。</span>
              </div>
              <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px">
                <input type="checkbox" name="home_notice_on" value="1" form="setForm" style="accent-color:var(--brand)"<?= setting('home_notice_on', '1') === '1' ? ' checked' : '' ?>> 显示首页公告栏
              </label>
              <div class="field" style="margin-top:10px"><label>公告链接（可选）</label>
                <input class="input" form="setForm" name="home_notice_link" value="<?= e(setting('home_notice_link', '')) ?>" placeholder="留空时点击公告打开站内公告详情页" autocomplete="off">
                <span class="tip">填站内路径或完整网址；留空就打开 <code>notice.php</code> 的公告详情页，保证公告也能点。</span>
              </div>
              <div class="field" style="margin-top:14px"><label>版本号</label>
                <input class="input" form="setForm" name="home_update_version" value="<?= e(setting('home_update_version', 'v' . SG_VERSION)) ?>" placeholder="例如：v1.14.4" autocomplete="off">
                <span class="tip">前台显示成「v1.14.4 已更新：…」；只填版本号，不用写“v”也可以。</span>
              </div>
              <div class="field" style="margin-top:14px"><label>版本更新说明</label>
                <textarea class="textarea" form="setForm" name="home_update_text" style="min-height:56px" placeholder="例如：云端同步支持 InfinityFree 自动验证；首页搜索框移到标题上方。"><?= e(setting('home_update_text', '首页空数据时补上空状态卡片，不再只剩标题和一大片空白。')) ?></textarea>
                <span class="tip">只填这次更新了什么；前台自动拼成「版本号 已更新：你填的内容」。留空或取消下面勾选，这一条就不显示。</span>
              </div>
              <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px">
                <input type="checkbox" name="home_update_on" value="1" form="setForm" style="accent-color:var(--brand)"<?= setting('home_update_on', '1') === '1' ? ' checked' : '' ?>> 显示版本更新说明
              </label>
              <p class="tip" style="margin-top:8px">每次修改版本号或更新说明并保存，上一条会自动收进 <a href="../updates.php" target="_blank" rel="noopener">版本更新记录</a>；前台点播报里的“更新”就能打开历史版本。</p>
            </div>

            <div class="card set-section<?= $sec === 'session' ? ' is-on' : '' ?>" style="margin-top:14px" id="sec-session">
              <div class="card-head"><h3>登录与安全</h3><span class="hint">前台用户多设备上限</span></div>
              <div class="form-grid">
                <div class="field"><label>同时在线设备</label>
                  <select class="select" form="setForm" name="session_limit">
<?php foreach ([1 => '1 台（单设备模式）', 2 => '2 台', 3 => '3 台（手机 + 电脑 + 备用）', 5 => '5 台（默认）', 8 => '8 台', 10 => '10 台'] as $n => $lb): ?>
                    <option value="<?= $n ?>"<?= (int) setting('session_limit', '5') === $n ? ' selected' : '' ?>><?= $lb ?></option>
<?php endforeach; ?>
                  </select>
                </div>
              </div>
              <p class="tip" style="margin-top:8px">同一账号可以手机和电脑同时在线。超过上限时，系统自动退出最久没用的设备；设为 1 台就恢复单设备模式。管理员登录不受这里影响，仍为单设备。</p>
            </div>

            <div class="card audio-card set-section<?= $sec === 'audio' ? ' is-on' : '' ?>" style="margin-top:14px" id="sec-audio">
              <div class="card-head"><h3>欢迎音频</h3><span class="hint">首次进入自动播放一次 · MP3 · 20 MB 以内</span><a class="arrow-link" href="site-doctor.php#audio" style="margin-left:auto;font-size:11.5px;color:var(--brand);text-decoration:none;font-weight:600;white-space:nowrap">网站诊断<span class="ico" data-ico="chev"></span></a></div>
              <p class="audio-lead">游客听“登录前”，已登录用户听“登录后”。两条各自启用、各自替换，只开一条也可以。
                <label style="display:inline-flex;align-items:center;gap:6px;margin-left:10px;font-size:12px;color:var(--muted)"><input type="checkbox" name="audio_embed" value="1" form="setForm" style="accent-color:var(--brand)"<?= sg_audio_embed_mode() ? ' checked' : '' ?>> 嵌入播放（InfinityFree 等免费主机没声音时勾选）</label></p>

              <div class="audio-slots">
<?php foreach ([$audioGuest, $audioMember] as $st):
    $aSlot = $st['slot'];
    $aOn   = $st['enabled'];
    $aOk   = $st['ok'];
    $aFile = $st['file'];
    $aPlay = audio_refresh_builtin($aFile, $aSlot);
    $aSrc  = '';
    $aProxy = '';
    if ($aOk) {
        $aProxy = '../audio.php?slot=' . $aSlot . '&preview=1&v=' . (int) $st['mtime'];
        $aSrc = sg_audio_embed_mode() ? audio_data_uri($aPlay) : $aProxy;
    }
?>
                <div class="audio-slot<?= ($aOk && $aOn) ? ' is-on' : '' ?>">
                  <span class="audio-slot-tag<?= $aSlot === 'member' ? ' is-member' : '' ?>"><?= e($st['label']) ?></span>
                  <div class="audio-slot-body">
                    <b class="audio-slot-name<?= $aOk ? '' : ' is-empty' ?>"><?= $aFile !== '' ? e(basename($aFile)) : '未添加音频' ?></b>
                    <span class="audio-slot-meta">
<?php if ($aOk): ?>
                      <?= $st['size'] > 1048576 ? round($st['size'] / 1048576, 1) . ' MB' : round($st['size'] / 1024) . ' KB' ?> · <?= e(date('Y-m-d H:i', $st['mtime'])) ?> · <?= $aOn ? '使用中' : '未启用' ?>
<?php elseif ($aFile !== ''): ?>
                      文件被改名或移动了；前台会按文件名里的“登录前 / 登录后”自动找一条，建议在下方音频库点“用于<?= $aSlot === 'member' ? '登录后' : '登录前' ?>”固定
<?php else: ?>
                      <?= $aSlot === 'member' ? '已登录用户第一次进入时播放' : '游客未登录时第一次进入时播放' ?>
<?php endif; ?>
                    </span>
                  </div>
                  <div class="audio-slot-acts">
<?php if ($aOk): ?>
                    <button type="button" class="btn ghost sm audio-play" data-audio-play><span class="ico" data-ico="play"></span><span data-audio-label>试听</span></button>
                    <audio class="sg-audio-probe" src="<?= e($aSrc) ?>" data-proxy="<?= e(sg_audio_embed_mode() ? '' : $aProxy) ?>" preload="metadata" data-audio-el></audio>
<?php endif; ?>
<?php if ($aFile !== ''): ?>
                    <form method="post" class="audio-mini">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="audio_toggle">
                      <input type="hidden" name="slot" value="<?= e($aSlot) ?>">
                      <label class="audio-switch"><input type="checkbox" name="enabled" value="1" onchange="this.form.submit()"<?= ($aOn && $aOk) ? ' checked' : '' ?><?= $aOk ? '' : ' disabled' ?>> 启用</label>
                    </form>
<?php endif; ?>
                    <form method="post" enctype="multipart/form-data" class="audio-mini">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="audio_save">
                      <input type="hidden" name="slot" value="<?= e($aSlot) ?>">
                      <label class="<?= $aFile !== '' ? 'btn ghost sm' : 'btn primary sm' ?> audio-pick"><span class="ico" data-ico="upload"></span><?= $aFile !== '' ? '更换' : '上传 MP3' ?><input type="file" name="audio" accept=".mp3,audio/mpeg" onchange="if (this.files[0]) this.form.submit()"></label>
                    </form>
<?php if ($aFile !== ''): ?>
                    <form method="post" class="audio-mini" onsubmit="return confirm('移除<?= e($st['label']) ?>音频？文件会留在音频库')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="audio_delete">
                      <input type="hidden" name="slot" value="<?= e($aSlot) ?>">
                      <button type="submit" class="btn ghost sm audio-rm"><span class="ico" data-ico="trash"></span>移除</button>
                    </form>
<?php endif; ?>
                  </div>
                </div>
<?php endforeach; ?>
              </div>

              <details class="audio-lib">
                <summary><b>音频库</b><span><?= count($audioList) ?> 个文件 · 点开换用或删除旧音频</span></summary>
<?php if (!$audioList): ?>
                <div class="audio-lib-empty">还没有上传过音频。</div>
<?php else: ?>
                <div class="audio-lib-list">
<?php foreach ($audioList as $a):
    $isGuest  = $a['path'] === $audioGuest['file'];
    $isMember = $a['path'] === $audioMember['file'];
?>
                  <div class="audio-lib-row">
                    <span class="ico" data-ico="music"></span>
                    <div class="audio-lib-main">
                      <b title="<?= e($a['name']) ?>"><?= e($a['name']) ?></b>
                      <div class="audio-lib-sub">
                        <span class="audio-lib-meta"><?= $a['size'] > 1048576 ? round($a['size'] / 1048576, 1) . ' MB' : round($a['size'] / 1024) . ' KB' ?> · <?= e(date('Y-m-d H:i', $a['mtime'])) ?></span>
<?php if ($isGuest): ?><span class="badge hot">登录前</span><?php endif; ?>
<?php if ($isMember): ?><span class="badge live">登录后</span><?php endif; ?>
                      </div>
                    </div>
                    <div class="audio-lib-acts">
<?php if (!$isGuest): ?>
                      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="audio_use"><input type="hidden" name="slot" value="guest"><input type="hidden" name="file" value="<?= e($a['path']) ?>"><button type="submit" class="lib-act">用于登录前</button></form>
<?php endif; ?>
<?php if (!$isMember): ?>
                      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="audio_use"><input type="hidden" name="slot" value="member"><input type="hidden" name="file" value="<?= e($a['path']) ?>"><button type="submit" class="lib-act">用于登录后</button></form>
<?php endif; ?>
                      <form method="post" onsubmit="return confirm('确定删除这条音频吗？正在使用它的位置会一并清空')"><?= csrf_field() ?><input type="hidden" name="do" value="audio_file_delete"><input type="hidden" name="file" value="<?= e($a['path']) ?>"><button type="submit" class="lib-act is-danger">删除</button></form>
                    </div>
                  </div>
<?php endforeach; ?>
                </div>
<?php endif; ?>
              </details>
              <p class="tip" style="margin-top:12px">同一浏览器每位访客只自动播放一次；换文件后，已经听过的访客不会被再次打扰。</p>
            </div>

            <div class="card set-section<?= $sec === 'theme' ? ' is-on' : '' ?>" style="margin-top:14px" id="sec-theme">
              <div class="card-head"><h3>外观主题</h3><span class="hint">前台与后台共用</span></div>
              <div class="field" style="margin-bottom:14px"><label>主色</label>
                <input class="input" form="setForm" name="theme_primary" value="<?= e(setting('theme_primary', '#C8502B')) ?>" placeholder="#C8502B" maxlength="9" style="max-width:160px">
                <span class="tip">修改后需刷新页面生效；十六进制颜色值，例如 #C8502B</span>
              </div>
              <div class="theme-swatches">
<?php foreach ($themePalette as $c): ?>
                <div class="theme-swatch-item">
                  <button type="button" class="theme-swatch<?= strtoupper(setting('theme_primary', '#C8502B')) === $c ? ' on' : '' ?>" data-theme-color="<?= $c ?>" aria-label="使用主色 <?= $c ?>" style="--c:<?= $c ?>"></button>
                  <form method="post" class="theme-swatch-del">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="theme_palette_delete">
                    <input type="hidden" name="color" value="<?= e($c) ?>">
                    <button type="submit" class="theme-swatch-del-btn" title="删除色块 <?= e($c) ?>" aria-label="删除色块 <?= e($c) ?>">×</button>
                  </form>
                </div>
<?php endforeach; ?>
<?php if (!$themePalette): ?>
                <span class="theme-empty">色块已全部删除；输入颜色后点“保存主色”即可添加。</span>
<?php endif; ?>
                <button class="btn sm theme-save" type="submit" form="setForm"><span class="ico" data-ico="check"></span>保存主色</button>
              </div>
              <span class="tip theme-hint" data-theme-hint>手动输入后会同步预览和选中；点“保存主色”会把当前色加入下方色块。色块右上角 × 可单独删除。</span>
              <script>
              (function () {
                var input = document.querySelector('input[name="theme_primary"]');
                var swatches = document.querySelectorAll('[data-theme-color]');
                var hint = document.querySelector('[data-theme-hint]');
                if (!input || !swatches.length) return;
                function mark(color) {
                  var c = (color || '').trim().toUpperCase();
                  for (var i = 0; i < swatches.length; i++) {
                    swatches[i].classList.toggle('on', swatches[i].getAttribute('data-theme-color') === c);
                  }
                }
                function preview(color) {
                  var c = (color || '').trim();
                  if (!/^#[0-9a-fA-F]{6}$/.test(c)) return false;
                  c = c.toUpperCase();
                  input.value = c;
                  mark(c);
                  document.documentElement.style.setProperty('--brand', c);
                  if (hint) hint.textContent = '已选中 ' + c + '，点“保存主色”后全站生效并加入色块。';
                  return true;
                }
                for (var i = 0; i < swatches.length; i++) {
                  swatches[i].addEventListener('click', function () {
                    preview(this.getAttribute('data-theme-color'));
                  });
                }
                input.addEventListener('input', function () {
                  if (preview(input.value)) { /* 预览 */ }
                });
              })();
              </script>
            </div>
            <div class="card set-section<?= $sec === 'ai' ? ' is-on' : '' ?>" style="margin-top:14px" id="sec-ai">
              <div class="card-head"><h3>AI 接入</h3><span class="hint">本地 Ollama / 在线 OpenAI 兼容，任选其一</span>
                <div class="right"><a class="btn sm" href="settings.php?ai_test=1"><span class="ico" data-ico="bolt"></span>测试连接</a></div>
              </div>
              <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px;margin-bottom:14px">
                <input type="checkbox" name="ai_enabled" value="1" form="setForm" style="accent-color:var(--brand)"<?= setting('ai_enabled', '0') === '1' ? ' checked' : '' ?>> 启用 AI 功能
              </label>
              <div class="preset-row" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">
                <button class="btn sm" type="button" data-preset="ollama">本机 Ollama</button>
                <button class="btn sm" type="button" data-preset="lmstudio">LM Studio</button>
                <button class="btn sm" type="button" data-preset="deepseek">DeepSeek</button>
                <button class="btn sm" type="button" data-preset="qwen">通义千问</button>
                <button class="btn sm" type="button" data-preset="openai">OpenAI</button>
              </div>
              <div class="form-grid">
                <div class="field"><label>服务类型</label>
                  <select class="select" form="setForm" name="ai_kind">
                    <option value="ollama"<?= setting('ai_kind', 'ollama') === 'ollama' ? ' selected' : '' ?>>Ollama（/api/chat）</option>
                    <option value="openai"<?= setting('ai_kind', 'ollama') === 'openai' ? ' selected' : '' ?>>OpenAI 兼容（/v1/chat/completions）</option>
                  </select>
                </div>
                <div class="field"><label>服务地址</label><input class="input" form="setForm" name="ai_base" value="<?= e(setting('ai_base', 'http://127.0.0.1:11434')) ?>" placeholder="http://127.0.0.1:11434"></div>
                <div class="field">
                  <label>模型名</label>
                  <div class="model-pick">
                    <input class="input" form="setForm" name="ai_model" id="aiModelInput" value="<?= e(setting('ai_model', 'qwen2.5:3b')) ?>" placeholder="qwen2.5:3b" autocomplete="off">
                    <button class="btn sm" type="button" id="aiModelFetch"><span class="ico" data-ico="refresh"></span>读取模型</button>
                  </div>
                  <div class="model-chips" id="aiModelChips"></div>
                  <div class="model-msg" id="aiModelMsg">填好服务地址后点「读取模型」，会列出这个地址里已有的模型。</div>
                </div>
                <div class="field"><label>接口密钥（本地留空；在线服务填 API Key）</label><input class="input" form="setForm" name="ai_key" value="<?= e(setting('ai_key', '')) ?>" placeholder="选填"></div>
                <div class="field"><label>超时（秒）</label><input class="input" form="setForm" name="ai_timeout" type="number" min="5" max="300" value="<?= (int) setting('ai_timeout', '60') ?>"></div>
              </div>
              <p class="tip" style="margin-top:12px">Ollama 本机地址填 <b>http://127.0.0.1:11434</b>；在线服务填各自 Base URL 与 API Key。填好地址后离开输入框，会自动读取模型列表，也可以点「读取模型」。具体选哪个模型、cpolar 怎么把本地 Ollama 给云端用，看下面。</p>

              <details class="ai-model-help">
                <summary>手机 / 电脑模型推荐 + cpolar 内网穿透用法</summary>
                <div class="ai-help-body">
                  <p><b>电脑：华硕天选5 Pro（RTX 4060 8GB / 16GB 内存）</b></p>
                  <ul>
                    <li><b>主用：<code>qwen2.5:7b</code></b>（约 4.7GB，8GB 显存能装下，中文菜谱效果比 3B 明显好；生成一道菜约 20–40 秒）</li>
                    <li>想更快：<code>qwen2.5:3b</code>；不建议 <code>qwen2.5:14b</code>，8GB 显存装不下，会大量走 CPU，反而很慢</li>
                    <li>命令：<code>ollama pull qwen2.5:7b</code>，再 <code>ollama run qwen2.5:7b</code>；用 <code>ollama list</code> 核对模型名</li>
                  </ul>

                  <p><b>手机：红米 K70 Pro（12GB 内存，Termux 版）</b></p>
                  <ul>
                    <li><b>主用：<code>qwen2.5:3b</code></b>（约 2GB，Termux 只能走 CPU，生成一道菜约 20–60 秒）</li>
                    <li>卡顿/发热明显：换 <code>qwen2.5:1.5b</code>；7B 在手机上能跑但很慢、发热大，不建议长期用</li>
                    <li>Termux 里一个窗口运行 <code>ollama serve</code>，另一个窗口 <code>ollama pull qwen2.5:3b</code>；模型名同样用 <code>ollama list</code> 核对</li>
                  </ul>

                  <p><b>cpolar 把本地 Ollama 临时给云端用</b></p>
                  <ol>
                    <li>cpolar 跑在 Ollama 同一台机器上：电脑主用，手机备用。客户端里创建 <b>HTTP 隧道</b>，本地地址填 <code>127.0.0.1:11434</code>，启动后复制 <code>https://</code> 开头的公网地址。命令行等价于 <code>cpolar http 11434</code>。</li>
                    <li>云端后台「系统设置 → AI 接入」：服务类型选 <b>Ollama（/api/chat）</b>；服务地址粘贴 cpolar 的 <code>https://…cpolar.cn</code>，<b>不要带 /api</b>；模型名填你在用的那个，例如电脑 <code>qwen2.5:7b</code>、手机 <code>qwen2.5:3b</code>。</li>
                    <li>先点「测试连接」。也可以在浏览器打开 <code>https://…cpolar.cn/api/tags</code>，能看到 JSON 就说明隧道和 Ollama 都通。</li>
                    <li>用完顺序：云端先关 AI → 再关 cpolar。cpolar 免费版重启后地址可能变，变一次就回来改一次服务地址。</li>
                  </ol>

                  <p><b>本地 KSWEB 后台也想用 AI 时：</b>用手机 Termux 就填 <code>http://127.0.0.1:11434</code> + <code>qwen2.5:3b</code>；用电脑 Ollama 就填电脑的局域网 IP（例如 <code>http://192.168.1.20:11434</code>）+ <code>qwen2.5:7b</code>，手机和电脑要在同一个 Wi-Fi，Windows 防火墙放行 11434。</p>

                  <p class="tip" style="margin:10px 0 0">安全提醒：cpolar 公网地址谁打开谁就能用你的模型。只给家人用也别长期挂；最好给 cpolar 加访问密码，或在 Ollama 前面加带 token 的网关。手机和电脑不要同时抢同一个 cpolar 地址，各开各的隧道。</p>
                </div>
              </details>
              <script>
              (function () {
                var P = {
                  ollama:   { kind: 'ollama', base: 'http://127.0.0.1:11434', model: 'qwen2.5:3b', key: '' },
                  lmstudio: { kind: 'openai', base: 'http://127.0.0.1:1234/v1', model: 'local-model', key: '' },
                  deepseek: { kind: 'openai', base: 'https://api.deepseek.com/v1', model: 'deepseek-chat', key: '' },
                  qwen:     { kind: 'openai', base: 'https://dashscope.aliyuncs.com/compatible-mode/v1', model: 'qwen-plus', key: '' },
                  openai:   { kind: 'openai', base: 'https://api.openai.com/v1', model: 'gpt-4o-mini', key: '' }
                };
                document.querySelectorAll('[data-preset]').forEach(function (b) {
                  b.addEventListener('click', function () {
                    var p = P[b.getAttribute('data-preset')]; if (!p) return;
                    var set = function (name, val) {
                      var el = document.querySelector('[name="' + name + '"]');
                      if (el) el.value = val;
                    };
                    set('ai_kind', p.kind);
                    set('ai_base', p.base);
                    set('ai_model', p.model);
                    var enabled = document.querySelector('[name="ai_enabled"]');
                    if (enabled) enabled.checked = true;
                    if (window.SG_TOAST) SG_TOAST('已填入 ' + b.textContent.trim() + ' 配置，记得保存');
                  });
                });
              })();

              /* 读取服务地址里的模型列表 */
              (function () {
                var base  = document.querySelector('[name="ai_base"]');
                var kind  = document.querySelector('[name="ai_kind"]');
                var key   = document.querySelector('[name="ai_key"]');
                var input = document.getElementById('aiModelInput');
                var btn   = document.getElementById('aiModelFetch');
                var chips = document.getElementById('aiModelChips');
                var msg   = document.getElementById('aiModelMsg');
                if (!base || !input || !btn || !chips || !msg) return;
                var lastBase = base.value.trim();
                var busy = false;

                function setMsg(text, bad) {
                  msg.textContent = text;
                  msg.className = 'model-msg' + (bad ? ' is-bad' : '');
                }
                function names(data) {
                  var out = [];
                  var add = function (v) { if (typeof v === 'string' && v.trim()) out.push(v.trim()); };
                  if (data && Array.isArray(data.models)) data.models.forEach(function (m) {
                    if (typeof m === 'string') add(m); else if (m) add(m.name || m.model || m.id);
                  });
                  if (data && Array.isArray(data.data)) data.data.forEach(function (m) {
                    if (typeof m === 'string') add(m); else if (m) add(m.id || m.name);
                  });
                  return out.filter(function (v, i, a) { return a.indexOf(v) === i; }).sort(function (a, b) { return a.localeCompare(b); });
                }
                function show(list) {
                  chips.innerHTML = '';
                  var cur = input.value.trim();
                  list.forEach(function (name) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'model-chip' + (name === cur ? ' on' : '');
                    b.textContent = name;
                    b.addEventListener('click', function () {
                      input.value = name;
                      Array.prototype.forEach.call(chips.children, function (x) { x.classList.remove('on'); });
                      b.classList.add('on');
                      setMsg('已选择 ' + name + '，记得点「保存设置」。');
                      if (window.SG_TOAST) SG_TOAST('已填入模型 ' + name);
                    });
                    chips.appendChild(b);
                  });
                  var missing = cur && list.indexOf(cur) === -1;
                  setMsg('已读取 ' + list.length + ' 个模型' + (missing ? '；当前填的「' + cur + '」不在列表里，可能还没 pull' : '') + '。点一个即可填入。', missing);
                }
                function directUrl(b, k) {
                  b = b.replace(/\/+$/, '').replace(/\/api\/chat$/i, '');
                  if (k === 'ollama') {
                    if (/\/api\/tags$/i.test(b)) return b;
                    if (/\/api$/i.test(b)) return b + '/tags';
                    return b + '/api/tags';
                  }
                  b = b.replace(/\/chat\/completions$/i, '');
                  if (/\/models$/i.test(b)) return b;
                  if (/\/v1$/i.test(b)) return b + '/models';
                  return b + '/v1/models';
                }
                function direct(b, k) {
                  return new Promise(function (resolve, reject) {
                    var url = directUrl(b, k);
                    if (location.protocol === 'https:' && /^http:\/\//i.test(url)) {
                      reject(new Error('当前页面是 HTTPS，浏览器不能直接访问 HTTP 地址'));
                      return;
                    }
                    var ctrl = new AbortController();
                    var timer = setTimeout(function () { ctrl.abort(); }, 6000);
                    fetch(url, {
                      method: 'GET',
                      mode: 'cors',
                      headers: key && key.value.trim() ? { Authorization: 'Bearer ' + key.value.trim() } : {},
                      signal: ctrl.signal
                    }).then(function (r) {
                      return r.json().then(function (d) {
                        clearTimeout(timer);
                        if (!r.ok) throw new Error((d && d.error && (d.error.message || d.error)) || ('HTTP ' + r.status));
                        var list = names(d);
                        if (!list.length) throw new Error('接口通了，但没有返回模型列表');
                        resolve(list);
                      });
                    }).catch(function (e) {
                      clearTimeout(timer);
                      reject(e && e.name === 'AbortError' ? new Error('浏览器直连超时') : e);
                    });
                  });
                }
                function load(auto) {
                  if (busy) return;
                  var b = base.value.trim();
                  if (!/^https?:\/\//i.test(b)) {
                    setMsg('请先填写以 http:// 或 https:// 开头的服务地址', true);
                    return;
                  }
                  lastBase = b;
                  busy = true;
                  btn.disabled = true;
                  setMsg('正在读取模型…');
                  var fd = new FormData();
                  fd.append('base', b);
                  fd.append('kind', kind ? kind.value : 'ollama');
                  fd.append('key', key ? key.value.trim() : '');
                  fd.append('_token', window.SG_CSRF || '');
                  fetch((window.SG_API || 'api/') + 'ai-models.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                      if (j.ok && Array.isArray(j.models) && j.models.length) { show(j.models); return; }
                      throw new Error(j.msg || '没有读取到模型');
                    })
                    .catch(function (e) {
                      return direct(b, kind ? kind.value : 'ollama').then(function (list) {
                        show(list);
                        setMsg('服务器读不到，已由浏览器直连读取到 ' + list.length + ' 个模型。点一个即可填入。');
                      }).catch(function (e2) {
                        setMsg('读取失败：' + (e.message || '服务器无法访问') + (e2 && e2.message ? '；直连：' + e2.message : '') + '。可以手动填写模型名。', true);
                      });
                    })
                    .finally(function () {
                      busy = false;
                      btn.disabled = false;
                    });
                }
                btn.addEventListener('click', function () { load(false); });
                base.addEventListener('change', function () {
                  var b = base.value.trim();
                  if (b && b !== lastBase) load(true);
                });
                if (kind) kind.addEventListener('change', function () {
                  if (base.value.trim()) load(true);
                });
              })();
              </script>

              <div style="border-top:1px dashed var(--line);margin:18px 0 14px"></div>
              <div class="card-head" style="padding:0;border:0;margin-bottom:12px"><h3 style="font-size:13px">图像接口（可选）</h3><span class="hint">用于生成食材圆图和步骤图</span></div>
              <div class="form-grid">
                <div class="field"><label>图像服务地址</label><input class="input" form="setForm" name="ai_img_base" value="<?= e(setting('ai_img_base', '')) ?>" placeholder="https://paraloncloud.com/v1 或完整 .../images/generations"></div>
                <div class="field"><label>图像模型名</label><input class="input" form="setForm" name="ai_img_model" value="<?= e(setting('ai_img_model', '')) ?>" placeholder="z-image-turbo / cogview-3-flash"></div>
                <div class="field"><label>图像接口密钥</label><input class="input" form="setForm" name="ai_img_key" value="<?= e(setting('ai_img_key', '')) ?>" placeholder="选填"></div>
              </div>
              <p class="tip" style="margin-top:10px">支持 OpenAI 兼容的 <b>/images/generations</b>，也可以直接填完整接口地址；返回 <b>b64_json</b> 或图片 <b>URL</b> 都会自动保存。<br>免费可选：智谱 CogView-3-Flash（地址 <b>https://open.bigmodel.cn/api/paas/v4/images/generations</b>，模型 <b>cogview-3-flash</b>）；ParalonCloud（地址 <b>https://paraloncloud.com/v1</b>，模型 <b>z-image-turbo</b>）。<br>留空则「AI 配图」按钮自动隐藏，纯文本功能不受影响。</p>
            </div>
          </div>

          <aside>
            <div class="card set-section<?= $sec === 'env' ? ' is-on' : '' ?>" id="sec-env">
              <div class="card-head"><h3>运行环境</h3></div>
              <div class="meta-box">
                <div class="meta-row"><span class="ico" data-ico="database"></span><span>数据库</span><b><?= e($GLOBALS['SG_CFG']['db']['name'] ?? '') ?></b></div>
                <div class="meta-row"><span class="ico" data-ico="folder"></span><span>表前缀</span><b><?= e($GLOBALS['SG_CFG']['db']['prefix'] ?? 'sg_') ?></b></div>
                <div class="meta-row"><span class="ico" data-ico="settings"></span><span>PHP 版本</span><b><?= e(PHP_VERSION) ?></b></div>
                <div class="meta-row"><span class="ico" data-ico="shield"></span><span>安装状态</span><b style="color:var(--green)"><?= is_file(SG_ROOT . '/config/install.lock') ? '已完成' : '未锁定' ?></b></div>
                <?php if (is_dir(SG_ROOT . '/install')): ?>
                <div class="meta-row"><span class="ico" data-ico="database"></span><span>数据备份</span><b><a class="arrow-link" href="backup.php" style="color:var(--brand)">前往备份 / 下载<span class="ico" data-ico="chev"></span></a></b></div>
                <div class="meta-row"><span class="ico" data-ico="database"></span><span>安装程序</span><b><a class="arrow-link" href="install.php" style="color:var(--brand)">打开安装 / 重装向导<span class="ico" data-ico="chev"></span></a></b></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card set-section<?= $sec === 'env' ? ' is-on' : '' ?>" style="margin-top:14px">
              <div class="card-head"><h3>内容统计</h3></div>
              <div class="meta-box">
                <div class="meta-row"><span class="ico" data-ico="book"></span><span>菜谱</span><b><?= $stats['recipes'] ?> 道</b></div>
                <div class="meta-row"><span class="ico" data-ico="users"></span><span>注册用户</span><b><?= $stats['users'] ?> 人</b></div>
                <div class="meta-row"><span class="ico" data-ico="comment"></span><span>评论</span><b><?= $stats['comments'] ?> 条</b></div>
              </div>
            </div>

            <div class="card set-section<?= $sec === 'env' ? ' is-on' : '' ?>" style="margin-top:14px">
              <div class="card-head"><h3>备份</h3><span class="hint">导出 SQL 文件</span></div>
              <div class="field" style="margin-bottom:12px"><label>自动备份频率</label>
                <select class="select" form="setForm" name="backup_freq">
<?php foreach (['daily' => '每天一次', 'week' => '每周一次', 'manual' => '仅手动'] as $k => $lb): ?>
                  <option value="<?= $k ?>"<?= setting('backup_freq', 'daily') === $k ? ' selected' : '' ?>><?= $lb ?></option>
<?php endforeach; ?>
                </select>
              </div>
              <a class="btn block" href="settings.php?backup=1"><span class="ico" data-ico="down"></span>立即备份数据库</a>
              <p style="margin:10px 0 0;font-size:11px;color:var(--muted)">备份为完整 SQL（结构 + 数据），可导入恢复。</p>
            </div>

            <div class="card set-section<?= $sec === 'pass' ? ' is-on' : '' ?>" style="margin-top:14px;background:#FBF6EE" id="sec-pass">
              <div class="card-head"><h3>管理员密码</h3></div>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="password">
                <div class="field" style="margin-bottom:10px"><label>当前密码</label><input class="input" name="old_pass" type="password" required></div>
                <div class="field" style="margin-bottom:12px"><label>新密码</label><input class="input" name="new_pass" type="password" required></div>
                <button class="btn block sm" type="submit"><span class="ico" data-ico="lock"></span>更新密码</button>
              </form>
            </div>
          </aside>
        </div>
<?php
sg_admin_foot();
