<?php
/** 收藏 / 取消收藏（Ajax） */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function sg_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sg_json(['ok' => false, 'msg' => '请使用 POST 请求'], 405);
}

csrf_verify();

$u = current_user();
if (!$u) {
    sg_json(['ok' => false, 'need_login' => true, 'msg' => '请先登录'], 401);
}

$id = post_int('recipe_id');
if ($id <= 0) {
    sg_json(['ok' => false, 'msg' => '参数错误']);
}

$recipe = fetch_one('SELECT id, fav_count FROM `' . T('recipes') . '` WHERE id = ? AND status = "published"', [$id]);
if (!$recipe) {
    sg_json(['ok' => false, 'msg' => '菜谱不存在或已下架']);
}

$has = fetch_one('SELECT id FROM `' . T('favorites') . '` WHERE user_id = ? AND recipe_id = ?', [(int) $u['id'], $id]);

if ($has) {
    q('DELETE FROM `' . T('favorites') . '` WHERE id = ?', [(int) $has['id']]);
    q('UPDATE `' . T('recipes') . '` SET fav_count = GREATEST(fav_count - 1, 0) WHERE id = ?', [$id]);
    $fav = false;
} else {
    q('INSERT INTO `' . T('favorites') . '` (user_id, recipe_id) VALUES (?, ?)', [(int) $u['id'], $id]);
    q('UPDATE `' . T('recipes') . '` SET fav_count = fav_count + 1 WHERE id = ?', [$id]);
    $fav = true;
}

$count = (int) fetch_val('SELECT fav_count FROM `' . T('recipes') . '` WHERE id = ?', [$id], 0);

sg_json(['ok' => true, 'fav' => $fav, 'count' => $count]);
