<?php
session_start();
include "../config/db_connect.php";
require_once __DIR__ . "/fpdf/fpdf.php";
require_once __DIR__ . "/../Includes/ticket_qr.php";
require_once __DIR__ . "/../Includes/qr_pdf.php";
require_once __DIR__ . "/../Includes/ticket_checkout_data.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$requestedSchedule = filter_var($_GET['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reservationId = filter_var($_GET['reservation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestedSchedule && !$reservationId) { die("Ticket not found."); }

$user_id = (int)$_SESSION['user_id'];
$user_query = mbus_db_prepare($conn, "SELECT full_name FROM users WHERE user_id = ?", [$user_id]);
if ($user_query === false) { die("Ticket data unavailable."); }
$user = mbus_db_fetch_assoc($user_query);
if (!$user) { die("User data unavailable."); }

$ticketData = mbus_ticket_checkout_data($conn, $user_id, $requestedSchedule ?: null, $reservationId ?: null);
if (!$ticketData) { die("No paid reservation found."); }
$schedule_id = (int)$ticketData['schedule_id'];
$reservations = $ticketData['reservations'];
$payment = $ticketData['payment'];
$fareLines = $ticketData['lines'];
$seats = array_map(static fn($row) => (string)$row['seat_number'], $reservations);
$seat_list = implode(", ", $seats);
$payment_method = (string)($payment['payment_method'] ?? '');
$payment_reference = (string)($payment['payment_reference'] ?? '');
$payment_date = (string)($payment['payment_date'] ?? '');
$ticket_code = (string)($payment['ticket_code'] ?? '');
$amount = $payment['amount'] ?? null;

$schedule_query = mbus_db_prepare($conn,"SELECT buses.bus_number, schedule.departure_time
    FROM schedule JOIN buses ON schedule.bus_id = buses.bus_id WHERE schedule.schedule_id=?", [$schedule_id]);
if($schedule_query === false){ die("Schedule data unavailable."); }
$data = mbus_db_fetch_assoc($schedule_query);
if(!$data){ die("Schedule not found."); }

// Match the on-screen ticket exactly; never encode a URL or the readable payment code.
$qr_token = mbus_ticket_token((int)$schedule_id, (int)$user_id);

/* =========================
   PDF GENERATION
========================= */
// Reserve space for the QR plus the existing receipt. Long text can continue safely.
$pdf = new FPDF('P','mm',array(80,300));
$pdf->SetMargins(5,5,5);
$pdf->SetAutoPageBreak(true,5);
$pdf->AddPage();

/* ===== HEADER (FIXED CENTER) ===== */
$pdf->SetFont('Arial','B',16);
$pdf->SetX(0);
$pdf->Cell(80,7,'MBUS',0,1,'C');

$pdf->SetFont('Arial','',9);
$pdf->SetX(0);
$pdf->Cell(80,5,'Digital Bus Ticket',0,1,'C');

$pdf->Ln(3);

/* ===== BOARDING QR (LOCAL VECTOR RENDERING, INCLUDING QUIET ZONE) ===== */
$qr_size = 50;
$qr_y = $pdf->GetY();
mbus_pdf_draw_qr($pdf, $qr_token, ($pdf->GetPageWidth() - $qr_size) / 2, $qr_y, $qr_size);
$pdf->SetY($qr_y + $qr_size + 1);
$pdf->SetFont('Arial','',8);
$pdf->MultiCell(0,4,'Show this QR to the operator when you board.',0,'C');
$pdf->Ln(2);

/* ===== TICKET CODE ===== */
$pdf->SetFont('Arial','B',10);
$pdf->MultiCell(0,7, !empty($ticket_code)?$ticket_code:"N/A",1,'C');

$pdf->Ln(3);

/* ===== PASSENGER ===== */
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Passenger',0,1);

$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0,5,$user['full_name']);

$pdf->Ln(2);

/* ===== ROUTES BY SEAT ===== */
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Pickup and destination by seat',0,1);
$pdf->SetFont('Arial','',8);
foreach ($reservations as $reservation) {
    $pdf->MultiCell(0,4,'Seat ' . $reservation['seat_number'] . ': ' . $reservation['pickup_location'] . ' to ' . $reservation['destination']);
}
$pdf->Ln(3);

/* ===== BUS ===== */
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Bus Number',0,1);

$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0,5,$data['bus_number']);

$pdf->Ln(2);

/* ===== TIME ===== */
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Departure Time',0,1);

$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0,5,date("F d, Y - h:i A",strtotime($data['departure_time'])));

$pdf->Ln(2);

/* ===== SEATS + PER-SEAT FARES ===== */
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Seat Number(s)',0,1);
$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0,5,$seat_list);
foreach ($reservations as $reservation) {
    $line = $fareLines[(int)$reservation['reservation_id']] ?? null;
    if ($line) {
        $pdf->MultiCell(0,4,'Seat ' . $reservation['seat_number'] . ' fare: PHP ' . number_format((float)$line['base'],2) . ' - PHP ' . number_format((float)$line['discount'],2) . ' discount');
    }
}
$pdf->Ln(3);

/* ===== LINE ===== */
$pdf->Cell(70,0,'','T',1);
$pdf->Ln(3);

/* ===== PAYMENT ===== */
$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Amount Paid',0,1);

$pdf->SetFont('Arial','',9);
$pdf->Cell(0,5, !empty($amount) ? "PHP ".number_format($amount,2) : "N/A",0,1);

$pdf->Ln(2);

$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Payment Method',0,1);

$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0,5, !empty($payment_method)?$payment_method:"N/A");

$pdf->Ln(2);

$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Reference Number',0,1);

$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0,5, !empty($payment_reference)?$payment_reference:"N/A");

$pdf->Ln(2);

$pdf->SetFont('Arial','B',9);
$pdf->Cell(0,5,'Payment Date',0,1);

$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0,5, !empty($payment_date)?date("F d, Y - h:i A",strtotime($payment_date)):"N/A");

$pdf->Ln(5);

/* ===== FOOTER ===== */
$pdf->SetFont('Arial','I',8);
$pdf->MultiCell(0,4,"Please present this ticket before boarding.",0,'C');

/* OUTPUT */
$pdf->Output('D','MBUS_Ticket.pdf');
exit();
?>
