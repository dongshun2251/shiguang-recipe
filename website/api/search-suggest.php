<?php
/** 首页 / 搜索页输入框联想：按菜名、食材、标签、简介做关键词匹配 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sg_suggest_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    sg_suggest_json(['ok' => false, 'msg' => '请使用 GET 请求'], 405);
}

$raw = trim((string) ($_GET['q'] ?? ''));
if ($raw === '') {
    sg_suggest_json(['ok' => true, 'q' => '', 'total' => 0, 'items' => [], 'capped' => false]);
}
if (mb_strlen($raw, 'UTF-8') > 10) {
    $raw = mb_substr($raw, 0, 10, 'UTF-8');
    $capped = true;
} else {
    $capped = false;
}

/* 空格 / 逗号分隔时按多个关键词同时匹配；单个词最长 10 个字 */
$tokens = preg_split('/[\s,，、]+/u', $raw);
$tokens = array_values(array_filter(array_map('trim', $tokens), function ($v) {
    return $v !== '';
}));
if (!$tokens) { $tokens = [$raw]; }
$tokens = array_slice($tokens, 0, 4);

$conds  = ['r.status = "published"'];
$params = [];
foreach ($tokens as $token) {
    $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
    $like = '%' . $safe . '%';
    /* 单字只搜菜名，避免“麻”把食材“白芝麻”的菜也带出来；2 字以上再扩展到食材 / 标签 */
    if (mb_strlen($token, 'UTF-8') === 1) {
        $conds[] = '(r.title LIKE ?)';
        $params[] = $like;
        continue;
    }
    $conds[] = '(r.title LIKE ? OR r.summary LIKE ? OR r.author_name LIKE ?
        OR EXISTS (SELECT 1 FROM `' . T('recipe_ingredients') . '` i WHERE i.recipe_id = r.id AND i.name LIKE ?)
        OR EXISTS (SELECT 1 FROM `' . T('tags') . '` tg JOIN `' . T('recipe_tags') . '` rt ON rt.tag_id = tg.id WHERE rt.recipe_id = r.id AND tg.name LIKE ?))';
    array_push($params, $like, $like, $like, $like, $like);
}
$where = implode(' AND ', $conds);
$total = (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '` r WHERE ' . $where, $params, 0);

/* 同菜名优先，其次菜名包含，最后才是食材 / 标签 / 简介命中 */
$qLike   = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $raw) . '%';
$qStart  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $raw) . '%';
$order   = 'CASE WHEN r.title = ? THEN 0 WHEN r.title LIKE ? THEN 1 WHEN r.title LIKE ? THEN 2 ELSE 3 END, r.fav_count DESC, r.views DESC, r.id DESC';
$rows    = $total ? fetch_all(
    'SELECT r.* FROM `' . T('recipes') . '` r WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT 60',
    array_merge($params, [$raw, $qStart, $qLike])
) : [];

$items = [];
foreach ($rows as $r) {
    $title = (string) $r['title'];
    $reason = '相关';
    if ($raw !== '' && mb_stripos($title, $raw, 0, 'UTF-8') !== false) {
        $reason = '菜名';
    } elseif (mb_stripos((string) ($r['summary'] ?? ''), $raw, 0, 'UTF-8') !== false) {
        $reason = '简介';
    } else {
        $reason = '食材 / 标签';
    }
    $items[] = [
        'id'         => (int) $r['id'],
        'title'      => $title,
        'url'        => recipe_url($r),
        'cover'      => image_url((string) ($r['cover'] ?? '')),
        'minutes'    => (int) recipe_total_minutes($r),
        'difficulty' => (string) ($r['difficulty'] ?? ''),
        'rating'     => number_format((float) ($r['rating'] ?? 0), 1),
        'reason'     => $reason,
    ];
}

sg_suggest_json([
    'ok'     => true,
    'q'      => $raw,
    'total'  => $total,
    'items'  => $items,
    'capped' => $capped,
]);
