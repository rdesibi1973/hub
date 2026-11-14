<?php
/**
 * xls_reader.php — old binary Excel (.xls, BIFF8 inside an OLE2 compound document) → cell values, pure PHP.
 *
 * BlueHost has no PhpSpreadsheet / Composer: this reads what a supplier pro-forma needs (Butiama Beach sends .xls):
 * shared strings, labels, numbers, RK / MULRK, formula cached results, booleans, sheet names, and the cell formats
 * so a date cell (Excel serial 46411) comes back as 2027-01-24. No formulas evaluated, no styles, no charts.
 * BIFF5 / BIFF7 (Excel 95) and encrypted files are refused with a clear message.
 *
 * xls_sheets(string $bytes): [['name' => 'Sheet1', 'cells' => [row => [col => 'text']]], …]  (0-based row / col)
 * xls_text(string $bytes, int $max = 60000): "## Sheet X\nRow 1 | A: … | B: …" (the .xlsx layout of the booking gate)
 * @throws RuntimeException  "Excel .xls could not be read (…) — save it as PDF and re-run."
 */

function xls_fail(string $why): void {
    throw new RuntimeException('Excel .xls could not be read (' . $why . ') — save it as PDF and re-run.');
}

/** OLE2 compound document → the bytes of the "Workbook" (or "Book") stream. */
function xls_ole_workbook(string $b): string {
    if (strlen($b) < 512 || substr($b, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        if (substr($b, 0, 2) === 'PK') xls_fail('it is an .xlsx file with the .xls name: rename it to .xlsx');
        if (stripos(substr($b, 0, 2048), '<html') !== false || stripos(substr($b, 0, 2048), '<table') !== false) xls_fail('it is a web page saved as .xls');
        xls_fail('not an Excel 97–2019 file');
    }
    $u16 = fn(int $o) => unpack('v', substr($b, $o, 2))[1];
    $u32 = fn(string $s, int $o) => unpack('V', substr($s, $o, 4))[1];
    $secShift = $u16(30); $miniShift = $u16(32);
    if ($secShift < 7 || $secShift > 16) xls_fail('bad sector size');
    $sec = 1 << $secShift; $mini = 1 << $miniShift;
    $nFat = $u32($b, 44); $dirStart = $u32($b, 48); $miniCutoff = $u32($b, 56);
    $miniFatStart = $u32($b, 60); $nMiniFat = $u32($b, 64); $difStart = $u32($b, 68); $nDif = $u32($b, 72);
    $sector = fn(int $i) => substr($b, 512 + $i * $sec, $sec);

    // FAT sector list: 109 in the header, then the DIFAT chain.
    $fatSecs = [];
    for ($i = 0; $i < 109 && count($fatSecs) < $nFat; $i++) $fatSecs[] = $u32($b, 76 + 4 * $i);
    $per = $sec / 4;
    for ($d = $difStart, $guard = 0; count($fatSecs) < $nFat && $d < 0xFFFFFFFA && $guard <= $nDif; $guard++) {
        $s = $sector($d);
        for ($i = 0; $i < $per - 1 && count($fatSecs) < $nFat; $i++) $fatSecs[] = $u32($s, 4 * $i);
        $d = $u32($s, 4 * ($per - 1));
    }
    $fat = '';
    foreach ($fatSecs as $f) $fat .= $sector($f);
    $fatN = intdiv(strlen($fat), 4);
    $chain = function (int $start, string $table, int $n, int $limit) use ($u32): array {
        $out = []; $seen = [];
        for ($s = $start; $s < 0xFFFFFFFA && $s < $n; $s = $u32($table, 4 * $s)) {
            if (isset($seen[$s]) || count($out) > $limit) xls_fail('broken sector chain');
            $seen[$s] = true; $out[] = $s;
        }
        return $out;
    };
    $read = function (int $start) use ($chain, $fat, $fatN, $sector, $b, $sec): string {
        $s = '';
        foreach ($chain($start, $fat, $fatN, intdiv(strlen($b), $sec) + 1) as $i) $s .= $sector($i);
        return $s;
    };

    $dir = $read($dirStart);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $nl = unpack('v', substr($dir, $o + 64, 2))[1];
        $name = $nl >= 2 ? (string)@mb_convert_encoding(substr($dir, $o, $nl - 2), 'UTF-8', 'UTF-16LE') : '';
        $entries[] = ['name' => $name, 'type' => ord($dir[$o + 66]), 'start' => $u32($dir, $o + 116), 'size' => $u32($dir, $o + 120)];
    }
    $root = $entries[0] ?? null;
    foreach ($entries as $e) {
        if ($e['type'] === 2 && in_array(strtolower($e['name']), ['encryptedpackage', 'encryptioninfo'], true)) xls_fail('the file is password-protected');
    }
    $wb = null;
    foreach ($entries as $e) if ($e['type'] === 2 && strcasecmp($e['name'], 'Workbook') === 0) $wb = $e;
    if (!$wb) foreach ($entries as $e) if ($e['type'] === 2 && strcasecmp($e['name'], 'Book') === 0) $wb = $e;
    if (!$wb) xls_fail('no workbook inside the file');
    if ($wb['size'] >= $miniCutoff) return substr($read($wb['start']), 0, $wb['size']);

    // Small stream: mini FAT inside the root entry's stream.
    $miniFat = $nMiniFat ? $read($miniFatStart) : '';
    $miniData = $root ? $read($root['start']) : '';
    $s = '';
    foreach ($chain($wb['start'], $miniFat, intdiv(strlen($miniFat), 4), intdiv(strlen($miniData), $mini) + 1) as $i) $s .= substr($miniData, $i * $mini, $mini);
    return substr($s, 0, $wb['size']);
}

/** BIFF8 unicode string at $o of $d (16-bit count when $wide, else 8-bit). → [text, bytes used]. No CONTINUE crossing. */
function xls_ustr(string $d, int $o, bool $wide = true): array {
    $n = $wide ? unpack('v', substr($d, $o, 2))[1] : ord($d[$o]);
    $p = $o + ($wide ? 2 : 1);
    $f = ord($d[$p] ?? "\0"); $p++;
    $rt = 0; $ext = 0;
    if ($f & 0x08) { $rt = unpack('v', substr($d, $p, 2))[1]; $p += 2; }
    if ($f & 0x04) { $ext = unpack('V', substr($d, $p, 4))[1]; $p += 4; }
    $raw = substr($d, $p, ($f & 1) ? 2 * $n : $n);
    $txt = ($f & 1) ? (string)@mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string)@mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    return [$txt, $p - $o + strlen($raw) + 4 * $rt + $ext];
}

/** RK value → float. */
function xls_rk(int $rk): float {
    if ($rk & 2) {
        $v = $rk >> 2;
        if ($v & 0x20000000) $v -= 0x40000000;   // sign of the 30-bit integer
        $v = (float)$v;
    } else {
        $v = unpack('d', pack('V2', 0, $rk & 0xFFFFFFFC))[1];   // little-endian IEEE double, high 30 bits
    }
    return ($rk & 1) ? $v / 100 : $v;
}

/** Number format string: a date / time one? (ignores literals, colours, [h]:mm elapsed counts as time). */
function xls_is_date_format(string $f): bool {
    $f = preg_replace('/"[^"]*"|\\\\.|\[(?!h\]|m\]|s\])[^\]]*\]/i', '', $f);
    return (bool)preg_match('/[dmyhs]/i', (string)preg_replace('/General/i', '', (string)$f)) && !preg_match('/^[#0.,% ]+$/', (string)$f);
}

/** Excel serial → "YYYY-MM-DD" (or "YYYY-MM-DD HH:MM" / "HH:MM"). */
function xls_serial_date(float $v, bool $mode1904 = false): string {
    if ($v < 1 && $v >= 0) { $m = (int)round($v * 1440); return sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60); }
    $days = (int)floor($v);
    if (!$mode1904 && $days < 60) $days++;   // Lotus 1900-02-29 bug: serials below 60 are one day off
    $base = $mode1904 ? gmmktime(0, 0, 0, 1, 1, 1904) : gmmktime(0, 0, 0, 12, 30, 1899);
    $out = gmdate('Y-m-d', $base + $days * 86400);
    $frac = $v - floor($v);
    if ($frac > 1e-6) { $m = (int)round($frac * 1440); $out .= sprintf(' %02d:%02d', intdiv($m, 60) % 24, $m % 60); }
    return $out;
}

function xls_num_text(float $v): string {
    if (abs($v - round($v)) < 1e-9 && abs($v) < 1e15) return (string)(int)round($v);
    return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
}

function xls_sheets(string $bytes): array {
    // A damaged file must end in the clear message, never in PHP warnings / ValueErrors (short unpack, bad offsets).
    set_error_handler(function (int $no, string $msg) { throw new ErrorException($msg, 0, $no); });
    try {
        return xls_sheets_raw($bytes);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'Excel .xls could not be read') === 0) throw $e;
        xls_fail('the file is damaged');
    } catch (Throwable $e) {
        xls_fail('the file is damaged');
    } finally {
        restore_error_handler();
    }
    return [];
}

function xls_sheets_raw(string $bytes): array {
    $wb = xls_ole_workbook($bytes);
    $len = strlen($wb);
    // Records: [type, data] with CONTINUE records kept apart (needed for SST).
    $recs = [];
    for ($o = 0; $o + 4 <= $len;) {
        $h =unpack('vt/vl', substr($wb, $o, 4)); $t = $h['t']; $l = $h['l'];
        $recs[] = [$t, substr($wb, $o + 4, $l), $o];
        $o += 4 + $l;
    }
    if (!$recs || $recs[0][0] !== 0x0809) xls_fail('no BIFF header');
    $ver = unpack('v', substr($recs[0][1], 0, 2))[1];
    if ($ver !== 0x0600) xls_fail('Excel 95 or older format: open it in Excel and save it again');

    $sst = []; $fmts = []; $xfDate = []; $xfPct = []; $sheets = []; $mode1904 = false;
    $builtinDate = array_flip([14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47, 27, 28, 29, 30, 31, 36, 50, 51, 52, 53, 54, 55, 56, 57, 58]);
    $n = count($recs);
    // Globals (up to the first EOF).
    $i = 1;
    for (; $i < $n; $i++) {
        [$t, $d] = $recs[$i];
        if ($t === 0x000A) break;                                    // EOF
        if ($t === 0x002F) xls_fail('the file is password-protected'); // FILEPASS
        if ($t === 0x0022) $mode1904 = unpack('v', substr($d, 0, 2))[1] === 1;
        elseif ($t === 0x041E) { $id = unpack('v', substr($d, 0, 2))[1]; $fmts[$id] = xls_ustr($d, 2)[0]; }
        elseif ($t === 0x00E0) {
            $fid = unpack('v', substr($d, 2, 2))[1];
            $xfDate[] = isset($builtinDate[$fid]) || (isset($fmts[$fid]) && xls_is_date_format($fmts[$fid]));
            $xfPct[]  = $fid === 9 || $fid === 10 || (isset($fmts[$fid]) && strpos(preg_replace('/"[^"]*"/', '', $fmts[$fid]), '%') !== false);
        } elseif ($t === 0x0085) {
            $pos = unpack('V', substr($d, 0, 4))[1];
            $sheets[] = ['pos' => $pos, 'type' => ord($d[5] ?? "\0"), 'name' => xls_ustr($d, 6, false)[0]];
        } elseif ($t === 0x00FC) {
            // SST across CONTINUE records: each CONTINUE starting inside a string begins with a new flags byte.
            $parts = [substr($d, 8)];
            for ($j = $i + 1; $j < $n && $recs[$j][0] === 0x003C; $j++) $parts[] = $recs[$j][1];
            $total = unpack('V', substr($d, 4, 4))[1];
            $sst = xls_sst($parts, $total);
        }
    }
    $byPos = [];
    foreach ($recs as $k => $r) $byPos[$r[2]] = $k;

    $out = [];
    foreach ($sheets as $sh) {
        if ($sh['type'] !== 0 || !isset($byPos[$sh['pos']])) continue;   // worksheets only
        $cells = [];
        $date = fn(int $xf, float $v) => (!empty($xfDate[$xf]) && $v >= 0 && $v < 2958466) ? xls_serial_date($v, $mode1904)
                                       : (!empty($xfPct[$xf]) ? xls_num_text($v * 100) . '%' : xls_num_text($v));
        $pendingFormula = null;
        for ($k = $byPos[$sh['pos']] + 1; $k < $n; $k++) {
            [$t, $d] = $recs[$k];
            if ($t === 0x000A) break;
            if ($t === 0x0809) { // embedded substream (chart): skip to its EOF
                for ($k++; $k < $n && $recs[$k][0] !== 0x000A; $k++);
                continue;
            }
            if (strlen($d) < 6 && $t !== 0x0207) continue;
            $row = strlen($d) >= 2 ? unpack('v', substr($d, 0, 2))[1] : 0;
            $col = strlen($d) >= 4 ? unpack('v', substr($d, 2, 2))[1] : 0;
            $xf  = strlen($d) >= 6 ? unpack('v', substr($d, 4, 2))[1] : 0;
            switch ($t) {
                case 0x00FD: $idx = unpack('V', substr($d, 6, 4))[1]; $cells[$row][$col] = $sst[$idx] ?? ''; break;   // LABELSST
                case 0x0204: case 0x00D6: $cells[$row][$col] = xls_ustr($d, 6)[0]; break;                              // LABEL / RSTRING
                case 0x0203: $cells[$row][$col] = $date($xf, unpack('d', substr($d, 6, 8))[1]); break;                // NUMBER
                case 0x027E: $cells[$row][$col] = $date($xf, xls_rk(unpack('V', substr($d, 6, 4))[1])); break;        // RK
                case 0x00BD:                                                                                         // MULRK
                    $last = unpack('v', substr($d, -2))[1];
                    for ($c = $col, $p = 4; $c <= $last && $p + 6 <= strlen($d) - 2; $c++, $p += 6) {
                        $x = unpack('v', substr($d, $p, 2))[1];
                        $cells[$row][$c] = $date($x, xls_rk(unpack('V', substr($d, $p + 2, 4))[1]));
                    }
                    break;
                case 0x0205: $cells[$row][$col] = ord($d[7] ?? "\0") ? (ord($d[6]) ? 'TRUE' : 'FALSE') : '#ERR'; break; // BOOLERR
                case 0x0006:                                                                                         // FORMULA
                    $res = substr($d, 6, 8);
                    if (substr($res, 6, 2) === "\xFF\xFF") {
                        $kind = ord($res[0]);
                        if ($kind === 0) { $pendingFormula = [$row, $col]; break; }            // string: next STRING record
                        $cells[$row][$col] = $kind === 1 ? (ord($res[2]) ? 'TRUE' : 'FALSE') : ($kind === 2 ? '#ERR' : '');
                    } else {
                        $cells[$row][$col] = $date($xf, unpack('d', $res)[1]);
                    }
                    break;
                case 0x0207:                                                                                         // STRING (formula result)
                    if ($pendingFormula) { $cells[$pendingFormula[0]][$pendingFormula[1]] = xls_ustr($d, 0)[0]; $pendingFormula = null; }
                    break;
            }
        }
        foreach ($cells as $r => $cs) {
            foreach ($cs as $c => $v) if (trim((string)$v) === '') unset($cells[$r][$c]);
            if (!$cells[$r]) unset($cells[$r]); else ksort($cells[$r]);
        }
        ksort($cells);
        $out[] = ['name' => $sh['name'], 'cells' => $cells];
    }
    return $out;
}

/** Shared string table split over SST + CONTINUE bodies. */
function xls_sst(array $parts, int $total): array {
    $out = [];
    $pi = 0; $d = $parts[0]; $o = 0;
    $next = function () use (&$pi, &$d, &$o, $parts): bool {
        if (++$pi >= count($parts)) return false;
        $d = $parts[$pi]; $o = 0;
        return true;
    };
    while (count($out) < $total) {
        if ($o >= strlen($d) && !$next()) break;
        if ($o + 3 > strlen($d)) { if (!$next()) break; }
        $cnt = unpack('v', substr($d, $o, 2))[1];
        $f = ord($d[$o + 2]); $o += 3;
        $rt = 0; $ext = 0;
        if ($f & 0x08) { $rt = unpack('v', substr($d, $o, 2))[1]; $o += 2; }
        if ($f & 0x04) { $ext = unpack('V', substr($d, $o, 4))[1]; $o += 4; }
        $wide = (bool)($f & 1);
        $txt = ''; $left = $cnt;
        while ($left > 0) {
            $avail = strlen($d) - $o;
            if ($avail <= 0) {                       // string continues: new record, new flags byte
                if (!$next()) break;
                $wide = (bool)(ord($d[0]) & 1); $o = 1;
                continue;
            }
            $take = min($left, $wide ? intdiv($avail, 2) : $avail);
            $raw = substr($d, $o, $wide ? 2 * $take : $take);
            $txt .= $wide ? (string)@mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string)@mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            $o += strlen($raw); $left -= $take;
            if ($take === 0) { if (!$next()) break; $wide = (bool)(ord($d[0]) & 1); $o = 1; }
        }
        // Rich-text runs and the extended block may also cross a record boundary.
        $skip = 4 * $rt + $ext;
        while ($skip > 0) {
            $avail = strlen($d) - $o;
            if ($avail >= $skip) { $o += $skip; $skip = 0; }
            else { $skip -= $avail; if (!$next()) break; }
        }
        $out[] = $txt;
    }
    return $out;
}

/** Column index (0-based) → "A", "AB". */
function xls_col(int $c): string {
    $s = '';
    for ($c++; $c > 0; $c = intdiv($c - 1, 26)) $s = chr(65 + ($c - 1) % 26) . $s;
    return $s;
}

/** The booking gate layout: "## Sheet X" then "Row N | A: … | C: …" (1-based rows, as the .xlsx reader). */
function xls_text(string $bytes, int $max = 60000): string {
    $out = [];
    foreach (xls_sheets($bytes) as $sh) {
        if (!$sh['cells']) continue;
        $out[] = '## Sheet ' . trim($sh['name']);
        foreach ($sh['cells'] as $r => $cs) {
            $line = [];
            foreach ($cs as $c => $v) $line[] = xls_col($c) . ': ' . trim(preg_replace('/\s+/', ' ', (string)$v));
            $out[] = 'Row ' . ($r + 1) . ' | ' . implode(' | ', $line);
        }
    }
    $text = implode("\n", $out);
    if (trim($text) === '') xls_fail('no cell text in it');
    return mb_substr($text, 0, $max);
}
