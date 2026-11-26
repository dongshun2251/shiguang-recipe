<?php
/* 食光菜谱 · 本地 AI 客户端
   支持两类本地服务：
   1) ollama  ：POST {base}/api/chat（Ollama 默认 127.0.0.1:11434）
   2) openai  ：POST {base}/v1/chat/completions（LM Studio / llama.cpp / vLLM 等兼容接口）
   图像生成（可选）：OpenAI 兼容 /images/generations，也支持智谱 CogView 这类直接返回图片 URL 的接口 */

function ai_cfg(): array
{
    return [
        'enabled'   => setting('ai_enabled', '0') === '1',
        'kind'      => setting('ai_kind', 'ollama'),
        'base'      => rtrim((string) setting('ai_base', 'http://127.0.0.1:11434'), '/'),
        'model'     => (string) setting('ai_model', 'qwen2.5:3b'),
        'key'       => (string) setting('ai_key', ''),
        'timeout'   => max(5, min(300, (int) setting('ai_timeout', '60'))),
        'img_base'  => rtrim((string) setting('ai_img_base', ''), '/'),
        'img_model' => (string) setting('ai_img_model', ''),
        'img_key'   => (string) setting('ai_img_key', ''),
    ];
}

function ai_enabled(): bool
{
    $c = ai_cfg();
    return $c['enabled'] && $c['base'] !== '' && $c['model'] !== '';
}

function ai_img_enabled(): bool
{
    $c = ai_cfg();
    return ai_enabled() && $c['img_base'] !== '' && $c['img_model'] !== '';
}

/** 拼接接口地址：base 已带 /v1 时不重复 */
function ai_url(string $base, string $path): string
{
    $base = rtrim($base, '/');
    if (str_ends_with($base, '/v1') && str_starts_with($path, '/v1')) {
        $path = substr($path, 3);
    }
    return $base . $path;
}

/** 图像接口地址：可填完整 endpoint，也可只填 base */
function ai_img_url(string $base): string
{
    $base = rtrim($base, '/');
    if (str_contains($base, '/images/generations')) {
        return $base;
    }
    if (str_ends_with($base, '/v1')) {
        return $base . '/images/generations';
    }
    if (str_ends_with($base, '/v4') || str_contains($base, 'open.bigmodel.cn/api/paas')) {
        return $base . (str_ends_with($base, '/v4') ? '/images/generations' : '/v4/images/generations');
    }
    if (str_contains($base, 'bigmodel.cn')) {
        return 'https://open.bigmodel.cn/api/paas/v4/images/generations';
    }
    return $base . '/v1/images/generations';
}

/** 下载接口返回的图片 URL，返回 [ok, binary, err] */
function ai_img_download(string $url, int $timeout = 60): array
{
    if (!function_exists('curl_init')) {
        return [false, '', '服务器没有启用 curl 扩展'];
    }
    if (!preg_match('#^https?://#i', $url)) {
        return [false, '', '图像地址无效'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET          => true,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => 3,
        CURLOPT_CONNECTTIMEOUT   => 10,
        CURLOPT_TIMEOUT          => max(10, $timeout),
        CURLOPT_MAXFILESIZE      => 10 * 1024 * 1024,
        CURLOPT_USERAGENT        => 'ShiguangRecipe/1.0',
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [false, '', '下载生成图失败：' . ($err ?: '超时')];
    }
    if ($code < 200 || $code >= 300) {
        return [false, '', '下载生成图失败：HTTP ' . $code];
    }
    if (strlen($raw) > 10 * 1024 * 1024) {
        return [false, '', '生成图超过 10MB，已拒绝'];
    }
    return [true, $raw, ''];
}

/** 底层 HTTP POST（JSON），返回 [ok, body, err] */
function ai_http(string $url, array $payload, string $key = '', int $timeout = 60): array
{
    if (!function_exists('curl_init')) {
        return [false, null, '服务器没有启用 curl 扩展'];
    }
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [false, null, 'AI 接口连接失败：' . ($err ?: '超时')];
    }
    $data = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? $data['error'] ?? ('HTTP ' . $code);
        return [false, null, 'AI 接口返回错误：' . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE))];
    }
    return [true, $data, ''];
}

/** 模型列表地址：Ollama 用 /api/tags，OpenAI 兼容用 /v1/models */
function ai_models_url(string $base, string $kind): string
{
    $base = rtrim(trim($base), '/');
    $base = preg_replace('#/api/chat$#i', '', $base);
    if ($kind === 'ollama') {
        if (str_ends_with($base, '/api/tags')) { return $base; }
        if (str_ends_with($base, '/api')) { return $base . '/tags'; }
        return $base . '/api/tags';
    }
    $base = preg_replace('#/chat/completions$#i', '', $base);
    if (str_ends_with($base, '/models')) { return $base; }
    if (str_ends_with($base, '/v1')) { return $base . '/models'; }
    return $base . '/v1/models';
}

/** GET 读取 JSON，返回 [ok, data, err] */
function ai_http_get(string $url, string $key = '', int $timeout = 8): array
{
    if (!function_exists('curl_init')) {
        return [false, null, '服务器没有启用 curl 扩展'];
    }
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($key !== '') { $headers[] = 'Authorization: Bearer ' . $key; }
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET          => true,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => 3,
        CURLOPT_CONNECTTIMEOUT   => min(8, max(3, $timeout)),
        CURLOPT_TIMEOUT          => max(5, $timeout),
        CURLOPT_HTTPHEADER       => $headers,
        CURLOPT_USERAGENT        => 'ShiguangRecipe/1.0',
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [false, null, '连接失败：' . ($err ?: '超时')];
    }
    if (strlen($raw) > 2 * 1024 * 1024) {
        return [false, null, '接口返回内容过大'];
    }
    $data = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? $data['error'] ?? ('HTTP ' . $code);
        return [false, null, '接口返回错误：' . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE))];
    }
    if (!is_array($data)) {
        return [false, null, '接口返回的不是 JSON'];
    }
    return [true, $data, ''];
}

/** 读取服务里的模型名，返回 [ok, names, err] */
function ai_list_models(string $base, string $kind, string $key = ''): array
{
    $base = trim($base);
    if (!preg_match('#^https?://#i', $base)) {
        return [false, [], '服务地址要以 http:// 或 https:// 开头'];
    }
    $url = ai_models_url($base, $kind === 'openai' ? 'openai' : 'ollama');
    [$ok, $data, $err] = ai_http_get($url, trim($key), 8);
    if (!$ok) { return [false, [], $err]; }
    $list = [];
    if (isset($data['models']) && is_array($data['models'])) {
        foreach ($data['models'] as $m) {
            $name = is_string($m) ? $m : (string) ($m['name'] ?? ($m['model'] ?? ($m['id'] ?? '')));
            if ($name !== '') { $list[] = $name; }
        }
    }
    if (isset($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as $m) {
            $name = is_string($m) ? $m : (string) ($m['id'] ?? ($m['name'] ?? ''));
            if ($name !== '') { $list[] = $name; }
        }
    }
    $list = array_values(array_unique($list));
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    if (!$list) { return [false, [], '接口通了，但没有返回模型列表']; }
    return [true, array_slice($list, 0, 200), ''];
}

/** 聊天补全，$messages 为 [{role,content}] ；返回 [ok, text, err] */
function ai_chat(array $messages, array $opt = []): array
{
    $c = ai_cfg();
    if (!ai_enabled()) {
        return [false, '', 'AI 未启用或未配置，请到「系统设置 → AI 接入」填写服务地址和模型名'];
    }

    $temperature = $opt['temperature'] ?? 0.4;
    $maxTokens   = $opt['max_tokens'] ?? 1200;

    if ($c['kind'] === 'ollama') {
        $payload = [
            'model'    => $c['model'],
            'messages' => $messages,
            'stream'   => false,
            'options'  => ['temperature' => $temperature, 'num_predict' => $maxTokens],
        ];
        [$ok, $data, $err] = ai_http($c['base'] . '/api/chat', $payload, '', $c['timeout']);
        if (!$ok) {
            return [false, '', $err];
        }
        $text = (string) ($data['message']['content'] ?? '');
        return [true, trim($text), ''];
    }

    $payload = [
        'model'       => $c['model'],
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
        'stream'      => false,
    ];
    [$ok, $data, $err] = ai_http(ai_url($c['base'], '/v1/chat/completions'), $payload, $c['key'], $c['timeout']);
    if (!$ok) {
        return [false, '', $err];
    }
    $text = (string) ($data['choices'][0]['message']['content'] ?? '');
    return [true, trim($text), ''];
}

/** 从模型输出里抽第一段 JSON，宽容处理 ```json 包裹与前后废话 */
function ai_json(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $start = strpos($text, '{');
    if ($start === false) {
        return null;
    }
    $depth = 0;
    $inStr = false;
    $esc   = false;
    $len   = strlen($text);
    for ($i = $start; $i < $len; $i++) {
        $ch = $text[$i];
        if ($inStr) {
            if ($esc) { $esc = false; continue; }
            if ($ch === '\\') { $esc = true; continue; }
            if ($ch === '"') { $inStr = false; }
            continue;
        }
        if ($ch === '"') { $inStr = true; continue; }
        if ($ch === '{') { $depth++; continue; }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $json = substr($text, $start, $i - $start + 1);
                $data = json_decode($json, true);
                return is_array($data) ? $data : null;
            }
        }
    }
    return null;
}

/** 系统提示：统一约束输出为紧凑 JSON */
function ai_system_prompt(string $role): string
{
    return $role . "\n你必须只输出一个 JSON 对象，不要解释、不要 Markdown、不要代码块围栏。中文内容用中文。";
}

/** 生成菜谱草稿；返回 [ok, array|null, err] */
function ai_recipe_draft(string $name, string $ingredients = '', string $style = ''): array
{
    $sys = ai_system_prompt('你是中餐菜谱编辑，擅长把家常菜写成可执行的菜谱。');
    $user = "请根据下面的信息生成一道菜谱草稿。\n"
        . "菜名：" . ($name !== '' ? $name : '（未填，请根据食材自拟）') . "\n"
        . "现有食材：" . ($ingredients !== '' ? $ingredients : '（未填，请按常规家常做法给）') . "\n"
        . "口味/风格要求：" . ($style !== '' ? $style : '（无）') . "\n\n"
        . "输出 JSON 字段：\n"
        . "title（菜名，不超过18字），summary（一句话简介，30-50字），servings（如 \"2-3 人份\"），\n"
        . "prep_minutes（整数），cook_minutes（整数），difficulty（只能是 初级/中级/高级 之一），\n"
        . "category（从 家常热菜/汤羹/凉菜/主食/烘焙/小吃 里选一个最接近的），\n"
        . "tags（3-5 个短标签，字符串数组），\n"
        . "ingredients（数组，每项 {name, quantity}，6-12 项，quantity 要带单位），\n"
        . "steps（数组，每项 {title, content, duration}，content 说清火候和时间，4-6 步），\n"
        . "tips（2-3 条关键提示，字符串数组），\n"
        . "nutrition（{calories, protein, fat, carb, note}，按每份估算，字符串即可）。";

    [$ok, $text, $err] = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ], ['temperature' => 0.5, 'max_tokens' => 1600]);
    if (!$ok) {
        return [false, null, $err];
    }
    $data = ai_json($text);
    if (!$data || empty($data['steps'])) {
        return [false, null, '模型返回的内容不是可解析的菜谱 JSON，请重试或换个模型'];
    }
    return [true, $data, ''];
}

/** 自然语言找菜：解析成查询条件；返回 [ok, array|null, err] */
function ai_search_intent(string $q): array
{
    $sys = ai_system_prompt('你是菜谱搜索条件解析器。');
    $user = "用户想找菜，原话：「{$q}」\n"
        . "请解析成 JSON：keywords（用于匹配菜名/食材/标签的词，字符串数组，1-4 个），\n"
        . "ingredients（用户手上有的食材，字符串数组，没有就空数组），\n"
        . "max_minutes（总耗时上限整数，没提就给 0），\n"
        . "difficulty（初级/中级/高级，没提就给空字符串），\n"
        . "category（家常热菜/汤羹/凉菜/主食/烘焙/小吃，没提就给空字符串）。\n"
        . "只输出 JSON。";

    [$ok, $text, $err] = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ], ['temperature' => 0.1, 'max_tokens' => 400]);
    if (!$ok) {
        return [false, null, $err];
    }
    $data = ai_json($text);
    if (!$data) {
        return [false, null, '模型返回的搜索条件解析失败'];
    }
    return [true, $data, ''];
}

/** 生成分享文案；返回 [ok, text, err] */
function ai_share_copy(array $recipe, array $steps): array
{
    $sys = ai_system_prompt('你是美食博主，写短平快的中文分享文案。');
    $stepTxt = '';
    foreach ($steps as $i => $s) {
        $stepTxt .= ($i + 1) . '. ' . ($s['title'] ?? '') . '：' . ($s['content'] ?? '') . "\n";
    }
    $user = "给这道菜写一条朋友圈/小红书文案：\n菜名：{$recipe['title']}\n简介：{$recipe['summary']}\n做法：\n{$stepTxt}\n"
        . "输出 JSON：{text（正文，120-180字，口语化，可带少量 emoji，别堆砌）, tags（3-5 个话题标签，不含 #）}。";
    [$ok, $text, $err] = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ], ['temperature' => 0.7, 'max_tokens' => 600]);
    if (!$ok) {
        return [false, '', $err];
    }
    $data = ai_json($text);
    if (!$data || empty($data['text'])) {
        return [false, '', '模型没有返回可用的文案'];
    }
    $out = (string) $data['text'];
    if (!empty($data['tags']) && is_array($data['tags'])) {
        $tags = [];
        foreach ($data['tags'] as $t) {
            $t = trim((string) $t);
            if ($t !== '') { $tags[] = '#' . ltrim($t, '#'); }
        }
        if ($tags) { $out .= "\n" . implode(' ', $tags); }
    }
    return [true, $out, ''];
}

/** 评论预审：返回 [ok, [id=>['flag'=>spam|ad|abuse|ok, 'reason'=>...]], err] */
function ai_moderate_comments(array $comments): array
{
    $sys = ai_system_prompt('你是社区评论审核助手，判断垃圾广告、辱骂、无意义灌水。');
    $lines = '';
    foreach ($comments as $c) {
        $lines .= '#' . (int) $c['id'] . ' ' . mb_substr((string) $c['content'], 0, 120) . "\n";
    }
    $user = "逐条判断下面的评论：\n{$lines}\n"
        . "输出 JSON：{items:[{id, flag(ok|spam|ad|abuse), reason(不超过12字)}]}，每条都要有。";
    [$ok, $text, $err] = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ], ['temperature' => 0.1, 'max_tokens' => 600]);
    if (!$ok) {
        return [false, [], $err];
    }
    $data = ai_json($text);
    if (!$data || empty($data['items'])) {
        return [false, [], '模型没有返回审核结果'];
    }
    $map = [];
    foreach ($data['items'] as $it) {
        $id = (int) ($it['id'] ?? 0);
        if ($id) {
            $map[$id] = [
                'flag'   => in_array($it['flag'] ?? '', ['ok', 'spam', 'ad', 'abuse'], true) ? $it['flag'] : 'ok',
                'reason' => mb_substr((string) ($it['reason'] ?? ''), 0, 20),
            ];
        }
    }
    return [true, $map, ''];
}

/** 营养估算：返回 [ok, 文本, err]（文本形如「热量 420 kcal · 蛋白质 28 g ...」） */
function ai_nutrition(array $recipe, array $ings): array
{
    $sys = ai_system_prompt('你是营养师，按中式家常做法估算每份营养。');
    $ingTxt = '';
    foreach ($ings as $i) {
        $ingTxt .= '- ' . $i['name'] . ' ' . ($i['quantity'] ?? '') . "\n";
    }
    $user = "菜名：{$recipe['title']}\n份量：{$recipe['servings']}\n食材：\n{$ingTxt}\n"
        . "输出 JSON：{calories, protein, fat, carb, note}，单位写清（kcal / g），note 不超过 20 字说明这是估算。";
    [$ok, $text, $err] = ai_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ], ['temperature' => 0.2, 'max_tokens' => 300]);
    if (!$ok) {
        return [false, '', $err];
    }
    $d = ai_json($text);
    if (!$d) {
        return [false, '', '模型没有返回可用的营养数据'];
    }
    $parts = [];
    foreach (['calories' => '热量', 'protein' => '蛋白质', 'fat' => '脂肪', 'carb' => '碳水'] as $k => $lb) {
        if (!empty($d[$k])) { $parts[] = $lb . ' ' . $d[$k]; }
    }
    if (!empty($d['note'])) { $parts[] = (string) $d['note']; }
    if (!$parts) {
        return [false, '', '营养数据为空'];
    }
    return [true, implode(' · ', $parts), ''];
}

/** 可选：调本地/兼容图像接口生成一张图，返回 [ok, 相对路径, err] */
function ai_image_gen(string $prompt, string $subDir = 'ai'): array
{
    $c = ai_cfg();
    if (!ai_img_enabled()) {
        return [false, '', '未配置图像接口'];
    }
    $url = ai_img_url($c['img_base']);
    $isZhipu = str_contains($url, 'bigmodel.cn') || stripos($c['img_model'], 'cogview') !== false;
    if ($isZhipu) {
        // 智谱 CogView：官方 /v4/images/generations，返回图片 URL
        $payload = ['model' => $c['img_model'], 'prompt' => $prompt, 'size' => '1024x1024'];
    } else {
        $payload = ['model' => $c['img_model'], 'prompt' => $prompt, 'n' => 1, 'size' => '512x512', 'response_format' => 'b64_json'];
    }
    [$ok, $data, $err] = ai_http($url, $payload, $c['img_key'], max(30, $c['timeout']));
    if (!$ok) {
        return [false, '', $err];
    }

    $b64 = (string) ($data['data'][0]['b64_json'] ?? $data['images'][0]['b64_json'] ?? '');
    $imgUrl = (string) ($data['data'][0]['url'] ?? $data['images'][0]['url'] ?? '');
    if ($b64 !== '') {
        $bin = base64_decode($b64, true);
        if ($bin === false) {
            return [false, '', '图片数据解码失败'];
        }
    } elseif ($imgUrl !== '') {
        [$ok2, $bin, $err2] = ai_img_download($imgUrl, max(30, $c['timeout']));
        if (!$ok2) {
            return [false, '', $err2];
        }
    } else {
        return [false, '', '图像接口没有返回图片数据'];
    }

    $dir = SG_ROOT . '/uploads/' . $subDir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return [false, '', '上传目录不可写'];
    }
    $ext = 'png';
    $info = @getimagesizefromstring($bin);
    if ($info && !empty($info['mime'])) {
        $map = ['image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/png' => 'png'];
        $ext = $map[$info['mime']] ?? 'png';
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $name, $bin) === false) {
        return [false, '', '图片写入失败'];
    }
    return [true, 'uploads/' . $subDir . '/' . $name, ''];
}
