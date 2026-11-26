<?php
/**
 * 接收端：本地 KSWEB → InfinityFree 单向同步
 * POST JSON；请求头 X-SG-Sync-Token 必须等于本机「系统设置 → 云端同步」里保存的密钥。
 * 只接收分类、自定义图标、食材图库、菜谱及其图片。
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function sync_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sync_out(['ok' => false, 'msg' => '只接受 POST'], 405);
}

$token = (string) setting('sync_token', '');
if ($token === '') {
    sync_out(['ok' => false, 'msg' => '云端还没有设置同步密钥，请先在后台「云端同步」里设置'], 503);
}
$got = (string) ($_SERVER['HTTP_X_SG_SYNC_TOKEN'] ?? '');
if (!hash_equals($token, $got)) {
    sync_out(['ok' => false, 'msg' => '同步密钥不正确'], 403);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 20 * 1024 * 1024) {
    sync_out(['ok' => false, 'msg' => '同步数据为空或超过 20MB'], 413);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    sync_out(['ok' => false, 'msg' => 'JSON 格式不正确'], 400);
}
$action = (string) ($body['action'] ?? $_GET['action'] ?? '');

function sync_slug(string $text, string $fallback = 'item'): string
{
    $slug = function_exists('slugify') ? slugify($text) : '';
    $slug = trim($slug);
    if ($slug === '') {
        $slug = $fallback . '-' . substr(md5($text . microtime()), 0, 8);
    }
    return mb_substr($slug, 0, 64);
}

function sync_map_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    q('CREATE TABLE IF NOT EXISTS `' . T('sync_map') . '` (
        `skey` VARCHAR(64) NOT NULL,
        `target_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`skey`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = true;
}

function sync_map_get(string $key): int
{
    if ($key === '') { return 0; }
    sync_map_ensure();
    return (int) fetch_val('SELECT target_id FROM `' . T('sync_map') . '` WHERE skey = ?', [$key], 0);
}

function sync_map_owner(int $id): string
{
    if ($id <= 0) { return ''; }
    sync_map_ensure();
    return (string) fetch_val('SELECT skey FROM `' . T('sync_map') . '` WHERE target_id = ? LIMIT 1', [$id], '');
}

function sync_map_set(string $key, int $id): void
{
    if ($key === '' || $id <= 0) { return; }
    sync_map_ensure();
    $old = fetch_one('SELECT skey FROM `' . T('sync_map') . '` WHERE skey = ?', [$key]);
    if ($old) {
        db_update(T('sync_map'), ['target_id' => $id, 'updated_at' => date('Y-m-d H:i:s')], 'skey = ?', [$key]);
    } else {
        db_insert(T('sync_map'), ['skey' => $key, 'target_id' => $id]);
    }
}

function sync_category_id(string $slug): int
{
    if ($slug === '') {
        return 0;
    }
    return (int) fetch_val('SELECT id FROM `' . T('categories') . '` WHERE slug = ?', [$slug], 0);
}

if ($action === 'ping') {
    sync_out([
        'ok' => true,
        'version' => SG_VERSION,
        'site' => setting('site_name', '食光菜谱'),
        'counts' => [
            'categories' => (int) fetch_val('SELECT COUNT(*) FROM `' . T('categories') . '`', [], 0),
            'ingredients' => (int) fetch_val('SELECT COUNT(*) FROM `' . T('ingredients') . '`', [], 0),
            'recipes' => (int) fetch_val('SELECT COUNT(*) FROM `' . T('recipes') . '`', [], 0),
        ],
    ]);
}

if ($action === 'rotate_token') {
    /* 本地发送端用旧密钥认证，云端换新密钥并返回；本地收到后写入本地设置。 */
    $newToken = bin2hex(random_bytes(16));
    setting_set('sync_token', $newToken);
    sync_out(['ok' => true, 'token' => $newToken, 'msg' => '云端同步密钥已更新，请让本地自动写回']);
}

if ($action === 'base') {
    $iconMap = [];
    foreach (($body['icons'] ?? []) as $ic) {
        if (!is_array($ic)) { continue; }
        $name = trim((string) ($ic['name'] ?? ''));
        if ($name === '') { continue; }
        $note = trim((string) ($ic['note'] ?? ''));
        $image = sync_image_store($ic['image'] ?? null, 'icons');
        $old = fetch_one('SELECT id, file FROM `' . T('icons') . '` WHERE name = ? ORDER BY id ASC LIMIT 1', [$name]);
        $data = ['name' => $name, 'note' => $note];
        if (array_key_exists('image', $ic)) { $data['file'] = $image; }
        if ($old) {
            db_update(T('icons'), $data, 'id = ?', [(int) $old['id']]);
            $id = (int) $old['id'];
        } else {
            $data['sort'] = (int) ($ic['sort'] ?? 0);
            $id = db_insert(T('icons'), $data);
        }
        $code = (string) ($ic['code'] ?? '');
        if ($code !== '' && $id > 0) {
            $iconMap[$code] = 'c' . $id;
        }
    }

    sg_categories_ensure_bg();
    $catMap = [];
    foreach (($body['categories'] ?? []) as $c) {
        if (!is_array($c)) { continue; }
        $name = trim((string) ($c['name'] ?? ''));
        if ($name === '') { continue; }
        $slug = sync_slug(trim((string) ($c['slug'] ?? '')) ?: $name, 'cat');
        $icon = (string) ($c['icon'] ?? '');
        if ($icon !== '' && isset($iconMap[$icon])) { $icon = $iconMap[$icon]; }
        $bg = sync_image_store($c['bg'] ?? null, 'categories');
        $sort = (int) ($c['sort'] ?? 0);
        $status = ((int) ($c['status'] ?? 1)) === 0 ? 0 : 1;
        $old = fetch_one('SELECT id FROM `' . T('categories') . '` WHERE slug = ?', [$slug]);
        $catData = ['name' => $name, 'icon' => $icon, 'sort' => $sort, 'status' => $status];
        if (array_key_exists('bg', $c)) { $catData['bg'] = $bg; }
        if ($old) {
            db_update(T('categories'), $catData, 'id = ?', [(int) $old['id']]);
            $id = (int) $old['id'];
        } else {
            $catData['slug'] = $slug;
            $id = db_insert(T('categories'), $catData);
        }
        $catMap[$slug] = $id;
    }

    foreach (($body['ingredients'] ?? []) as $ing) {
        if (!is_array($ing)) { continue; }
        $name = trim((string) ($ing['name'] ?? ''));
        if ($name === '') { continue; }
        $image = sync_image_store($ing['image'] ?? null, 'sync/ingredients');
        $sort = (int) ($ing['sort'] ?? 0);
        $old = fetch_one('SELECT id, image FROM `' . T('ingredients') . '` WHERE name = ?', [$name]);
        if ($old) {
            $data = ['sort' => $sort];
            if (array_key_exists('image', $ing)) { $data['image'] = $image; }
            db_update(T('ingredients'), $data, 'id = ?', [(int) $old['id']]);
        } else {
            db_insert(T('ingredients'), ['name' => $name, 'image' => $image, 'sort' => $sort]);
        }
    }

    sync_out(['ok' => true, 'icons' => $iconMap, 'categories' => $catMap]);
}

if ($action === 'recipe') {
    $r = $body['recipe'] ?? null;
    if (!is_array($r)) {
        sync_out(['ok' => false, 'msg' => '缺少菜谱数据'], 400);
    }
    $title = trim((string) ($r['title'] ?? ''));
    if ($title === '') {
        sync_out(['ok' => false, 'msg' => '菜谱标题为空'], 400);
    }
    $slug = trim((string) ($r['slug'] ?? ''));
    $slug = $slug !== '' ? $slug : sync_slug($title, 'recipe');
    $slug = mb_substr($slug, 0, 128);
    $catSlug = trim((string) ($r['category_slug'] ?? ''));
    $catId = sync_category_id($catSlug);
    $localId = (int) ($r['local_id'] ?? 0);
    $mapKey = $localId > 0 ? 'r' . $localId : '';
    sync_map_ensure();

    try {
        db()->beginTransaction();
        $coverProvided = array_key_exists('cover_image', $r);
        $cover = sync_image_store($r['cover_image'] ?? null, 'sync/recipes');
        $mappedId = sync_map_get($mapKey);
        $old = $mappedId > 0 ? fetch_one('SELECT id, views, fav_count, rating FROM `' . T('recipes') . '` WHERE id = ?', [$mappedId]) : null;
        if (!$old) {
            $bySlug = fetch_one('SELECT id, views, fav_count, rating FROM `' . T('recipes') . '` WHERE slug = ? ORDER BY id ASC LIMIT 1', [$slug]);
            if ($bySlug && $mapKey !== '' && sync_map_owner((int) $bySlug['id']) !== '' && sync_map_owner((int) $bySlug['id']) !== $mapKey) {
                $bySlug = null;
                $slug = mb_substr($slug . '-' . $localId, 0, 128);
            }
            $old = $bySlug;
        }
        $data = [
            'title' => $title,
            'slug' => $slug,
            'summary' => (string) ($r['summary'] ?? ''),
            'category_id' => $catId,
            'difficulty' => (string) ($r['difficulty'] ?? '初级'),
            'prep_minutes' => (int) ($r['prep_minutes'] ?? 0),
            'cook_minutes' => (int) ($r['cook_minutes'] ?? 0),
            'servings' => (string) ($r['servings'] ?? '2 人份'),
            'author_name' => (string) ($r['author_name'] ?? ''),
            'tips' => $r['tips'] ?? null,
            'nutrition' => (string) ($r['nutrition'] ?? ''),
            'status' => (string) ($r['status'] ?? 'draft'),
            'is_featured' => (int) ($r['is_featured'] ?? 0) === 1 ? 1 : 0,
        ];
        if ($coverProvided) { $data['cover'] = $cover; }
        if (array_key_exists('published_at', $r)) { $data['published_at'] = $r['published_at'] !== '' ? (string) $r['published_at'] : null; }
        if ($old) {
            $id = (int) $old['id'];
            db_update(T('recipes'), $data, 'id = ?', [$id]);
        } else {
            $data['views'] = (int) ($r['views'] ?? 0);
            $data['fav_count'] = 0;
            $data['rating'] = 0.0;
            
            $id = db_insert(T('recipes'), $data);
        }

        q('DELETE FROM `' . T('recipe_ingredients') . '` WHERE recipe_id = ?', [$id]);
        foreach (($r['ingredients'] ?? []) as $ing) {
            if (!is_array($ing)) { continue; }
            $name = trim((string) ($ing['name'] ?? ''));
            if ($name === '') { continue; }
            db_insert(T('recipe_ingredients'), [
                'recipe_id' => $id,
                'name' => mb_substr($name, 0, 64),
                'quantity' => mb_substr((string) ($ing['quantity'] ?? ''), 0, 64),
                'sort' => (int) ($ing['sort'] ?? 0),
            ]);
        }

        q('DELETE FROM `' . T('recipe_steps') . '` WHERE recipe_id = ?', [$id]);
        foreach (($r['steps'] ?? []) as $st) {
            if (!is_array($st)) { continue; }
            $image = sync_image_store($st['image'] ?? null, 'sync/recipes');
            db_insert(T('recipe_steps'), [
                'recipe_id' => $id,
                'step_no' => (int) ($st['step_no'] ?? 0),
                'title' => mb_substr((string) ($st['title'] ?? ''), 0, 128),
                'content' => $st['content'] ?? null,
                'duration' => mb_substr((string) ($st['duration'] ?? ''), 0, 64),
                'image' => $image,
            ]);
        }

        q('DELETE FROM `' . T('recipe_tags') . '` WHERE recipe_id = ?', [$id]);
        foreach (($r['tags'] ?? []) as $tag) {
            $name = trim((string) $tag);
            if ($name === '') { continue; }
            $oldTag = fetch_one('SELECT id FROM `' . T('tags') . '` WHERE name = ?', [$name]);
            if ($oldTag) {
                $tid = (int) $oldTag['id'];
            } else {
                $tid = db_insert(T('tags'), ['name' => mb_substr($name, 0, 32), 'slug' => mb_substr(sync_slug($name, 'tag'), 0, 32)]);
            }
            if ($tid > 0) {
                q('INSERT IGNORE INTO `' . T('recipe_tags') . '` (recipe_id, tag_id) VALUES (?, ?)', [$id, $tid]);
            }
        }

        sync_map_set($mapKey, $id);

        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) { db()->rollBack(); }
        sync_out(['ok' => false, 'msg' => '写入云端失败：' . $ex->getMessage()], 500);
    }

    sync_out(['ok' => true, 'id' => $id, 'slug' => $slug]);
}

sync_out(['ok' => false, 'msg' => '未知同步动作'], 400);
