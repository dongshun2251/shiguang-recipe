<?php
/* 部署自检页：访问 /check.php 可确认实际运行的文件版本（检查完请删除本文件） */
$root = __DIR__;
$boot = @file_get_contents($root . '/inc/bootstrap.php') ?: '';
preg_match("/SG_VERSION,\s*'([^']+)'/", $boot, $m);
$version = $m[1] ?? '未知';
$checks = [];
foreach (['assets/app.js', 'assets/app-extra.css', 'assets/base.css', 'inc/layout.php', 'install/index.php'] as $f) {
    $p = $root . '/' . $f;
    $checks[$f] = is_file($p) ? ['size' => filesize($p), 'time' => date('Y-m-d H:i', filemtime($p)), 'hash' => substr(sha1_file($p), 0, 8)] : null;
}
$js = @file_get_contents($root . '/assets/app.js') ?: '';
$flags = [
    '含旧版全屏弹窗文案（全屏显示模式）' => strpos($js, '全屏显示模式') !== false,
    "仍强制横屏 lock('landscape')" => strpos($js, "lock('landscape')") !== false,
    '含旧版模式记忆 sg_fs_mode' => strpos($js, 'sg_fs_mode') !== false,
];
$wanted = "已内置竖屏锁定 lock('portrait')" ;
$hasPortrait = strpos($js, "lock('portrait')") !== false;
?>
<!DOCTYPE html><html lang="zh-CN" translate="no"><head><meta charset="UTF-8"><meta name="google" content="notranslate"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>部署自检 · 食光菜谱</title>
<style>body{margin:0;padding:20px;font:14px/1.7 -apple-system,"PingFang SC",sans-serif;background:#F7F3EC;color:#1B1712}
h1{font-size:18px;margin:0 0 4px}p{margin:0 0 16px;color:#8A8378;font-size:12px}
.card{background:#FFFDFA;border:1px solid #E4DCCE;border-radius:12px;padding:14px 16px;margin-bottom:12px}
b{font-size:13px}table{width:100%;border-collapse:collapse;font-size:12px}td{padding:7px 0;border-bottom:1px dashed #E4DCCE;word-break:break-all}
td:last-child{text-align:right;color:#2E6B57}.bad{color:#C8502B!important}
.ok{display:inline-block;padding:2px 8px;border-radius:99px;background:#E7F1EA;color:#2E6B57;font-size:12px}
</style></head><body>
<h1>部署自检</h1><p>当前运行版本 <span class="ok">v<?= htmlspecialchars($version) ?></span> · PHP <?= htmlspecialchars(PHP_VERSION) ?> · <?= date('Y-m-d H:i:s') ?></p>
<div class="card"><b>关键文件</b><table>
<?php foreach ($checks as $f => $i): ?>
<tr><td><?= htmlspecialchars($f) ?></td><td><?= $i ? htmlspecialchars($i['size'] . ' B · ' . $i['hash'] . ' · ' . $i['time']) : '<span class="bad">缺失</span>' ?></td></tr>
<?php endforeach; ?>
</table></div>
<div class="card"><b>前端脚本检查</b><table>
<?php foreach ($flags as $label => $bad): ?>
<tr><td><?= htmlspecialchars($label) ?></td><td><?= $bad ? '<span class="bad">是（旧版）</span>' : '否（正常）' ?></td></tr>
<?php endforeach; ?>
<tr><td><?= htmlspecialchars($wanted) ?></td><td><?= $hasPortrait ? '是（正常）' : '<span class="bad">否（缺少竖屏锁定）</span>' ?></td></tr>
</table></div>
<div class="card"><b>安装状态</b><table>
<tr><td>config/config.php</td><td><?= is_file($root . '/config/config.php') ? '已生成' : '未生成' ?></td></tr>
<tr><td>config/install.lock</td><td><?= is_file($root . '/config/install.lock') ? '已锁定' : '未锁定' ?></td></tr>
<tr><td>install 目录</td><td><?= is_dir($root . '/install') ? '存在' : '已删除' ?></td></tr>
</table></div>
<div class="card"><b>浏览器与视口</b><table>
<tr><td>User-Agent</td><td style="text-align:left;font-size:11px"><?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '') ?></td></tr>
<tr><td>视口（CSS 像素）</td><td id="vp">读取中…</td></tr>
<tr><td>是否识别为手机 UA</td><td><?= function_exists('sg_is_mobile_ua') ? '需在站内页面验证' : '—' ?></td></tr>
</table></div>
<script>document.getElementById('vp').textContent = innerWidth + ' × ' + innerHeight + ' · DPR ' + (devicePixelRatio || 1);</script>
<p>确认无误后请删除本文件 check.php。</p>
</body></html>
