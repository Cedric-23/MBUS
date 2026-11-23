<?php

require_once __DIR__ . '/load_env.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/mysqli_compat.php';

$host = getenv('SUPABASE_DB_HOST') ?: 'aws-1-ap-south-1.pooler.supabase.com';
$port = getenv('SUPABASE_DB_PORT') ?: '5432';
$database = getenv('SUPABASE_DB_NAME') ?: 'postgres';
$user = getenv('SUPABASE_DB_USER') ?: 'postgres.xeczvnheaixpfattbwsk';
$password = getenv('SUPABASE_DB_PASSWORD') ?: '';

if ($password === '') {
    die(
        'Supabase database password is missing. Copy .env.example to .env and set SUPABASE_DB_PASSWORD ' .
        '(Supabase Dashboard → Project Settings → Database → Database password).'
    );
}

function mbus_run_schema_upgrades(PDO $pdo): void {
  // Keep profile uploads compatible with deployments where the optional migration
  // has not yet been run manually. IF NOT EXISTS makes this safe on every request.
  try {
      $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255)');
      $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture_b64 TEXT');
      $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture_mime VARCHAR(50)');
      $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_code VARCHAR(6)');
      $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expiry TIMESTAMP NULL');
      $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMP NULL');
      $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS registration_code VARCHAR(6)');
      $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS registration_code_expiry TIMESTAMP NULL');
      $pdo->exec("UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL AND LOWER(user_type) = 'commuter' AND registration_code IS NULL");
  } catch (PDOException $schema_error) {
      error_log('Profile picture schema check failed: ' . $schema_error->getMessage());
  }
  // Rate-limit schema is required for signup safety; let failures abort this versioned
  // upgrade so the marker is not saved and the next request can retry it.
  $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS registration_code_attempts SMALLINT NOT NULL DEFAULT 0');
  $pdo->exec('ALTER TABLE users ALTER COLUMN email TYPE VARCHAR(254)');
  $pdo->exec("CREATE TABLE IF NOT EXISTS registration_rate_limit_events (
      event_id BIGSERIAL PRIMARY KEY, bucket_hash CHAR(64) NOT NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())");
  $pdo->exec('CREATE INDEX IF NOT EXISTS registration_rate_limit_events_bucket_idx ON registration_rate_limit_events (bucket_hash, created_at)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS registration_rate_limit_events_created_idx ON registration_rate_limit_events (created_at)');
  // Per-ticket passenger type and discount ID (group bookings: student/senior/PWD seats).
  try {
      foreach ([
          "passenger_type VARCHAR(20) DEFAULT 'Regular'",
          "discount_status VARCHAR(20) DEFAULT 'None'",
          "id_image_b64 TEXT",
          "id_image_mime VARCHAR(50)",
          "fare_amount NUMERIC(10,2)",
          "discount_amount NUMERIC(10,2)",
          "discount_reviewed_by INT",
          "discount_reviewed_at TIMESTAMP",
          "expires_at TIMESTAMPTZ",
          "pickup_latitude DOUBLE PRECISION",
          "pickup_longitude DOUBLE PRECISION",
          "destination_latitude DOUBLE PRECISION",
          "destination_longitude DOUBLE PRECISION",
      ] as $col) {
          $pdo->exec('ALTER TABLE reservation ADD COLUMN IF NOT EXISTS ' . $col);
      }
      $pdo->exec("UPDATE reservation SET expires_at = created_at + INTERVAL '10 minutes' WHERE expires_at IS NULL");
      $pdo->exec("ALTER TABLE reservation ALTER COLUMN expires_at SET DEFAULT (NOW() + INTERVAL '10 minutes')");
      $pdo->exec("CREATE INDEX IF NOT EXISTS reservation_pending_expiry_idx ON reservation (schedule_id, expires_at) WHERE status = 'Pending'");
  } catch (PDOException $schema_error) {
      error_log('Reservation schema check failed: ' . $schema_error->getMessage());
  }
  // PayMongo GCash checkout sessions: one row per redirect, finalized by the webhook or the return page.
  try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS paymongo_checkout (
          session_id TEXT PRIMARY KEY, user_id INT NOT NULL, schedule_id INT NOT NULL,
          reservation_ids TEXT NOT NULL, lines TEXT NOT NULL, amount NUMERIC(10,2) NOT NULL,
          checkout_url TEXT, status TEXT NOT NULL DEFAULT 'Created', payment_ref TEXT, ticket_code TEXT,
          created_at TIMESTAMP DEFAULT NOW(), paid_at TIMESTAMP)");
  } catch (PDOException $schema_error) {
      error_log('PayMongo schema check failed: ' . $schema_error->getMessage());
  }
  try {
      foreach (["reference_number VARCHAR(40)", "receipt_b64 TEXT", "receipt_mime VARCHAR(30)", "reject_reason TEXT", "reviewed_by INT", "reviewed_at TIMESTAMP", "submitted_at TIMESTAMP", "paid_at TIMESTAMP"] as $col) {
          $pdo->exec('ALTER TABLE paymongo_checkout ADD COLUMN IF NOT EXISTS ' . $col);
      }
      $pdo->exec("CREATE TABLE IF NOT EXISTS incoming_payments (
          id SERIAL PRIMARY KEY, reference_number VARCHAR(40) UNIQUE NOT NULL, amount NUMERIC(10,2) NOT NULL,
          sender TEXT, raw_message TEXT, received_at TIMESTAMP DEFAULT NOW(), used_by TEXT)");
      // Each open GCash payment gets its own centavo amount, so the incoming SMS identifies the booking.
      $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_manual_open_amount ON paymongo_checkout (amount) WHERE status = 'Submitted' AND session_id LIKE 'manual\\_%'");
      $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
  } catch (PDOException $schema_error) {
      error_log('Manual payment schema check failed: ' . $schema_error->getMessage());
  }
  // Operator application documents live in the database (the web host's disk is temporary).
  try {
      foreach (["license_document_b64 TEXT", "license_document_mime VARCHAR(50)", "vehicle_document_b64 TEXT", "vehicle_document_mime VARCHAR(50)", "password_hash TEXT"] as $col) {
          $pdo->exec('ALTER TABLE operator_application ADD COLUMN IF NOT EXISTS ' . $col);
      }
  } catch (PDOException $schema_error) {
      error_log('Operator application schema check failed: ' . $schema_error->getMessage());
  }
  // Route creation persists map pins; installs that skipped the manual coordinate
  // migration must receive the columns before the Admin form attempts an insert.
  try {
      foreach ([
          "origin_lat DECIMAL(10, 8)",
          "origin_lng DECIMAL(11, 8)",
          "destination_lat DECIMAL(10, 8)",
          "destination_lng DECIMAL(11, 8)",
      ] as $col) {
          $pdo->exec('ALTER TABLE routes ADD COLUMN IF NOT EXISTS ' . $col);
      }
  } catch (PDOException $schema_error) {
      error_log('Route coordinate schema check failed: ' . $schema_error->getMessage());
  }
  // Driver applications can include reviewable vehicle photos and seat layout.
  try {
      foreach ([
          "bus_photo_b64 TEXT",
          "bus_photo_mime VARCHAR(50)",
          "interior_photo_b64 TEXT",
          "interior_photo_mime VARCHAR(50)",
          "seat_layout TEXT",
          "last_name VARCHAR(100)",
          "vehicle_registration_expiry DATE",
      ] as $col) {
          $pdo->exec('ALTER TABLE operator_application ADD COLUMN IF NOT EXISTS ' . $col);
      }
  } catch (PDOException $schema_error) {
      error_log('Operator bus setup schema check failed: ' . $schema_error->getMessage());
  }
  // Drivers customise their own bus (photos + seat layout) from Operator > My Bus.
  try {
      foreach ([
          "seat_layout TEXT",
          "bus_photo_b64 TEXT",
          "bus_photo_mime VARCHAR(50)",
          "interior_photo_b64 TEXT",
          "interior_photo_mime VARCHAR(50)",
      ] as $col) {
          $pdo->exec('ALTER TABLE buses ADD COLUMN IF NOT EXISTS ' . $col);
      }
  } catch (PDOException $schema_error) {
      error_log('Bus customisation schema check failed: ' . $schema_error->getMessage());
  }
  try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS conductor_application (
          application_id SERIAL PRIMARY KEY, full_name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL,
          phone_number VARCHAR(20) NOT NULL, password_hash TEXT NOT NULL, application_status TEXT NOT NULL DEFAULT 'Pending',
          submitted_at TIMESTAMP DEFAULT NOW(), reviewed_at TIMESTAMP, reviewed_by INT, rejection_reason TEXT, user_id INT)");
  } catch (PDOException $schema_error) {
      error_log('Conductor application schema check failed: ' . $schema_error->getMessage());
  }
  try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS conductor_bus_assignments (
          assignment_id SERIAL PRIMARY KEY,
          conductor_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
          bus_id INTEGER NOT NULL UNIQUE REFERENCES buses(bus_id) ON DELETE CASCADE,
          assigned_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp())");
      $pdo->exec('CREATE INDEX IF NOT EXISTS idx_conductor_bus_assignments_conductor ON conductor_bus_assignments(conductor_id, bus_id)');
      $pdo->exec('ALTER TABLE conductor_bus_assignments ENABLE ROW LEVEL SECURITY');
      $pdo->exec('REVOKE ALL ON TABLE conductor_bus_assignments FROM PUBLIC, anon, authenticated');
  } catch (PDOException $schema_error) {
      error_log('Conductor bus assignment schema check failed: ' . $schema_error->getMessage());
  }
}

// connect_timeout: never hang forever if the database is unreachable.
$dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=require;connect_timeout=10";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Reuse the database connection between requests. Opening a new TLS connection to a far-away database
        // costs several round trips on EVERY page; a persistent connection pays that once per web worker.
        PDO::ATTR_PERSISTENT => true,
    ]);
    // A persistent connection can come back mid-transaction (or aborted) if the previous request died; clear it.
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e) { $pdo->exec('ROLLBACK'); } }
    $pdo->exec("SET TIME ZONE '" . str_replace("'", "''", mbus_timezone()) . "'");
    // Schema upgrades are ~35 extra round trips to the database. Run them once per version, not on every page.
    $schemaVersion = '2026-11-24a';
    $schemaFlag = sys_get_temp_dir() . '/mbus_schema_' . md5($host . $database . $schemaVersion) . '.ok';
    if (!is_file($schemaFlag)) {
        $done = false;
        try {
            $chk = $pdo->query("SELECT value FROM app_settings WHERE key = 'schema_version'");
            $done = $chk && $chk->fetchColumn() === $schemaVersion;
        } catch (PDOException $e) { $done = false; }
        if (!$done) {
            mbus_run_schema_upgrades($pdo);
            try {
                $pdo->prepare("INSERT INTO app_settings (key, value) VALUES ('schema_version', ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value")->execute([$schemaVersion]);
            } catch (PDOException $e) { error_log('Schema version save failed: ' . $e->getMessage()); }
        }
        @file_put_contents($schemaFlag, $schemaVersion);
    }
    $conn = new MbusConnection($pdo);
} catch (PDOException $e) {
    $GLOBALS['mbus_connect_error'] = $e->getMessage();
    die('Connection failed: ' . $e->getMessage());
}
