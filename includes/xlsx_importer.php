<?php
/**
 * Import d'un fichier XLSX/ODS RGESN V2 2024 dans un audit JSON.
 */

function xlsx_unescape(string $s): string
{
    return html_entity_decode($s, ENT_QUOTES | (defined('ENT_XML1') ? ENT_XML1 : 0), 'UTF-8');
}

function xlsx_unzip_to_dir(string $path, string $dest): void
{
    $data = @file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Fichier illisible.');
    }
    $eocdSig = "\x50\x4b\x05\x06";
    $pos = strrpos($data, $eocdSig);
    if ($pos === false) {
        throw new RuntimeException('Archive ZIP invalide (EOCD introuvable).');
    }
    $cdSize   = unpack('V', substr($data, $pos + 12, 4))[1];
    $cdOffset = unpack('V', substr($data, $pos + 16, 4))[1];

    $ptr = $cdOffset;
    $end = $cdOffset + $cdSize;
    while ($ptr < $end) {
        if (substr($data, $ptr, 4) !== "PK\x01\x02") {
            break;
        }
        $compMethod   = unpack('v', substr($data, $ptr + 10, 2))[1];
        $compSize     = unpack('V', substr($data, $ptr + 20, 4))[1];
        $fnLen        = unpack('v', substr($data, $ptr + 28, 2))[1];
        $extraLen     = unpack('v', substr($data, $ptr + 30, 2))[1];
        $commentLen   = unpack('v', substr($data, $ptr + 32, 2))[1];
        $localOffset  = unpack('V', substr($data, $ptr + 42, 4))[1];
        $filename     = substr($data, $ptr + 46, $fnLen);

        $lp = $localOffset;
        if (substr($data, $lp, 4) !== "PK\x03\x04") {
            throw new RuntimeException('En-tête local ZIP invalide.');
        }
        $localFnLen    = unpack('v', substr($data, $lp + 26, 2))[1];
        $localExtraLen = unpack('v', substr($data, $lp + 28, 2))[1];
        $dataOffset    = $lp + 30 + $localFnLen + $localExtraLen;
        $compData      = substr($data, $dataOffset, $compSize);

        if ($compMethod === 0) {
            $uncomp = $compData;
        } elseif ($compMethod === 8) {
            $uncomp = @gzinflate($compData);
            if ($uncomp === false) {
                throw new RuntimeException('Décompression ZIP (deflate) échouée pour ' . $filename);
            }
        } else {
            throw new RuntimeException('Méthode de compression ZIP non supportée (' . $compMethod . ')');
        }

        $target = $dest . '/' . $filename;
        if (substr($filename, -1) === '/') {
            @mkdir($target, 0777, true);
        } else {
            @mkdir(dirname($target), 0777, true);
            if (file_put_contents($target, $uncomp) === false) {
                throw new RuntimeException('Écriture impossible : ' . $target);
            }
        }

        $ptr += 46 + $fnLen + $extraLen + $commentLen;
    }
}

function xlsx_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($dir);
}

function xlsx_read_shared_strings(string $base): array
{
    $xml = @file_get_contents($base . '/xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }
    $strings = [];
    preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $m[1], $tm);
        $text = '';
        foreach ($tm[1] as $t) {
            $text .= xlsx_unescape($t);
        }
        $strings[] = $text;
    }
    return $strings;
}

function xlsx_parse_sheet(string $xml, array $shared): array
{
    $rows = [];
    preg_match_all('/<row\b([^>]*?)(?:\/>|>(.*?)<\/row>)/s', $xml, $all, PREG_SET_ORDER);
    foreach ($all as $m) {
        $attrs  = $m[1];
        $inner  = isset($m[2]) ? $m[2] : '';
        if (!preg_match('/\br="(\d+)"/', $attrs, $rr)) {
            $rr = [1, (string) (count($rows) + 1)];
        }
        $rowNum = (int) $rr[1];

        $cells = [];
        preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $inner, $cellMatches, PREG_SET_ORDER);
        foreach ($cellMatches as $cm) {
            $cattrs = $cm[1];
            $cinner = isset($cm[2]) ? $cm[2] : '';
            if (!preg_match('/\br="([A-Z]+)(\d+)"/', $cattrs, $cr)) {
                continue;
            }
            $col = $cr[1];

            $t = '';
            if (preg_match('/\bt="([^"]+)"/', $cattrs, $ct)) {
                $t = $ct[1];
            }

            $raw = '';
            if (preg_match('/<v>(.*?)<\/v>/s', $cinner, $vm)) {
                $raw = xlsx_unescape($vm[1]);
            } elseif (preg_match('/<is\b[^>]*>(.*?)<\/is>/s', $cinner, $ism)) {
                preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $ism[1], $ttm);
                $raw = implode('', array_map('xlsx_unescape', $ttm[1]));
            } elseif (preg_match('/<t\b[^>]*>(.*?)<\/t>/s', $cinner, $tm)) {
                $raw = xlsx_unescape($tm[1]);
            }

            if ($t === 's') {
                $val = $shared[(int) trim($raw)] ?? '';
            } else {
                $val = $raw;
            }
            $cells[$col] = $val;
        }
        if (!empty($cells)) {
            $rows[$rowNum] = $cells;
        }
    }
    return $rows;
}

function xlsx_read_workbook(string $path): array
{
    $extractDir = sys_get_temp_dir() . '/rgesn_xlsx_' . uniqid('', true);
    @mkdir($extractDir, 0777, true);
    try {
        xlsx_unzip_to_dir($path, $extractDir);
    } catch (Throwable $e) {
        xlsx_rrmdir($extractDir);
        throw $e;
    }

    $base = $extractDir;
    $relsXml = @file_get_contents($base . '/xl/_rels/workbook.xml.rels');
    $relMap  = [];
    if ($relsXml !== false) {
        preg_match_all('/<Relationship\b[^>]*>/s', $relsXml, $rels);
        foreach ($rels[0] as $tag) {
            $id = null;
            $target = null;
            if (preg_match('/\bId="([^"]+)"/', $tag, $m))     { $id = $m[1]; }
            if (preg_match('/\bTarget="([^"]+)"/', $tag, $m)) { $target = $m[1]; }
            if ($id !== null && $target !== null) {
                $relMap[$id] = $target;
            }
        }
    }

    $wbXml = @file_get_contents($base . '/xl/workbook.xml');
    if ($wbXml === false) {
        xlsx_rrmdir($extractDir);
        throw new RuntimeException('workbook.xml illisible.');
    }

    $shared = xlsx_read_shared_strings($base);
    $sheets = [];
    preg_match_all('/<sheet\b[^>]*>/s', $wbXml, $sheetTags);
    foreach ($sheetTags[0] as $tag) {
        $name = null;
        $rid  = null;
        if (preg_match('/\bname="([^"]+)"/', $tag, $m)) { $name = xlsx_unescape($m[1]); }
        if (preg_match('/\br:id="([^"]+)"/', $tag, $m)) { $rid = $m[1]; }
        if ($name === null || $rid === null || !isset($relMap[$rid])) {
            continue;
        }
        $target = ltrim($relMap[$rid], '/');
        if (strpos($target, 'xl/') === 0) {
            $target = substr($target, 3);
        }
        $content = @file_get_contents($base . '/xl/' . $target);
        if ($content === false) {
            continue;
        }
        $sheets[$name] = xlsx_parse_sheet($content, $shared);
    }

    xlsx_rrmdir($extractDir);
    return $sheets;
}

function ods_read_workbook(string $path): array
{
    $extractDir = sys_get_temp_dir() . '/rgesn_ods_' . uniqid('', true);
    @mkdir($extractDir, 0777, true);
    try {
        xlsx_unzip_to_dir($path, $extractDir);
    } catch (Throwable $e) {
        xlsx_rrmdir($extractDir);
        throw $e;
    }

    $content = @file_get_contents($extractDir . '/content.xml');
    xlsx_rrmdir($extractDir);
    if ($content === false) {
        throw new RuntimeException('content.xml illisible.');
    }

    $sheets = [];
    preg_match_all('/<table:table\b([^>]*)>(.*?)<\/table:table>/s', $content, $tableMatches, PREG_SET_ORDER);
    foreach ($tableMatches as $tm) {
        $attrs = $tm[1];
        $inner = $tm[2];
        $name = 'Feuille';
        if (preg_match('/table:name="([^"]*)"/', $attrs, $nm)) {
            $name = xlsx_unescape($nm[1]);
        }

        $rows = [];
        $rowNum = 1;
        preg_match_all('/<table:table-row\b([^>]*?)(?:\/>|>(.*?)<\/table:table-row>)/s', $inner, $rowMatches, PREG_SET_ORDER);
        foreach ($rowMatches as $rm) {
            $rowAttrs = $rm[1];
            $rowInner = isset($rm[2]) ? $rm[2] : '';
            $repeatRows = 1;
            if (preg_match('/table:number-rows-repeated="(\d+)"/', $rowAttrs, $rr)) {
                $repeatRows = (int) $rr[1];
            }
            if ($repeatRows < 1) { $repeatRows = 1; }
            if ($repeatRows > 2000) { $repeatRows = 2000; }

            for ($r = 0; $r < $repeatRows; $r++) {
                $cells = [];
                $colIndex = 0;
                preg_match_all('/<table:table-cell\b([^>]*?)(?:\/>|>(.*?)<\/table:table-cell>)/s', $rowInner, $cellMatches, PREG_SET_ORDER);
                foreach ($cellMatches as $cm) {
                    $cattrs = $cm[1];
                    $cinner = isset($cm[2]) ? $cm[2] : '';
                    $repeatCols = 1;
                    if (preg_match('/table:number-columns-repeated="(\d+)"/', $cattrs, $cr)) {
                        $repeatCols = (int) $cr[1];
                    }
                    if ($repeatCols < 1) { $repeatCols = 1; }
                    if ($repeatCols > 2000) { $repeatCols = 2000; }

                    $val = ods_cell_value($cattrs, $cinner);
                    for ($c = 0; $c < $repeatCols; $c++) {
                        $cells[ods_col_letter($colIndex)] = $val;
                        $colIndex++;
                    }
                }
                $hasValue = false;
                foreach ($cells as $v) {
                    if (trim((string) $v) !== '') { $hasValue = true; break; }
                }
                if ($hasValue) {
                    $rows[$rowNum] = $cells;
                }
                $rowNum++;
            }
        }
        $sheets[$name] = $rows;
    }

    return $sheets;
}

function ods_cell_value(string $attrs, string $inner): string
{
    $innerP = preg_replace('/<\/text:p>/i', "\n", $inner);
    $text = preg_replace('/<[^>]+>/', '', $innerP);
    $text = xlsx_unescape(trim($text));

    $type = '';
    if (preg_match('/office:value-type="([^"]*)"/', $attrs, $m)) {
        $type = $m[1];
    }

    if ($type === 'string') {
        if (preg_match('/office:string-value="([^"]*)"/', $attrs, $m)) {
            $sv = xlsx_unescape($m[1]);
            if ($sv !== '') { return $sv; }
        }
        return $text;
    }
    if (in_array($type, ['float', 'currency', 'percentage', 'date', 'time', 'boolean'], true)) {
        if ($type === 'boolean' && preg_match('/office:boolean-value="([^"]*)"/', $attrs, $m)) {
            return $m[1];
        }
        if ($type === 'date' && preg_match('/office:date-value="([^"]*)"/', $attrs, $m)) {
            return $m[1];
        }
        if ($type === 'time' && preg_match('/office:time-value="([^"]*)"/', $attrs, $m)) {
            return $m[1];
        }
        if (preg_match('/office:value="([^"]*)"/', $attrs, $m)) {
            return xlsx_unescape($m[1]);
        }
        return $text;
    }

    if (preg_match('/office:value="([^"]*)"/', $attrs, $m)) {
        return xlsx_unescape($m[1]);
    }
    return $text;
}

function ods_col_letter(int $index): string
{
    $index++;
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = intdiv($index - 1, 26);
    }
    return $letters;
}

function rgesn_row_cells_sorted(array $cells): array
{
    $keys = array_keys($cells);
    usort($keys, fn($a, $b) => rgesn_col_index($a) <=> rgesn_col_index($b));
    $out = [];
    foreach ($keys as $k) {
        $out[] = $cells[$k];
    }
    return $out;
}

function rgesn_col_index(string $col): int
{
    $n = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n;
}

function rgesn_apply_import(array $audit, array $sheets): array
{
    $statusMap = [
        'Validé'          => 'conforme',
        'Non validé'      => 'non-conforme',
        'Non applicable'  => 'non-applicable',
        'À évaluer'       => 'non-testé',
    ];

    // ── Projet / auditeur ────────────────────────────────────────────────────
    foreach ($sheets as $name => $rows) {
        if (stripos($name, 'score') === false) {
            continue;
        }
        foreach ($rows as $cells) {
            $cells = rgesn_row_cells_sorted($cells);
            foreach ($cells as $i => $labelCell) {
                $label = trim((string) ($labelCell ?? ''));
                if ($label === 'Nom du service évalué' || $label === 'Nom du responsable de cette évaluation') {
                    $value = '';
                    for ($j = $i + 1; $j < count($cells); $j++) {
                        $v = trim((string) ($cells[$j] ?? ''));
                        if ($v !== '') { $value = $v; break; }
                    }
                    if ($value === '') { continue; }

                    if ($label === 'Nom du service évalué') {
                        if (preg_match('/^(.*?):\s*(https?:\/\/\S+)\s*$/u', $value, $m)) {
                            $audit['project']['name'] = trim($m[1]);
                            if (filter_var(trim($m[2]), FILTER_VALIDATE_URL)) {
                                $audit['project']['url'] = trim($m[2]);
                            }
                        } else {
                            $audit['project']['name'] = $value;
                        }
                    } else {
                        $audit['auditor']['name'] = $value;
                    }
                }
            }
        }
    }

    $index = [];
    foreach ($audit['criteria'] as $k => $c) {
        if (isset($c['id'])) {
            $index[$c['id']] = $k;
        }
    }

    // ── Critères ─────────────────────────────────────────────────────────────
    $lastId = null;
    foreach ($sheets as $name => $rows) {
        if (stripos($name, 'score') !== false) {
            continue;
        }
        foreach ($rows as $cells) {
            $sorted = rgesn_row_cells_sorted($cells);

            $idIndex = null;
            foreach ($sorted as $idx => $val) {
                if (preg_match('/^\d+\.\d+$/', trim((string) $val))) {
                    $idIndex = $idx;
                    break;
                }
            }

            if ($idIndex !== null) {
                $id = trim((string) $sorted[$idIndex]);
                $lastId = $id;
                if (!isset($index[$id])) {
                    $lastId = null;
                    continue;
                }
                $k = $index[$id];

                $pri = trim((string) ($sorted[$idIndex + 2] ?? ''));
                if (in_array($pri, ['Prioritaire', 'Recommandé', 'Modéré'], true)) {
                    $audit['criteria'][$k]['priority'] = $pri;
                }

                $statusRaw = trim((string) ($sorted[$idIndex + 3] ?? ''));
                if ($statusRaw !== '') {
                    if (isset($statusMap[$statusRaw])) {
                        $audit['criteria'][$k]['status'] = $statusMap[$statusRaw];
                    } elseif (in_array($statusRaw, ['conforme', 'non-conforme', 'non-applicable', 'non-testé'], true)) {
                        $audit['criteria'][$k]['status'] = $statusRaw;
                    }
                }

                $diff = trim((string) ($sorted[$idIndex + 6] ?? ''));
                if (in_array($diff, ['Fort', 'Moyen', 'Faible'], true)) {
                    $audit['criteria'][$k]['difficulty'] = $diff;
                }

                $action = trim((string) ($sorted[$idIndex + 7] ?? ''));
                if ($action !== '') {
                    $audit['criteria'][$k]['action_text'] = $action;
                }

                $whoRaw = trim((string) ($sorted[$idIndex + 8] ?? ''));
                if ($whoRaw !== '') {
                    $parts = preg_split('/[\n,+;]+/', $whoRaw);
                    $tags = [];
                    foreach ($parts as $p) {
                        $t = trim($p);
                        $t = rtrim($t, " \t.");
                        if ($t !== '') {
                            $tags[] = $t;
                        }
                    }
                    $tags = array_values(array_unique($tags));
                    if (!empty($tags)) {
                        $audit['criteria'][$k]['action_who'] = $tags;
                    }
                }

                $whenRaw = trim((string) ($sorted[$idIndex + 9] ?? ''));
                if ($whenRaw !== '') {
                    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $whenRaw, $m)) {
                        $audit['criteria'][$k]['action_when'] = sprintf('%04d-%02d', $m[3], $m[2]);
                    } elseif (preg_match('/^\d{4}-\d{2}$/', $whenRaw)) {
                        $audit['criteria'][$k]['action_when'] = $whenRaw;
                    }
                }

                $evalDate = trim((string) ($sorted[$idIndex + 4] ?? ''));
                if ($evalDate !== '') {
                    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $evalDate, $m)) {
                        $audit['criteria'][$k]['evaluation_date'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    }
                }

            } else {
                // Ligne complémentaire (commentaire)
                if ($lastId !== null && isset($index[$lastId])) {
                    $k = $index[$lastId];

                    $candidate = '';
                    foreach ($sorted as $v) {
                        $t = trim((string) $v);
                        if ($t !== '' && strlen($t) >= 20) {
                            $candidate = $t;
                            break;
                        }
                    }
                    if ($candidate !== '') {
                        $label = (string) ($audit['criteria'][$k]['label'] ?? '');
                        if ($candidate !== $label) {
                            $existing = trim((string) ($audit['criteria'][$k]['comment'] ?? ''));
                            if ($existing === '') {
                                $audit['criteria'][$k]['comment'] = $candidate;
                            } elseif (strpos($existing, $candidate) === false) {
                                $audit['criteria'][$k]['comment'] = $existing . "\n" . $candidate;
                            }
                        }
                    }

                }
            }
        }
    }

    $audit['updated_at'] = date('c');
    if (function_exists('calculate_score')) {
        $audit['score'] = calculate_score($audit['criteria']);
    } else {
        $audit['score'] = $audit['score'] ?? 0.0;
    }

    return $audit;
}

function rgesn_import_xlsx(array $audit, string $path): array
{
    $sheets = xlsx_read_workbook($path);
    return rgesn_apply_import($audit, $sheets);
}

function rgesn_import_ods(array $audit, string $path): array
{
    $sheets = ods_read_workbook($path);
    return rgesn_apply_import($audit, $sheets);
}

function rgesn_import_spreadsheet(array $audit, string $path): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'ods') {
        return rgesn_import_ods($audit, $path);
    }
    return rgesn_import_xlsx($audit, $path);
}

function action_import_audit_xlsx(array $input, array $current_user): never
{
    $id = trim($input['id'] ?? ($_POST['id'] ?? ''));
    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $tmpPath = null;
    $cleanup  = false;

    if (defined('RGESN_API_TESTING') && isset($input['file_path'])) {
        $tmpPath = (string) $input['file_path'];
        if (!is_file($tmpPath)) {
            json_response(['error' => 'Fichier introuvable'], 400);
        }
    } else {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            json_response(['error' => 'Fichier manquant'], 400);
        }
        $f = $_FILES['file'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_response(['error' => 'Erreur lors de l\'upload'], 400);
        }
        if (($f['size'] ?? 0) > 10 * 1024 * 1024) {
            json_response(['error' => 'Fichier trop volumineux (max. 10 Mo)'], 400);
        }
        $name = (string) ($f['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'ods'], true)) {
            json_response(['error' => 'Le fichier doit être au format XLSX ou ODS'], 400);
        }
        $tmpPath = tempnam(sys_get_temp_dir(), 'rgesn_xlsx_');
        if ($tmpPath === false || !move_uploaded_file($f['tmp_name'], $tmpPath)) {
            json_response(['error' => 'Enregistrement temporaire impossible'], 500);
        }
        $cleanup = true;
    }

    try {
        $result = atomic_audit_update($id, function (array $audit) use ($current_user, $tmpPath) {
            $permission = get_user_permission($audit, $current_user);
            if (!permission_can_edit($permission)) {
                return [null, ['success' => false, 'error' => 'Vous n\'avez pas les droits pour modifier cet audit', '_status' => 403]];
            }
            $lock = lock_status($audit);
            if ($lock['locked'] && $lock['by_id'] !== $current_user['id']) {
                return [null, [
                    'success' => false,
                    'error'   => "Cet audit est en cours de modification par {$lock['by_name']}.",
                    '_status' => 409,
                ]];
            }

            try {
                $newAudit = rgesn_import_spreadsheet($audit, $tmpPath);
            } catch (Throwable $e) {
                return [null, ['success' => false, 'error' => 'Impossible de lire le fichier : ' . $e->getMessage(), '_status' => 400]];
            }

            return [$newAudit, [
                'success'    => true,
                'score'      => $newAudit['score'],
                'updated_at' => $newAudit['updated_at'],
            ]];
        });
    } finally {
        if ($cleanup && $tmpPath && is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    $status = $result['_status']
        ?? ($result['success'] ?? false ? 200 : (($result['error'] ?? '') === 'Audit introuvable.' ? 404 : 500));
    unset($result['_status']);
    json_response($result, $status);
}
