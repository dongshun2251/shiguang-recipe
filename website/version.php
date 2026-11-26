<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');

/* 只给桌面端 / Android 客户端用；网页端不弹更新提示。
   三端版本优先读后台「客户端更新」，没配置过时回落到下面这份默认值。 */
require_once __DIR__ . '/inc/bootstrap.php';

$sgClientDefaults = [
    'desktop' => [
        'version' => '1.0.5',
        'url'     => 'https://aka.doubaocdn.com/s/wHz8D6RlOa',
        'notes'   => '欢迎音频改为客户端本地播放，修复桌面端“音频播放失败”；二维码刷新按钮回到失效遮罩内。',
    ],
    'android' => [
        'version' => '1.0.5',
        'url'     => 'https://aka.doubaocdn.com/s/mQzlBJRPkk',
        'notes'   => '扫码不再调用会卡死的系统识别器，改为中心裁切 jsQR；二维码刷新按钮回到失效遮罩内。',
    ],
    'tablet' => [
        'version' => '1.0.5',
        'url'     => 'https://aka.doubaocdn.com/s/c1T2CN39rX',
        'notes'   => '平板扫码同步新的 jsQR 识别逻辑，应用名去掉“平板”，二维码刷新按钮回到失效遮罩内。',
    ],
];

$sgClientUpdate = [];
foreach ($sgClientDefaults as $sgKey => $sgDefault) {
    $sgVersionKey = 'client_update_' . $sgKey . '_version';
    /* 后台从未保存过这一端：使用代码里的默认值，避免刚升级时更新检测失效 */
    if (!array_key_exists($sgVersionKey, $GLOBALS['SG_SETTINGS'] ?? [])) {
        $sgClientUpdate[$sgKey] = $sgDefault;
        continue;
    }
    if (setting('client_update_' . $sgKey . '_enabled', '1') === '0') continue;
    $sgVer = trim((string) setting($sgVersionKey, ''));
    if ($sgVer === '') continue;
    $sgClientUpdate[$sgKey] = [
        'version' => $sgVer,
        'url'     => trim((string) setting('client_update_' . $sgKey . '_url', $sgDefault['url'])),
        'notes'   => trim((string) setting('client_update_' . $sgKey . '_notes', $sgDefault['notes'])),
    ];
}

echo json_encode(array_merge(['version' => SG_VERSION], $sgClientUpdate), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
