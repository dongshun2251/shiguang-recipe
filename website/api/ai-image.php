<?php
/** AI 配图（Ajax）：后台菜谱编辑页、食材图库调用 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function ai_json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ai_json_out(['ok' => false, 'msg' => '请使用 POST 请求'], 405);
}
csrf_verify();

if (!current_admin()) {
    ai_json_out(['ok' => false, 'msg' => '请先登录后台'], 401);
}
if (!ai_img_enabled()) {
    ai_json_out(['ok' => false, 'msg' => '未配置图像接口，请到系统设置填写']);
}

$prompt = trim(post('prompt'));
$kind   = post('kind', 'step');
if ($prompt === '') {
    ai_json_out(['ok' => false, 'msg' => '请填写图片描述']);
}
if (mb_strlen($prompt) > 300) {
    $prompt = mb_substr($prompt, 0, 300);
}

$full = $prompt . '，中式家常菜，自然光，浅色木质桌面，俯拍特写，真实摄影质感，不要文字';
[$ok, $path, $err] = ai_image_gen($full, $kind === 'ing' ? 'ingredients' : 'steps');
if (!$ok) {
    ai_json_out(['ok' => false, 'msg' => $err]);
}
ai_json_out(['ok' => true, 'path' => $path, 'url' => image_url($path)]);
