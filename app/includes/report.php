<?php
/**
 * SportsInfraX – Report Engine
 *
 * Column definition array keys:
 *   key          string   Row array key to read value from
 *   label        string   Header label
 *   format       mixed    null | 'date' | 'money' | callable($value, $row): string
 *                         Callable must return a string (may include HTML for screen; tags stripped for print/xlsx).
 *   width        int      xlsx column width in characters (default: 18)
 *   noscreen     bool     Exclude from on-screen HTML table
 *   noprint      bool     Exclude from printable HTML page
 *   noxlsx       bool     Exclude from xlsx export
 */
class Report
{
    /**
     * Render an HTML <table> for on-screen or print view.
     * $forPrint=true strips HTML from callable-formatted cells and skips 'noprint' cols.
     */
    public static function table(array $rows, array $cols, bool $forPrint = false): string
    {
        $skip = $forPrint ? 'noprint' : 'noscreen';
        $vis  = array_values(array_filter($cols, fn($c) => empty($c[$skip])));

        $html  = '<table class="table table-bordered' . ($forPrint ? '' : ' table-hover') . '">';
        $html .= '<thead class="table-dark"><tr>';
        foreach ($vis as $c) {
            $html .= '<th>' . h($c['label']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (!$rows) {
            $span = count($vis);
            $html .= '<tr><td colspan="' . $span . '" class="text-center text-muted py-3">No records found.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($vis as $c) {
                    $val  = $row[$c['key']] ?? null;
                    $cell = $forPrint
                        ? h(self::_plain($val, $row, $c['format'] ?? null))
                        : self::_html($val, $row, $c['format'] ?? null);
                    $html .= '<td>' . $cell . '</td>';
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Stream an xlsx download. Calls exit() when done.
     * Requires PhpSpreadsheet via Composer (vendor/autoload.php).
     */
    public static function xlsx(
        array   $rows,
        array   $cols,
        string  $title,
        array   $filters,
        ?array  $inst = null
    ): void {
        $autoload = dirname(APP_ROOT) . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            http_response_code(503);
            die('<p>Excel export is unavailable. Run <code>composer install</code> on the server.</p>');
        }
        require_once $autoload;

        $vis = array_values(array_filter($cols, fn($c) => empty($c['noxlsx'])));

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));

        $r = 1;

        // Institution name
        if (!empty($inst['institution_name'])) {
            $sheet->setCellValue('A' . $r, $inst['institution_name']);
            $sheet->getStyle('A' . $r)->getFont()->setBold(true)->setSize(13);
            $r++;
        }

        // Report title
        $sheet->setCellValue('A' . $r, $title);
        $sheet->getStyle('A' . $r)->getFont()->setBold(true)->setSize(11);
        $r++;

        // Active filters
        foreach ($filters as $label => $value) {
            if ($value === null || $value === '') continue;
            $sheet->setCellValue('A' . $r, $label . ': ' . $value);
            $sheet->getStyle('A' . $r)->getFont()->setSize(9)->setItalic(true);
            $r++;
        }

        // Generated line
        $sheet->setCellValue('A' . $r, 'Generated: ' . date('d M Y, H:i') . '  by ' . authName());
        $sheet->getStyle('A' . $r)->getFont()->setSize(9)
              ->getColor()->setARGB('FF9CA3AF');
        $r += 2; // blank separator

        // Column header row
        $headerRow = $r;
        foreach ($vis as $i => $c) {
            $cl = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($cl . $r, $c['label']);
            $style = $sheet->getStyle($cl . $r);
            $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $style->getFill()
                  ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                  ->getStartColor()->setARGB('FF0B1F3A');
            $style->getAlignment()
                  ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth($c['width'] ?? 18);
        }
        $r++;

        // Data rows
        foreach ($rows as $row) {
            foreach ($vis as $i => $c) {
                $cl  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $raw = $row[$c['key']] ?? null;
                if (($c['format'] ?? null) === 'money' && is_numeric($raw)) {
                    $sheet->setCellValue($cl . $r, (float)$raw);
                    $sheet->getStyle($cl . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                } else {
                    $sheet->setCellValue($cl . $r, self::_plain($raw, $row, $c['format'] ?? null));
                }
            }
            $r++;
        }

        // Freeze panes below header row
        $sheet->freezePane('A' . ($headerRow + 1));

        // Auto-filter on header row
        $lastCl = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($vis));
        $sheet->setAutoFilter('A' . $headerRow . ':' . $lastCl . $headerRow);

        // Stream
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $title);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe . '_' . date('Ymd') . '.xlsx"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        exit;
    }

    /**
     * Output a standalone printable HTML page. Calls exit() when done.
     * Opens in a new tab; has a "Print / Save PDF" button.
     */
    public static function printPage(
        array   $rows,
        array   $cols,
        string  $title,
        array   $filters,
        ?array  $inst = null
    ): void {
        $instName  = $inst['institution_name'] ?? APP_NAME;
        $logoHtml  = '';
        if (!empty($inst['logo'])) {
            $logoHtml = '<img src="' . h(LOGO_URL . '/' . $inst['logo'])
                      . '" alt="Logo" style="height:48px;object-fit:contain;vertical-align:middle;margin-right:14px;">';
        }

        $filterChips = '';
        foreach ($filters as $lbl => $val) {
            if ($val === null || $val === '') continue;
            $filterChips .= '<span class="chip"><b>' . h($lbl) . ':</b> ' . h($val) . '</span> ';
        }

        $tableHtml = self::table($rows, $cols, true);
        $count     = count($rows);
        $genLine   = h(date('d M Y, H:i')) . ' &nbsp;·&nbsp; ' . h(authName())
                   . ' &nbsp;·&nbsp; ' . $count . ' record' . ($count !== 1 ? 's' : '');

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width">
<title>' . h($title) . ' – ' . h($instName) . '</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body  { font-family: "Segoe UI", system-ui, sans-serif; color: #1f2937; margin: 0; background: #fff; }
  .toolbar { background: #0b1f3a; color: #fff; padding: 9px 18px; display: flex; align-items: center; gap: 8px; }
  .toolbar button { border: 1px solid rgba(255,255,255,.35); background: rgba(255,255,255,.1);
    color: #fff; padding: 4px 14px; border-radius: 4px; cursor: pointer; font-size: 13px; }
  .toolbar button:hover { background: rgba(255,255,255,.2); }
  .wrap  { padding: 18px 24px; }
  .rpt-header { display: flex; align-items: center; border-bottom: 2.5px solid #0b1f3a; padding-bottom: 10px; margin-bottom: 10px; }
  .rpt-title  { font-size: 18pt; font-weight: 700; color: #0b1f3a; margin: 0; line-height: 1.2; }
  .rpt-sub    { font-size: 10pt; color: #4b5563; margin: 3px 0 0; }
  .filter-bar { margin: 6px 0 10px; font-size: 9pt; }
  .chip       { display: inline-block; background: #eff6ff; color: #1d4ed8;
    border: 1px solid #bfdbfe; border-radius: 12px; padding: 1px 9px; margin: 2px 3px 2px 0; }
  .generated  { font-size: 8.5pt; color: #9ca3af; margin-bottom: 14px; }
  table       { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 10px; }
  thead th    { background: #0b1f3a; color: #fff; padding: 6px 8px; text-align: left; font-weight: 600; white-space: nowrap; }
  tbody td    { border: 1px solid #e5e7eb; padding: 5px 8px; vertical-align: top; }
  tbody tr:nth-child(even) td { background: #f8fafc; }
  .rpt-footer { display: flex; justify-content: space-between; font-size: 8pt; color: #9ca3af;
    border-top: 1px solid #e5e7eb; padding-top: 8px; margin-top: 4px; }
  @media print {
    @page { size: A4 landscape; margin: 12mm 10mm; }
    .toolbar { display: none !important; }
    body    { font-size: 9.5pt; }
    table   { font-size: 8pt; }
    thead th{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    tbody tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    a { color: inherit !important; text-decoration: none !important; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="window.print()">&#128438; Print / Save PDF</button>
  <button onclick="window.close()">&#10005; Close</button>
  <span style="opacity:.6;font-size:13px;margin-left:10px;">' . h($title) . '</span>
</div>
<div class="wrap">
  <div class="rpt-header">
    ' . $logoHtml . '
    <div>
      <p class="rpt-title">' . h($title) . '</p>
      <p class="rpt-sub">' . h($instName) . '</p>
    </div>
  </div>
  ' . ($filterChips ? '<div class="filter-bar"><b style="color:#4b5563">Filters:</b> ' . $filterChips . '</div>' : '') . '
  <p class="generated">' . $genLine . '</p>
  ' . $tableHtml . '
  <div class="rpt-footer">
    <span>' . h(APP_NAME) . ' &nbsp;&middot;&nbsp; ' . h($instName) . '</span>
    <span>Printed ' . h(date('d M Y')) . '</span>
  </div>
</div>
</body>
</html>';
        exit;
    }

    // ── Private formatting helpers ─────────────────────────────

    private static function _html(mixed $val, array $row, mixed $fmt): string
    {
        if ($val === null || $val === '') {
            return '<span class="text-muted">—</span>';
        }
        if (is_callable($fmt)) return $fmt($val, $row);
        return match($fmt) {
            'date'  => h(fmtDate((string)$val)),
            'money' => '&#8377;' . h(number_format((float)$val, 2)),
            default => h((string)$val),
        };
    }

    private static function _plain(mixed $val, array $row, mixed $fmt): string
    {
        if ($val === null || $val === '') return '';
        if (is_callable($fmt)) return strip_tags((string)$fmt($val, $row));
        return match($fmt) {
            'date'  => fmtDate((string)$val, 'Y-m-d'),
            'money' => number_format((float)$val, 2),
            default => (string)$val,
        };
    }
}
