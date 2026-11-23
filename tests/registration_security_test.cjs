/* Security regression coverage for commuter signup email verification. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const registration = read('register.php');
assert.match(registration, /mbus_check_email\(\$email\)/,
    'signup must continue to validate and normalize the submitted email server-side');
assert.match(registration, /mbus_registration_mail_is_configured\(\)/,
    'commuter signup should accept any supported configured mail transport');
assert.match(registration, /mbus_registration_allow_code_send\(\$conn, \$email\)/,
    'commuter code sends must pass shared rate limits before creating the account');
assert.match(registration, /registration_code_attempts\) VALUES \(\?, \?, \?, \?, 'Commuter', \?, \?, 0\)/,
    'new commuter codes must start with a persistent zero-attempt counter');
assert.match(registration, /mbus_send_registration_code\(\$email, \$registration_code, \$full_name\)/,
    'a commuter must receive a code before the account can be used');
assert.match(registration, /aria-describedby="email-verification-hint"/,
    'the email input must expose the verification guidance to assistive technology');
assert.match(registration, /6-digit code to this address[\s\S]*code expires in 15 minutes/,
    'the signup form must explain the verification code and expiry before submission');
assert.match(registration, /mbus_registration_allow_application\(\$conn, \$email\)/,
    'admin-reviewed applications also need a server-side submission throttle');
assert.ok(registration.indexOf('mbus_registration_allow_application($conn, $email)') < registration.indexOf("mbus_application_file('license_document'"),
    'application throttling must run before documents are read and stored');
assert.doesNotMatch(registration, /SMTP_USERNAME.*SMTP_PASSWORD.*Email verification is not configured/s,
    'signup must not reject a configured Brevo or mail-relay transport just because SMTP is absent');

const verification = read('verify_registration.php');
for (const expression of [
    /preg_match\('\/\^\\d\{6\}\$\//,
    /hash_equals\(\(string\)\$user\['registration_code'\], \$code\)/,
    /registration_code_attempts<5/,
    /Too many incorrect attempts/,
    /mbus_registration_allow_code_send\(\$conn, \$email\)/,
    /registration_code_expiry>NOW\(\)/,
    /registration_code_attempts=0/,
    /mbus_reset_mask_email\(\$email\)/,
    /You can resend in/,
]) assert.match(verification, expression, `verification flow is missing safeguard ${expression}`);
assert.doesNotMatch(verification, /SMTP_USERNAME.*SMTP_PASSWORD/s,
    'resending must not be hard-wired to SMTP-only configuration');

const security = read('Includes/registration_security.php');
assert.match(security, /BREVO_API_KEY/);
assert.match(security, /MAIL_RELAY_URL/);
assert.match(security, /SMTP_USERNAME/);
assert.match(security, /function_exists\('curl_init'\)/,
    'HTTP provider readiness must require the cURL transport used by the mailer');
assert.match(security, /\(\$relayParts\['scheme'\] \?\? ''\)\) === 'https'/,
    'mail-relay readiness must require HTTPS');
assert.match(security, /hash_hmac\('sha256'/,
    'rate-limit buckets must not persist raw IP addresses or email addresses');
assert.match(security, /pg_advisory_xact_lock\(hashtextextended/,
    'concurrent requests must serialize quota checks in PostgreSQL');
assert.match(security, /registration_rate_limit_events/,
    'rate limits must use timestamped events rather than resettable fixed windows');
assert.match(security, /clock_timestamp\(\)/,
    'sliding windows must use wall-clock time after a request waits on an advisory lock');
assert.match(security, /registration-code:email:' \./);
assert.match(security, /\['registration-code:email:' \. \$normalizedEmail, 5, 3600, 60, 'email_cooldown', 'email_limit'\]/,
    'email codes must be capped at five per hour with a 60-second resend cooldown');
assert.match(security, /'registration-code:global', 120, 3600/,
    'verification-code traffic must have a provider-wide hourly cap');
assert.match(security, /\['registration-application:email:' \. \$normalizedEmail, 3, 3600, 60, 'application_email_cooldown', 'application_email_limit'\]/);
assert.match(security, /'registration-application:global', 30, 3600/,
    'large application submissions must have a shared hourly cap');

const mailer = read('Includes/reset_mailer.php');
assert.match(mailer, /CURLOPT_FOLLOWLOCATION => false/,
    'the mailer must not forward verification codes or relay secrets automatically across redirects');
assert.match(mailer, /mbus_mail_redirect_is_allowed/);
assert.match(mailer, /script\.googleusercontent\.com/,
    'the configured Apps Script response redirect should remain usable without forwarding the POST body');
assert.match(mailer, /if \(\$http === null\) return null/);
assert.match(mailer, /trying SMTP fallback/,
    'a failed HTTPS provider should not mask otherwise-valid SMTP credentials');

const verifyMigration = read('supabase/16_registration_verification.sql');
assert.match(verifyMigration, /AND registration_code IS NULL/,
    'legacy backfill must not silently verify a pending code registration');
const securityMigration = read('supabase/18_registration_security.sql');
assert.match(securityMigration, /registration_code_attempts SMALLINT NOT NULL DEFAULT 0/);
assert.match(securityMigration, /CREATE TABLE IF NOT EXISTS registration_rate_limit_events/);
assert.match(securityMigration, /bucket_hash CHAR\(64\) NOT NULL/);
assert.match(securityMigration, /VARCHAR\(254\)/,
    'database email length must match server-side email validation');

const db = read('config/db_connect.php');
assert.match(db, /schemaVersion\s*=\s*'2026-11-23a'/,
    'existing deployments must run the new additive schema upgrade');
assert.match(db, /CREATE TABLE IF NOT EXISTS registration_rate_limit_events/);
assert.match(db, /registration_code_attempts SMALLINT NOT NULL DEFAULT 0/);
assert.match(read('scripts/setup_supabase.php'), /18_registration_security\.sql/);

console.log('PASS email verification, sliding email/global quotas, provider readiness/fallback, safe redirects, and additive schema upgrades');
