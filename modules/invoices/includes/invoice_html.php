<?php
/**
 * invoice_html.php — the ONE invoice / credit note layout, shared by:
 *   - invoice_pdf.php / cn_pdf.php   (page; Chrome "Print → Save as PDF")
 *   - buildInvoiceHtml() → Dompdf    (inv_pdf(): API save_invoice_pdf, api_send_invoice_email.php,
 *                                     invoices_zip.php)
 *
 * Table layout and plain class CSS only (Dompdf has no flex / grid), so the page and the
 * Dompdf PDF render the same. Change the layout here, never in the pages.
 *
 *   inv_doc_issuer()          issuer name, logo, address, bank details (SH: AfrAsia, per currency)
 *   inv_doc_from_invoice()    invoice row + items  → document array
 *   inv_doc_from_credit_note()credit note row + items → document array
 *   inv_doc_css()             shared CSS
 *   inv_doc_body()            <div class="invoice-body">…</div>
 *   buildInvoiceHtml()        full Dompdf HTML for an invoice (Open Sans from assets/fonts)
 *   inv_doc_dompdf_options()  Dompdf options for it (font folder allowed, font cache in tmp)
 */

const INV_DOC_SH = 'Savannah Holidays Ltd';

function inv_doc_e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Issuer data: name, logo file (assets/), address lines, bank rows (Savannah Holidays only, per currency). */
function inv_doc_issuer(string $issuer, string $currency): array {
    if ($issuer === INV_DOC_SH) {
        $usd = $currency === 'USD';
        return [
            'name'    => INV_DOC_SH,
            'logo'    => 'logo_sh.png',
            'address' => ['Certificate of Incorporation No. 212622',
                          'H21 Home Scene Building, Healthscape, Forbach - Mauritius',
                          'info@savannahholidays.net'],
            'bank'    => [
                ['Beneficiary Bank:', 'AfrAsia Bank Ltd'],
                ['Bank Address:',     'Bowen Square, 10, Dr Ferriere Street, Port Louis, Mauritius'],
                ['Account Name:',     'SAVANNAH HOLIDAYS LTD'],
                ['IBAN:',             $usd ? 'MU55AFBL2501138053000000013USD' : 'MU40AFBL2501138053000000024EUR'],
                ['Account Number:',   $usd ? '138053000000013' : '138053000000024'],
                ['Currency:',         $usd ? 'USD' : 'EUR'],
                ['Swift Code:',       'AFBLMUMU'],
            ],
        ];
    }
    return ['name' => $issuer !== '' ? $issuer : 'Savannah Explorers Ltd', 'logo' => 'logo_se.png',
            'address' => ['Arusha, P.O. Box 16726', 'Tanzania'], 'bank' => null];
}

function inv_doc_qty($q): string {
    return rtrim(rtrim(number_format((float)$q, 2), '0'), '.');
}

/** Invoice → document. Payment Made only when something is paid; Terms / Due Date only when set. */
function inv_doc_from_invoice(array $inv, array $items): array {
    $sym   = $inv['currency'] === 'EUR' ? '€' : '$';
    $dates = [['Invoice Date :', date('d M Y', strtotime($inv['issue_date']))]];
    if (!empty($inv['terms']))    $dates[] = ['Terms :', $inv['terms']];
    if (!empty($inv['due_date'])) $dates[] = ['Due Date :', date('d M Y', strtotime($inv['due_date']))];

    $rows = [];
    foreach ($items as $n => $it) {
        $rows[] = ['n' => $n + 1, 'desc' => $it['description'], 'qty' => inv_doc_qty($it['quantity']),
                   'rate' => number_format((float)$it['unit_price'], 2),
                   'amount' => number_format((float)$it['line_total'], 2), 'red' => (float)$it['line_total'] < 0];
    }
    $totals = [['Sub Total', number_format((float)$inv['subtotal'], 2), ''],
               ['Total', $sym . number_format((float)$inv['total'], 2), 'total']];
    if ((float)$inv['amount_paid'] > 0) $totals[] = ['Payment Made', '(-) ' . number_format((float)$inv['amount_paid'], 2), 'payment'];
    $totals[] = ['Balance Due', $sym . number_format((float)$inv['balance_due'], 2), 'balance'];

    return [
        'kind'         => 'invoice',
        'title'        => 'Invoice',
        'number'       => $inv['invoice_number'],
        'ref_line'     => '',
        'amount_label' => 'Balance Due',
        'amount'       => $sym . number_format((float)$inv['balance_due'], 2),
        'issuer'       => inv_doc_issuer((string)$inv['issuer'], (string)$inv['currency']),
        'bill_label'   => 'Bill To',
        'bill_name'    => $inv['bill_to_name'],
        'bill_addr'    => (string)($inv['bill_to_address'] ?? ''),
        'dates'        => $dates,
        'cols'         => ['Qty', 'Rate', 'Amount'],
        'items'        => $rows,
        'totals'       => $totals,
        'notes'        => (string)($inv['notes'] ?? ''),
        'terms_conditions' => (string)($inv['terms_conditions'] ?? ''),
        'show_bank'    => true,
    ];
}

/** Credit note (row joined with the original invoice_number) → document. */
function inv_doc_from_credit_note(array $cn, array $items): array {
    $sym   = $cn['currency'] === 'EUR' ? '€' : '$';
    $dates = [['Credit Note Date :', date('d M Y', strtotime($cn['issue_date']))]];
    if (!empty($cn['invoice_number'])) $dates[] = ['Original Invoice :', $cn['invoice_number']];
    if (!empty($cn['reason']))         $dates[] = ['Reason :', $cn['reason']];

    $rows = [];
    foreach ($items as $n => $it) {
        $rows[] = ['n' => $n + 1, 'desc' => $it['description'], 'qty' => inv_doc_qty($it['quantity']),
                   'rate' => number_format((float)$it['unit_price'], 2),
                   'amount' => number_format((float)$it['line_total'], 2), 'red' => true];
    }
    return [
        'kind'         => 'credit_note',
        'title'        => 'Credit Note',
        'number'       => $cn['cn_number'],
        'ref_line'     => !empty($cn['invoice_number']) ? 'Against invoice ' . $cn['invoice_number'] : '',
        'amount_label' => 'Total Credit',
        'amount'       => $sym . number_format((float)$cn['total'], 2),
        'issuer'       => inv_doc_issuer((string)$cn['issuer'], (string)$cn['currency']),
        'bill_label'   => 'Credit To',
        'bill_name'    => $cn['bill_to_name'],
        'bill_addr'    => (string)($cn['bill_to_address'] ?? ''),
        'dates'        => $dates,
        'cols'         => ['Qty', 'Amount', 'Credit'],
        'items'        => $rows,
        'totals'       => [['Sub Total', number_format((float)$cn['subtotal'], 2), ''],
                           ['Total Credit', $sym . number_format((float)$cn['total'], 2), 'balance']],
        'notes'        => (string)($cn['notes'] ?? ''),
        'terms_conditions' => '',
        'show_bank'    => false,
    ];
}

/**
 * Shared CSS. Page and Dompdf differ only in font and outer padding (set by the caller).
 * No background fills: the reference is Chrome's print (background graphics off).
 */
function inv_doc_css(): string {
    return <<<'CSS'
.invoice-body { color: #333; font-size: 13px; }
.invoice-body table { border-collapse: collapse; }
.lay { width: 100%; }
.lay td { vertical-align: top; padding: 0; }
.inv-header { margin-bottom: 36px; }
.inv-logo { height: 90px; width: auto; }
.inv-title-block { text-align: right; }
.inv-title { font-size: 32px; font-weight: 300; color: #333; letter-spacing: 1px; margin: 0 0 4px; line-height: 1.2; }
.inv-number { font-size: 13px; color: #555; margin-bottom: 12px; }
.ref-line { font-size: 12px; color: #666; margin: -8px 0 10px; }
.inv-balance-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; }
.inv-balance-amount { font-size: 24px; font-weight: 700; color: #1A1A1A; margin-top: 2px; }
.issuer-block { margin-bottom: 32px; }
.issuer-name { font-weight: 700; font-size: 14px; color: #1A1A1A; }
.issuer-addr { font-size: 12px; color: #666; line-height: 1.6; margin-top: 2px; }
.meta-row { margin-bottom: 36px; }
.bill-to-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
.bill-to-name { font-weight: 700; font-size: 14px; color: #1A1A1A; }
.bill-to-addr { font-size: 12px; color: #666; line-height: 1.6; margin-top: 2px; }
.date-table { margin-left: auto; }
.date-table td { padding: 3px 0 3px 24px; font-size: 12px; text-align: right; }
.date-table td.k { color: #888; }
.date-table td.v { font-weight: 600; color: #333; }
.items-tbl { width: 100%; }
.items-tbl th { color: #999; font-size: 11px; font-weight: 600; text-transform: uppercase;
                letter-spacing: .06em; padding: 10px 14px; text-align: left; }
.items-tbl td { padding: 12px 14px; border-bottom: 1px solid #eee; font-size: 12.5px; vertical-align: top; }
.items-tbl tr.last td { border-bottom: 2px solid #ddd; }
.items-tbl .r { text-align: right; }
.items-tbl th.w-n { width: 36px; } .items-tbl th.w-q { width: 70px; } .items-tbl th.w-a { width: 100px; }
.items-tbl td.n { color: #aaa; }
.red { color: #C0211B; }
.totals-tbl { width: 280px; margin-left: auto; }
.totals-tbl td { padding: 7px 14px; font-size: 12.5px; border-bottom: 1px solid #eee; }
.totals-tbl td.l { text-align: left; color: #555; }
.totals-tbl td.r { text-align: right; font-weight: 600; }
.totals-tbl tr.total td { font-weight: 700; color: #333; }
.totals-tbl tr.payment td { color: #C0211B; }
.totals-tbl tr.balance td { font-weight: 700; font-size: 14px; color: #1A1A1A; border-bottom: none; padding-top: 10px; }
.footer-sections { margin-top: 36px; width: 100%; }
.footer-sections td.col { width: 50%; padding-right: 24px; }
.footer-sections td.col2 { padding-right: 0; padding-left: 0; }
.footer-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 5px; }
.footer-text { font-size: 12px; color: #555; line-height: 1.65; }
.bank-details { margin-top: 20px; padding: 10px 20px; border-left: 3px solid #C0211B; page-break-inside: avoid; }
.bank-details table td { padding: 1px 0; font-size: 12px; }
.bank-details table td.k { color: #888; width: 160px; }
.bank-details table td.v { font-weight: 600; color: #1A1A1A; }
.doc-cn .inv-title, .doc-cn .inv-balance-amount { color: #C0211B; }
.doc-cn .inv-title { font-size: 30px; }
.doc-cn .totals-tbl tr.balance td { color: #C0211B; }
CSS;
}

/** The document body. $logoSrc: URL for the page, data: URI for Dompdf. */
function inv_doc_body(array $d, string $logoSrc): string {
    $e  = 'inv_doc_e';
    $is = $d['issuer'];
    $h  = '<div class="invoice-body' . ($d['kind'] === 'credit_note' ? ' doc-cn' : '') . '">';

    // Header: logo | title, number, amount
    $h .= '<table class="lay inv-header"><tr>'
        . '<td><img class="inv-logo" src="' . $e($logoSrc) . '" alt="' . $e($is['name']) . '"></td>'
        . '<td class="inv-title-block">'
        . '<div class="inv-title">' . $e($d['title']) . '</div>'
        . '<div class="inv-number"># ' . $e($d['number']) . '</div>'
        . ($d['ref_line'] !== '' ? '<div class="ref-line">' . $e($d['ref_line']) . '</div>' : '')
        . '<div class="inv-balance-label">' . $e($d['amount_label']) . '</div>'
        . '<div class="inv-balance-amount">' . $e($d['amount']) . '</div>'
        . '</td></tr></table>';

    // Issuer
    $h .= '<div class="issuer-block"><div class="issuer-name">' . $e($is['name']) . '</div>'
        . '<div class="issuer-addr">' . implode('<br>', array_map($e, $is['address'])) . '</div></div>';

    // Bill To | dates (only the rows that have a value)
    $dates = '';
    foreach ($d['dates'] as $r) $dates .= '<tr><td class="k">' . $e($r[0]) . '</td><td class="v">' . $e($r[1]) . '</td></tr>';
    $h .= '<table class="lay meta-row"><tr><td>'
        . '<div class="bill-to-label">' . $e($d['bill_label']) . '</div>'
        . '<div class="bill-to-name">' . $e($d['bill_name']) . '</div>'
        . ($d['bill_addr'] !== '' ? '<div class="bill-to-addr">' . nl2br($e($d['bill_addr'])) . '</div>' : '')
        . '</td><td><table class="date-table">' . $dates . '</table></td></tr></table>';

    // Items
    $h .= '<table class="items-tbl"><thead><tr>'
        . '<th class="w-n">#</th><th>Item &amp; Description</th>'
        . '<th class="r w-q">' . $e($d['cols'][0]) . '</th>'
        . '<th class="r w-a">' . $e($d['cols'][1]) . '</th>'
        . '<th class="r w-a">' . $e($d['cols'][2]) . '</th>'
        . '</tr></thead><tbody>';
    $last = count($d['items']) - 1;
    foreach ($d['items'] as $i => $it) {
        $h .= '<tr' . ($i === $last ? ' class="last"' : '') . '>'
            . '<td class="n">' . (int)$it['n'] . '</td>'
            . '<td>' . $e($it['desc']) . '</td>'
            . '<td class="r">' . $e($it['qty']) . '</td>'
            . '<td class="r">' . $e($it['rate']) . '</td>'
            . '<td class="r' . ($it['red'] ? ' red' : '') . '">' . $e($it['amount']) . '</td></tr>';
    }
    $h .= '</tbody></table>';

    // Totals (right)
    $h .= '<table class="totals-tbl">';
    foreach ($d['totals'] as $t) {
        $h .= '<tr' . ($t[2] !== '' ? ' class="' . $t[2] . '"' : '') . '><td class="l">' . $e($t[0]) . '</td><td class="r">' . $e($t[1]) . '</td></tr>';
    }
    $h .= '</table>';

    // Notes | Terms & Conditions, side by side
    $cols = [];
    if ($d['notes'] !== '') $cols[] = ['Notes', $d['notes']];
    if ($d['terms_conditions'] !== '') $cols[] = ['Terms &amp; Conditions', $d['terms_conditions']];
    if ($cols) {
        $h .= '<table class="lay footer-sections"><tr>';
        foreach ($cols as $i => $c) {
            $h .= '<td class="col' . ($i === 1 ? ' col2' : '') . '"><div class="footer-label">' . $c[0] . '</div>'
                . '<div class="footer-text">' . nl2br($e($c[1])) . '</div></td>';
        }
        if (count($cols) === 1) $h .= '<td class="col col2"></td>';
        $h .= '</tr></table>';
    }

    // Bank details (Savannah Holidays: paid on AfrAsia)
    if ($d['show_bank'] && $is['bank']) {
        $h .= '<div class="bank-details"><div class="footer-label">Bank Details for Payment</div><table>';
        foreach ($is['bank'] as $b) $h .= '<tr><td class="k">' . $e($b[0]) . '</td><td class="v">' . $e($b[1]) . '</td></tr>';
        $h .= '</table></div>';
    }
    return $h . '</div>';
}

/** Logo as a data: URI (Dompdf runs with remote and file access off). */
function inv_doc_logo_data_uri(array $d): string {
    $f = __DIR__ . '/../assets/' . $d['issuer']['logo'];
    return is_file($f) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($f)) : '';
}

/** Full HTML for Dompdf: the same body as the page, A4 with Chrome's print margins. */
function buildInvoiceHtml(array $inv, array $items, array $payments): string {
    $d = inv_doc_from_invoice($inv, $items);
    return inv_doc_dompdf_html($d, 'Invoice ' . $inv['invoice_number']);
}

const INV_DOC_FONTS = __DIR__ . '/../assets/fonts';

/**
 * Open Sans for Dompdf (assets/fonts, static TTF). Dompdf only knows normal / bold, so
 * Light (300) and SemiBold (600) are registered as their own families.
 */
function inv_doc_dompdf_fonts(): string {
    $dir = 'file://' . str_replace('\\', '/',(string)realpath(INV_DOC_FONTS));
    $faces = [['Open Sans', 'normal', 400], ['Open Sans', 'bold', 700],
              ['Open Sans Light', 'normal', 300], ['Open Sans SemiBold', 'normal', 600]];
    $css = '';
    foreach ($faces as $f) {
        $css .= "@font-face { font-family: '{$f[0]}'; font-style: normal; font-weight: {$f[1]}; "
              . "src: url('{$dir}/open-sans-{$f[2]}.ttf') format('truetype'); }";
    }
    return $css
         . "body { font-family: 'Open Sans', Helvetica, Arial, sans-serif; }"
         . ".inv-title { font-family: 'Open Sans Light'; font-weight: normal; }"
         . ".items-tbl th, .date-table td.v, .totals-tbl td.r, .bank-details table td.v { font-family: 'Open Sans SemiBold'; font-weight: normal; }"
         . ".totals-tbl tr.total td.r, .totals-tbl tr.balance td.r { font-family: 'Open Sans'; font-weight: bold; }"
         // Dompdf multiplies line-height by the font's own height (Open Sans 1.362): divide by it,
         // a little less, so lines are spaced as in Chrome (measured against its print).
         . ".invoice-body { line-height: 0.93; }"
         . ".issuer-addr, .bill-to-addr { line-height: 1.1; }"
         . ".footer-text { line-height: 1.14; }"
         . ".inv-title { line-height: 0.88; }";
}

/** Dompdf options for the invoice PDFs: no remote files; fonts read from assets/fonts, metrics cached in tmp. */
function inv_doc_dompdf_options(): \Dompdf\Options {
    $o = new \Dompdf\Options();
    $o->set('isHtml5ParserEnabled', true);
    $o->set('isRemoteEnabled', false);
    $o->setChroot(array_merge((array)$o->getChroot(), [(string)realpath(INV_DOC_FONTS)]));
    $cache = sys_get_temp_dir() . '/hub_dompdf_fonts';
    if (!is_dir($cache)) @mkdir($cache, 0775, true);
    if (is_dir($cache) && is_writable($cache)) { $o->setFontDir($cache); $o->setFontCache($cache); }
    return $o;
}

function inv_doc_dompdf_html(array $d, string $title): string {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . inv_doc_e($title) . '</title><style>'
         . '@page { size: A4 portrait; margin: 10mm; }'
         . 'body { margin: 0; }'
         . '.invoice-body { padding: 24px 32px; }'
         . inv_doc_css() . inv_doc_dompdf_fonts()
         // After the shared CSS (same selectors, so the later rule wins). The page sizes columns
         // border-box; Dompdf adds the 2 × 14px padding, so widths without it, and the table in px
         // (A4 190 mm = 718px − 64px padding = 654px): with 100% Dompdf widens # and squeezes the description.
         . '.items-tbl { width: 654px; } .items-tbl th.w-n { width: 8px; } .items-tbl th.w-q { width: 42px; } .items-tbl th.w-a { width: 72px; }'
         . '</style></head><body>'
         . inv_doc_body($d, inv_doc_logo_data_uri($d)) . '</body></html>';
}
