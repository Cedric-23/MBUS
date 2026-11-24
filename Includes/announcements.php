<?php
/* Transportation updates: admin-published announcements about bus operations and service availability. */
require_once __DIR__ . '/notify.php';

const MBUS_ANNOUNCEMENT_CATEGORIES = ['Notice', 'Service Alert', 'Delay', 'Suspension', 'Service Resumed'];
const MBUS_ANNOUNCEMENT_AUDIENCES = ['All', 'Commuters', 'Staff'];

/** Create the table on first use so the feature works before the SQL migration is applied. */
function mbus_announcements_ensure_table($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        mbus_db_prepare($conn, "CREATE TABLE IF NOT EXISTS announcement (
            announcement_id SERIAL PRIMARY KEY,
            title VARCHAR(120) NOT NULL,
            body TEXT NOT NULL,
            category VARCHAR(30) NOT NULL DEFAULT 'Notice',
            audience VARCHAR(20) NOT NULL DEFAULT 'All',
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_by INT REFERENCES users(user_id) ON DELETE SET NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL
        )", []);
    } catch (Throwable $e) { error_log('announcement table failed: ' . $e->getMessage()); }
}

/** Validate admin input. Returns [clean values, error message ('' when valid)]. */
function mbus_announcement_validate(array $in): array
{
    $title = trim((string)($in['title'] ?? ''));
    $body = trim((string)($in['body'] ?? ''));
    $category = (string)($in['category'] ?? '');
    $audience = (string)($in['audience'] ?? '');
    $expires = trim((string)($in['expires_at'] ?? ''));
    if ($title === '' || mb_strlen($title) > 120) return [[], 'Enter a title of up to 120 characters.'];
    if ($body === '' || mb_strlen($body) > 1000) return [[], 'Enter a message of up to 1000 characters.'];
    if (!in_array($category, MBUS_ANNOUNCEMENT_CATEGORIES, true)) return [[], 'Choose a valid category.'];
    if (!in_array($audience, MBUS_ANNOUNCEMENT_AUDIENCES, true)) return [[], 'Choose a valid audience.'];
    $expires_at = null;
    if ($expires !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expires);
        $errs = DateTimeImmutable::getLastErrors();
        if ($d === false || ($errs !== false && ($errs['warning_count'] > 0 || $errs['error_count'] > 0))) return [[], 'The expiry date is not valid.'];
        if ($d <= new DateTimeImmutable('now')) return [[], 'The expiry must be in the future.'];
        $expires_at = $d->format('Y-m-d H:i:s');
    }
    return [['title' => $title, 'body' => $body, 'category' => $category, 'audience' => $audience, 'expires_at' => $expires_at], ''];
}

/** User ids that should be told about an announcement for the given audience. Admins are not notified of their own posts. */
function mbus_announcement_recipient_ids($conn, string $audience): array
{
    $sql = "SELECT user_id FROM users WHERE LOWER(user_type) <> 'admin'";
    if ($audience === 'Commuters') $sql .= " AND LOWER(user_type) = 'commuter'";
    elseif ($audience === 'Staff') $sql .= " AND LOWER(user_type) <> 'commuter'";
    $ids = [];
    try {
        $q = mbus_db_prepare($conn, $sql, []);
        while ($q !== false && ($row = mbus_db_fetch_assoc($q))) $ids[] = (int)$row['user_id'];
    } catch (Throwable $e) { error_log('announcement recipients failed: ' . $e->getMessage()); }
    return $ids;
}

/** Save the announcement and send a bell notification to its audience. Returns the new id, or 0 on failure. */
function mbus_announcement_publish($conn, array $clean, int $admin_id): int
{
    mbus_announcements_ensure_table($conn);
    $ok = mbus_db_prepare($conn, "INSERT INTO announcement (title, body, category, audience, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)",
        [$clean['title'], $clean['body'], $clean['category'], $clean['audience'], $admin_id, $clean['expires_at']]);
    if ($ok === false) return 0;
    $id = 0;
    $q = mbus_db_prepare($conn, "SELECT MAX(announcement_id) AS id FROM announcement WHERE created_by = ? AND title = ?", [$admin_id, $clean['title']]);
    $row = $q !== false ? mbus_db_fetch_assoc($q) : false;
    if ($row) $id = (int)$row['id'];
    $message = $clean['category'] . ': ' . $clean['title'] . ' - ' . $clean['body'];
    foreach (mbus_announcement_recipient_ids($conn, $clean['audience']) as $uid) mbus_notify($conn, $uid, $message);
    return $id > 0 ? $id : 1;
}

/** Take an announcement down. */
function mbus_announcement_withdraw($conn, int $id): bool
{
    mbus_announcements_ensure_table($conn);
    return mbus_db_prepare($conn, "UPDATE announcement SET is_active = FALSE WHERE announcement_id = ?", [$id]) !== false;
}

/**
 * Current announcements, newest first. $audiences is the list of audiences the viewer belongs to
 * (for example ['All','Commuters']); pass an empty array for every audience (admin view).
 */
function mbus_announcements_active($conn, array $audiences = [], int $limit = 50): array
{
    mbus_announcements_ensure_table($conn);
    $sql = "SELECT announcement_id, title, body, category, audience, created_at, expires_at FROM announcement WHERE is_active = TRUE AND (expires_at IS NULL OR expires_at > NOW())";
    $params = [];
    if ($audiences) {
        $sql .= ' AND audience IN (' . implode(',', array_fill(0, count($audiences), '?')) . ')';
        $params = array_values($audiences);
    }
    $sql .= ' ORDER BY created_at DESC, announcement_id DESC LIMIT ' . max(1, min(200, $limit));
    $rows = [];
    $q = mbus_db_prepare($conn, $sql, $params);
    while ($q !== false && ($r = mbus_db_fetch_assoc($q))) $rows[] = $r;
    return $rows;
}
