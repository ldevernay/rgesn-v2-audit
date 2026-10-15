<?php
/**
 * Import d'un fichier XLSX RGESN V2 2024 dans un audit JSON.
 *
 * Lecture minimaliste d'un .xlsx (ZIP + XML) via le wrapper phar://,
 * sans extension PHP zip ni libxml DOM — uniquement des expressions
 * régulières et html_entity_decode(), pour rester portable.
 */

if (!defined('AUDITS_DIR')) { /* nothing */ }

function xlsx_unescape(string $s): string
{
    return html_entity_decode($s, ENT_QUOTES | (defined('ENT_XML1') ? ENT_XML1 : 0), 'UTF-8');
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
    preg_match_all('/<row\b([^>]*)>(.*?)<\/row>/s', $xml, $all, PREG_SET_ORDER);
    foreach ($all as $m) {
        $attrs  = $m[1];
        $inner  = $m[2];
        if (!preg_match('/\br="(\d+)"/', $attrs, $rr)) {
            $rr = [1, (string) (count($rows) + 1)];
        }
        $rowNum = (int) $rr[1];

        $cells = [];
        preg_match_all('/<c\\b([^>]*?)(?:\\/>|>(.*?)<\\/c>)/s', $inner, $cellMatches, PREG_SET_ORDER);
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
    $base = 'phar://' . $path;

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

    return $sheets;
}

function rgesn_import_xlsx(array $audit, string $path): array
{
    $sheets = xlsx_read_workbook($path);

    // ── Projet / auditeur depuis l'onglet « Score d'avancement » ─────────────
    foreach ($sheets as $name => $rows) {
        if (stripos($name, 'score') === false) {
            continue;
        }
        foreach ($rows as $cells) {
            $b = trim((string) ($cells['B'] ?? ''));
            $c = trim((string) ($cells['C'] ?? ''));

            if ($b === 'Nom du service évalué' && $c !== '') {
                if (preg_match('/^(.*?):\s*(https?:\/\/\S+)\s*$/u', $c, $m)) {
                    $audit['project']['name'] = trim($m[1]);
                    if (filter_var(trim($m[2]), FILTER_VALIDATE_URL)) {
                        $audit['project']['url'] = trim($m[2]);
                    }
                } else {
                    $audit['project']['name'] = $c;
                }
            } elseif ($b === 'Nom du responsable de cette évaluation' && $c !== '') {
                $audit['auditor']['name'] = $c;
            }
        }
    }

    $index = [];
    foreach ($audit['criteria'] as $k => $c) {
        if (isset($c['id'])) {
            $index[$c['id']] = $k;
        }
    }

    $statusMap = [
        'Validé'          => 'conforme',
        'Non validé'      => 'non-conforme',
        'Non applicable'  => 'non-applicable',
        'À évaluer'       => 'non-testé',
    ];

    $lastId = null;
    foreach ($sheets as $name => $rows) {
        if (stripos($name, 'score') !== false) {
            continue;
        }
        foreach ($rows as $cells) {
            $b = trim((string) ($cells['B'] ?? ''));
            $c = trim((string) ($cells['C'] ?? ''));

            if (preg_match('/^\d+\.\d+$/', $b)) {
                $lastId = $b;
                if (!isset($index[$b])) {
                    $lastId = null;
                    continue;
                }
                $k = $index[$b];

                $pri = trim((string) ($cells['D'] ?? ''));
                if (in_array($pri, ['Prioritaire', 'Recommandé', 'Modéré'], true)) {
                    $audit['criteria'][$k]['priority'] = $pri;
                }

                $statusRaw = trim((string) ($cells['E'] ?? ''));
                if ($statusRaw !== '') {
                    if (isset($statusMap[$statusRaw])) {
                        $audit['criteria'][$k]['status'] = $statusMap[$statusRaw];
                    } elseif (in_array($statusRaw, ['conforme', 'non-conforme', 'non-applicable', 'non-testé'], true)) {
                        $audit['criteria'][$k]['status'] = $statusRaw;
                    }
                }

                $diff = trim((string) ($cells['H'] ?? ''));
                if (in_array($diff, ['Fort', 'Moyen', 'Faible'], true)) {
                    $audit['criteria'][$k]['difficulty'] = $diff;
                }

                $action = trim((string) ($cells['I'] ?? ''));
                if ($action !== '') {
                    $audit['criteria'][$k]['action_text'] = $action;
                }

                $whoRaw = trim((string) ($cells['J'] ?? ''));
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

                $whenRaw = trim((string) ($cells['K'] ?? ''));
                if ($whenRaw !== '') {
                    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $whenRaw, $m)) {
                        $audit['criteria'][$k]['action_when'] = sprintf('%04d-%02d', $m[3], $m[2]);
                    } elseif (preg_match('/^\d{4}-\d{2}$/', $whenRaw)) {
                        $audit['criteria'][$k]['action_when'] = $whenRaw;
                    }
                }

                $evalDate = trim((string) ($cells['F'] ?? ''));
                if ($evalDate !== '') {
                    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $evalDate, $m)) {
                        $audit['criteria'][$k]['evaluation_date'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    }
                }

            } else {
                // Ligne complémentaire (commentaire) attachée au critère précédent
                if ($lastId !== null && isset($index[$lastId])) {
                    $k = $index[$lastId];

                    $cellC = trim((string) ($cells['C'] ?? ''));
                    if ($cellC !== '' && $cellC !== 'Libellé du critère') {
                        $label = (string) ($audit['criteria'][$k]['label'] ?? '');
                        if ($cellC !== $label) {
                            $existing = trim((string) ($audit['criteria'][$k]['comment'] ?? ''));
                            if ($existing === '') {
                                $audit['criteria'][$k]['comment'] = $cellC;
                            } elseif (strpos($existing, $cellC) === false) {
                                $audit['criteria'][$k]['comment'] = $existing . "\n" . $cellC;
                            }
                        }
                    }

                    $cellI = trim((string) ($cells['I'] ?? ''));
                    if ($cellI !== '' && trim((string) ($audit['criteria'][$k]['action_text'] ?? '')) === '') {
                        $audit['criteria'][$k]['action_text'] = $cellI;
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
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            json_response(['error' => 'Le fichier doit être au format XLSX'], 400);
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
                $newAudit = rgesn_import_xlsx($audit, $tmpPath);
            } catch (Throwable $e) {
                return [null, ['success' => false, 'error' => 'Impossible de lire le fichier XLSX : ' . $e->getMessage(), '_status' => 400]];
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
