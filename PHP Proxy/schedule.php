<?php
/**
 * F3 Schedule Proxy — Standalone PHP Version
 * ============================================================
 * Use this if your site runs on plain PHP hosting (DreamHost,
 * Bluehost, SiteGround, HostGator, etc.) but does NOT use WordPress.
 *
 * SETUP INSTRUCTIONS
 * ============================================================
 * 1. Set your F3 Nation bearer token in the CONFIG section below.
 *
 * 2. Upload this file to your web server. Put it somewhere
 *    logical, for example:
 *    https://yoursite.com/f3-proxy/schedule.php
 *
 * 3. In your schedule widget, set:
 *    PROXY_URL: 'https://yoursite.com/f3-proxy'
 *    REGION_ORG_ID: your region's orgId number
 *
 *    The widget will call:
 *    https://yoursite.com/f3-proxy/schedule.php?regionOrgId=XXXXX
 *
 *    Wait — the widget calls /region-schedule not /schedule.php
 *    To make the URL clean, either:
 *    A) Rename this file to region-schedule.php, OR
 *    B) Add this line to your .htaccess file:
 *       RewriteRule ^f3/v1/region-schedule$ /f3-proxy/schedule.php [QSA,L]
 * ============================================================
 */

// ── CONFIG — only change this section ────────────────────────────────────
define('F3_BEARER_TOKEN', 'f3_YOUR_TOKEN_HERE'); // ← paste your F3 Nation token
define('F3_CACHE_MINUTES', 30);                  // how long to cache results
define('F3_RATE_LIMIT', 60);                     // max requests per hour per region
// ── END CONFIG ────────────────────────────────────────────────────────────

// Send CORS headers so the widget can call this from any domain
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=' . (F3_CACHE_MINUTES * 60));

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get and validate regionOrgId parameter
$region_id = isset($_GET['regionOrgId']) ? intval($_GET['regionOrgId']) : 0;
if (!$region_id) {
    http_response_code(400);
    echo json_encode(['error' => 'regionOrgId parameter is required']);
    exit;
}

// Check token is configured
if (F3_BEARER_TOKEN === 'f3_YOUR_TOKEN_HERE') {
    http_response_code(503);
    echo json_encode(['error' => 'Proxy not configured — set F3_BEARER_TOKEN in schedule.php']);
    exit;
}

// ── Simple file-based rate limiting ──────────────────────────────────────
// Stores request counts in /tmp — works on most shared hosts
$rate_file  = sys_get_temp_dir() . '/f3_rate_' . $region_id . '.json';
$rate_data  = file_exists($rate_file) ? json_decode(file_get_contents($rate_file), true) : [];
$hour_key   = date('Y-m-d-H');
$rate_count = ($rate_data['hour'] === $hour_key) ? ($rate_data['count'] ?? 0) : 0;

if ($rate_count > F3_RATE_LIMIT) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded — try again later']);
    exit;
}

// Update rate count
file_put_contents($rate_file, json_encode(['hour' => $hour_key, 'count' => $rate_count + 1]));

// ── Simple file-based cache ───────────────────────────────────────────────
$cache_file = sys_get_temp_dir() . '/f3_cache_' . $region_id . '.json';
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < (F3_CACHE_MINUTES * 60)) {
    // Serve from cache
    echo file_get_contents($cache_file);
    exit;
}

// ── Small helper: GET a URL from the F3 Nation API ───────────────────────
// Returns [body_string, http_code]. Uses cURL when available and falls back
// to file_get_contents, mirroring what the main request below does.
function f3_api_get(string $url): array {
    $headers = [
        'Authorization: Bearer ' . F3_BEARER_TOKEN,
        'client: f3-schedule-proxy',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return $err ? ['', 0] : [$body, $code];
    }

    $context = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => implode("
", $headers) . "
",
        'timeout' => 15,
    ]]);
    $body = @file_get_contents($url, false, $context);
    return $body === false ? ['', 0] : [$body, 200];
}

// ── One-off closures and time changes ────────────────────────────────────
// When a region closes an AO for a day in Slack, F3 Nation records that on
// the event instance as seriesException = 'closed' — but the event stays
// ACTIVE, and the calendar-home-schedule endpoint below does NOT return the
// seriesException field. So the schedule feed alone cannot tell a closed
// workout from a live one, and the widget would advertise a workout that is
// not happening.
//
// The event-instance list endpoint DOES return seriesException (and the
// reason, in meta.series_exception_reason), so we read it here and match the
// two lists together by event id.
//
// Returns [ event_id => ['status' => 'closed', 'reason' => '...'] ].
// Returns whatever it collected on failure — a schedule without closure
// flags still beats no schedule at all.
function f3_fetch_exceptions(int $region_id, string $start_date): array {
    $page_size = 100;   // the API caps page size at 100
    $max_pages = 6;     // safety stop — far more than any region needs
    $map       = [];

    for ($i = 0; $i < $max_pages; $i++) {
        $url = 'https://api.f3nation.com/v1/event-instance'
             . '?regionOrgId=' . $region_id
             . '&startDate=' . $start_date
             . '&pageIndex=' . $i
             . '&pageSize=' . $page_size;

        [$body, $code] = f3_api_get($url);
        if ($code !== 200) {
            break;
        }

        $rows = json_decode($body, true)['eventInstances'] ?? [];
        foreach ($rows as $row) {
            if (empty($row['seriesException'])) {
                continue;   // a normal, running workout
            }
            $map[$row['id']] = [
                'status' => $row['seriesException'],
                'reason' => $row['meta']['series_exception_reason'] ?? '',
            ];
        }

        if (count($rows) < $page_size) {
            break;   // ran out of instances
        }
    }

    return $map;
}

// ── Fetch from F3 Nation API ──────────────────────────────────────────────
$today   = date('Y-m-d');
$api_url = 'https://api.f3nation.com/v1/event-instance/calendar-home-schedule'
         . '?regionOrgId=' . $region_id
         . '&userId=1'
         . '&startDate=' . $today
         . '&limit=150';

// Use cURL if available, fall back to file_get_contents
if (function_exists('curl_init')) {
    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . F3_BEARER_TOKEN,
            'client: f3-schedule-proxy',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not reach F3 Nation API: ' . $err]);
        exit;
    }
} else {
    // Fallback: file_get_contents with stream context
    $context = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Bearer " . F3_BEARER_TOKEN . "\r\nclient: f3-schedule-proxy\r\n",
        'timeout' => 15,
    ]]);
    $body = @file_get_contents($api_url, false, $context);
    $code = 200;
    if ($body === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not reach F3 Nation API']);
        exit;
    }
}

if ($code !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'F3 Nation API returned error ' . $code]);
    exit;
}

// ── Stamp on the one-off closures before caching ─────────────────────────
$payload = json_decode($body, true);
if (is_array($payload) && !empty($payload['events'])) {
    $exceptions = f3_fetch_exceptions($region_id, $today);
    foreach ($payload['events'] as &$ev) {
        $id = $ev['id'] ?? null;
        $ev['seriesException']       = $exceptions[$id]['status'] ?? null;
        $ev['seriesExceptionReason'] = $exceptions[$id]['reason'] ?? '';
    }
    unset($ev);
    $body = json_encode($payload);
}

// Cache the response and return it
file_put_contents($cache_file, $body);
echo $body;
