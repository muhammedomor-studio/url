<?php
declare(strict_types=1);
session_start();

/*
 * Short-Link URL Shortener
 * Configure all secrets in Render Environment Variables.
 */

$cfg = [
    'owner'  => getenv('GH_OWNER') ?: '',
    'repo'   => getenv('GH_REPO') ?: '',
    'token'  => getenv('GH_TOKEN') ?: '',
    'branch' => getenv('GH_BRANCH') ?: 'main',
    'data'   => getenv('GH_DATA_PATH') ?: 'data/links.json',
    'api'    => getenv('SHORTENER_API_KEY') ?: '',
    'admin'  => getenv('ADMIN_PASSWORD') ?: '',
    'base'   => rtrim(getenv('APP_BASE_URL') ?: '', '/'),
];

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function jsonReply(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function configured(): bool {
    global $cfg;
    return $cfg['owner'] !== '' && $cfg['repo'] !== '' &&
        $cfg['token'] !== '' && $cfg['api'] !== '' && $cfg['admin'] !== '';
}
function appBase(): string {
    global $cfg;
    if ($cfg['base'] !== '') return $cfg['base'];
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
        throw new RuntimeException('Set APP_BASE_URL in Render.');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . $host;
}
function githubRequest(string $method, string $path, ?array $body = null): array {
    global $cfg;
    $url = 'https://api.github.com/repos/' . rawurlencode($cfg['owner']) . '/' .
        rawurlencode($cfg['repo']) . '/' . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $cfg['token'],
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Short-Link-URL-Shortener',
            'Content-Type: application/json',
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('GitHub connection failed: ' . $error);
    $decoded = json_decode($raw, true);
    return ['status' => $status, 'data' => is_array($decoded) ? $decoded : []];
}
function githubPath(): string {
    global $cfg;
    return 'contents/' . implode('/', array_map('rawurlencode', explode('/', $cfg['data'])));
}
function loadLinks(): array {
    global $cfg;
    $r = githubRequest('GET', githubPath() . '?ref=' . rawurlencode($cfg['branch']));
    if ($r['status'] === 404) return ['links' => [], 'sha' => null];
    if ($r['status'] !== 200) throw new RuntimeException('Cannot read GitHub data file. Check path, branch and token permissions.');
    $content = base64_decode(str_replace("\n", '', (string)($r['data']['content'] ?? '')), true);
    if ($content === false) throw new RuntimeException('Invalid data file encoding.');
    $links = json_decode($content, true);
    if (!is_array($links)) throw new RuntimeException('links.json must contain a JSON object.');
    return ['links' => $links, 'sha' => $r['data']['sha'] ?? null];
}
function saveLinks(array $links, ?string $sha): void {
    global $cfg;
    $body = [
        'message' => 'Update Short-Link data',
        'branch' => $cfg['branch'],
        'content' => base64_encode(json_encode((object)$links,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ];
    if ($sha !== null) $body['sha'] = $sha;
    $r = githubRequest('PUT', githubPath(), $body);
    if (!in_array($r['status'], [200, 201], true)) {
        throw new RuntimeException(in_array($r['status'], [409, 422], true)
            ? 'GitHub update conflict; retry the request.' : 'GitHub save failed; check token permissions and branch.');
    }
}
function validUrl(string $url): bool {
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}
function validAlias(string $alias): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{3,32}$/', $alias);
}
function makeCode(array $links): string {
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($n = 0; $n < 15; $n++) {
        $code = '';
        for ($i = 0; $i < 7; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        if (!isset($links[$code])) return $code;
    }
    throw new RuntimeException('Could not generate a unique short code.');
}
function createLink(string $url, string $alias = '', string $expiry = ''): array {
    if (!validUrl($url)) throw new InvalidArgumentException('Enter a valid HTTP or HTTPS URL.');
    $expires = null;
    if ($expiry !== '') {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry);
        if (!$dt || $dt->format('Y-m-d') !== $expiry) throw new InvalidArgumentException('Expiry date must use YYYY-MM-DD.');
        if ($dt->setTime(23, 59, 59)->getTimestamp() < time()) throw new InvalidArgumentException('Expiry date must be today or later.');
        $expires = $dt->setTime(23, 59, 59)->format(DateTimeInterface::ATOM);
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $d = loadLinks();
        $links = $d['links'];
        $code = $alias !== '' ? $alias : makeCode($links);
        if ($alias !== '' && !validAlias($alias)) throw new InvalidArgumentException('Alias must be 3–32 letters, numbers, underscore or hyphen.');
        if (isset($links[$code])) throw new InvalidArgumentException('That alias/code is already taken.');
        $links[$code] = [
            'url' => $url,
            'created_at' => gmdate(DateTimeInterface::ATOM),
            'expires_at' => $expires,
            'clicks' => 0,
        ];
        try {
            saveLinks($links, $d['sha']);
            return ['code' => $code, 'url' => appBase() . '/' . rawurlencode($code)];
        } catch (RuntimeException $e) {
            if ($attempt === 2 || !str_contains($e->getMessage(), 'conflict')) throw $e;
        }
    }
    throw new RuntimeException('Please retry.');
}
function privateApiAllowed(): bool {
    global $cfg;
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    return $cfg['api'] !== '' && is_string($provided) && hash_equals($cfg['api'], $provided);
}
function adminLoggedIn(): bool { return !empty($_SESSION['admin_logged_in']); }
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfValid(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf']) && is_string($_POST['csrf']) &&
        hash_equals($_SESSION['csrf'], $_POST['csrf']);
}
function isExpired(array $item): bool {
    $expiry = $item['expires_at'] ?? null;
    return is_string($expiry) && $expiry !== '' && strtotime($expiry) !== false && strtotime($expiry) < time();
}

/* Configuration health is not exposed with secret details. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!configured()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Short-Link is not configured. Add required Environment Variables in Render.');
}

/* Public API: status and documentation. */
if ($path === '/api/v1/status') jsonReply(['success' => true, 'service' => 'Short-Link']);
if ($path === '/api/docs') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Short-Link API Docs</title><body style="font:16px system-ui;max-width:850px;margin:40px auto;padding:20px;color:#172554"><h1>Short-Link API Documentation</h1><p>Public endpoint: GET /api/v1/status</p><h2>Create a short link</h2><p>POST /api/v1/shorten</p><pre>{"url":"https://example.com","alias":"my-link","expires_at":"2027-12-31"}</pre><p>Header: X-API-Key: YOUR_SHORTENER_API_KEY</p><h2>Bulk create (up to 20)</h2><p>POST /api/v1/bulk</p><pre>{"urls":[{"url":"https://example.com"},{"url":"https://example.org","alias":"example-org"}]}</pre><h2>Statistics</h2><p>GET /api/v1/stats/CODE with X-API-Key</p><p>Keep API keys secret. GitHub API requests are subject to rate limits.</p><p><a href="/">Back to Short-Link</a></p></body></html>';
    exit;
}

/* Private API: single and bulk URL creation. */
if (in_array($path, ['/api/v1/shorten', '/api/v1/bulk'], true)) {
    if ($method !== 'POST') { header('Allow: POST'); jsonReply(['error' => 'Use POST.'], 405); }
    if (!privateApiAllowed()) jsonReply(['error' => 'Invalid API key.'], 401);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) jsonReply(['error' => 'Send a JSON object.'], 400);
    try {
        if ($path === '/api/v1/shorten') {
            if (!is_string($input['url'] ?? null)) jsonReply(['error' => 'url is required.'], 400);
            $made = createLink(trim($input['url']), trim((string)($input['alias'] ?? '')), trim((string)($input['expires_at'] ?? '')));
            jsonReply(['success' => true] + $made, 201);
        }
        $items = $input['urls'] ?? null;
        if (!is_array($items) || count($items) < 1 || count($items) > 20) jsonReply(['error' => 'Provide 1–20 URLs per bulk request.'], 400);
        $results = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['url'] ?? null)) {
                $results[] = ['success' => false, 'error' => 'Invalid item.']; continue;
            }
            try {
                $results[] = ['success' => true] + createLink(trim($item['url']), trim((string)($item['alias'] ?? '')), trim((string)($item['expires_at'] ?? '')));
            } catch (Throwable $e) {
                $results[] = ['success' => false, 'error' => $e->getMessage()];
            }
        }
        jsonReply(['success' => true, 'results' => $results], 201);
    } catch (InvalidArgumentException $e) {
        jsonReply(['error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        jsonReply(['error' => 'Could not process request. Check Render logs.'], 500);
    }
}

/* Private statistics endpoint. */
if (preg_match('#^/api/v1/stats/([A-Za-z0-9_-]{3,32})$#', $path, $m)) {
    if (!privateApiAllowed()) jsonReply(['error' => 'Invalid API key.'], 401);
    try {
        $d = loadLinks();
        if (!isset($d['links'][$m[1]])) jsonReply(['error' => 'Not found.'], 404);
        jsonReply(['success' => true, 'code' => $m[1], 'data' => $d['links'][$m[1]]]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        jsonReply(['error' => 'Could not read statistics.'], 500);
    }
}

/* Admin logout. */
if ($path === '/admin/logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin');
    exit;
}

/* Admin login. */
if ($path === '/admin' && $method === 'POST' && isset($_POST['login'])) {
    if (!csrfValid()) { http_response_code(403); exit('Invalid form token. Refresh and try again.'); }
    if (hash_equals($cfg['admin'], (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header('Location: /admin');
        exit;
    }
    $loginError = 'Incorrect password.';
}
if ($path === '/admin' && !adminLoggedIn()) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Short-Link Admin Login</title><body style="font:16px system-ui;background:#f2f6ff;padding:24px;color:#172554"><form method="post" style="max-width:420px;margin:60px auto;background:#ffffffdf;padding:28px;border:1px solid white;border-radius:22px;box-shadow:0 20px 60px #17255412"><h1>Short-Link</h1><p>Admin Dashboard Login</p>';
    if (!empty($loginError)) echo '<p style="color:#b91c1c">' . h($loginError) . '</p>';
    echo '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><input type="password" name="password" placeholder="Admin password" required style="width:100%;padding:12px;box-sizing:border-box;border:1px solid #dbe4f0;border-radius:10px"><button name="login" value="1" style="margin-top:14px;padding:12px;width:100%;border:0;border-radius:10px;background:#4355e8;color:white">Login</button></form></body></html>';
    exit;
}

/* Admin dashboard. */
if ($path === '/admin') {
    $notice = '';
    if ($method === 'POST' && isset($_POST['create'])) {
        if (!csrfValid()) { http_response_code(403); exit('Invalid form token.'); }
        try {
            $made = createLink(trim((string)($_POST['url'] ?? '')), trim((string)($_POST['alias'] ?? '')), trim((string)($_POST['expires_at'] ?? '')));
            $notice = 'Created: ' . $made['url'];
        } catch (Throwable $e) { $notice = 'Error: ' . $e->getMessage(); }
    }
    try { $d = loadLinks(); $links = $d['links']; }
    catch (Throwable $e) { error_log($e->getMessage()); $links = []; $notice = 'Unable to load links. Check Render Logs.'; }
    $clicks = array_sum(array_map(fn($v) => (int)($v['clicks'] ?? 0), $links));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Short-Link Admin</title><style>
    *{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at top left,#dbeafe,transparent 50%),#f7f9ff;font:15px system-ui;color:#172554;padding:20px}
    main{max-width:1050px;margin:auto}.card{background:#ffffffdc;border:1px solid white;border-radius:20px;padding:22px;margin:18px 0;box-shadow:0 12px 40px #1725540b}
    input,button{font:inherit;padding:12px;border:1px solid #dbe4f0;border-radius:10px}input{width:100%;margin:5px 0 12px}button{background:#4355e8;color:white;border:0;cursor:pointer}
    a{color:#3547ce;overflow-wrap:anywhere}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid #e2e8f0;overflow-wrap:anywhere}
    .stats{display:flex;gap:12px;flex-wrap:wrap}.stats div{background:#eef2ff;padding:15px;border-radius:12px}.muted{color:#64748b}
    </style><main><p><a href="/">← Website</a> · <a href="/api/docs">API Docs</a> · <a href="/admin/logout">Logout</a></p><h1>Short-Link</h1><p class="muted">Admin Dashboard</p>';
    if ($notice !== '') echo '<div class="card">' . h($notice) . '</div>';
    echo '<section class="stats"><div>Total links: <b>' . count($links) . '</b></div><div>Total clicks: <b>' . $clicks . '</b></div></section>
    <section class="card"><h2>Create a short link</h2><form method="post"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '"><label>Long URL</label><input type="url" name="url" required placeholder="https://example.com"><label>Custom alias (optional)</label><input name="alias" placeholder="my-link"><label>Expiry date (optional)</label><input type="date" name="expires_at"><button name="create" value="1">Create link</button></form></section>
    <section class="card"><h2>All links</h2><div class="tablewrap"><table><tr><th>Code</th><th>Destination</th><th>Clicks</th><th>Expiry</th><th>Status</th><th>QR</th></tr>';
    foreach ($links as $code => $item) {
        $short = appBase() . '/' . rawurlencode((string)$code);
        $expired = isExpired(is_array($item) ? $item : []);
        $url = is_array($item) ? (string)($item['url'] ?? '') : '';
        $count = (int)($item['clicks'] ?? 0);
        $expiry = (string)($item['expires_at'] ?? 'Never');
        $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' . rawurlencode($short);
        echo '<tr><td>' . h((string)$code) . '</td><td><a target="_blank" rel="noopener noreferrer" href="' . h($url) . '">' . h($url) . '</a><br><a href="' . h($short) . '">' . h($short) . '</a></td><td>' . $count . '</td><td>' . h($expiry ?: 'Never') . '</td><td>' . ($expired ? 'Expired' : 'Active') . '</td><td><a target="_blank" rel="noopener noreferrer" href="' . h($qr) . '">QR</a></td></tr>';
    }
    echo '</table></div></section></main></html>';
    exit;
}

/* Short-link redirect and click counter. */
if ($method === 'GET' && preg_match('#^/([A-Za-z0-9_-]{3,32})$#', $path, $m)) {
    $code = $m[1];
    try {
        $d = loadLinks();
        if (!isset($d['links'][$code]) || !is_array($d['links'][$code])) {
            http_response_code(404); exit('Short link not found.');
        }
        $item = $d['links'][$code];
        if (isExpired($item)) { http_response_code(410); exit('This short link has expired.'); }
        $target = (string)($item['url'] ?? '');
        if (!validUrl($target)) { http_response_code(404); exit('Invalid destination.'); }

        /* Best-effort click counting. High-traffic analytics should use a database. */
        $d['links'][$code]['clicks'] = (int)($item['clicks'] ?? 0) + 1;
        try { saveLinks($d['links'], $d['sha']); } catch (Throwable $e) { error_log('Click counter update failed: ' . $e->getMessage()); }

        header('Cache-Control: no-store');
        header('Location: ' . $target, true, 302);
        exit;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        http_response_code(503); exit('Temporarily unable to look up this link.');
    }
}

/* Home form submission: key is compared but never stored. */
if ($path === '/' && $method === 'POST') {
    $provided = (string)($_POST['api_key'] ?? '');
    if (!hash_equals($cfg['api'], $provided)) {
        $_SESSION['flash_error'] = 'Invalid API key.';
    } else {
        try {
            $_SESSION['flash_created'] = createLink(
                trim((string)($_POST['url'] ?? '')),
                trim((string)($_POST['alias'] ?? '')),
                trim((string)($_POST['expires_at'] ?? ''))
            );
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = $e instanceof InvalidArgumentException
                ? $e->getMessage() : 'Could not create link. Check Render logs.';
            error_log($e->getMessage());
        }
    }
    header('Location: /');
    exit;
}

/* Public web interface. */
if ($path !== '/' || $method !== 'GET') {
    http_response_code(404); exit('Not found.');
}
header('Content-Type: text/html; charset=utf-8');
$created = null;
$error = '';
if (isset($_SESSION['flash_created'])) {
    $created = $_SESSION['flash_created'];
    unset($_SESSION['flash_created']);
}
if (isset($_SESSION['flash_error'])) {
    $error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
if ($method === 'POST') { /* POST handler is intentionally handled below by the home form route in the next request. */ }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#f8fbff">
<title>Short-Link — URL Shortener</title>
<style>
:root{color-scheme:light;--ink:#172554;--muted:#64748b;--line:#dbe5f3;--blue:#4355e8}
*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:24px 15px;background:radial-gradient(ellipse at 10% 8%,#dbeafe 0,transparent 42%),radial-gradient(ellipse at 95% 92%,#e0e7ff 0,transparent 40%),#f8fbff;color:var(--ink);font-family:system-ui,-apple-system,"Noto Sans Bengali",sans-serif;display:flex;align-items:center;justify-content:center}
.wrap{width:100%;max-width:760px}.brand{text-align:center;margin-bottom:24px}.logo{display:inline-grid;place-items:center;width:58px;height:58px;border-radius:19px;background:linear-gradient(135deg,#60a5fa,#4338ca);color:white;font-size:29px;box-shadow:0 12px 28px #4f46e533}
h1{font-size:clamp(30px,7vw,44px);margin:12px 0 4px;letter-spacing:-1px}.brand p,.muted{color:var(--muted);line-height:1.7}.card{background:#ffffffd9;border:1px solid #ffffff;border-radius:25px;padding:clamp(20px,5vw,36px);box-shadow:0 22px 65px #17255412;backdrop-filter:blur(18px)}
label{display:block;margin:17px 0 8px;font-weight:650}input{display:block;width:100%;min-height:50px;padding:13px 14px;border:1px solid var(--line);border-radius:12px;background:#fff;color:var(--ink);font:inherit;outline:none}input:focus{border-color:var(--blue);box-shadow:0 0 0 3px #4f46e51c}
button,.btn{display:inline-flex;justify-content:center;align-items:center;gap:8px;min-height:49px;padding:12px 18px;border:0;border-radius:12px;background:linear-gradient(135deg,#60a5fa,#4338ca);color:white;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
form button{width:100%;margin-top:18px}.notice{padding:14px 16px;margin:16px 0;border-radius:13px;overflow-wrap:anywhere}.success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.failure{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}
.features{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:18px}.feature{padding:14px 8px;border:1px solid #e5eaf5;border-radius:14px;text-align:center;background:#ffffffa8}.feature b{display:block;font-size:14px}.feature span{font-size:12px;color:var(--muted)}
.small{font-size:13px}.result{margin-top:12px;overflow-wrap:anywhere}.result a{color:#3730a3;font-weight:700}.qr{width:140px;height:140px;display:block;margin:14px auto;border-radius:10px}footer{text-align:center;color:var(--muted);font-size:13px;margin:20px 0}footer a{color:#4338ca}
@media(max-width:480px){.features{grid-template-columns:1fr}.card{border-radius:20px}}
</style>
</head>
<body>
<main class="wrap">
<header class="brand"><div class="logo" aria-hidden="true">↗</div><h1>Short-Link</h1><p>Make long URLs short, simple and shareable.</p></header>
<section class="card">
<h2>Shorten your URL</h2>
<p class="muted">Paste your long link below. Optional: choose a custom alias and expiry date.</p>
<?php if ($created): ?>
<div class="notice success"><strong>Your short link is ready!</strong><div class="result"><a href="<?= h($created['url']) ?>"><?= h($created['url']) ?></a></div><button type="button" onclick="copyShort()">Copy link</button><input type="hidden" id="shortUrl" value="<?= h($created['url']) ?>"><img class="qr" alt="QR code" src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= rawurlencode($created['url']) ?>"></div>
<?php endif; ?>
<?php if ($error !== ''): ?><div class="notice failure"><?= h($error) ?></div><?php endif; ?>
<form method="post" action="/">
<label for="url">Long URL</label><input id="url" name="url" type="url" placeholder="https://example.com/a/long/page" required maxlength="2048">
<label for="alias">Custom alias (optional)</label><input id="alias" name="alias" pattern="[A-Za-z0-9_-]{3,32}" placeholder="my-link">
<label for="expiry">Expiry date (optional)</label><input id="expiry" name="expires_at" type="date">
<label for="key">Private API key</label><input id="key" name="api_key" type="password" autocomplete="off" placeholder="Enter your API key" required>
<button type="submit">✦ Shorten URL</button>
</form>
<div class="features"><div class="feature"><b>⚡ Fast</b><span>Short links in seconds</span></div><div class="feature"><b>🔐 Protected</b><span>Private API key</span></div><div class="feature"><b>📊 Stats</b><span>Click counter</span></div></div>
<p class="muted small">Your links are stored in your configured GitHub repository. GitHub API limits apply.</p>
</section>
<footer><a href="/api/docs">API Documentation</a> · <a href="/admin">Admin Dashboard</a><br>Short-Link</footer>
</main>
<script>
function copyShort(){const el=document.getElementById('shortUrl');if(!el)return;if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(el.value).then(()=>alert('Copied!')).catch(()=>fallbackCopy(el));}else fallbackCopy(el);}
function fallbackCopy(el){el.type='text';el.select();document.execCommand('copy');el.type='hidden';alert('Copy attempted.');}
</script>
</body>
</html>
