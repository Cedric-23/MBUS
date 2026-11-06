<?php

require_once __DIR__ . '/vendor/qrcode-generator/qrcode.php';

use MBUS\Vendor\QRCodeGenerator\QRCode;
use MBUS\Vendor\QRCodeGenerator\QRUtil;
use const MBUS\Vendor\QRCodeGenerator\QR_ERROR_CORRECT_LEVEL_M;
use const MBUS\Vendor\QRCodeGenerator\QR_MODE_8BIT_BYTE;

/** Encode the supplied payload byte-for-byte, locally, with medium error correction. */
function mbus_qr_matrix(string $payload): array
{
    $length = strlen($payload);
    if ($length === 0) {
        throw new InvalidArgumentException('QR payload must not be empty.');
    }

    // The vendored automatic-capacity table covers versions 1 through 10.
    for ($version = 1; $version <= 10; $version++) {
        if ($length <= QRUtil::getMaxLength($version, QR_MODE_8BIT_BYTE, QR_ERROR_CORRECT_LEVEL_M)) {
            $qr = new QRCode();
            $qr->setTypeNumber($version);
            $qr->setErrorCorrectLevel(QR_ERROR_CORRECT_LEVEL_M);
            $qr->addData($payload, QR_MODE_8BIT_BYTE);
            $qr->make();

            $matrix = [];
            for ($row = 0; $row < $qr->getModuleCount(); $row++) {
                $matrix[$row] = [];
                for ($col = 0; $col < $qr->getModuleCount(); $col++) {
                    $matrix[$row][$col] = (bool)$qr->isDark($row, $col);
                }
            }
            return $matrix;
        }
    }

    throw new InvalidArgumentException('QR payload exceeds the supported capacity.');
}

/**
 * Draw an opaque QR square, including a four-module white quiet zone on all sides.
 * Coordinates and size use the FPDF document's units (millimetres for tickets).
 * The caller reserves vertical space; this function does not move the text cursor.
 */
function mbus_pdf_draw_qr(FPDF $pdf, string $payload, float $x, float $y, float $size): void
{
    if (!is_finite($x) || !is_finite($y) || !is_finite($size) || $size <= 0
        || $x < 0 || $y < 0 || $x + $size > $pdf->GetPageWidth()
        || $y + $size > $pdf->GetPageHeight()) {
        throw new InvalidArgumentException('QR must fit entirely within the PDF page.');
    }

    $matrix = mbus_qr_matrix($payload);
    $count = count($matrix);
    $quietZone = 4;
    $moduleSize = $size / ($count + 2 * $quietZone);

    // Ensure an opaque white background; leave the fill colour black for receipt text.
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x, $y, $size, $size, 'F');
    $pdf->SetFillColor(0, 0, 0);
    for ($row = 0; $row < $count; $row++) {
        for ($col = 0; $col < $count; $col++) {
            if (!$matrix[$row][$col]) {
                continue;
            }
            // Merge adjacent dark modules into horizontal runs for a smaller vector PDF.
            $start = $col;
            while ($col + 1 < $count && $matrix[$row][$col + 1]) {
                $col++;
            }
            $pdf->Rect(
                $x + ($start + $quietZone) * $moduleSize,
                $y + ($row + $quietZone) * $moduleSize,
                ($col - $start + 1) * $moduleSize,
                $moduleSize,
                'F'
            );
        }
    }
}
