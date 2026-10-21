<?php
declare(strict_types=1);
session_start();
$config = require __DIR__ . '/config.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function appBase(): string {
    global $config;
    if (!empty($config['app_base_url'])) return rtrim((string)$config['app_base_url'], '/');
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && !empty($_SERVER['HTTP_HOST'])) {
        return rtrim($_SERVER['HTTP_X_FORWARDED_PROTO'], ',') . '://' . $_SERVER['HTTP_HOST'];
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return 'https://' . $_SERVER['HTTP_HOST'];
    return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function ghRequest(array $cfg, string $method, string $url, ?array $body = null): array {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL extension is not available.');
    $ch = curl_init($url);
    $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: Short-Link-App'];
    if (!empty($cfg['github_token']) && $cfg['github_token'] !== 'PASTE_YOUR_GITHUB_TOKEN_HERE') $headers[] = 'Authorization: Bearer ' . $cfg['github_token'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new RuntimeException('GitHub connection failed: ' . $err);
    $decoded = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($decoded) ? ($decoded['message'] ?? 'GitHub API error') : 'GitHub API error';
        throw new RuntimeException("GitHub API ($status): $msg");
    }
    return ['status'=>$status, 'data'=>$decoded];
}
function repoFileUrl(array $cfg): string {
    return 'https://api.github.com/repos/' . rawurlencode($cfg['github_owner']) . '/' . rawurlencode($cfg['github_repo']) . '/contents/' . str_replace('%2F','/',rawurlencode($cfg['github_data_path'])) . '?ref=' . rawurlencode($cfg['github_branch']);
}
function loadLinks(array $cfg): array {
    $res = ghRequest($cfg, 'GET', repoFileUrl($cfg));
    $content = base64_decode((string)($res['data']['content'] ?? ''), true);
    if ($content === false) throw new RuntimeException('GitHub JSON file could not be decoded.');
    $links = json_decode($content, true);
    return is_array($links) ? $links : [];
}
function saveLinks(array $cfg, array $links): void {
    $url = repoFileUrl($cfg);
    $current = ghRequest($cfg, 'GET', $url);
    $content = json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $plainUrl = strtok($url, '?');
    ghRequest($cfg, 'PUT', $plainUrl, [
        'message' => 'Update Short-Link data',
        'content' => base64_encode($content === false ? '{}' : $content),
        'sha' => $current['data']['sha'] ?? null,
        'branch' => $cfg['github_branch'],
    ]);
}
function validConfig(array $c): bool {
    return !empty($c['github_owner']) && !empty($c['github_repo'])
        && !empty($c['github_token']) && !empty($c['api_key'])
        && !empty($c['admin_password']);
}
function shortUrl(string $code): string { return appBase() . '/' . rawurlencode($code); }
function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}
function findCode(array $links, string $code): ?string {
    foreach ($links as $key => $item) if (strcasecmp((string)$key, $code) === 0) return (string)$key;
    return null;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$setupReady = validConfig($config);

// Short link redirect
if ($path !== '/' && !str_starts_with($path, '/admin') && !str_starts_with($path, '/api/')) {
    $code = trim(rawurldecode(substr($path, 1)), '/');
    if ($code !== '' && !str_contains($code, '/')) {
        try {
            $links = loadLinks($config); $key = findCode($links, $code);
            if ($key !== null && !empty($links[$key]['url'])) {
                if (!empty($links[$key]['expires_at']) && strtotime((string)$links[$key]['expires_at']) < time()) {
                    http_response_code(410); echo 'This short link has expired.'; exit;
                }
                $links[$key]['clicks'] = (int)($links[$key]['clicks'] ?? 0) + 1;
                try { saveLinks($config, $links); } catch (Throwable $e) {}
                header('Location: ' . $links[$key]['url'], true, 302); exit;
            }
        } catch (Throwable $e) {}
        http_response_code(404); echo 'Short link not found.'; exit;
    }
}

// JSON API endpoints
if (str_starts_with($path, '/api/')) {
    if (!$setupReady) jsonResponse(['error'=>'Please configure config.php first.'], 503);
    $auth = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['api_key'] ?? '');
    if (!hash_equals((string)$config['api_key'], (string)$auth)) jsonResponse(['error'=>'Unauthorized'], 401);
    try { $links = loadLinks($config); } catch (Throwable $e) { jsonResponse(['error'=>$e->getMessage()], 502); }
    if ($path === '/api/v1/shorten' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $url = trim((string)($input['url'] ?? '')); $alias = trim((string)($input['alias'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true)) jsonResponse(['error'=>'A valid http/https URL is required.'], 422);
        $code = $alias !== '' ? $alias : substr(bin2hex(random_bytes(6)), 0, 8);
        if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $code)) jsonResponse(['error'=>'Alias must be 3-40 characters (letters, numbers, _ or -).'], 422);
        if (findCode($links, $code) !== null) jsonResponse(['error'=>'This alias already exists.'], 409);
        $links[$code] = ['url'=>$url, 'created_at'=>gmdate('c'), 'expires_at'=>(string)($input['expires_at'] ?? ''), 'clicks'=>0];
        saveLinks($config, $links);
        jsonResponse(['code'=>$code, 'name'=>$code, 'short_url'=>shortUrl($code), 'created_at'=>$links[$code]['created_at']], 201);
    }
    if ($path === '/api/v1/bulk' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $items = $input['links'] ?? []; if (!is_array($items) || count($items) > 100) jsonResponse(['error'=>'Provide up to 100 links.'], 422);
        $out = [];
        foreach ($items as $item) {
            $url = trim((string)($item['url'] ?? '')); $alias = trim((string)($item['alias'] ?? ''));
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true)) { $out[]=['error'=>'Invalid URL']; continue; }
            $code = $alias !== '' ? $alias : substr(bin2hex(random_bytes(6)), 0, 8);
            if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $code) || findCode($links, $code) !== null) { $out[]=['error'=>'Invalid or duplicate alias']; continue; }
            $links[$code]=['url'=>$url,'created_at'=>gmdate('c'),'expires_at'=>(string)($item['expires_at'] ?? ''),'clicks'=>0];
            $out[]=['code'=>$code,'short_url'=>shortUrl($code)];
        }
        saveLinks($config, $links); jsonResponse(['results'=>$out]);
    }
    if (preg_match('~^/api/v1/stats/([A-Za-z0-9_-]+)$~', $path, $m)) {
        $key=findCode($links,$m[1]); if ($key===null) jsonResponse(['error'=>'Not found'],404);
        jsonResponse(['code'=>$key,'short_url'=>shortUrl($key),'clicks'=>(int)($links[$key]['clicks']??0),'created_at'=>$links[$key]['created_at']??null,'expires_at'=>$links[$key]['expires_at']??null]);
    }
    jsonResponse(['error'=>'Unknown API endpoint'],404);
}

// Admin sign-in and dashboard
if ($path === '/admin' || $path === '/admin/') {
    if (!$setupReady) { http_response_code(503); $adminError = 'আগে config.php ফাইলে GitHub token, API key ও admin password বসান।'; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if (hash_equals((string)$config['admin_password'], (string)$_POST['admin_password'])) { $_SESSION['sl_admin']=true; header('Location: /admin'); exit; }
        $adminError='পাসওয়ার্ড সঠিক নয়।';
    }
    if (isset($_GET['logout'])) { unset($_SESSION['sl_admin']); header('Location: /admin'); exit; }
    $adminLinks=[]; $adminLoadError='';
    if (!empty($_SESSION['sl_admin']) && $setupReady) { try { $adminLinks=loadLinks($config); } catch(Throwable $e) { $adminLoadError=$e->getMessage(); } }
    ?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin · Short-Link</title><style>body{font-family:system-ui;background:#f4f8ff;color:#17233b;margin:0;padding:24px}.panel{max-width:900px;margin:30px auto;background:#ffffffd9;border:1px solid #dbe7fa;border-radius:24px;padding:24px;box-shadow:0 20px 60px #17407a12}input,button{padding:12px;border-radius:12px;border:1px solid #d5e0f2}button{background:#2563eb;color:white;border:0;cursor:pointer}.item{padding:14px;border:1px solid #e1eaf8;border-radius:14px;margin:10px 0;overflow-wrap:anywhere}.muted{color:#71809a}.error{color:#b42318}</style></head><body><div class="panel"><h1>Short-Link Admin</h1><?php if (!empty($_SESSION['sl_admin'])): ?><p><a href="/admin?logout=1">Logout</a> · <a href="/">Home</a></p><p><?=count($adminLinks)?> links saved</p><?php if($adminLoadError):?><p class="error"><?=h($adminLoadError)?></p><?php endif; ?><?php foreach(array_reverse($adminLinks,true) as $code=>$item): ?><div class="item"><strong><?=h((string)$code)?></strong><p><?=h(shortUrl((string)$code))?></p><p class="muted"><?=h((string)($item['url']??''))?></p><p>Clicks: <?= (int)($item['clicks']??0) ?> · Created: <?=h((string)($item['created_at']??''))?></p></div><?php endforeach; ?><?php else: ?><p class="muted">অ্যাডমিন ড্যাশবোর্ডে প্রবেশ করতে পাসওয়ার্ড দিন।</p><?php if(!empty($adminError)):?><p class="error"><?=h($adminError)?></p><?php endif; ?><form method="post"><input type="password" name="admin_password" placeholder="Admin password" required><button>Login</button></form><?php endif; ?></div></body></html><?php exit;
}

// Main page POST create
$notice=''; $noticeType='success'; $created=null;
if ($method === 'POST' && isset($_POST['create_link'])) {
    if (!$setupReady) { $notice='Render-এর Environment Variables (GH_OWNER, GH_REPO, GH_TOKEN, SHORTENER_API_KEY, ADMIN_PASSWORD) সেট করা আছে কি না পরীক্ষা করুন।'; $noticeType='error'; }
    elseif (!hash_equals((string)$config['api_key'], (string)($_POST['api_key']??''))) { $notice='API key সঠিক নয়।'; $noticeType='error'; }
    else {
        $url=trim((string)($_POST['url']??'')); $alias=trim((string)($_POST['alias']??'')); $expires=trim((string)($_POST['expires_at']??''));
        if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)) { $notice='সঠিক http/https URL দিন।'; $noticeType='error'; }
        elseif ($alias!=='' && !preg_match('/^[A-Za-z0-9_-]{3,40}$/',$alias)) { $notice='Alias ৩–৪০ অক্ষর হবে; ইংরেজি অক্ষর, সংখ্যা, _ অথবা - ব্যবহার করুন।'; $noticeType='error'; }
        else {
            try {
                $links=loadLinks($config); $code=$alias!==''?$alias:substr(bin2hex(random_bytes(6)),0,8);
                if(findCode($links,$code)!==null) throw new RuntimeException('এই Alias আগে থেকেই আছে। অন্য নাম দিন।');
                $links[$code]=['url'=>$url,'created_at'=>gmdate('c'),'expires_at'=>$expires,'clicks'=>0]; saveLinks($config,$links);
                $created=['code'=>$code,'short_url'=>shortUrl($code)]; $notice='আপনার Short-Link তৈরি হয়েছে।';
            } catch(Throwable $e) { $notice='তৈরি করা যায়নি: '.$e->getMessage(); $noticeType='error'; }
        }
    }
}
$links=[]; $loadError='';
if ($setupReady) { try { $links=loadLinks($config); } catch(Throwable $e) { $loadError=$e->getMessage(); } }
$links=array_reverse($links,true);
?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f4f8ff"><title>Short-Link · URL Shortener</title><style>
:root{--blue:#2563eb;--navy:#17233b;--muted:#6d7b94;--line:#dfe8f7;--bg:#f4f8ff}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 5% 0%,#e3edff 0,transparent 34%),radial-gradient(circle at 100% 10%,#e9f6ff 0,transparent 30%),var(--bg);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--navy);min-height:100vh}.wrap{max-width:1040px;margin:auto;padding:24px 16px 60px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:8px 0 30px}.brand{display:flex;align-items:center;gap:12px;font-weight:850;font-size:22px;letter-spacing:-.5px}.logo{width:44px;height:44px;border-radius:15px;display:grid;place-items:center;color:white;background:linear-gradient(135deg,#3478ff,#62c7ff);box-shadow:0 10px 24px #3478ff40;font-size:23px}.pill{border:1px solid #d7e4fa;background:#ffffffa8;padding:9px 13px;border-radius:999px;color:#476181;font-size:12px;font-weight:700}.hero{text-align:center;padding:14px 0 26px}.eyebrow{display:inline-block;color:var(--blue);font-weight:800;font-size:12px;letter-spacing:1.6px;text-transform:uppercase}.hero h1{font-size:clamp(30px,5vw,48px);letter-spacing:-1.6px;margin:12px 0 10px;line-height:1.12}.hero p{color:var(--muted);max-width:600px;margin:0 auto;line-height:1.7}.glass{background:#ffffffc9;border:1px solid #ffffff;box-shadow:0 20px 55px #234a8310,inset 0 1px #ffffff;border-radius:24px;backdrop-filter:blur(18px)}.create{padding:24px;margin-bottom:24px}.section-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.section-head h2{font-size:19px;margin:0;letter-spacing:-.4px}.hint{font-size:12px;color:var(--muted)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:flex;flex-direction:column;gap:8px}.field.full{grid-column:1/-1}label{font-size:12px;font-weight:800;color:#4a5d79}input{font:inherit;width:100%;padding:13px 14px;border:1px solid #dbe5f3;background:#fff;border-radius:13px;outline:none;color:var(--navy)}input:focus{border-color:#79a6ff;box-shadow:0 0 0 4px #2563eb12}.btn{border:0;border-radius:13px;padding:13px 18px;background:linear-gradient(135deg,#2563eb,#4388ff);color:white;font-weight:800;font-size:14px;cursor:pointer;box-shadow:0 9px 20px #2563eb27}.btn:active{transform:translateY(1px)}.submitrow{display:flex;gap:12px;align-items:center;margin-top:16px;flex-wrap:wrap}.smallnote{font-size:12px;color:var(--muted)}.notice{padding:13px 15px;border-radius:14px;margin:0 0 18px;font-size:13px;font-weight:650}.success{background:#ecfdf3;color:#087443;border:1px solid #b7ebcc}.error{background:#fff1f0;color:#b42318;border:1px solid #ffd0cc}.created{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:16px;border:1px solid #b9e6cb;background:#f0fff5;border-radius:16px;margin-bottom:18px}.created strong{display:block;margin-bottom:6px}.created code{font-size:14px;overflow-wrap:anywhere;color:#087443}.history{padding:24px}.historybar{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:18px}.historybar h2{font-size:20px;margin:0 0 5px}.search{max-width:320px}.stats{display:flex;gap:8px;flex-wrap:wrap}.stat{font-size:12px;color:#5c6e88;border:1px solid var(--line);border-radius:12px;padding:8px 11px;background:#ffffffb8}.list{display:grid;gap:11px}.linkrow{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;padding:16px;border:1px solid #e2eaf6;border-radius:17px;background:#ffffffa8;transition:.2s}.linkrow:hover{border-color:#b9d0fb;box-shadow:0 8px 24px #2b5eaa0b}.linkname{display:flex;align-items:center;gap:10px;min-width:0}.linkicon{width:38px;height:38px;flex-shrink:0;display:grid;place-items:center;border-radius:12px;background:#edf4ff;color:#2563eb;font-size:18px}.linktitle{font-weight:850;font-size:15px;overflow-wrap:anywhere}.short{color:#2563eb;font-size:13px;margin-top:5px;overflow-wrap:anywhere}.meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}.meta span{font-size:11px;color:#77859b;background:#f1f5fb;padding:5px 8px;border-radius:8px}.actions{display:flex;gap:8px;align-items:center}.copy{border:1px solid #d6e3f8;background:white;color:#2759ad;border-radius:11px;padding:10px 12px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}.copy:hover{background:#eff5ff}.empty{text-align:center;padding:38px 16px;color:var(--muted)}.empty .symbol{font-size:30px;margin-bottom:10px}.footer{text-align:center;color:#8a98ad;font-size:12px;margin-top:22px}.configbox{margin-bottom:18px;padding:16px;border-radius:14px;background:#fff7e8;border:1px solid #f6d9a5;color:#855a10;font-size:13px;line-height:1.65}.hidden{display:none!important}@media(max-width:620px){.wrap{padding:16px 12px 40px}.top{margin-bottom:22px}.hero{padding:8px 0 22px}.create,.history{padding:17px;border-radius:20px}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}.linkrow{grid-template-columns:1fr;gap:12px}.actions{justify-content:flex-end}.search{max-width:none;width:100%}.historybar{align-items:stretch}.historybar .search{order:2}.stats{order:3}.btn{width:100%}.submitrow .smallnote{width:100%}}
</style></head><body><main class="wrap">
<header class="top"><div class="brand"><span class="logo">↗</span><span>Short-Link</span></div><div class="pill">Simple · Fast · Reliable</div></header>
<section class="hero"><span class="eyebrow">URL SHORTENER</span><h1>লম্বা লিংক হোক ছোট</h1><p>সহজে Short-Link তৈরি করুন, আর আপনার আগের তৈরি করা লিংকগুলো এখান থেকেই আবার কপি করুন।</p></section>
<?php if(!$setupReady): ?><div class="configbox"><strong>Render সেটআপ:</strong> অ্যাপটি আগের Render Environment Variables থেকেই সেটিংস পড়ে। কোনো Secret কোডে লিখবেন না এবং নতুন Environment Variable যোগ করার দরকার নেই।</div><?php endif; ?>
<section class="glass create"><div class="section-head"><h2>✦ নতুন Short-Link তৈরি করুন</h2><span class="hint">GitHub JSON storage</span></div>
<?php if($notice): ?><div class="notice <?=$noticeType==='error'?'error':'success'?>"><?=h($notice)?></div><?php endif; ?>
<?php if($created): ?><div class="created"><div><strong>আপনার নতুন লিংক তৈরি হয়েছে!</strong><code id="newLink"><?=h($created['short_url'])?></code></div><button class="btn" type="button" onclick="copyText(<?=json_encode($created['short_url'])?>,this)">লিংক কপি করুন</button></div><?php endif; ?>
<form method="post"><input type="hidden" name="create_link" value="1"><div class="grid">
<div class="field full"><label for="url">মূল URL</label><input id="url" type="url" name="url" placeholder="https://example.com/your-long-link" required></div>
<div class="field"><label for="alias">আপনার পছন্দের নাম (Alias)</label><input id="alias" name="alias" placeholder="যেমন: my-book অথবা offer2026" minlength="3" maxlength="40"><span class="hint">খালি রাখলে স্বয়ংক্রিয় নাম তৈরি হবে</span></div>
<div class="field"><label for="expires_at">মেয়াদ শেষ (ঐচ্ছিক)</label><input id="expires_at" type="date" name="expires_at"></div>
<div class="field full"><label for="api_key">আপনার API Key</label><input id="api_key" type="password" name="api_key" placeholder="config.php-তে সেট করা API key" required autocomplete="off"></div>
</div><div class="submitrow"><button class="btn" type="submit">＋ Short-Link তৈরি করুন</button><span class="smallnote">আপনার মূল URL ইতিহাসের তালিকায় দেখানো হবে না।</span></div></form></section>
<section class="glass history"><div class="historybar"><div><h2>আপনার তৈরি করা লিংক</h2><div class="hint">নাম ও Short-Link দেখা যাবে — মূল URL গোপন থাকবে</div></div><input class="search" id="search" type="search" placeholder="নাম দিয়ে খুঁজুন…"><div class="stats"><span class="stat">মোট লিংক: <strong><?=count($links)?></strong></span><span class="stat">মোট ক্লিক: <strong><?=array_sum(array_map(fn($x)=>(int)($x['clicks']??0),$links))?></strong></span></div></div>
<?php if($loadError): ?><div class="notice error">লিংক লোড করা যায়নি। config.php-এর GitHub সেটিংস এবং data/links.json ফাইল পরীক্ষা করুন।</div><?php endif; ?>
<div class="list" id="linkList">
<?php if(!$links && !$loadError): ?><div class="empty"><div class="symbol">↗</div><strong>এখনও কোনো লিংক নেই</strong><p>প্রথম Short-Link তৈরি করলে সেটি এখানে দেখা যাবে।</p></div><?php endif; ?>
<?php foreach($links as $code=>$item): $su=shortUrl((string)$code); $expired=!empty($item['expires_at']) && strtotime((string)$item['expires_at']) < time(); ?>
<article class="linkrow" data-search="<?=h(strtolower((string)$code.' '.$su))?>"><div><div class="linkname"><span class="linkicon">↗</span><div style="min-width:0"><div class="linktitle"><?=h((string)$code)?></div><div class="short"><?=h($su)?></div></div></div><div class="meta"><span><?= (int)($item['clicks']??0) ?> ক্লিক</span><span><?=!empty($item['created_at'])?h(date('d M Y',strtotime((string)$item['created_at']))):'তারিখ নেই'?></span><?php if(!empty($item['expires_at'])): ?><span><?=$expired?'মেয়াদ শেষ':'মেয়াদ: '.h((string)$item['expires_at'])?></span><?php endif; ?></div></div><div class="actions"><button class="copy" type="button" onclick="copyText(<?=json_encode($su)?>,this)">⧉ কপি</button></div></article>
<?php endforeach; ?></div></section><footer class="footer">Short-Link · Clean links, simple sharing <span> · </span><a href="/admin" style="color:inherit">Admin</a></footer></main>
<script>
async function copyText(text,button){const old=button.textContent;try{await navigator.clipboard.writeText(text)}catch(e){const ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.select();document.execCommand('copy');ta.remove()}button.textContent='✓ কপি হয়েছে';setTimeout(()=>button.textContent=old,1500)}
document.getElementById('search').addEventListener('input',function(){const q=this.value.trim().toLowerCase();document.querySelectorAll('.linkrow').forEach(row=>row.classList.toggle('hidden',!row.dataset.search.includes(q)))});
</script></body></html>
