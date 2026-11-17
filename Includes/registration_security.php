<?php

/** Return true only when one of the supported verification-email transports is usable. */
function mbus_registration_mail_is_configured(): bool
{
    $smtpUsername = trim((string)getenv('SMTP_USERNAME'));
    $smtpPassword = trim((string)getenv('SMTP_PASSWORD'));
    $brevoKey = trim((string)getenv('BREVO_API_KEY'));
    $mailFrom = trim((string)(getenv('MAIL_FROM_EMAIL') ?: getenv('SMTP_FROM') ?: $smtpUsername));
    $relayUrl = trim((string)getenv('MAIL_RELAY_URL'));
    $relaySecret = trim((string)getenv('MAIL_RELAY_SECRET'));
    $relayParts = $relayUrl !== '' ? parse_url($relayUrl) : false;

    $smtpReady = $smtpUsername !== '' && $smtpPassword !== '';
    $curlReady = function_exists('curl_init');
    $brevoReady = $curlReady && $brevoKey !== '' && $mailFrom !== '';
    $relayReady = $curlReady && $relaySecret !== '' && filter_var($relayUrl, FILTER_VALIDATE_URL) !== false
        && is_array($relayParts) && strtolower((string)($relayParts['scheme'] ?? '')) === 'https'
        && !empty($relayParts['host']);

    return $smtpReady || $brevoReady || $relayReady;
}

/**
 * Atomically check all of a request's limits over sliding windows, then record every bucket
 * only if the request is allowed. Advisory locks prevent concurrent requests from racing
 * past a quota; HMAC bucket keys keep raw email addresses out of the database.
 * Each check is [bucket, limit, window seconds, cooldown seconds, cooldown reason, limit reason].
 */
function mbus_registration_consume_limits($conn, array $checks): ?string
{
    $pepper = trim((string)getenv('SUPABASE_DB_PASSWORD'));
    if ($pepper === '' || !$checks || $conn->pdo->inTransaction()) {
        return 'unavailable';
    }

    $prepared = [];
    $lockKeys = [];
    foreach ($checks as $check) {
        if (count($check) !== 6) return 'unavailable';
        [$bucket, $limit, $windowSeconds, $cooldownSeconds, $cooldownReason, $limitReason] = $check;
        if (!is_string($bucket) || $bucket === '' || $limit < 1 || $windowSeconds < 1 || $cooldownSeconds < 0) {
            return 'unavailable';
        }
        $bucketHash = hash_hmac('sha256', $bucket, $pepper);
        $prepared[] = [$bucketHash, (int)$limit, (int)$windowSeconds, (int)$cooldownSeconds, (string)$cooldownReason, (string)$limitReason];
        $lockKeys[$bucketHash] = true;
    }

    $pdo = $conn->pdo;
    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))');
        $hashes = array_keys($lockKeys);
        sort($hashes, SORT_STRING);
        foreach ($hashes as $hash) {
            $lock->execute([$hash]);
        }

        $count = $pdo->prepare(
            "SELECT COUNT(*) AS event_count,
                    CASE WHEN MAX(created_at) > clock_timestamp() - (CAST(? AS INTEGER) * INTERVAL '1 second') THEN 1 ELSE 0 END AS cooldown_active
             FROM registration_rate_limit_events
             WHERE bucket_hash = ? AND created_at > clock_timestamp() - (CAST(? AS INTEGER) * INTERVAL '1 second')"
        );
        foreach ($prepared as [$bucketHash, $limit, $windowSeconds, $cooldownSeconds, $cooldownReason, $limitReason]) {
            $count->execute([$cooldownSeconds, $bucketHash, $windowSeconds]);
            $row = $count->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                return 'unavailable';
            }
            if ((int)$row['event_count'] >= $limit) {
                $pdo->rollBack();
                return $limitReason;
            }
            if ((int)$row['cooldown_active'] === 1) {
                $pdo->rollBack();
                return $cooldownReason;
            }
        }

        $insert = $pdo->prepare('INSERT INTO registration_rate_limit_events (bucket_hash, created_at) VALUES (?, clock_timestamp())');
        foreach (array_keys($lockKeys) as $hash) {
            $insert->execute([$hash]);
        }

        if (random_int(1, 100) === 1) {
            $pdo->exec("DELETE FROM registration_rate_limit_events WHERE created_at < clock_timestamp() - INTERVAL '2 days'");
        }

        $pdo->commit();
        return null;
    } catch (Throwable $rateLimitError) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Registration rate-limit check failed: ' . $rateLimitError->getMessage());
        return 'unavailable';
    }
}

/** Five accepted verification-code delivery requests/email/hour, 60 seconds apart, plus a 120/hour service cap. */
function mbus_registration_allow_code_send($conn, string $email): ?string
{
    $normalizedEmail = strtolower(trim($email));
    return mbus_registration_consume_limits($conn, [
        ['registration-code:email:' . $normalizedEmail, 5, 3600, 60, 'email_cooldown', 'email_limit'],
        ['registration-code:global', 120, 3600, 0, '', 'limit'],
    ]);
}

/** Protect large driver/conductor application submissions with per-email and shared caps. */
function mbus_registration_allow_application($conn, string $email): ?string
{
    $normalizedEmail = strtolower(trim($email));
    return mbus_registration_consume_limits($conn, [
        ['registration-application:email:' . $normalizedEmail, 3, 3600, 60, 'application_email_cooldown', 'application_email_limit'],
        ['registration-application:global', 30, 3600, 0, '', 'application_limit'],
    ]);
}

function mbus_registration_limit_message(?string $reason, bool $emailCode = false): string
{
    if ($reason === 'email_cooldown') {
        return 'Please wait at least 60 seconds before requesting another verification code.';
    }
    if ($reason === 'email_limit') {
        return 'Too many verification-code requests for this email. Please try again later.';
    }
    if ($reason === 'application_email_cooldown') {
        return 'Please wait at least 60 seconds before submitting another application with this email.';
    }
    if ($reason === 'application_email_limit') {
        return 'Too many applications for this email. Please try again later.';
    }
    if ($reason === 'limit') {
        return $emailCode
            ? 'Too many verification-code requests. Please wait and try again later.'
            : 'Too many sign-up attempts. Please try again later.';
    }
    if ($reason === 'application_limit') {
        return 'Too many applications are being submitted. Please try again later.';
    }
    return 'Registration security checks are temporarily unavailable. Please try again later.';
}
