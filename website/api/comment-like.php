<?php
/** 评论点赞 / 取消点赞（Ajax） */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function sg_like_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sg_like_json(['ok' => false, 'msg' => '请使用 POST 请求'], 405);
}

csrf_verify();

$u = current_user();
if (!$u) {
    sg_like_json(['ok' => false, 'need_login' => true, 'msg' => '登录后才能点赞'], 401);
}

$id = post_int('comment_id');
if ($id <= 0) {
    sg_like_json(['ok' => false, 'msg' => '参数错误']);
}

$cmt = fetch_one('SELECT id FROM `' . T('comments') . '` WHERE id = ? AND status = "approved"', [$id]);
if (!$cmt) {
    sg_like_json(['ok' => false, 'msg' => '评论不存在或未通过审核']);
}

$uid = (int) $u['id'];
$has = fetch_one('SELECT id FROM `' . T('comment_likes') . '` WHERE comment_id = ? AND user_id = ?', [$id, $uid]);

if ($has) {
    q('DELETE FROM `' . T('comment_likes') . '` WHERE id = ?', [(int) $has['id']]);
    $liked = false;
} else {
    try {
        q('INSERT INTO `' . T('comment_likes') . '` (comment_id, user_id) VALUES (?, ?)', [$id, $uid]);
        $liked = true;
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Duplicate') === false) {
            throw $e;
        }
        $liked = true;
    }
}

$count = (int) fetch_val('SELECT COUNT(*) FROM `' . T('comment_likes') . '` WHERE comment_id = ?', [$id], 0);

sg_like_json(['ok' => true, 'liked' => $liked, 'count' => $count]);
