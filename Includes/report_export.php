<?php
/* Report downloads: a formatted Excel workbook (.xlsx) and a PDF table. Replaces the old CSV exports.
 * No extensions or libraries beyond zlib (built into PHP) and the FPDF copy already in Commuter/fpdf.
 *
 * mbus_report_send($format, $baseName, $title, $meta, $summary, $head, $rows)
 *   $format  'xlsx' | 'pdf'
 *   $meta    ['From' => '2026-11-01', ...]  small label/value lines under the title (filters, dates, who generated it)
 *   $summary ['Trips' => 12, 'Revenue (PHP)' => '1,200.00']  headline numbers, may be empty
 *   $head    column titles;  $rows  list of rows (same number of cells as $head)
 * Sends the file and exits. */

require_once __DIR__ . '/../Commuter/fpdf/fpdf.php';

/* ---------- helpers ---------- */

function mbus_report_is_money(string $head): bool { return (bool)preg_match('/\(PHP\)|amount|revenue/i', $head); }

function mbus_report_xml($v): string
{
    $s = (string)$v;
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function mbus_report_col_letter(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/** Minimal ZIP writer (deflate), enough for an .xlsx package. */
function mbus_report_zip(array $files): string
{
    $out = ''; $central = ''; $n = 0;
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    foreach ($files as $name => $data) {
        $crc = crc32($data); $size = strlen($data);
        $comp = gzdeflate($data, 6); $csize = strlen($comp);
        $off = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $dosTime, $dosDate, $crc, $csize, $size, strlen($name), 0) . $name . $comp;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, $csize, $size, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
        $n++;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($out), 0);
}

/* ---------- Excel ---------- */

function mbus_report_xlsx(string $title, array $meta, array $summary, array $head, array $rows): string
{
    $cols = count($head);
    $lastCol = mbus_report_col_letter(max(0, $cols - 1));
    $r = 1;
    $xml = '';
    $cell = static function (int $c, int $row, $v, int $style, bool $number = false): string {
        $ref = mbus_report_col_letter($c) . $row;
        if ($v === null || $v === '') return '<c r="' . $ref . '" s="' . $style . '"/>';
        if ($number && is_numeric($v)) return '<c r="' . $ref . '" s="' . $style . '"><v>' . (0 + $v) . '</v></c>';
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . mbus_report_xml($v) . '</t></is></c>';
    };
    $merges = [];

    $xml .= '<row r="' . $r . '" ht="26" customHeight="1">' . $cell(0, $r, 'MBUS  ' . $title, 1) . '</row>';
    $merges[] = "A$r:{$lastCol}$r"; $r++;
    foreach ($meta as $k => $v) {
        $xml .= '<row r="' . $r . '">' . $cell(0, $r, $k . ': ' . $v, 2) . '</row>';
        $merges[] = "A$r:{$lastCol}$r"; $r++;
    }
    $r++;
    if ($summary) {
        $xml .= '<row r="' . $r . '">' . $cell(0, $r, 'Summary', 9) . '</row>'; $r++;
        $pairs = array_chunk($summary, 3, true);   // three label/value pairs per line
        foreach ($pairs as $chunk) {
            $xml .= '<row r="' . $r . '" ht="18" customHeight="1">';
            $c = 0;
            foreach ($chunk as $label => $value) {
                $xml .= $cell($c, $r, $label, 7) . $cell($c + 1, $r, $value, 8);
                $c += 2;
            }
            $xml .= '</row>'; $r++;
        }
        $r++;
    }
    $headRow = $r;
    $xml .= '<row r="' . $r . '" ht="22" customHeight="1">';
    foreach ($head as $i => $h) $xml .= $cell($i, $r, $h, 3);
    $xml .= '</row>'; $r++;

    $width = [];
    foreach ($head as $i => $h) $width[$i] = mb_strlen((string)$h) + 4;
    foreach ($rows as $row) {
        $xml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $i => $v) {
            $money = mbus_report_is_money((string)($head[$i] ?? ''));
            if ($money && is_numeric($v)) $xml .= $cell($i, $r, $v, 6, true);
            elseif (is_int($v) || is_float($v)) $xml .= $cell($i, $r, $v, 5, true);
            else $xml .= $cell($i, $r, $v, 4);
            $width[$i] = max($width[$i] ?? 0, min(42, mb_strlen((string)$v) + 3));
        }
        $xml .= '</row>'; $r++;
    }
    $lastRow = max($headRow, $r - 1);
    $colsXml = '';
    if ($summary) foreach (range(0, min(5, count($head) - 1)) as $i) $width[$i] = max($width[$i] ?? 0, 18);
    foreach ($width as $i => $w) $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(10, $w) . '" customWidth="1"/>';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $headRow . '" topLeftCell="A' . ($headRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="16"/><cols>' . $colsXml . '</cols><sheetData>' . $xml . '</sheetData>'
        . '<autoFilter ref="A' . $headRow . ':' . $lastCol . $lastRow . '"/>'
        . '<mergeCells count="' . count($merges) . '">' . implode('', array_map(fn($m) => '<mergeCell ref="' . $m . '"/>', $merges)) . '</mergeCells>'
        . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '<headerFooter><oddFooter>&amp;LMBUS&amp;RPage &amp;P of &amp;N</oddFooter></headerFooter></worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        . '<fonts count="6">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FF1E4FB8"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="10"/><color rgb="FF5A6B82"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FF1E4FB8"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF2F6FE4"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF1FF"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD3DCEB"/></left><right style="thin"><color rgb="FFD3DCEB"/></right><top style="thin"><color rgb="FFD3DCEB"/></top><bottom style="thin"><color rgb="FFD3DCEB"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . mbus_report_xml(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title), 0, 30)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . mbus_report_xml(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title), 0, 30)) . '\'!$A$' . $headRow . ':$' . $lastCol . '$' . $lastRow . '</definedName>'
            . '<definedName name="_xlnm.Print_Titles" localSheetId="0">\'' . mbus_report_xml(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title), 0, 30)) . '\'!$' . $headRow . ':$' . $headRow . '</definedName></definedNames></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    return mbus_report_zip($files);
}

/* ---------- PDF ---------- */

class MbusReportPDF extends FPDF
{
    public $reportTitle = '';
    function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(110, 122, 140);
        $this->Cell(0, 5, 'MBUS - ' . $this->reportTitle, 0, 0, 'L');
        $this->SetX(-40);
        $this->Cell(30, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }
}

function mbus_report_latin(string $s): string
{
    $s = str_replace(["\u{20B1}", "\u{2013}", "\u{2014}", "\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}"], ['PHP ', '-', '-', "'", "'", '"', '"'], $s);
    $c = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $c === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $c;
}

function mbus_report_pdf(string $title, array $meta, array $summary, array $head, array $rows): string
{
    $pdf = new MbusReportPDF('L', 'mm', 'A4');
    $pdf->reportTitle = mbus_report_latin($title);
    $pdf->AliasNbPages();
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(10, 10, 10);
    $usable = 277;
    $fs = count($head) > 10 ? 7 : (count($head) > 8 ? 7.5 : 8.5);

    /* column widths: as wide as the longest cell (up to a cap), then squeezed to fit the page */
    $pdf->SetFont('Arial', '', $fs);
    $w = [];
    foreach ($head as $i => $h) {
        $pdf->SetFont('Arial', 'B', $fs);
        $w[$i] = $pdf->GetStringWidth(mbus_report_latin((string)$h)) + 5;
    }
    $pdf->SetFont('Arial', '', $fs);
    foreach (array_slice($rows, 0, 400) as $row) {
        foreach (array_values($row) as $i => $v) {
            $w[$i] = max($w[$i] ?? 0, min(52, $pdf->GetStringWidth(mbus_report_latin((string)$v)) + 5));
        }
    }
    $sum = array_sum($w) ?: 1;
    foreach ($w as $i => $x) $w[$i] = $x * $usable / $sum;

    $drawHead = function () use ($pdf, $head, $w, $fs) {
        $pdf->SetFillColor(47, 111, 228); $pdf->SetTextColor(255, 255, 255); $pdf->SetDrawColor(47, 111, 228);
        $pdf->SetFont('Arial', 'B', $fs);
        foreach ($head as $i => $h) $pdf->Cell($w[$i], 8, mbus_report_latin((string)$h), 1, 0, 'C', true);
        $pdf->Ln();
        $pdf->SetTextColor(30, 40, 55); $pdf->SetDrawColor(211, 220, 235);
        $pdf->SetFont('Arial', '', $fs);
    };

    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 18); $pdf->SetTextColor(30, 79, 184);
    $pdf->Cell(0, 9, 'MBUS', 0, 1);
    $pdf->SetFont('Arial', 'B', 13); $pdf->SetTextColor(30, 40, 55);
    $pdf->Cell(0, 7, mbus_report_latin($title), 0, 1);
    $pdf->SetFont('Arial', '', 8.5); $pdf->SetTextColor(90, 107, 130);
    foreach ($meta as $k => $v) $pdf->Cell(0, 4.6, mbus_report_latin($k . ': ' . $v), 0, 1);
    $pdf->Ln(3);

    if ($summary) {
        $n = count($summary);
        $bw = min(46, ($usable - ($n - 1) * 3) / $n);
        $x0 = $pdf->GetX(); $y0 = $pdf->GetY();
        $i = 0;
        foreach ($summary as $label => $value) {
            $x = $x0 + $i * ($bw + 3);
            $pdf->SetFillColor(234, 241, 255); $pdf->SetDrawColor(211, 220, 235);
            $pdf->Rect($x, $y0, $bw, 14, 'DF');
            $pdf->SetXY($x, $y0 + 1.5); $pdf->SetFont('Arial', 'B', 11); $pdf->SetTextColor(30, 79, 184);
            $pdf->Cell($bw, 6, mbus_report_latin((string)$value), 0, 0, 'C');
            $pdf->SetXY($x, $y0 + 8); $pdf->SetFont('Arial', '', 7.5); $pdf->SetTextColor(90, 107, 130);
            $pdf->Cell($bw, 4, mbus_report_latin((string)$label), 0, 0, 'C');
            $i++;
        }
        $pdf->SetXY($x0, $y0 + 18);
    }

    $drawHead();
    $rh = 6.2; $zebra = false;
    foreach ($rows as $row) {
        if ($pdf->GetY() + $rh > 190) { $pdf->AddPage(); $drawHead(); $zebra = false; }
        $pdf->SetFillColor(246, 249, 255);
        foreach (array_values($row) as $i => $v) {
            $txt = mbus_report_latin((string)$v);
            while ($txt !== '' && $pdf->GetStringWidth($txt) > $w[$i] - 2.2) $txt = substr($txt, 0, -1);
            if ($txt !== mbus_report_latin((string)$v) && strlen($txt) > 2) $txt = substr($txt, 0, -2) . '..';
            $num = is_int($v) || is_float($v) || (mbus_report_is_money((string)($head[$i] ?? '')) && is_numeric($v));
            if ($num && mbus_report_is_money((string)($head[$i] ?? ''))) $txt = number_format((float)$v, 2);
            $pdf->Cell($w[$i], $rh, $txt, 1, 0, $num ? 'R' : 'L', $zebra);
        }
        $pdf->Ln();
        $zebra = !$zebra;
    }
    if (!$rows) {
        $pdf->SetFont('Arial', 'I', 9); $pdf->SetTextColor(90, 107, 130);
        $pdf->Cell(0, 8, 'No rows match these filters.', 0, 1);
    }
    return $pdf->Output('S');
}

/* ---------- send ---------- */

function mbus_report_send(string $format, string $baseName, string $title, array $meta, array $summary, array $head, array $rows): void
{
    $baseName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $baseName) ?: 'mbus_report';
    $meta = ['Generated' => date('M j, Y h:i A')] + $meta;
    if ($format === 'pdf') {
        $data = mbus_report_pdf($title, $meta, $summary, $head, $rows);
        $type = 'application/pdf'; $ext = 'pdf';
    } else {
        $data = mbus_report_xlsx($title, $meta, $summary, $head, $rows);
        $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; $ext = 'xlsx';
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $baseName . '.' . $ext . '"');
    header('Content-Length: ' . strlen($data));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $data;
    exit;
}

/** 'xlsx' (default) or 'pdf' from ?format= */
function mbus_report_format(): string
{
    return (($_GET['format'] ?? '') === 'pdf') ? 'pdf' : 'xlsx';
}
