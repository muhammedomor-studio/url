
<?php
declare(strict_types=1);

session_start();

$owner = getenv('GH_OWNER') ?: '';
$repo = getenv('GH_REPO') ?: '';
$token = getenv('GH_TOKEN') ?: '';
$branch = getenv('GH_BRANCH') ?: 'main';
$dataPath = getenv('GH_DATA_PATH') ?: 'data/links.json';
$apiKey = getenv('SHORTENER_API_KEY') ?: '';
$baseUrl = rtrim(getenv('APP_BASE_URL') ?: '', '/');

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function configReady(): bool {
    global $owner, $repo, $token, $apiKey;
    return $owner !== '' && $repo !== '' && $token !== '' && $apiKey !== '';
}

function githubRequest(string $method, string $path, ?array $body = null): array {
    global $owner, $repo, $token;

    $url = 'https://api.github.com/repos/' .
        rawurlencode($owner) . '/' .
        rawurlencode($repo) . '/' . $path;

    $ch = curl_init($url);

    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: Simple-URL-Shortener',
        'Content-Type: application/json'
    ];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
    ]);

    if ($body !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($body, JSON_UNESCAPED_SLASHES)
        );
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('GitHub connection failed: ' . $error);
    }

    $decoded = json_decode($response, true);

    return [
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function githubFilePath(): string {
    global $dataPath;

    return 'contents/' . implode(
        '/',
        array_map('rawurlencode', explode('/', $dataPath))
    );
}

function loadLinks(): array {
    global $branch;

    $result = githubRequest(
        'GET',
        githubFilePath() . '?ref=' . rawurlencode($branch)
    );

    if ($result['status'] !== 200) {
        throw new RuntimeException(
            'GitHub data file could not be read. Check data/links.json and repository permissions.'
        );
    }

    $file = $result['data'];
    $content = $file['content'] ?? '';
    $content = str_replace("\n", '', $content);
    $decoded = base64_decode($content, true);

    if ($decoded === false) {
        throw new RuntimeException('Invalid Base64 data in GitHub file.');
    }

    $links = json_decode($decoded, true);

    if (!is_array($links)) {
        throw new RuntimeException('The links.json file must contain a JSON object.');
    }

    return [
        'links' => $links,
        'sha' => $file['sha'] ?? ''
    ];
}

function saveLinks(array $links, string $sha): void {
    global $branch, $dataPath;

    $path = githubFilePath();

    $result = githubRequest('PUT', $path, [
        'message' => 'Add shortened URL',
        'branch' => $branch,
        'sha' => $sha,
        'content' => base64_encode(
            json_encode(
                (object) $links,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        )
    ]);

    if (!in_array($result['status'], [200, 201], true)) {
        if ($result['status'] === 409 || $result['status'] === 422) {
            throw new RuntimeException(
                'GitHub update conflict. Please try again.'
            );
        }

        throw new RuntimeException(
            'Could not save links to GitHub. Check token permissions and branch.'
        );
    }
}

function validTargetUrl(string $url): bool {
    if (strlen($url) > 2048) {
        return false;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true);
}

function makeCode(array $links): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        if (!array_key_exists($code, $links)) {
            return $code;
        }
    }

    throw new RuntimeException('Could not create a unique short code.');
}

function currentBaseUrl(): string {
    global $baseUrl;

    if ($baseUrl !== '') {
        return $baseUrl;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';

    // Only allow a normal hostname or hostname with a port.
    if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
        throw new RuntimeException('Set APP_BASE_URL in Render environment variables.');
    }

    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    return ($https ? 'https://' : 'http://') . $host;
}

function createShortLink(string $url): array {
    if (!configReady()) {
        throw new RuntimeException('Missing required Render environment variables.');
    }

    if (!validTargetUrl($url)) {
        throw new InvalidArgumentException('Enter a valid HTTP or HTTPS URL.');
    }

    // Retry a small number of times if the repository changes concurrently.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $data = loadLinks();
        $links = $data['links'];
        $sha = $data['sha'];

        $code = makeCode($links);
        $links[$code] = $url;

        try {
            saveLinks($links, $sha);
            return [
                'code' => $code,
                'url' => currentBaseUrl() . '/' . $code,
                'target' => $url
            ];
        } catch (RuntimeException $exception) {
            if ($attempt === 2 ||
                !str_contains($exception->getMessage(), 'conflict')) {
                throw $exception;
            }
        }
    }

    throw new RuntimeException('Please try again.');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// JSON API: POST /api/shorten
if ($path === '/api/shorten') {
    if ($method !== 'POST') {
        header('Allow: POST');
        jsonResponse(['error' => 'Use POST for this endpoint.'], 405);
    }

    if (!configReady()) {
        jsonResponse(['error' => 'Service is not configured.'], 503);
    }

    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (!hash_equals($apiKey, $providedKey)) {
        jsonResponse(['error' => 'Invalid API key.'], 401);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input) || !isset($input['url']) || !is_string($input['url'])) {
        jsonResponse(['error' => 'Send JSON containing a url field.'], 400);
    }

    try {
        jsonResponse(['success' => true] + createShortLink(trim($input['url'])), 201);
    } catch (InvalidArgumentException $exception) {
        jsonResponse(['error' => $exception->getMessage()], 400);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        jsonResponse(['error' => 'Could not create link. Check server configuration.'], 500);
    }
}

// Short-link redirect: GET /Ab3xY7
if ($method === 'GET' && preg_match('#^/([a-zA-Z0-9]{6})$#', $path, $match)) {
    try {
        if (!configReady()) {
            http_response_code(503);
            exit('Service is not configured.');
        }

        $data = loadLinks();
        $target = $data['links'][$match[1]] ?? null;

        if (!is_string($target) || !validTargetUrl($target)) {
            http_response_code(404);
            exit('Short link not found.');
        }

        header('Cache-Control: no-store');
        header('Location: ' . $target, true, 302);
        exit;
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        http_response_code(503);
        exit('Temporarily unable to look up this link.');
    }
}

$message = '';
$error = '';
$created = null;

if ($method === 'POST' && $path === '/') {
    $sessionKey = $_POST['api_key'] ?? '';

    if (!is_string($sessionKey) || !configReady() ||
        !hash_equals($apiKey, $sessionKey)) {
        $error = 'API key সঠিক নয়। Render-এর SHORTENER_API_KEY ব্যবহার করুন।';
    } else {
        $inputUrl = trim((string) ($_POST['url'] ?? ''));

        try {
            $created = createShortLink($inputUrl);
            $message = 'শর্ট লিংক সফলভাবে তৈরি হয়েছে!';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'লিংক তৈরি করা যায়নি। Render Logs এবং Environment Variables পরীক্ষা করুন।';
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#f8fafc">
<title>LinkMini — URL Shortener</title>
<style>
:root {
    color-scheme: light;
    --ink: #172033;
    --muted: #65718a;
    --line: #e4e9f2;
    --blue: #4f46e5;
    --bg: #f6f8fc;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100vh;
    padding: 24px 15px;
    background:
        radial-gradient(ellipse at 15% 5%, #e0e7ff 0, transparent 40%),
        radial-gradient(ellipse at 95% 90%, #dbeafe 0, transparent 38%),
        var(--bg);
    color: var(--ink);
    font-family: system-ui, -apple-system, "Noto Sans Bengali", sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
}
.wrap { width: 100%; max-width: 680px; }
.brand { text-align: center; margin-bottom: 24px; }
.logo {
    display: inline-grid; place-items: center;
    width: 56px; height: 56px; border-radius: 18px;
    background: linear-gradient(135deg, #6366f1, #4338ca);
    color: white; font-size: 27px;
    box-shadow: 0 12px 28px #4f46e533;
}
h1 { margin: 12px 0 6px; font-size: clamp(27px, 6vw, 38px); }
p { line-height: 1.7; }
.brand p, .muted { color: var(--muted); }
.card {
    background: #ffffffd9;
    border: 1px solid #ffffff;
    border-radius: 24px;
    padding: clamp(20px, 5vw, 34px);
    box-shadow: 0 20px 65px #1e293b12;
    backdrop-filter: blur(18px);
}
label { display: block; margin: 18px 0 8px; font-weight: 650; }
input {
    display: block; width: 100%; min-height: 50px;
    padding: 13px 14px; border: 1px solid var(--line);
    border-radius: 12px; background: white; color: var(--ink);
    font: inherit; outline: none;
}
input:focus { border-color: var(--blue); box-shadow: 0 0 0 3px #4f46e51c; }
button {
    width: 100%; min-height: 51px; margin-top: 18px;
    border: 0; border-radius: 12px; color: white;
    background: linear-gradient(135deg, #6366f1, #4338ca);
    font: inherit; font-weight: 700; cursor: pointer;
}
.notice {
    padding: 13px 15px; margin: 16px 0; border-radius: 12px;
    overflow-wrap: anywhere;
}
.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.failure { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; }
.result-link { display: block; margin-top: 8px; font-weight: 700; }
.result-link a { color: #4338ca; overflow-wrap: anywhere; }
.small { font-size: 13px; }
footer { text-align: center; color: var(--muted); font-size: 13px; margin: 20px 0; }
</style>
</head>
<body>
<main class="wrap">
    <header class="brand">
        <div class="logo" aria-hidden="true">↗</div>
        <h1>LinkMini</h1>
        <p>লম্বা লিংক ছোট করুন, সহজে শেয়ার করুন।</p>
    </header>

    <section class="card">
        <h2>আপনার শর্ট লিংক তৈরি করুন</h2>
        <p class="muted">মূল URL এবং আপনার API key দিন। API key কখনো অন্য কারও সঙ্গে শেয়ার করবেন না।</p>

        <?php if ($message !== ''): ?>
            <div class="notice success">
                <?= e($message) ?>
                <?php if ($created): ?>
                    <div class="result-link">
                        <a href="<?= e($created['url']) ?>"><?= e($created['url']) ?></a>
                    </div>
                    <p class="small">মূল URL: <?= e($created['target']) ?></p>
                    <button type="button" onclick="copyLink()">লিংক কপি করুন</button>
                    <input type="hidden" id="shortLink" value="<?= e($created['url']) ?>">
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice failure"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/">
            <label for="url">দীর্ঘ URL</label>
            <input id="url" name="url" type="url" placeholder="https://example.com/your/long/link" required maxlength="2048">

            <label for="api_key">আপনার API Key</label>
            <input id="api_key" name="api_key" type="password" placeholder="Render-এর SHORTENER_API_KEY" required autocomplete="off">

            <button type="submit">✦ Shorten URL</button>
        </form>

        <p class="muted small">
            লিংক GitHub repository-তে সংরক্ষিত হয়। এই প্রাথমিক সংস্করণে প্রতিটি নতুন লিংক GitHub-এ একটি commit তৈরি করবে।
        </p>
    </section>

    <footer>LinkMini · Powered by GitHub API and Render</footer>
</main>
<script>
function copyLink() {
    const field = document.getElementById('shortLink');
    if (!field) return;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(field.value)
            .then(() => alert('লিংক কপি হয়েছে!'))
            .catch(() => fallbackCopy(field));
    } else {
        fallbackCopy(field);
    }
}
function fallbackCopy(field) {
    field.type = 'text';
    field.select();
    document.execCommand('copy');
    field.type = 'hidden';
    alert('লিংক কপি করার চেষ্টা করা হয়েছে।');
}
</script>
</body>
</html>
