<?php
/** PayMongo (GCash) helpers. Enabled only when PAYMONGO_SECRET_KEY is set; test keys (sk_test_...) move no real money. */

function paymongo_secret(): string { return trim((string)getenv('PAYMONGO_SECRET_KEY')); }
function paymongo_enabled(): bool { return paymongo_secret() !== ''; }

/** Public base URL used for success/cancel redirects (MBUS_BASE_URL wins, otherwise the current request). */
function paymongo_base_url(): string
{
    $env = rtrim(trim((string)getenv('MBUS_BASE_URL')), '/');
    if ($env !== '') return $env;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Call the PayMongo API; throws RuntimeException with a readable message on failure. */
function paymongo_call(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init('https://api.paymongo.com/v1' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ' . base64_encode(paymongo_secret() . ':')],
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Could not reach PayMongo. Please try again.');
    $json = json_decode($raw, true);
    if ($code >= 400 || !is_array($json)) {
        $detail = is_array($json) ? ($json['errors'][0]['detail'] ?? '') : '';
        error_log('PayMongo ' . $method . ' ' . $path . ' -> ' . $code . ' ' . substr((string)$raw, 0, 300));
        throw new RuntimeException($detail !== '' ? 'PayMongo: ' . $detail : 'PayMongo could not process this request.');
    }
    return $json;
}

/** Create a GCash-only hosted checkout. $items = [['name'=>, 'amount'=>centavos], ...]. Returns [id, checkout_url]. */
function paymongo_create_gcash_checkout(array $items, string $description, string $reference, string $successUrl, string $cancelUrl, array $metadata): array
{
    $lineItems = [];
    foreach ($items as $it) {
        $lineItems[] = ['currency' => 'PHP', 'amount' => (int)$it['amount'], 'name' => substr((string)$it['name'], 0, 100), 'quantity' => 1];
    }
    $res = paymongo_call('POST', '/checkout_sessions', ['data' => ['attributes' => [
        'send_email_receipt' => false, 'show_description' => true, 'show_line_items' => true,
        'description' => $description, 'reference_number' => $reference,
        'line_items' => $lineItems, 'payment_method_types' => ['gcash'],
        'success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'metadata' => $metadata,
    ]]]);
    $id = $res['data']['id'] ?? ''; $url = $res['data']['attributes']['checkout_url'] ?? '';
    if ($id === '' || $url === '') throw new RuntimeException('PayMongo did not return a checkout link.');
    return ['id' => $id, 'checkout_url' => $url];
}

/** Returns the PayMongo payment id when the session has a paid payment, otherwise null. */
function paymongo_session_paid(string $sessionId): ?string
{
    $res = paymongo_call('GET', '/checkout_sessions/' . rawurlencode($sessionId));
    $attrs = $res['data']['attributes'] ?? [];
    foreach (($attrs['payments'] ?? []) as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') return (string)($p['id'] ?? $sessionId);
    }
    if (($attrs['payment_intent']['attributes']['status'] ?? '') === 'succeeded') return $sessionId;
    return null;
}

/** Best effort: stop an open checkout from accepting payment (e.g. the reservation was cancelled). */
function paymongo_expire_session(string $sessionId): void
{
    try { paymongo_call('POST', '/checkout_sessions/' . rawurlencode($sessionId) . '/expire'); } catch (Throwable $e) { /* already expired or paid */ }
}

/** Verify the Paymongo-Signature header: HMAC-SHA256 of "<t>.<raw body>" with the webhook secret (te = test, li = live). */
function paymongo_verify_signature(string $rawBody, string $header, string $secret): bool
{
    if ($secret === '' || $header === '') return false;
    $parts = [];
    foreach (explode(',', $header) as $kv) { $kv = explode('=', trim($kv), 2); if (count($kv) === 2) $parts[$kv[0]] = $kv[1]; }
    if (empty($parts['t'])) return false;
    $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawBody, $secret);
    foreach (['te', 'li'] as $k) {
        if (!empty($parts[$k]) && hash_equals($expected, $parts[$k])) return true;
    }
    return false;
}
