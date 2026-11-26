<?php
/** 读取 AI 服务里的模型列表（后台设置页使用） */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function ai_models_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ai_models_out(['ok' => false, 'msg' => '请使用 POST 请求'], 405);
}
if (!current_admin()) {
    ai_models_out(['ok' => false, 'msg' => '请先登录后台'], 401);
}
csrf_verify();

$base = trim((string) post('base'));
$kind = post('kind', 'ollama') === 'openai' ? 'openai' : 'ollama';
$key  = trim((string) post('key'));
if (mb_strlen($base) > 300) {
    ai_models_out(['ok' => false, 'msg' => '服务地址过长']);
}

[$ok, $models, $err] = ai_list_models($base, $kind, $key);
if (!$ok) {
    ai_models_out(['ok' => false, 'msg' => $err]);
}
ai_models_out(['ok' => true, 'models' => $models, 'url' => ai_models_url($base, $kind)]);
