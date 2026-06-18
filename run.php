<?php
// ============================================================
//  Cloudflare MX Record Updater — fpctextile.com
//  Sets the MX record to Microsoft 365 mail protection.
//  Run via cron: php /path/to/run.php >> /var/log/cf-mx.log 2>&1
// ============================================================

// ── Credentials ─────────────────────────────────────────────
define('CF_EMAIL',      'gdp2@fpctextile.com');
define('CF_API_KEY',    '1a3b85b73ec981e11b2ac353dca22afee4dbb');
// ────────────────────────────────────────────────────────────

// ── Target record ────────────────────────────────────────────
define('CF_DOMAIN',     'fpctextile.com');          // Zone root & record name
define('MX_VALUE',      'fpctextile-com.maiI.protection.outlook.com');
define('MX_PRIORITY',   10);                        // Standard MS365 priority
define('CF_TTL',        1);                         // 1 = auto TTL
// ────────────────────────────────────────────────────────────

define('LOG_FILE', __DIR__ . '/mx-update.log');
define('CF_API',   'https://api.cloudflare.com/client/v4');

// ── Helpers ──────────────────────────────────────────────────

function log_msg(string $level, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

function cf_request(string $method, string $endpoint, array $body = []): array {
    $ch = curl_init(CF_API . $endpoint);
    $headers = [
        'X-Auth-Email: ' . CF_EMAIL,
        'X-Auth-Key: '   . CF_API_KEY,
        'Content-Type: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);

    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        log_msg('ERROR', 'cURL error: ' . $err);
        exit(1);
    }

    $data = json_decode($response, true) ?? [];

    if (isset($data['success']) && !$data['success']) {
        $errors = implode(', ', array_column($data['errors'] ?? [], 'message'));
        log_msg('ERROR', 'Cloudflare API error: ' . $errors);
        exit(1);
    }

    return $data;
}

// ── Step 1: Auto-fetch Zone ID ───────────────────────────────

log_msg('INFO', 'Fetching zone ID for ' . CF_DOMAIN . '...');

$zones = cf_request('GET', '/zones?name=' . urlencode(CF_DOMAIN) . '&status=active');

if (empty($zones['result'])) {
    log_msg('ERROR', 'No active zone found for ' . CF_DOMAIN . '.');
    exit(1);
}

$zone_id = $zones['result'][0]['id'];
log_msg('INFO', 'Zone ID: ' . $zone_id);

// ── Step 2: Find existing MX record ──────────────────────────

$records = cf_request('GET', '/zones/' . $zone_id . '/dns_records?type=MX&name=' . urlencode(CF_DOMAIN));

// ── Step 3: Update or create ──────────────────────────────────

$payload = [
    'type'     => 'MX',
    'name'     => CF_DOMAIN,
    'content'  => MX_VALUE,
    'priority' => MX_PRIORITY,
    'ttl'      => CF_TTL,
];

if (!empty($records['result'])) {
    // Update first existing MX record
    $record    = $records['result'][0];
    $record_id = $record['id'];
    $old_value = $record['content'];

    if ($old_value === MX_VALUE && (int)$record['priority'] === MX_PRIORITY) {
        log_msg('INFO', 'MX record already set to correct value. Nothing to do.');
        exit(0);
    }

    log_msg('INFO', 'Updating MX: "' . $old_value . '" → "' . MX_VALUE . '"');
    $result = cf_request('PUT', '/zones/' . $zone_id . '/dns_records/' . $record_id, $payload);
} else {
    // No MX record exists — create one
    log_msg('INFO', 'No MX record found. Creating new record...');
    $result = cf_request('POST', '/zones/' . $zone_id . '/dns_records', $payload);
}

if (!empty($result['success'])) {
    log_msg('INFO', 'MX record updated successfully → ' . MX_VALUE);
} else {
    $errors = implode(', ', array_column($result['errors'] ?? [], 'message'));
    log_msg('ERROR', 'Failed: ' . $errors);
    exit(1);
}
