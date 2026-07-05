<?php

// ============================================================
// PINBOT ROUTER — Single File (metadata-only, no cache/disk writes)
// ============================================================

// ── CONFIG — Edit these 3 values only ───────────────────────
define('MAIN_API_URL',    'http://api.pinbot.shop');   // Main API base URL
define('MAIN_API_TOKEN',  'arc_95fb3e23-c6fb-47b3-8f18-2921310a028c'); // Your token for main API
define('ROUTER_BASE_URL', 'https://router-bal.onrender.com');   // This file's public URL (no trailing slash)
// ────────────────────────────────────────────────────────────

// ── Routing ──────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = rtrim($uri, '/');

// Strip subfolder prefix if this file isn't at domain root
$uri = '/' . trim(preg_replace('#^.*?(/topup|/callback)#', '$1', $uri), '/');

if ($method === 'POST' && $uri === '/topup') {
    handle_topup();
} elseif ($method === 'POST' && preg_match('#^/callback/(.+)$#', $uri, $m)) {
    handle_callback($m[1]);
} else {
    json_out(['status' => 'online', 'service' => 'Pinbot Router'], 200);
}

// ============================================================
// HANDLER: /topup
// ============================================================
function handle_topup()
{
    $headers    = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];

    $apiToken = trim(str_replace(['Bearer ', 'bearer '], '', $authHeader));
    if (empty($apiToken)) {
        $apiToken = trim($body['api_key'] ?? '');
    }

    if (empty($apiToken)) {
        json_out(['error' => 'Missing API token', 'code' => 'TOKEN_MISSING'], 401);
    }

    $orderid  = (string) ($body['orderid']  ?? '');
    $playerid = (string) ($body['playerid'] ?? '');
    $code     = trim($body['code'] ?? '');

    if (empty($orderid) || empty($playerid) || empty($code)) {
        json_out(['error' => 'orderid, playerid and code are required', 'code' => 'MISSING_FIELDS'], 400);
    }

    // Caller's original callback URL (the website's own webhook endpoint)
    $callerUrl = trim($body['url'] ?? '');

    // Metadata-only strategy: inject caller URL into metadata.callback so pinbot
    // echoes it back on the callback. No local cache/disk fallback is used.
    $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
    if (!empty($callerUrl)) {
        $metadata['callback'] = $callerUrl;
    }

    $payload = [
        'orderid'  => $orderid,
        'playerid' => $playerid,
        'code'     => $code,
        'url'      => ROUTER_BASE_URL . '/callback/' . $orderid,
        'qty'      => (int) ($body['qty'] ?? 1),
    ];

    foreach (['package', 'pacakge', 'username', 'password', 'autocode'] as $field) {
        if (isset($body[$field])) {
            $payload[$field] = $body[$field];
        }
    }

    if (!empty($metadata)) {
        $payload['metadata'] = $metadata;
    }

    $result = http_post(MAIN_API_URL . '/topup', $payload, MAIN_API_TOKEN);

    json_out($result['body'], $result['status']);
}

// ============================================================
// HANDLER: /callback/{orderid}
// ============================================================
function handle_callback(string $orderid)
{
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];

    // Metadata-only: caller URL must come from metadata.callback
    $callerUrl = $payload['metadata']['callback'] ?? '';

    // Convert status: failed -> error
    if (($payload['status'] ?? '') === 'failed') {
        $payload['status'] = 'error';
    }

    // Strip internal metadata.callback before forwarding
    if (isset($payload['metadata']['callback'])) {
        unset($payload['metadata']['callback']);
        if (empty($payload['metadata'])) {
            unset($payload['metadata']);
        }
    }

    // Flatten structured 'content' (e.g. batch results) into a readable string
    if (isset($payload['content']) && is_array($payload['content'])) {
        $payload['content'] = flatten_content($payload['content']);
    }

    if (empty($callerUrl)) {
        // No metadata.callback echoed back — nothing to forward to.
        json_out(['received' => true, 'warning' => 'no callback url in metadata'], 200);
    }

    $fwd = http_post($callerUrl, $payload);

    json_out(['received' => true], 200);
}

// ============================================================
// HELPERS
// ============================================================
function http_post(string $url, array $payload, string $token = ''): array
{
    $body    = json_encode($payload);
    $headers = ["Content-Type: application/json", "Accept: application/json"];
    if (!empty($token)) {
        $headers[] = "Authorization: {$token}";
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response   = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error      = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['body' => ['error' => 'cURL error: ' . $error, 'code' => 'UPSTREAM_UNREACHABLE'], 'status' => 502];
    }

    $decoded = json_decode($response, true);
    return ['body' => $decoded ?? [], 'status' => $statusCode ?: 502];
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function flatten_content($content): string
{
    if (isset($content['batch']) && is_array($content['batch'])) {
        $lines = [];
        foreach ($content['batch'] as $item) {
            $uc     = $item['uc']     ?? 'unknown';
            $detail = $item['detail'] ?? ($item['ok'] ?? false ? 'OK' : 'Failed');
            $lines[] = "{$uc}: {$detail}";
        }
        return implode(' | ', $lines);
    }

    return json_encode($content);
}
