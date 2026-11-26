<?php
/** AI 文本任务（Ajax）：营养估算、分享文案 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function ai_text_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ai_text_out(['ok' => false, 'msg' => '请使用 POST 请求'], 405);
}
csrf_verify();
if (!ai_enabled()) {
    ai_text_out(['ok' => false, 'msg' => 'AI 未启用，请到系统设置配置']);
}

$do = post('do');
$id = post_int('recipe_id');
$r  = $id ? fetch_one('SELECT * FROM `' . T('recipes') . '` WHERE id = ?', [$id]) : null;
if (!$r) {
    ai_text_out(['ok' => false, 'msg' => '菜谱不存在']);
}

if ($do === 'nutrition') {
    if (!current_admin()) {
        ai_text_out(['ok' => false, 'msg' => '请先登录后台'], 401);
    }
    [$ok, $text, $err] = ai_nutrition($r, recipe_ingredients($id));
    if (!$ok) {
        ai_text_out(['ok' => false, 'msg' => $err]);
    }
    ai_text_out(['ok' => true, 'text' => $text]);
}

if ($do === 'copy') {
    [$ok, $text, $err] = ai_share_copy($r, recipe_steps($id));
    if (!$ok) {
        ai_text_out(['ok' => false, 'msg' => $err]);
    }
    ai_text_out(['ok' => true, 'text' => $text]);
}

ai_text_out(['ok' => false, 'msg' => '未知操作']);
