<?php
/**
 * Isolated regression test: php tests/ticket_qr_pdf_test.php [artifact-directory]
 * No application DB bootstrap is loaded: the endpoint is copied into a temporary
 * fixture tree with a read-only fake DB. Production signing, QR and FPDF code run
 * unchanged. Optional PDF decoding uses pdftoppm, Python/Pillow, Node and the
 * repository's existing operator jsQR decoder; none are runtime dependencies.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

function qr_test_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// These fake DB functions are only available in the test subprocess.
function mbus_db_prepare($conn, string $sql, array $params)
{
    qr_test_check((bool)preg_match('/^\s*SELECT\b/i', $sql), 'Fixture must not perform DB writes.');
    $case = $GLOBALS['qr_test_case'];
    qr_test_check($params[0] === 42 || strpos($sql, 'FROM schedule') !== false, 'Query must scope to the session user.');
    if (strpos($sql, 'FROM users') !== false) {
        if ($case === 'user-query-failure') { return false; }
        $rows = $case === 'missing-user' ? [] : [['full_name' => $case === 'long' ? str_repeat('Long Passenger Name ', 10) : 'Test Passenger']];
    } elseif (strpos($sql, 'FROM reservation') !== false) {
        qr_test_check(strpos($sql, "status IN ('Paid', 'Boarded')") !== false, 'Paid/Boarded reservation check must remain.');
        qr_test_check($params === [42, (int)$_GET['schedule_id']], 'Reservation must scope to user and requested schedule.');
        if ($case === 'reservation-query-failure') { return false; }
        $rows = in_array($case, ['unpaid', 'other-user', 'other-schedule'], true) ? [] : [[
            'seat_number' => 'A1', 'pickup_location' => 'Central Terminal',
            'destination' => 'North Station', 'status' => $case === 'boarded' ? 'Boarded' : 'Paid',
        ]];
        if ($case === 'long') {
            $rows = [];
            for ($i = 1; $i <= 60; $i++) {
                $rows[] = ['seat_number' => 'A' . $i, 'pickup_location' => str_repeat('Long Pickup Location ', 6), 'destination' => str_repeat('Long Destination ', 6), 'status' => 'Paid'];
            }
        }
    } elseif (strpos($sql, 'FROM payment') !== false) {
        qr_test_check(strpos($sql, "r.status IN ('Paid', 'Boarded')") !== false, 'Paid/Boarded payment reservation check must remain.');
        qr_test_check(strpos($sql, "p.payment_status = 'Paid'") !== false, 'Paid payment check must remain.');
        qr_test_check($params === [42, (int)$_GET['schedule_id']], 'Payment must scope to session user and requested schedule.');
        if ($case === 'payment-query-failure') { return false; }
        $rows = $case === 'missing-payment' ? [] : [[
            'payment_method' => 'GCash', 'payment_reference' => $case === 'long' ? str_repeat('1234567890', 20) : 'REF-TEST-123',
            'payment_date' => '2026-11-08 08:15:00', 'ticket_code' => $case === 'missing-ticket-code' ? '' : 'MB-1234',
            'amount' => 150, 'payment_status' => 'Paid',
        ]];
    } elseif (strpos($sql, 'FROM schedule') !== false) {
        qr_test_check($params === [(int)$_GET['schedule_id']], 'Schedule query must scope to requested schedule.');
        if ($case === 'schedule-query-failure') { return false; }
        $rows = $case === 'missing-schedule' ? [] : [['bus_number' => 'BUS-01', 'departure_time' => '2026-11-08 09:00:00']];
    } else {
        throw new RuntimeException('Unexpected fixture query.');
    }
    return (object)['rows' => $rows, 'offset' => 0];
}

function mbus_db_fetch_assoc($result)
{
    return $result->rows[$result->offset++] ?? false;
}

function mbus_db_num_rows($result): int
{
    return count($result->rows);
}

if (($argv[1] ?? '') === '--endpoint') {
    $GLOBALS['qr_test_case'] = $argv[3];
    $_GET = ['schedule_id' => 123];
    switch ($GLOBALS['qr_test_case']) {
        case 'invalid-zero': $_GET['schedule_id'] = 0; break;
        case 'invalid-negative': $_GET['schedule_id'] = -1; break;
        case 'invalid-array': $_GET['schedule_id'] = ['123']; break;
        case 'missing-id': $_GET = []; break;
        case 'other-schedule': $_GET['schedule_id'] = 999; break;
    }
    putenv('MBUS_QR_SECRET=isolated-ticket-qr-test-secret');
    date_default_timezone_set('Asia/Manila');
    chdir($argv[2] . '/Commuter');
    require $argv[2] . '/Commuter/download_ticket.php';
    exit;
}

function qr_test_run(array $command, ?string $cwd = null): string
{
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $cwd);
    qr_test_check(is_resource($process), 'Cannot start test subprocess.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    qr_test_check($status === 0 && $errors === '', 'Subprocess failed: ' . implode(' ', $command) . '\n' . $errors);
    return $output;
}

function qr_test_remove(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) as $child) {
        if ($child !== '.' && $child !== '..') { qr_test_remove($path . '/' . $child); }
    }
    rmdir($path);
}

$repo = dirname(__DIR__);
require_once $repo . '/Includes/ticket_qr.php';
require_once $repo . '/Includes/qr_pdf.php';
require_once $repo . '/Commuter/fpdf/fpdf.php';
putenv('MBUS_QR_SECRET=isolated-ticket-qr-test-secret');
$root = sys_get_temp_dir() . '/mbus-ticket-qr-' . bin2hex(random_bytes(6));
mkdir($root);
$artifacts = isset($argv[1]) ? rtrim($argv[1], '/') : $root . '/artifacts';
if (!is_dir($artifacts)) { mkdir($artifacts, 0700, true); }
$artifacts = realpath($artifacts);

class QrBoundsTestPDF extends FPDF
{
    public array $rectangles = [];
    public function Rect($x, $y, $w, $h, $style = '')
    {
        $this->rectangles[] = [$x, $y, $w, $h, $style];
        parent::Rect($x, $y, $w, $h, $style);
    }
}

try {
    $cases = [];
    foreach ([[1, 2], [123, 42], [PHP_INT_MAX, PHP_INT_MAX]] as $index => [$schedule, $user]) {
        $token = mbus_ticket_token($schedule, $user);
        qr_test_check(mbus_verify_ticket_token($token) === ['schedule_id' => $schedule, 'user_id' => $user], 'Token must pass the existing operator verifier.');
        $matrix = mbus_qr_matrix($token);
        $count = count($matrix);
        qr_test_check($count >= 21 && $count <= 37 && ($count - 17) % 4 === 0, 'Current signed token must fit within QR version 5.');
        foreach ($matrix as $row) { qr_test_check(count($row) === $count, 'QR matrix must be square.'); }
        $pdf = new QrBoundsTestPDF('P', 'mm', [80, 300]);
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage();
        $pdf->SetXY(5, 20);
        $cursor = [$pdf->GetX(), $pdf->GetY()];
        mbus_pdf_draw_qr($pdf, $token, 17, 20, 46);
        qr_test_check([$pdf->GetX(), $pdf->GetY()] === $cursor, 'Drawing QR must not move the receipt cursor.');
        $quiet = 4 * 46 / ($count + 8);
        foreach (array_slice($pdf->rectangles, 1) as [$x, $y, $w, $h, $style]) {
            qr_test_check($x >= 17 + $quiet - 1e-8 && $y >= 20 + $quiet - 1e-8
                && $x + $w <= 63 - $quiet + 1e-8 && $y + $h <= 66 - $quiet + 1e-8
                && $style === 'F', 'Dark vector modules must remain inside the four-module quiet zone.');
        }
        $bytes = $pdf->Output('S');
        qr_test_check(strncmp($bytes, '%PDF-', 5) === 0 && strpos($bytes, '/Subtype /Image') === false, 'QR PDF must use vectors, not raster/GD images.');
        $file = $artifacts . '/token-' . $index . '.pdf';
        file_put_contents($file, $bytes);
        $cases[$file] = $token;
    }
    foreach (['', str_repeat('x', 214)] as $payload) {
        try { mbus_qr_matrix($payload); throw new RuntimeException('Invalid payload was accepted.'); }
        catch (InvalidArgumentException $expected) {}
    }
    try { mbus_pdf_draw_qr($pdf, $token, 60, 20, 46); throw new RuntimeException('Clipped QR was accepted.'); }
    catch (InvalidArgumentException $expected) {}

    mkdir($root . '/Commuter');
    mkdir($root . '/config');
    copy($repo . '/Commuter/download_ticket.php', $root . '/Commuter/download_ticket.php');
    symlink($repo . '/Commuter/fpdf', $root . '/Commuter/fpdf');
    symlink($repo . '/Includes', $root . '/Includes');
    file_put_contents($root . '/config/db_connect.php', '<?php $conn = new stdClass(); if ($GLOBALS["qr_test_case"] !== "anonymous") { $_SESSION["user_id"] = 42; }');

    foreach (['paid', 'boarded', 'long', 'missing-ticket-code'] as $case) {
        $bytes = qr_test_run([PHP_BINARY, __FILE__, '--endpoint', $root, $case]);
        qr_test_check(strncmp($bytes, '%PDF-', 5) === 0, $case . ' endpoint must generate a PDF.');
        qr_test_check(strpos($bytes, '/Subtype /Image') === false, $case . ' endpoint must use vector QR.');
        if ($case !== 'long') {
            qr_test_check((bool)preg_match('/\/Type \/Pages\s*\/Kids\s*\[[^\]]+\]\s*\/Count 1\b/s', $bytes), 'Normal receipt and QR must fit on one page.');
        }
        $file = $artifacts . '/ticket-' . $case . '.pdf';
        file_put_contents($file, $bytes);
        $cases[$file] = mbus_ticket_token(123, 42);
    }
    $denials = [
        'anonymous' => '', 'invalid-zero' => 'Schedule not found.', 'invalid-negative' => 'Schedule not found.',
        'invalid-array' => 'Schedule not found.', 'missing-id' => 'Schedule not found.',
        'missing-user' => 'User data unavailable.', 'user-query-failure' => 'Ticket data unavailable.',
        'unpaid' => 'No paid reservation found.', 'other-user' => 'No paid reservation found.',
        'other-schedule' => 'No paid reservation found.', 'reservation-query-failure' => 'Ticket data unavailable.',
        'missing-payment' => 'Payment data unavailable.', 'payment-query-failure' => 'Payment data unavailable.',
        'missing-schedule' => 'Schedule not found.', 'schedule-query-failure' => 'Schedule data unavailable.',
    ];
    foreach ($denials as $case => $expected) {
        qr_test_check(qr_test_run([PHP_BINARY, __FILE__, '--endpoint', $root, $case]) === $expected, $case . ' must not produce a ticket.');
    }

    $tools = [];
    foreach (['pdftoppm', 'pdftotext', 'node', 'python3'] as $tool) {
        $tools[$tool] = trim((string)shell_exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null'));
    }
    $canDecode = !in_array('', $tools, true)
        && trim((string)shell_exec('python3 -c ' . escapeshellarg('from PIL import Image; print("available")') . ' 2>/dev/null')) === 'available';
    if ($canDecode) {
        $decoder = <<<'JS'
const fs = require('fs');
const jsQR = require(process.argv[2]);
const width = Number(process.argv[4]), height = Number(process.argv[5]);
const data = new Uint8ClampedArray(fs.readFileSync(process.argv[3]));
const code = jsQR(data, width, height, {inversionAttempts: 'dontInvert'});
if (!code) { throw new Error('QR did not decode through the operator jsQR library'); }
process.stdout.write(code.data);
JS;
        file_put_contents($root . '/decode.js', $decoder);
        foreach ($cases as $file => $expected) {
            $resolutions = strpos(basename($file), 'ticket-') === 0 ? [150, 300] : [300];
            foreach ($resolutions as $dpi) {
                $prefix = $root . '/raster';
                qr_test_run([$tools['pdftoppm'], '-f', '1', '-singlefile', '-r', (string)$dpi, '-png', $file, $prefix]);
                $dimensions = qr_test_run([$tools['python3'], '-c', 'from PIL import Image; import sys; im=Image.open(sys.argv[1]).convert("RGBA"); open(sys.argv[2],"wb").write(im.tobytes()); print(im.width, im.height)', $prefix . '.png', $root . '/rgba.bin']);
                [$width, $height] = explode(' ', trim($dimensions));
                $decoded = qr_test_run([$tools['node'], $root . '/decode.js', $repo . '/Assets/js/vendor/jsQR.js', $root . '/rgba.bin', $width, $height]);
                qr_test_check($decoded === $expected, basename($file) . ' at ' . $dpi . ' DPI must decode to the exact on-screen signed token.');
                qr_test_check(mbus_verify_ticket_token($decoded) !== null, 'Decoded QR must be accepted by the existing operator verifier.');
            }
            if (strpos(basename($file), 'ticket-') === 0) {
                $text = qr_test_run([$tools['pdftotext'], $file, '-']);
                qr_test_check(strpos($text, 'Show this QR to the operator when you board.') !== false, 'PDF must include the boarding hint.');
                qr_test_check(strpos($text, basename($file) === 'ticket-missing-ticket-code.pdf' ? 'N/A' : 'MB-1234') !== false, 'Readable ticket code must remain in PDF.');
                qr_test_check(strpos($text, 'Please present this ticket before boarding.') !== false, 'Footer must not be clipped off the PDF.');
            }
        }
        echo 'PASS: exact QR decode through operator jsQR for ' . count($cases) . " vector PDFs.\n";
    } else {
        echo "SKIP: QR decoding requires pdftoppm, pdftotext, Node and Python/Pillow.\n";
    }
    echo 'PASS: local matrix/vector bounds, no GD, one-page normal tickets, long receipt, and ' . count($denials) . " authorization/payment/error cases.\n";
    if (isset($argv[1])) { echo 'PDF artifacts: ' . $artifacts . "\n"; }
} finally {
    qr_test_remove($root);
}
