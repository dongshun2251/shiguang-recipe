<?php
/**
 * 食光菜谱 · 公共函数
 */

/* ============================================================
 * 数据库
 * ============================================================ */

function db_connect(array $c): void
{
    $host    = (string) ($c['host'] ?? '127.0.0.1');
    $port    = (int) ($c['port'] ?? 3306);
    $charset = (string) ($c['charset'] ?? 'utf8mb4');
    $name    = (string) ($c['name'] ?? '');
    $user    = (string) ($c['user'] ?? '');
    $pass    = (string) ($c['pass'] ?? '');

    /* KSWEB/Android 上 MySQL 的 host 和端口偶尔会和安装时不同：
       localhost 可能走 socket，3306/3307 也可能互换。
       先按配置连；只有遇到“连接被拒绝”时才尝试同机的另一组 host/port。 */
    $candidates = [[$host, $port]];
    if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
        foreach ([$port, 3306, 3307] as $p) {
            foreach (['127.0.0.1', 'localhost'] as $h) {
                if ($h === $host && $p === $port) continue;
                if (!in_array([$h, $p], $candidates, true)) $candidates[] = [$h, $p];
            }
        }
    }

    $last  = null;
    $tried = [];
    foreach ($candidates as [$h, $p]) {
        $tried[] = $h . ':' . $p;
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $h, $p, $name, $charset);
            $GLOBALS['SG_PDO'] = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 2,
            ]);
            /* 让 MySQL 会话时区和 PHP 一致，避免后台时间 / 记录时间和数据库差 8 小时 */
            try {
                $GLOBALS['SG_PDO']->exec("SET time_zone = '" . date('P') . "'");
            } catch (Throwable $e) {}
            $GLOBALS['SG_DB_ENDPOINT'] = $h . ':' . $p;
            return;
        } catch (PDOException $ex) {
            $last = $ex;
            $code = (string) ($ex->errorInfo[1] ?? '');
            if (!in_array($code, ['2002', '2006'], true)) break;
        }
    }

    $GLOBALS['SG_DB_TRIED'] = $tried;
    throw $last ?: new RuntimeException('数据库连接失败');
}

function db(): PDO
{
    if (empty($GLOBALS['SG_PDO'])) {
        throw new RuntimeException('数据库尚未连接');
    }
    return $GLOBALS['SG_PDO'];
}

/** 带表前缀的表名 */
function T(string $name): string
{
    $prefix = $GLOBALS['SG_CFG']['db']['prefix'] ?? 'sg_';
    return $prefix . $name;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function fetch_val(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    foreach (array_keys($data) as $k) {
        $sets[] = "`$k` = ?";
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE ' . $where;
    return q($sql, array_merge(array_values($data), $whereParams))->rowCount();
}

/* ============================================================
 * 设置
 * ============================================================ */

function load_settings(): void
{
    $rows = fetch_all('SELECT skey, svalue FROM `' . T('settings') . '`');
    $GLOBALS['SG_SETTINGS'] = [];
    foreach ($rows as $r) {
        $GLOBALS['SG_SETTINGS'][$r['skey']] = $r['svalue'];
    }
}

function setting(string $key, string $default = ''): string
{
    return $GLOBALS['SG_SETTINGS'][$key] ?? $default;
}

function setting_set(string $key, string $value): void
{
    $exists = fetch_one('SELECT id FROM `' . T('settings') . '` WHERE skey = ?', [$key]);
    if ($exists) {
        db_update(T('settings'), ['svalue' => $value], 'skey = ?', [$key]);
    } else {
        db_insert(T('settings'), ['skey' => $key, 'svalue' => $value]);
    }
    $GLOBALS['SG_SETTINGS'][$key] = $value;
}

/** 版本号统一成 v 开头，空值回落到当前版本 */
function home_update_version_norm(string $version): string
{
    $version = trim(preg_replace('/\s+/u', '', $version));
    if ($version === '') {
        $version = SG_VERSION;
    }
    if (!preg_match('/^v/i', $version)) {
        $version = 'v' . $version;
    }
    return mb_substr($version, 0, 32);
}

/** 已归档的历史版本（最新在前） */
function home_update_history(): array
{
    $raw = (string) setting('home_update_history', '');
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return [];
    }
    $out = [];
    foreach ($arr as $row) {
        if (!is_array($row)) { continue; }
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($row['text'] ?? '')));
        if ($text === '') { continue; }
        $out[] = [
            'version' => home_update_version_norm((string) ($row['version'] ?? '')),
            'text' => $text,
            'date' => (string) ($row['date'] ?? ''),
        ];
    }
    return $out;
}

/** 保存一条历史版本（按版本号+内容去重，最多保留 50 条） */
function home_update_archive(string $version, string $text): void
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') { return; }
    $version = home_update_version_norm($version);
    $list = home_update_history();
    foreach ($list as $i => $row) {
        if (strcasecmp($row['version'], $version) === 0 && $row['text'] === $text) {
            return;
        }
    }
    array_unshift($list, ['version' => $version, 'text' => $text, 'date' => date('Y-m-d')]);
    $list = array_slice($list, 0, 50);
    setting_set('home_update_history', json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** 内置版本历史（来自 inc/changelog.json，不写入数据库） */
function sg_builtin_changelog(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $file = SG_ROOT . '/inc/changelog.json';
    if (!is_file($file)) {
        return $cache = [];
    }
    $rows = json_decode((string) file_get_contents($file), true);
    if (!is_array($rows)) {
        return $cache = [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['version'])) { continue; }
        $notes = [];
        foreach ((array) ($row['notes'] ?? []) as $note) {
            $note = trim((string) $note);
            if ($note !== '') { $notes[] = $note; }
        }
        if (!$notes) { continue; }
        $out[] = [
            'version' => home_update_version_norm((string) $row['version']),
            'series' => (string) ($row['series'] ?? ''),
            'title' => trim((string) ($row['title'] ?? '')),
            'notes' => $notes,
            'date' => '',
        ];
    }
    return $cache = $out;
}

/** 数据库归档 + 内置历史，数据库里的记录优先 */
function sg_update_history_all(): array
{
    $out = [];
    $seen = [];
    foreach (home_update_history() as $row) {
        $seen[strtolower($row['version'])] = true;
        $out[] = [
            'version' => $row['version'],
            'series' => preg_match('/^v[0-9]+\.[0-9]+/', $row['version'], $m) ? $m[0] : '其他',
            'title' => '后台归档',
            'notes' => [$row['text']],
            'date' => $row['date'],
        ];
    }
    foreach (sg_builtin_changelog() as $row) {
        $key = strtolower($row['version']);
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $out[] = $row;
    }
    return $out;
}

/** 当前正在播报的版本更新 */
function home_update_current(): array
{
    $version = home_update_version_norm((string) setting('home_update_version', 'v' . SG_VERSION));
    $text = trim(preg_replace('/\s+/u', ' ', (string) setting('home_update_text', '首页空数据时补上空状态卡片，不再只剩标题和一大片空白。')));
    return ['version' => $version, 'text' => $text, 'date' => ''];
}

/** 规范化主题色：补 #、只接受 6 位十六进制，返回大写；非法返回空 */
function theme_color_clean(string $value): string
{
    $value = trim($value);
    if ($value !== '' && $value[0] !== '#') {
        $value = '#' . $value;
    }
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : '';
}

/** 后台色块预设；默认 5 个，当前主色不在其中时自动补进最后 */
function theme_palette(): array
{
    $defaults = ['#C8502B', '#2E6B57', '#B08637', '#7A5A44', '#37516B'];
    $raw = (string) setting('theme_palette', '');
    $hasSetting = $raw !== '';
    $colors = [];
    if ($hasSetting) {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            foreach ($arr as $item) {
                $c = theme_color_clean((string) $item);
                if ($c !== '' && !in_array($c, $colors, true)) {
                    $colors[] = $c;
                }
            }
        } else {
            $colors = $defaults;
        }
    } else {
        $colors = $defaults;
    }
    /* 首次使用且已自定义主色时，把当前主色补进色块；之后允许手动删空 */
    if (!$hasSetting) {
        $current = theme_color_clean((string) setting('theme_primary', '#C8502B'));
        if ($current !== '' && !in_array($current, $colors, true)) {
            $colors[] = $current;
        }
    }
    return array_values($colors);
}

/** 保存色块预设；允许存为空数组（最后一枚色块被删掉时） */
function theme_palette_save(array $colors): void
{
    $out = [];
    foreach ($colors as $item) {
        $c = theme_color_clean((string) $item);
        if ($c !== '' && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    }
    setting_set('theme_palette', json_encode($out, JSON_UNESCAPED_UNICODE));
}

/* ============================================================
 * 输出与链接
 * ============================================================ */

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 生成站内链接（自动带上部署子目录） */
function url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return SG_WEB_BASE . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return $GLOBALS['SG_BASE'] . 'assets/' . ltrim($path, '/');
}

function sg_has_column(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        /* SHOW COLUMNS 不支持预处理占位符；用无参查询再把列名逐个比对，
           避免 MySQL 原生预处理报错后被误判成“列不存在”，重复 ALTER。 */
        $cols = db()->query('SHOW COLUMNS FROM `' . T($table) . '`')->fetchAll(PDO::FETCH_COLUMN, 0);
        $cache[$key] = in_array($column, $cols, true);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function sg_avatar(string $path, string $name, string $extra = ''): string
{
    $path = trim($path);
    $inner = $path !== ''
        ? '<img src="' . e(image_url($path)) . '" alt="">'
        : e(mb_substr($name !== '' ? $name : '客', 0, 1));
    return '<span class="avatar' . ($extra !== '' ? ' ' . $extra : '') . '">' . $inner . '</span>';
}

function image_url(?string $cover): string
{
    $cover = trim((string) $cover);
    if ($cover === '') {
        return asset('img/placeholder.jpg');
    }
    if (preg_match('#^https?://#i', $cover)) {
        return $cover;
    }
    return $GLOBALS['SG_BASE'] . ltrim($cover, '/');
}

function recipe_url(array $r): string
{
    return $GLOBALS['SG_BASE'] . 'recipe.php?id=' . (int) $r['id'];
}

function cat_url(string $slug): string
{
    return $GLOBALS['SG_BASE'] . 'recipes.php?cat=' . urlencode($slug);
}

function current_uri(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/';
}

/* ============================================================
 * 跳转 / 提示
 * ============================================================ */

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function flash_pull(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function flash_render(): string
{
    $f = flash_pull();
    if (!$f) {
        return '';
    }
    $cls = $f['type'] === 'err' ? 'alert err' : 'alert ok';
    return '<div class="' . $cls . '">' . e($f['msg']) . '</div>';
}

/* ============================================================
 * CSRF
 * ============================================================ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['_token'] ?? ($_POST['token'] ?? '');
        if (!hash_equals(csrf_token(), (string) $token)) {
            http_response_code(419);
            exit('表单已过期，请刷新页面后重试。');
        }
    }
}

/* ============================================================
 * 当前用户 / 权限
 * ============================================================ */

function current_user(): ?array
{
    static $cache = null;
    if (!empty($cache)) {
        return $cache;
    }
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $uid = (int) $_SESSION['uid'];
    $u = fetch_one('SELECT * FROM `' . T('users') . '` WHERE id = ? AND status = 1', [$uid]);
    if (!$u) {
        unset($_SESSION['uid'], $_SESSION['sg_utok'], $_SESSION['sg_sid']);
        return null;
    }

    $mine = (string) ($_SESSION['sg_utok'] ?? '');
    if ($mine === '') {
        unset($_SESSION['uid'], $_SESSION['sg_utok'], $_SESSION['sg_sid'], $_SESSION['history']);
        flash('网站已升级，请重新登录。', 'err');
        return null;
    }

    if (sg_security_ready()) {
        $session = sg_session_find($mine, $uid);
        if (!$session) {
            /* 升级前的单设备令牌：只迁移一次，迁移后旧 setting 会被清掉 */
            $legacy = sg_lock_read('u', $uid);
            if ($legacy !== '' && hash_equals($legacy, $mine)) {
                try {
                    $row = sg_session_create($uid, null, $mine);
                    $_SESSION['sg_sid'] = (int) $row['id'];
                    $session = sg_session_find($mine, $uid);
                } catch (Throwable $e) {
                    $session = null;
                }
            }
        }
        if (!$session) {
            unset($_SESSION['uid'], $_SESSION['sg_utok'], $_SESSION['sg_sid'], $_SESSION['history']);
            flash('登录已失效，请在当前设备重新登录。', 'err');
            return null;
        }
        $_SESSION['sg_sid'] = (int) $session['id'];
        $last = strtotime((string) $session['last_seen_at']);
        if ($last > 0 && time() - $last > 60) {
            sg_session_touch((int) $session['id'], $uid);
        }
    } else {
        unset($_SESSION['uid'], $_SESSION['sg_utok'], $_SESSION['sg_sid'], $_SESSION['history']);
        flash('账号安全模块尚未就绪，请重新登录。', 'err');
        return null;
    }
    return $cache = $u;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $next = urlencode(current_uri());
        redirect($GLOBALS['SG_BASE'] . 'login.php?next=' . $next);
    }
    return $u;
}

function current_admin(): ?array
{
    static $cache = null;
    if (!empty($cache)) {
        return $cache;
    }
    if (empty($_SESSION['aid'])) {
        return null;
    }
    $a = fetch_one('SELECT * FROM `' . T('admins') . '` WHERE id = ? AND status = 1', [(int) $_SESSION['aid']]);
    if (!$a) {
        unset($_SESSION['aid'], $_SESSION['sg_atok']);
        return null;
    }
    $stored = sg_lock_read('a', (int) $a['id']);
    $mine   = (string) ($_SESSION['sg_atok'] ?? '');
    if ($mine === '') {
        unset($_SESSION['aid'], $_SESSION['sg_atok']);
        flash('网站已升级，请重新登录后台。', 'err');
        return null;
    }
    if ($stored !== '' && !hash_equals($stored, $mine)) {
        unset($_SESSION['aid'], $_SESSION['sg_atok']);
        flash('管理员账号已在另一台设备登录，本机已自动退出。', 'err');
        return null;
    }
    return $cache = $a;
}

function sg_new_token(): string
{
    return bin2hex(random_bytes(20));
}

/* ---- 管理员单设备登录锁：存在 sg_settings 里，前台用户已改为多设备会话 ---- */
function sg_lock_key(string $scope, int $id): string
{
    return 'sess_' . $scope . '_' . $id;
}

function sg_lock_read(string $scope, int $id): string
{
    $r = fetch_one('SELECT svalue FROM `' . T('settings') . '` WHERE skey = ?', [sg_lock_key($scope, $id)]);
    return $r ? (string) $r['svalue'] : '';
}

function sg_lock_write(string $scope, int $id, string $token): void
{
    setting_set(sg_lock_key($scope, $id), $token);
}

/* 登录成功：为这台设备建立独立会话；同一账号可以有多台设备 */
function sg_begin_user_session(array $u): void
{
    if (empty($u['id'])) {
        throw new RuntimeException('登录用户信息不完整');
    }
    $s = sg_session_create((int) $u['id']);
    if (empty($s['id']) || empty($s['token'])) {
        throw new RuntimeException('登录会话写入失败，请检查数据库权限');
    }
    $_SESSION['uid']     = (int) $u['id'];
    $_SESSION['sg_utok'] = (string) $s['token'];
    $_SESSION['sg_sid']  = (int) $s['id'];
    db_update(T('users'), ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $u['id']]);
}

function sg_begin_admin_session(array $a): void
{
    $t = sg_new_token();
    sg_lock_write('a', (int) $a['id'], $t);
    if (sg_lock_read('a', (int) $a['id']) !== $t) {
        throw new RuntimeException('登录锁写入失败：sg_settings 表不可写，请检查数据库权限');
    }
    $_SESSION['aid']     = (int) $a['id'];
    $_SESSION['sg_atok'] = $t;
    db_update(T('admins'), ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $a['id']]);
}

function require_admin(): array
{
    $a = current_admin();
    if (!$a) {
        redirect('login.php');
    }
    return $a;
}

/* ============================================================
 * 请求参数
 * ============================================================ */

function post(string $key, $default = '')
{
    return isset($_POST[$key]) ? (is_array($_POST[$key]) ? $_POST[$key] : trim((string) $_POST[$key])) : $default;
}

function get(string $key, $default = '')
{
    return isset($_GET[$key]) ? (is_array($_GET[$key]) ? $_GET[$key] : trim((string) $_GET[$key])) : $default;
}

function post_int(string $key, int $default = 0): int
{
    return (int) post($key, $default);
}

function get_int(string $key, int $default = 0): int
{
    return (int) get($key, $default);
}

/* ============================================================
 * 业务辅助
 * ============================================================ */

function recipe_total_minutes(array $r): int
{
    return (int) ($r['prep_minutes'] ?? 0) + (int) ($r['cook_minutes'] ?? 0);
}

function difficulty_class(string $d): string
{
    return $d === '进阶' ? 'gold' : ($d === '入门' ? 'green' : '');
}

function is_fav(int $recipeId): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    return (bool) fetch_one('SELECT id FROM `' . T('favorites') . '` WHERE user_id = ? AND recipe_id = ?',
        [(int) $u['id'], $recipeId]);
}

function history_add(int $recipeId): void
{
    $list = $_SESSION['history'] ?? [];
    $list = array_values(array_diff($list, [$recipeId]));
    array_unshift($list, $recipeId);
    $_SESSION['history'] = array_slice($list, 0, 20);
}

function history_list(int $limit = 10): array
{
    $ids = array_slice($_SESSION['history'] ?? [], 0, $limit);
    if (!$ids) {
        return [];
    }
    $rows = fetch_all('SELECT * FROM `' . T('recipes') . '` WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') AND status = "published"');
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['id']] = $r;
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($map[$id])) {
            $out[] = $map[$id];
        }
    }
    return $out;
}

function recipe_ingredients(int $id): array
{
    return fetch_all('SELECT * FROM `' . T('recipe_ingredients') . '` WHERE recipe_id = ? ORDER BY sort ASC, id ASC', [$id]);
}

/** 食材名归一化（去空格、转小写），用于图库匹配 */
function ing_name_key(string $name): string
{
    return mb_strtolower(preg_replace('/\s+/u', '', trim($name)));
}

/** 全局食材图库：[归一化名 => 图片地址] */
function ingredient_image_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    try {
        $rows = fetch_all('SELECT name, image FROM `' . T('ingredients') . '` WHERE image <> ""');
        foreach ($rows as $r) {
            $map[ing_name_key((string) $r['name'])] = image_url($r['image']);
        }
    } catch (Throwable $e) {
        $map = [];
    }
    return $map;
}

/** 按食材名取配图，没有则返回空串 */
function ingredient_image(string $name): string
{
    $map = ingredient_image_map();
    $key = ing_name_key($name);
    if (isset($map[$key])) {
        return $map[$key];
    }

    /* 菜谱里常写「姜片 / 葱段」「八角 / 花椒 / 香叶」，按分隔符逐段匹配 */
    $parts = preg_split('/[\/、,，|｜]+/u', $name);
    foreach ((array) $parts as $part) {
        $partKey = ing_name_key((string) $part);
        if ($partKey === '') { continue; }
        if (isset($map[$partKey])) { return $map[$partKey]; }
        /* 「姜片」→「姜」、「葱段」→「葱」 */
        $baseKey = preg_replace('/(片|段|丝|末|块|颗|根|叶|粒|碎)$/u', '', $partKey);
        if ($baseKey !== '' && isset($map[$baseKey])) { return $map[$baseKey]; }
    }

    /* 最后按包含关系兜底，取最长的食材名，避免「牛」误配到「牛腱子」时抢过精确项 */
    $best = '';
    $bestLen = 0;
    foreach ($map as $k => $url) {
        $len = mb_strlen((string) $k);
        if ($len >= 2 && $len > $bestLen && mb_strpos($key, (string) $k) !== false) {
            $best = $url;
            $bestLen = $len;
        }
    }
    return $best;
}

/** 给菜品食材行附上图片字段，供前台/长图使用 */
function ingredients_with_image(array $ings): array
{
    foreach ($ings as &$ing) {
        $ing['image'] = ingredient_image((string) ($ing['name'] ?? ''));
    }
    unset($ing);
    return $ings;
}

function recipe_steps(int $id): array
{
    return fetch_all('SELECT * FROM `' . T('recipe_steps') . '` WHERE recipe_id = ? ORDER BY step_no ASC, id ASC', [$id]);
}

/** 读取单步配图：优先 images JSON，兼容旧的单图 image 字段 */
function step_images(array $step): array
{
    $out = [];
    $raw = trim((string) ($step['images'] ?? ''));
    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            foreach ($arr as $path) {
                $path = trim((string) $path);
                if ($path !== '' && preg_match('#^uploads/[A-Za-z0-9._/-]+$#', $path) && !in_array($path, $out, true)) {
                    $out[] = $path;
                }
            }
        }
    }
    $legacy = trim((string) ($step['image'] ?? ''));
    if ($legacy !== '' && preg_match('#^uploads/[A-Za-z0-9._/-]+$#', $legacy) && !in_array($legacy, $out, true)) {
        $out[] = $legacy;
    }
    return array_slice($out, 0, 9);
}

/** 拆分步骤用时：返回 [分钟数, 火候/补充说明] */
function step_duration_parts(string $duration): array
{
    $d = trim(preg_replace('/\s+/u', ' ', $duration));
    if ($d === '') { return ['', '']; }
    if (preg_match('/^(\d+(?:\.\d+)?)\s*(分钟|分|min(?:ute)?s?)?\s*(.*)$/iu', $d, $m)) {
        $rest = trim($m[3]);
        $rest = ltrim($rest, "·,，、/;；- ");
        if ($m[2] === '' && preg_match('/^(秒|小时|个小时)/u', $rest)) {
            return ['', $d];
        }
        return [$m[1], $rest];
    }
    /* 兼容「小火 12 分钟」这类火候在前的旧写法 */
    if (preg_match('/(\d+(?:\.\d+)?)\s*(分钟|分|min(?:ute)?s?)/iu', $d, $m, PREG_OFFSET_CAPTURE)) {
        $min  = $m[1][0];
        $at   = (int) $m[0][1];
        $len  = strlen($m[0][0]);
        $rest = trim(substr($d, 0, $at) . ' ' . substr($d, $at + $len));
        $rest = trim($rest, " ·,，、/;；-");
        return [$min, $rest];
    }
    return ['', $d];
}

/** 显示用时：把裸露的数字补成「5 分钟」 */
function step_duration_text(string $duration): string
{
    [$min, $heat] = step_duration_parts($duration);
    if ($min === '') { return trim($duration); }
    $out = $min . ' 分钟';
    if ($heat !== '') { $out .= ' · ' . $heat; }
    return $out;
}

function recipe_tags(int $id): array
{
    return fetch_all('SELECT t.* FROM `' . T('tags') . '` t
        JOIN `' . T('recipe_tags') . '` rt ON rt.tag_id = t.id
        WHERE rt.recipe_id = ?', [$id]);
}

function categories_visible(): array
{
    return fetch_all('SELECT * FROM `' . T('categories') . '` WHERE status = 1 ORDER BY sort ASC, id ASC');
}

function category_name(int $id): string
{
    $c = fetch_one('SELECT name FROM `' . T('categories') . '` WHERE id = ?', [$id]);
    return $c['name'] ?? '未分类';
}

/** 菜谱卡片（前台多处复用） */
function recipe_card(array $r): void
{
    $fav  = is_fav((int) $r['id']);
    $mins = recipe_total_minutes($r);
    $ratingCount = (int) ($r['rating_count'] ?? 0);
    $good = $ratingCount >= 3 && (float) $r['rating'] >= 4.8;
    ?>
    <a class="rcard" href="<?= e(recipe_url($r)) ?>">
      <div class="rcard-media">
        <img src="<?= e(image_url($r['cover'])) ?>" alt="<?= e($r['title']) ?>" loading="lazy">
        <span class="tm"><span class="ico" data-ico="clock"></span><?= $mins ?> 分钟</span>
        <span class="fav<?= $fav ? ' on' : '' ?>" role="button" tabindex="0" data-fav="<?= (int) $r['id'] ?>" title="收藏"><span class="ico" data-ico="heart"></span></span>
<?php if ($good): ?><span class="rcard-good">高分好评</span><?php endif; ?>
        <span class="diff"><?= e($r['difficulty']) ?></span>
      </div>
      <div class="rcard-body">
        <h3><?= e($r['title']) ?></h3>
        <p data-marquee><?= e($r['summary']) ?></p>
        <div class="rcard-foot">
          <span class="rate"><span class="ico" data-ico="star"></span><?= number_format((float) $r['rating'], 1) ?></span>
          <span data-favcount><?= (int) $r['fav_count'] ?> 收藏</span><?php if (!empty($r['author_name'])): ?><span class="sp"><?= e($r['author_name']) ?></span><?php endif; ?>
        </div>
      </div>
    </a>
    <?php
}

/** 分页条 */
function pager(int $page, int $total, int $per, string $baseUrl): string
{
    $pages = max(1, (int) ceil($total / max(1, $per)));
    if ($pages <= 1) {
        return '';
    }
    $sep  = strpos($baseUrl, '?') === false ? '?' : '&';
    $out  = '<div class="pager">';
    $prev = max(1, $page - 1);
    $out .= '<a href="' . e($baseUrl . $sep . 'page=' . $prev) . '" class="' . ($page <= 1 ? 'disabled' : '') . '" title="上一页" aria-label="上一页">&lt;</a>';
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    if ($start > 1) {
        $out .= '<a href="' . e($baseUrl . $sep . 'page=1') . '">1</a>';
        if ($start > 2) {
            $out .= '<span class="ell">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $out .= $i === $page
            ? '<span class="on">' . $i . '</span>'
            : '<a href="' . e($baseUrl . $sep . 'page=' . $i) . '">' . $i . '</a>';
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $out .= '<span class="ell">…</span>';
        }
        $out .= '<a href="' . e($baseUrl . $sep . 'page=' . $pages) . '">' . $pages . '</a>';
    }
    $out .= '<a href="' . e($baseUrl . $sep . 'page=' . min($pages, $page + 1)) . '" class="' . ($page >= $pages ? 'disabled' : '') . '" title="下一页" aria-label="下一页">&gt;</a>';
    $out .= '</div>';
    return $out;
}

/* ============================================================
 * 图片上传
 * ============================================================ */

/** 上传分类自定义图标：仅 PNG/JPG/WebP，≤1MB，缩到 256px 内并保留透明底 */
function upload_icon(array $file): string
{
    if (empty($file['tmp_name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('图标上传失败，请重试');
    }
    if ($file['size'] > 1024 * 1024) {
        throw new RuntimeException('图标图片不能超过 1 MB，请先压小一点');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        throw new RuntimeException('只支持 PNG / WebP / JPG 图片');
    }
    $w0 = (int) $info[0];
    $h0 = (int) $info[1];
    if ($w0 < 48 || $h0 < 48) {
        throw new RuntimeException('图标太小了，建议上传 256×256 以上的正方形图片');
    }
    if ($w0 > 4096 || $h0 > 4096) {
        throw new RuntimeException('图标尺寸太大，最长边请控制在 4096px 以内');
    }
    $extMap = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $ext    = $extMap[$info[2]] ?? '';
    if ($ext === '') {
        throw new RuntimeException('只支持 PNG / WebP / JPG 图片，不支持 GIF 或动图');
    }

    $dir = SG_ROOT . '/uploads/icons';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('上传目录不可写');
    }
    $base = date('Ymd') . '-' . bin2hex(random_bytes(6));

    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if ($src) {
            $size = 256;
            $dst  = imagecreatetruecolor($size, $size);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            if ($ext === 'jpg') {
                $bg = imagecolorallocate($dst, 255, 255, 255);
            } else {
                $bg = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            }
            imagefill($dst, 0, 0, $bg);
            imagealphablending($dst, true);

            $scale = min($size / $w0, $size / $h0);
            $nw    = max(1, (int) round($w0 * $scale));
            $nh    = max(1, (int) round($h0 * $scale));
            $dx    = (int) floor(($size - $nw) / 2);
            $dy    = (int) floor(($size - $nh) / 2);
            imagecopyresampled($dst, $src, $dx, $dy, 0, 0, $nw, $nh, $w0, $h0);

            $path = $dir . '/' . $base . '.png';
            imagepng($dst, $path, 9);
            imagedestroy($dst);
            imagedestroy($src);
            return 'uploads/icons/' . $base . '.png';
        }
    }

    $name = $base . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('保存图标失败');
    }
    return 'uploads/icons/' . $name;
}

function upload_image(array $file, string $subDir = 'recipes'): string
{
    if (empty($file['tmp_name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('图片上传失败，请重试');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('图片不能超过 8 MB');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        throw new RuntimeException('只支持 JPG / PNG / WebP 图片');
    }
    $extMap = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $ext    = $extMap[$info[2]] ?? 'jpg';

    $dir = SG_ROOT . '/uploads/' . $subDir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('上传目录不可写');
    }

    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $path = $dir . '/' . $name;

    if (in_array($ext, ['jpg', 'png', 'webp'], true) && function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if ($src) {
            $w = imagesx($src);
            $h = imagesy($src);
            $max = 1400;
            if ($w > $max) {
                $nh = (int) round($h * ($max / $w));
                $dst = imagecreatetruecolor($max, $nh);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $max, $nh, $w, $h);
                if ($ext === 'jpg') {
                    imagejpeg($dst, $path, 82);
                } elseif ($ext === 'png') {
                    imagepng($dst, $path, 6);
                } else {
                    imagewebp($dst, $path, 82);
                }
                imagedestroy($dst);
                imagedestroy($src);
                return 'uploads/' . $subDir . '/' . $name;
            }
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new RuntimeException('保存图片失败');
    }
    return 'uploads/' . $subDir . '/' . $name;
}

/* ============================================================
 * 音频上传
 * ============================================================ */

/** 上传首次访问欢迎音频：仅 MP3，≤20MB */
function upload_audio(array $file): string
{
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('音频上传失败，请重试');
    }
    if ((int) ($file['size'] ?? 0) > 20 * 1024 * 1024) {
        throw new RuntimeException('MP3 不能超过 20 MB，请压缩后再上传');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'mp3') {
        throw new RuntimeException('只支持 MP3 格式，请先把音频转成 .mp3');
    }

    /*
     * 手机文件选择器返回的 MIME 很杂（audio/mpeg、application/octet-stream、
     * video/mp2t 都可能出现），不能只靠 finfo 判断。以文件头为准：
     * 前 8KB 里出现 ID3 标签或 MPEG 帧同步即可。
     */
    $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 8192);
    if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') {
        throw new RuntimeException('这是 MP4/M4A 容器里的 AAC 音频，扩展名改成 .mp3 浏览器也读不稳；请先转成真正的 MP3 再上传');
    }
    $isMp3 = substr($head, 0, 3) === 'ID3';
    if (!$isMp3) {
        $limit = min(strlen($head) - 1, 4096);
        for ($i = 0; $i < $limit; $i++) {
            if (ord($head[$i]) === 0xFF && (ord($head[$i + 1]) & 0xE0) === 0xE0) {
                $isMp3 = true;
                break;
            }
        }
    }
    if (!$isMp3) {
        throw new RuntimeException('不是有效的 MP3 文件，请换一个文件');
    }

    $dir = SG_ROOT . '/uploads/audio';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('音频目录不可写，请检查 uploads/audio 权限');
    }
    $name = audio_store_name((string) ($file['name'] ?? ''), $dir);
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('保存音频失败');
    }
    return 'uploads/audio/' . $name;
}

/** 上传时尽量保留原文件名（支持中文），只清掉路径不安全的字符；重名追加 -2、-3 */
function audio_store_name(string $original, string $dir): string
{
    $base = (string) pathinfo($original, PATHINFO_FILENAME);
    $base = (string) preg_replace('/[\/\\:*?"<>|\x00-\x1F]+/u', '-', $base);
    $base = (string) preg_replace('/\s+/u', '-', $base);
    $base = trim((string) preg_replace('/-{2,}/u', '-', $base), '-. _');
    if ($base === '') {
        $base = date('Ymd-His');
    }
    if (mb_strlen($base, 'UTF-8') > 60) {
        $base = mb_substr($base, 0, 60, 'UTF-8');
    }
    $name = $base . '.mp3';
    $i    = 2;
    while (is_file($dir . '/' . $name)) {
        $name = $base . '-' . $i . '.mp3';
        $i++;
    }
    return $name;
}

/** 列出 uploads/audio 下所有 MP3，按上传时间倒序 */
function audio_files(): array
{
    $dir = SG_ROOT . '/uploads/audio';
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach ((array) glob($dir . '/*.mp3') as $full) {
        $name = basename((string) $full);
        if (!preg_match('#^[^\x00-\x1F/\\\\]+\.mp3$#iu', $name) || $name[0] === '.' || !is_file($full)) {
            continue;
        }
        $out[] = [
            'path'  => 'uploads/audio/' . $name,
            'name'  => $name,
            'size'  => (int) @filesize($full),
            'mtime' => (int) @filemtime($full),
        ];
    }
    usort($out, function ($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });
    return $out;
}

/** 前台/后台播放地址：目录保留，文件名按 URL 规则编码（支持中文名） */
function audio_url(string $file): string
{
    if ($file === '') {
        return '';
    }
    return 'uploads/audio/' . rawurlencode(basename($file));
}

/** 单个 mp3 路径是否合法且文件存在 */
function audio_path_ok(string $file): bool
{
    return $file !== ''
        && preg_match('#^(uploads/audio|assets/audio)/[^\x00-\x1F/\\\\]+\.mp3$#iu', $file)
        && is_file(SG_ROOT . '/' . $file);
}

/** 音频位：guest = 登录前（游客），member = 登录后（已登录用户） */
function audio_slot_keys(string $slot): array
{
    if ($slot === 'member') {
        return ['file' => 'audio_file_after', 'enabled' => 'audio_after_enabled', 'label' => '登录后'];
    }
    return ['file' => 'audio_file', 'enabled' => 'audio_enabled', 'label' => '登录前'];
}

/** 某个音频位的当前文件、是否可用、是否启用 */
function audio_slot_state(string $slot): array
{
    $k       = audio_slot_keys($slot);
    $file    = (string) setting($k['file'], '');
    $enabled = setting($k['enabled'], '0') === '1';
    $ok      = audio_path_ok($file);
    return [
        'slot'    => $slot === 'member' ? 'member' : 'guest',
        'label'   => $k['label'],
        'file'    => $file,
        'enabled' => $enabled,
        'ok'      => $ok,
        'size'    => $ok ? (int) filesize(SG_ROOT . '/' . $file) : 0,
        'mtime'   => $ok ? (int) filemtime(SG_ROOT . '/' . $file) : 0,
    ];
}

/** 配置的文件被改名/移动后，按文件名里的“登录前 / 登录后”关键字兜底找一条（取最新） */
function welcome_audio_slot_guess(string $slot): string
{
    $words = $slot === 'member'
        ? ['登录后', '登陆后', '登錄後', '登録後', 'member', 'after']
        : ['登录前', '登陆前', '登錄前', '登録前', 'guest', 'before'];
    $dir = SG_ROOT . '/uploads/audio';
    if (!is_dir($dir)) {
        return '';
    }
    $best = '';
    $bestTime = -1;
    foreach ((array) glob($dir . '/*.mp3') as $full) {
        $name = basename((string) $full);
        if ($name === '' || $name[0] === '.' || !is_file($full)) {
            continue;
        }
        $hit = false;
        foreach ($words as $word) {
            if (strpos($name, $word) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            continue;
        }
        $t = (int) @filemtime($full);
        if ($t > $bestTime) {
            $bestTime = $t;
            $best = 'uploads/audio/' . $name;
        }
    }
    return $best;
}

/** 内置欢迎音频在 uploads/audio 里的保留文件名 */
function audio_builtin_name(string $slot): string
{
    return $slot === 'member' ? '欢迎您加入食光菜谱-登录后.mp3' : '欢迎您加入食光菜谱-登录前.mp3';
}

/** 源码包自带的默认欢迎音频；按内容哈希同步，换新版内置音后旧文件也会被刷新 */
function audio_builtin_default(string $slot): string
{
    $slot = $slot === 'member' ? 'member' : 'guest';
    $name = $slot === 'member' ? 'welcome-member.mp3' : 'welcome-guest.mp3';
    $src  = SG_ROOT . '/assets/audio/' . $name;
    if (!is_file($src)) {
        return '';
    }
    $dir = SG_ROOT . '/uploads/audio';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $dstName = audio_builtin_name($slot);
    $dst = $dir . '/' . $dstName;
    if (!is_file($dst) || md5_file($dst) !== md5_file($src)) {
        $copied = @copy($src, $dst);
        /* 免费主机 uploads 可能不可写：不要继续返回那份旧文件 */
        if (!$copied || !is_file($dst) || md5_file($dst) !== md5_file($src)) {
            return 'assets/audio/' . $name;
        }
    }
    return 'uploads/audio/' . $dstName;
}

/** 按文件头判断真实音频类型：MP4/M4A 容器返回 audio/mp4，真 MP3 返回 audio/mpeg */
function audio_file_mime(string $full): string
{
    if (!is_file($full)) {
        return 'audio/mpeg';
    }
    $head = (string) @file_get_contents($full, false, null, 0, 16);
    if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') {
        return 'audio/mp4';
    }
    if (substr($head, 0, 3) === 'ID3') {
        return 'audio/mpeg';
    }
    if (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) {
        return 'audio/mpeg';
    }
    return 'audio/mpeg';
}

/**
 * 旧内置音或“欢迎您加入食光菜谱-登陆前/后”这类别名文件若实际是 MP4/AAC，
 * 自动改用源码里真正的新 MP3，避免浏览器把 MP4 当 MP3 解码而无声。
 */
function audio_refresh_builtin(string $file, string $slot): string
{
    $slot = $slot === 'member' ? 'member' : 'guest';
    if ($file === '' || !audio_path_ok($file)) {
        return $file;
    }
    $full = SG_ROOT . '/' . $file;
    $name = basename($file);
    $canonical = audio_builtin_name($slot);
    $alias = false;
    if (strpos($name, '欢迎您加入食光菜谱') !== false) {
        $words = $slot === 'member'
            ? ['登录后', '登陆后', '登錄後', '登録後']
            : ['登录前', '登陆前', '登錄前', '登録前'];
        foreach ($words as $word) {
            if (strpos($name, $word) !== false) {
                $alias = true;
                break;
            }
        }
    }
    $isMp3 = audio_file_mime($full) === 'audio/mpeg';
    if ($name === $canonical || ($alias && !$isMp3)) {
        $fresh = audio_builtin_default($slot);
        if ($fresh !== '') {
            return $fresh;
        }
    }
    return $file;
}

/** 指定音频位真正要播的文件；未开启返回空，配置文件丢失时先按名称、再用源码内置默认兜底 */
function welcome_audio_for_slot(string $slot): string
{
    $slot = $slot === 'member' ? 'member' : 'guest';
    $st   = audio_slot_state($slot);
    if (!$st['enabled']) {
        return '';
    }
    if ($st['ok']) {
        /* 内置默认音频换了新版本，或别名的旧文件其实是 MP4/AAC：换成源码里的新 MP3 */
        $fresh = audio_refresh_builtin($st['file'], $slot);
        if ($fresh !== $st['file']) {
            return $fresh;
        }
        return $st['file'];
    }
    $guess = welcome_audio_slot_guess($slot);
    if ($guess !== '' && audio_path_ok($guess)) {
        return $guess;
    }
    return audio_builtin_default($slot);
}

/** 免费主机（InfinityFree 等）会把 PHP 二进制响应的 Content-Type 改成 text/html，音频改用 data URI 嵌入 */
function sg_audio_embed_mode(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    /* gt.tc 这类 InfinityFree 分销站：实测 audio.php 能正常返回音频，移动端只是首次 HTML 会走 AES 挑战，
       浏览器拿到 cookie 后代理可用；不再使用大体积 data URI（部分 WebView 反而播不了）。
       即使后台勾了“嵌入播放”，这里也强制走代理，避免旧设置把页面拖回 data URI。 */
    if ($host !== '' && strpos($host, 'gt.tc') !== false) {
        return false;
    }
    $v = setting('audio_embed', '');
    if ($v === '1') {
        return true;
    }
    if ($v === '0') {
        return false;
    }
    foreach (['infinityfree', 'rf.gd', 'freesite.online', 'free.nf', 'epizy.com', 'page.gd', '42web.io', '0me.me'] as $needle) {
        if ($host !== '' && strpos($host, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/** MP3 转 data URI，前台/后台直接嵌进 HTML，不再单独发音频请求；超过 4 MB 返回空走原代理 */
function audio_data_uri(string $file): string
{
    if ($file === '') {
        return '';
    }
    $full = SG_ROOT . '/' . $file;
    if (!is_file($full) || filesize($full) > 4 * 1024 * 1024) {
        return '';
    }
    $bytes = @file_get_contents($full);
    if ($bytes === false || $bytes === '') {
        return '';
    }
    return 'data:' . audio_file_mime($full) . ';base64,' . base64_encode($bytes);
}

/** 当前可用的前台欢迎音频路径；按登录状态取登录前 / 登录后那一条 */
function welcome_audio_file(): string
{
    return welcome_audio_for_slot(current_user() ? 'member' : 'guest');
}

/** 删除上传目录里的音频；只允许 uploads/audio 下的 mp3 */
function delete_audio_file(string $path): void
{
    if (!preg_match('#^uploads/audio/[^\x00-\x1F/\\\\]+\.mp3$#iu', $path)) {
        return;
    }
    $full = SG_ROOT . '/' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/* ============================================================
 * 杂项
 * ============================================================ */

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function fmt_date(?string $dt, string $format = 'Y-m-d'): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date($format, $ts) : '—';
}

function time_ago(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts   = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 60) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' 小时前';
    }
    if (date('Y', $ts) === date('Y')) {
        return date('m-d H:i', $ts);
    }
    return date('Y-m-d', $ts);
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\-]+/', '-', $text);
    return trim(preg_replace('/-+/', '-', $text), '-');
}

function badge_status(string $status): string
{
    $map = [
        'published' => ['live', '已发布'],
        'draft'     => ['draft', '草稿'],
        'pending'   => ['hot', '待审核'],
        'approved'  => ['live', '已通过'],
        'rejected'  => ['draft', '已拒绝'],
    ];
    [$cls, $label] = $map[$status] ?? ['draft', $status];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}


/**
 * 服务端内联图标：即使前端脚本未加载，标题栏按钮也不会出现空白方块
 */
function sg_icon(string $name, string $cls = ''): string
{
    static $map = [
        'expand'   => '<path d="M4 9V4h5"/><path d="M20 15v5h-5"/><path d="M4 4l6 6"/><path d="M20 20l-6-6"/>',
        'shrink'   => '<path d="M9 4v5H4"/><path d="M15 20v-5h5"/><path d="M10 9L4 3"/><path d="M14 15l6 6"/>',
        'star'     => '<path d="M12 3.2l2.7 5.6 6 .9-4.4 4.3 1 6.1-5.3-2.9-5.3 2.9 1-6.1L3.3 9.7l6-.9"/>',
        'settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.2 5.2l2.1 2.1M16.7 16.7l2.1 2.1M18.8 5.2l-2.1 2.1M7.3 16.7l-2.1 2.1"/>',
        'check'    => '<path d="M4.5 12.5l5 5 10-11"/>',
        'back'     => '<path d="M15 5l-7 7 7 7"/>',
    ];
    $path = $map[$name] ?? $map['settings'];
    return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}


/** 是否手机/平板 UA：即使浏览器开了“电脑版网站”，也强制走手机布局 */
function sg_is_mobile_ua(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile|HarmonyOS|MiuiBrowser|MicroMessenger/i', $ua);
}
/** 老站自动补分类背景图字段 */

/** 旧库补 recipe_steps.images（一步多图） */
function sg_recipe_steps_images_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    try {
        $has = (bool) q('SHOW COLUMNS FROM `' . T('recipe_steps') . '` LIKE \'images\'')->fetch();
        if (!$has) {
            q('ALTER TABLE `' . T('recipe_steps') . '` ADD COLUMN `images` TEXT NULL AFTER `image`');
        }
        $done = true;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') === false && strpos($msg, 'Unknown table') === false && strpos($msg, "doesn't exist") === false) {
            throw $e;
        }
        $done = true;
    }
}

function sg_categories_ensure_bg(): void
{
    static $done = null;
    if ($done === true) { return; }
    $has = false;
    try {
        $has = (bool) q('SHOW COLUMNS FROM `' . T('categories') . '` LIKE \'bg\'')->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    if (!$has) {
        try {
            q('ALTER TABLE `' . T('categories') . '` ADD COLUMN `bg` VARCHAR(255) NOT NULL DEFAULT \'\' AFTER `icon`');
        } catch (Throwable $e) {
            // 并发请求可能已经加过；只忽略重复列，其他错误继续抛出
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }
    $done = true;
}

/** 用户评分表 / 菜谱评分计数字段（老站自动补） */
function sg_ratings_ensure(): void
{
    static $done = false;
    if ($done) { return; }

    try {
        q('CREATE TABLE IF NOT EXISTS `' . T('ratings') . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `recipe_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `rating` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_rating_user` (`recipe_id`, `user_id`),
            KEY `idx_rating_recipe` (`recipe_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        // 表已存在或并发建表时忽略；其他错误继续抛出
        if (strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
    }

    $has = false;
    try {
        $has = (bool) q("SHOW COLUMNS FROM `" . T('recipes') . "` LIKE 'rating_count'")->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    if (!$has) {
        try {
            q('ALTER TABLE `' . T('recipes') . '` ADD COLUMN `rating_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `rating`');
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }
    $done = true;
}

/** 评论点赞表（老站自动补） */
function sg_comment_likes_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    q('CREATE TABLE IF NOT EXISTS `' . T('comment_likes') . '` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `comment_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_cmt_like` (`comment_id`, `user_id`),
        KEY `idx_cmt_like_comment` (`comment_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = true;
}

/** 读取某道菜的用户评分：平均分、人数、当前用户分数 */
function sg_recipe_rating_stats(int $recipeId, int $userId = 0): array
{
    $row = fetch_one('SELECT COUNT(*) AS c, ROUND(AVG(rating), 1) AS a FROM `' . T('ratings') . '` WHERE recipe_id = ?', [$recipeId]);
    $mine = $userId > 0 ? (int) fetch_val('SELECT rating FROM `' . T('ratings') . '` WHERE recipe_id = ? AND user_id = ?', [$recipeId, $userId], 0) : 0;
    return [
        'count' => (int) ($row['c'] ?? 0),
        'avg'   => $row && $row['a'] !== null ? (float) $row['a'] : 0.0,
        'mine'  => $mine,
    ];
}

/** 用户打分后，重算菜谱平均分与评分人数 */
function sg_recipe_rating_refresh(int $recipeId): void
{
    $row = fetch_one('SELECT COUNT(*) AS c, AVG(rating) AS a FROM `' . T('ratings') . '` WHERE recipe_id = ?', [$recipeId]);
    $count = (int) ($row['c'] ?? 0);
    if ($count < 1) { return; }
    $avg = round((float) ($row['a'] ?? 0), 1);
    q('UPDATE `' . T('recipes') . '` SET rating = ?, rating_count = ? WHERE id = ?', [$avg, $count, $recipeId]);
}

/** 首页当前会展示的菜谱 ID，用于“试试这些”避开重复 */
function sg_home_recipe_ids(): array
{
    $ids = [];
    $featured = fetch_one('SELECT id FROM `' . T('recipes') . '` WHERE status = "published" AND is_featured = 1 ORDER BY published_at DESC LIMIT 1');
    if (!$featured) {
        $featured = fetch_one('SELECT id FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY published_at DESC LIMIT 1');
    }
    if ($featured) { $ids[] = (int) $featured['id']; }
    foreach (fetch_all('SELECT id FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY fav_count DESC, views DESC LIMIT 4') as $row) {
        $ids[] = (int) $row['id'];
    }
    $weekIds = array_values(array_filter(array_map('intval', (array) ($_SESSION['home_week_ids'] ?? []))));
    if ($weekIds) {
        foreach ($weekIds as $wid) {
            $row = fetch_one('SELECT id FROM `' . T('recipes') . '` WHERE id = ? AND status = "published"', [$wid]);
            if ($row) { $ids[] = (int) $row['id']; }
        }
    } else {
        $seed = (int) ($_SESSION['home_week_seed'] ?? 0);
        if ($seed > 0) {
            foreach (fetch_all('SELECT id FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY RAND(' . $seed . ') LIMIT 5') as $row) {
                $ids[] = (int) $row['id'];
            }
        } else {
            foreach (fetch_all('SELECT id FROM `' . T('recipes') . '` WHERE status = "published" ORDER BY published_at DESC, id DESC LIMIT 5') as $row) {
                $ids[] = (int) $row['id'];
            }
        }
    }
    return array_values(array_unique($ids));
}

/** 分类图标的可选项：[code => [中文名, 适用场景]] */
function sg_icon_options(): array
{
    // 内置图标已停用：分类图标全部由管理员自定义上传，默认留空。
    return [];
}

/** 可视化图标选择器（替代原生 select，手机上也能看到样式） */
/** 自定义图标表不存在时自动补建（老站不跑升级也能用） */
function sg_icons_ensure_table(): void
{
    static $ok = null;
    if ($ok === true) { return; }
    try {
        q('SELECT 1 FROM `' . T('icons') . '` LIMIT 1');
        $ok = true;
        return;
    } catch (Throwable $e) {
        // 继续建表
    }
    q('CREATE TABLE IF NOT EXISTS `' . T('icons') . '` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(32) NOT NULL,
        `note` VARCHAR(80) NOT NULL DEFAULT \'\',
        `file` VARCHAR(255) NOT NULL DEFAULT \'\',
        `sort` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $ok = true;
}

/** 从 Iconify 下载一个 SVG 图标，返回 [ok, svg, err] */
function sg_iconify_svg(string $iconId): array
{
    if (!preg_match('#^([a-z0-9-]+):([a-z0-9][a-z0-9-]*)$#i', $iconId, $m)) {
        return [false, '', '图标代号格式不对'];
    }
    if (!function_exists('curl_init')) {
        return [false, '', '服务器没有启用 curl 扩展'];
    }
    $url = 'https://api.iconify.design/' . strtolower($m[1]) . '/' . strtolower($m[2]) . '.svg?height=256';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET          => true,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => 3,
        CURLOPT_CONNECTTIMEOUT   => 8,
        CURLOPT_TIMEOUT          => 12,
        CURLOPT_ENCODING         => '',
        CURLOPT_USERAGENT        => 'ShiguangRecipe/1.0',
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) { return [false, '', '下载图标失败：' . ($err ?: '超时')]; }
    if ($code < 200 || $code >= 300) { return [false, '', '图标库返回 HTTP ' . $code]; }
    if (strlen($raw) > 512 * 1024) { return [false, '', '图标文件过大']; }
    $svg = trim((string) $raw);
    if (!preg_match('#^<svg[\s>]#i', $svg)) { return [false, '', '下载到的不是 SVG 图标']; }
    /* 只保留图形，去掉脚本和事件属性 */
    $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg);
    $svg = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $svg);
    return [true, $svg, ''];
}

/** 把 Iconify 图标存进自定义图标表，返回 [id, path, err] */
function sg_iconify_icon_add(string $iconId, string $label, string $assignCat = ''): array
{
    [$ok, $svg, $err] = sg_iconify_svg($iconId);
    if (!$ok) { return [0, '', $err]; }
    [$prefix, $name] = explode(':', strtolower($iconId), 2);
    $prefix = preg_replace('/[^a-z0-9-]/', '', $prefix);
    $name   = preg_replace('/[^a-z0-9-]/', '', $name);
    if ($prefix === '' || $name === '') { return [0, '', '图标代号无效']; }
    $dir = SG_ROOT . '/uploads/icons';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) { return [0, '', '图标目录不可写']; }
    $file = 'iconify-' . $prefix . '-' . substr($name, 0, 60) . '.svg';
    $path = 'uploads/icons/' . $file;
    if (file_put_contents($dir . '/' . $file, $svg) === false) {
        return [0, '', '保存图标文件失败'];
    }
    sg_icons_ensure_table();
    $exist = fetch_one('SELECT * FROM `' . T('icons') . '` WHERE file = ?', [$path]);
    if ($exist) { return [(int) $exist['id'], $path, '']; }
    $label = trim($label) !== '' ? mb_substr(trim($label), 0, 32) : mb_substr($name, 0, 32);
    $note  = mb_substr('Iconify · ' . $iconId, 0, 80);
    $id = db_insert(T('icons'), [
        'name' => $label,
        'note' => $note,
        'file' => $path,
    ]);
    return [(int) $id, $path, ''];
}

/** 自定义图标（管理员上传），表不存在时安全返回空 */
function sg_custom_icons(): array
{
    static $rows = null;
    if ($rows !== null) { return $rows; }
    $rows = [];
    try {
        $rows = fetch_all('SELECT * FROM `' . T('icons') . '` ORDER BY sort ASC, id ASC');
    } catch (Throwable $e) {
        $rows = [];
    }
    return $rows;
}

/** 内置 + 自定义图标的完整库：[code => [name,note,custom,file]] */
function sg_icon_library(): array
{
    static $lib = null;
    if ($lib !== null) { return $lib; }
    $lib = [];
    foreach (sg_icon_options() as $code => $meta) {
        $lib[$code] = ['code' => $code, 'name' => $meta[0], 'note' => $meta[1], 'custom' => false, 'file' => ''];
    }
    foreach (sg_custom_icons() as $r) {
        $code = 'c' . (int) $r['id'];
        $lib[$code] = [
            'code'   => $code,
            'name'   => (string) $r['name'],
            'note'   => (string) $r['note'],
            'custom' => true,
            'file'   => (string) $r['file'],
        ];
    }
    return $lib;
}

/** 取图标资料；未设置或已删除的代号返回空图标 */
function sg_icon_meta(string $code): array
{
    $lib = sg_icon_library();
    return $lib[$code] ?? ['code' => '', 'name' => '', 'note' => '', 'custom' => false, 'file' => ''];
}

function sg_icon_name(string $code): string
{
    return sg_icon_meta($code)['name'];
}

/** 输出分类图标：只输出自定义图片；没有设置时留空 */
function sg_cat_icon(string $code, string $extra = ''): string
{
    $m = sg_icon_meta($code);
    if ($m['code'] === '') {
        return '<span class="ico ico-none ' . e($extra) . '"></span>';
    }
    if ($m['custom'] && $m['file'] !== '') {
        return '<span class="ico ico-img ' . e($extra) . '"><img src="' . e(image_url($m['file'])) . '" alt="' . e($m['name']) . '" loading="lazy"></span>';
    }
    return '<span class="ico ' . e($extra) . '" data-ico="' . e($m['code']) . '"></span>';
}

function sg_icon_picker(string $name, string $value, string $formId = '', bool $compact = false): string
{
    $lib     = sg_icon_library();
    $value   = isset($lib[$value]) ? $value : '';
    $customs = array_filter($lib, function ($m) { return $m['custom']; });
    $html    = '<div class="icon-pick-wrap">';

    if ($customs) {
        $html .= '<div class="icon-pick ip-custom-grid' . ($compact ? ' compact' : '') . '">';
        foreach ($customs as $code => $meta) {
            $on = $code === $value ? ' on' : '';
            $html .= '<button type="button" class="ip-btn ip-btn-custom' . $on . '" data-icon-pick="' . e($code) . '"'
                . ($formId !== '' ? ' data-form="' . e($formId) . '"' : '')
                . ' title="' . e($meta['name']) . ' · ' . e($meta['note']) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">'
                . '<span class="ip-tag">自定义</span>'
                . sg_cat_icon($code)
                . '<small>' . e($meta['name']) . '</small>'
                . '<em>' . e($meta['note']) . '</em>'
                . '</button>';
        }
        $html .= '</div>';
    } else {
        $html .= '<div class="ip-empty"><b>还没有自定义图标</b>'
            . '<span>点下面的「＋ 自定义图标」上传一张，填好名称和适用类型，保存后就能在这里选。</span></div>';
    }

    $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '"'
        . ($formId !== '' ? ' form="' . e($formId) . '"' : '') . ' data-icon-value>';
    $catId = 0;
    if ($formId !== '' && preg_match('/^cf(\d+)$/', $formId, $m)) { $catId = (int) $m[1]; }
    $html .= '<div class="ip-foot"><span>支持 PNG / WebP / JPG，上传后统一成 256×256</span>'
        . '<button type="button" class="ip-more" data-iconlib-open data-cat="' . $catId . '">＋ 添加图标</button></div>';
    $html .= '</div>';
    return $html;
}

/* 颜色微调：$pct > 0 变亮，< 0 变暗（用于把主题色派生出一套色阶） */
function sg_color_adjust(string $hex, float $pct): string
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) { return '#C8502B'; }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $f = function ($v) use ($pct) {
        $v = $pct >= 0 ? $v + (255 - $v) * $pct : $v * (1 + $pct);
        return max(0, min(255, (int) round($v)));
    };
    return sprintf('#%02X%02X%02X', $f($r), $f($g), $f($b));
}
