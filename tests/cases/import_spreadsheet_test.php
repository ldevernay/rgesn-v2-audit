#!/usr/bin/env php
<?php
/**
 * Tests de non-régression — import de tableur XLSX/ODS
 *
 * Reproduit le modèle RGESN V2 2024 (colonnes : N° / Libellé / Priorité /
 * Évaluation / Date / Difficulté / Actions à mener / Qui fait / Pour quand) et
 * vérifie notamment que les lignes d'en-tête d'une feuille ne sont JAMAIS
 * rattachées au dernier critère de la feuille précédente.
 *
 * Bug corrigé : le critère 1.10 (dernier critère de l'onglet « 1 Stratégie (2) »)
 * recevait l'intitulé de colonne « Actions à mener » dans son champ action,
 * parce que les deux lignes d'en-tête de l'onglet suivant étaient traitées
 * comme des lignes de données.
 *
 * Fichier autonome : exécutable seul (`php tests/cases/import_spreadsheet_test.php`)
 * ou via tests/run.php.
 */

require_once __DIR__ . '/../TestRunner.php';
require_once __DIR__ . '/../../includes/xlsx_importer.php';

// ── Constructeur de fixtures ZIP ─────────────────────────────────────────────
//
// L'extension zip n'est pas garantie sur l'hébergement cible : les fixtures
// sont donc assemblées avec PharData (ext-phar, activée par défaut), qui
// produit des archives ZIP conformes sans extension dédiée.

/** @param array<string, string> $entries */
function t_zip_create(string $path, array $entries): void
{
    // PharData ne produit une archive ZIP que si le chemin se termine par
    // « .zip » : on écrit donc à côté, puis on renomme.
    $tmpZip = $path . '.zip';
    @unlink($tmpZip);

    $phar = new PharData($tmpZip);
    foreach ($entries as $name => $content) {
        $phar->addFromString($name, $content);
    }
    // Entrées non compressées (« stored ») : l'importeur sait lire les deux
    // méthodes de compression du format ZIP (0 = stored, 8 = deflate).
    $phar->decompressFiles();
    unset($phar);

    rename($tmpZip, $path);
}

function t_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ── Modélisation du tableur RGESN ────────────────────────────────────────────
//
// $sheets[$nom][$numLigne] = tableau [colonne => valeur].
// En XLSX l'identifiant du critère est en colonne B (A = libellé de la
// thématique) ; en ODS il est en colonne A (le modèle ODS n'a pas la colonne
// « ID »). Le reste est décalé d'une colonne : c'est justement ce décalage que
// l'importeur doit absorber, puisqu'il se réfère aux colonnes par position
// relative par rapport à la colonne de l'identifiant.

const T_HEADERS = [
    'A' => 'ID',
    'C' => 'Libellé du critère',
    'D' => 'Niveau de priorité',
    'E' => 'Évaluation',
    'F' => "Date de l'évaluation",
    'G' => 'Évolutions potentielles',
    'H' => 'Niveau de difficulté',
    'I' => 'Actions à mener',
    'J' => 'Qui fait ?',
    'K' => 'Pour quand ?',
];

const T_NOTES = "Le contenu de ces colonnes est utilisé pour générer le PDF à publier";

/** Feuille « critères » : ligne de notes + ligne d'en-têtes + critères. */
function t_sheet_rows(): array
{
    return [
        1 => ['A' => T_NOTES],
        2 => T_HEADERS,
        3 => [
            'A' => '1 – Stratégie (1/2)',
            'B' => '1.1', 'C' => 'Premier critère',
            'D' => 'Prioritaire', 'E' => 'Validé', 'F' => '12/03/2025',
            'G' => '', 'H' => 'Moyen',
            'I' => 'Première action', 'J' => 'Alice + Bob', 'K' => '22/10/2026',
        ],
        4 => [
            'A' => '', 'B' => '', 'C' => 'Réponse libre de l\'utilisateur au critère 1.1',
            'D' => '', 'E' => '', 'F' => '', 'G' => '', 'H' => 'Exemple du modèle',
            'I' => '', 'J' => '', 'K' => '',
        ],
        5 => [
            'A' => '', 'B' => '1.2', 'C' => 'Deuxième critère',
            'D' => 'Recommandé', 'E' => 'Non validé', 'F' => '',
            'G' => '', 'H' => 'Fort',
            'I' => '', 'J' => '', 'K' => '',
        ],
    ];
}

/** Seconde feuille : le critère 1.2 y est le dernier de l'onglet. */
function t_sheet2_rows(): array
{
    return [
        1 => ['A' => T_NOTES],
        2 => T_HEADERS,
        3 => [
            'A' => '1 – Stratégie (2/2)',
            'B' => '1.2', 'C' => 'Deuxième critère',
            'D' => 'Recommandé', 'E' => 'Non validé', 'F' => '',
            'G' => '', 'H' => 'Fort',
            'I' => '', 'J' => '', 'K' => '',
        ],
        4 => [
            'A' => '', 'B' => '', 'C' => '',
            'D' => '', 'E' => '', 'F' => '', 'G' => '', 'H' => '',
            'I' => '', 'J' => '', 'K' => '',
        ],
    ];
}

/** Feuille de score d'avancement (projet / auditeur). */
function t_score_rows(): array
{
    return [
        1 => ['A' => T_NOTES],
        2 => [
            'B' => 'Nom du service évalué',
            'C' => 'Mon service : https://exemple.fr',
        ],
        3 => [
            'B' => 'Nom du responsable de cette évaluation',
            'C' => 'Jeanne Dupont',
        ],
    ];
}

function t_build_xlsx(string $path): void
{
    $sheets = [
        'sheet1' => t_score_rows(),
        'sheet2' => t_sheet_rows(),
        'sheet3' => t_sheet2_rows(),
    ];

    $shared = [];
    $sidx   = [];
    $xmls   = [];

    foreach ($sheets as $name => $rows) {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rn => $cells) {
            $out .= '<row r="' . $rn . '">';
            foreach ($cells as $col => $val) {
                $ref = $col . $rn;
                if ((string) $val === '') {
                    $out .= '<c r="' . $ref . '"/>';
                } else {
                    if (!isset($sidx[(string) $val])) {
                        $sidx[(string) $val] = count($shared);
                        $shared[] = (string) $val;
                    }
                    $out .= '<c r="' . $ref . '" t="s"><v>' . $sidx[(string) $val] . '</v></c>';
                }
            }
            $out .= '</row>';
        }
        $out .= '</sheetData></worksheet>';
        $xmls[$name] = $out;
    }

    $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
        . count($shared) . '" uniqueCount="' . count($shared) . '">'
        . implode('', array_map(static fn($s) => '<si><t>' . t_esc($s) . '</t></si>', $shared))
        . '</sst>';

    $names = array_keys($sheets);

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . '<sheet name="Score d\'avancement" sheetId="1" r:id="rId1"/>'
        . '<sheet name="1 Stratégie (1)" sheetId="2" r:id="rId2"/>'
        . '<sheet name="1 Stratégie (2)" sheetId="3" r:id="rId3"/>'
        . '</sheets></workbook>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';

    $entries = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml'           => $workbook,
        'xl/_rels/workbook.xml.rels' => $rels,
        'xl/sharedStrings.xml'       => $sharedXml,
    ];
    foreach ($xmls as $name => $xml) {
        $entries['xl/worksheets/' . $name . '.xml'] = $xml;
    }

    t_zip_create($path, $entries);
}

function t_build_ods(string $path): void
{
    // En ODS, l'identifiant du critère est en colonne A (pas de colonne « ID »).
    $shift = static function (array $row): array {
        $out = [];
        foreach ($row as $col => $val) {
            if ($col === 'A' && (string) $val === '') { continue; }
            $out[rgesn_col_letter(rgesn_col_index($col) - 1)] = $val;
        }
        return $out;
    };

    $table = static function (string $name, array $rows): string {
        $out = '<table:table table:name="' . t_esc($name) . '" table:style-name="ta1">';
        foreach ($rows as $cells) {
            $out .= '<table:table-row table:style-name="ro1">';
            // On repart de la colonne A pour que la position de chaque cellule
            // soit stable.
            $max = 0;
            foreach ($cells as $col => $v) {
                $max = max($max, rgesn_col_index($col));
            }
            for ($i = 1; $i <= $max; $i++) {
                $letter = rgesn_col_letter($i);
                $val    = (string) ($cells[$letter] ?? '');
                $out   .= $val === ''
                    ? '<table:table-cell/>'
                    : '<table:table-cell office:value-type="string"><text:p>' . t_esc($val) . '</text:p></table:table-cell>';
            }
            $out .= '</table:table-row>';
        }
        return $out . '</table:table>';
    };

    $content = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-content '
        . 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        . 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
        . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
        . 'office:version="1.2"><office:body><office:spreadsheet>'
        . $table("Score d'avancement", array_map($shift, t_score_rows()))
        . $table('1 Stratégie (1)', array_map($shift, t_sheet_rows()))
        . $table('1 Stratégie (2)', array_map($shift, t_sheet2_rows()))
        . '</office:spreadsheet></office:body></office:document-content>';

    t_zip_create($path, [
        'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
        'content.xml' => $content,
    ]);
}

/** Audit minimal attendu par l'importeur. */
function t_audit(): array
{
    return [
        'project' => ['name' => '', 'url' => ''],
        'auditor' => ['name' => ''],
        'criteria' => [
            ['id' => '1.1', 'label' => 'Premier critère', 'comment' => '', 'action_text' => '', 'action_who' => [], 'action_when' => ''],
            ['id' => '1.2', 'label' => 'Deuxième critère', 'comment' => '', 'action_text' => '', 'action_who' => [], 'action_when' => ''],
        ],
    ];
}

function t_criterion(array $audit, string $id): array
{
    foreach ($audit['criteria'] as $c) {
        if (($c['id'] ?? '') === $id) {
            return $c;
        }
    }
    return [];
}

// ── Tests ────────────────────────────────────────────────────────────────────

$tmpDir = sys_get_temp_dir() . '/rgesn_import_test_' . getmypid();
@mkdir($tmpDir, 0777, true);

$xlsxPath = $tmpDir . '/modele.xlsx';
$odsPath  = $tmpDir . '/modele.ods';
t_build_xlsx($xlsxPath);
t_build_ods($odsPath);

T::section('Import XLSX — les en-têtes de feuille ne sont pas importés comme des données');

$audit = rgesn_import_xlsx(t_audit(), $xlsxPath);

$c11 = t_criterion($audit, '1.1');
$c12 = t_criterion($audit, '1.2');

T::assertSame(
    'Prioritaire',
    $c11['priority'] ?? null,
    'La priorité du critère 1.1 est importée'
);
T::assertSame(
    'conforme',
    $c11['status'] ?? null,
    'Le statut du critère 1.1 est importé (« Validé » => conforme)'
);
T::assertSame(
    'Moyen',
    $c11['difficulty'] ?? null,
    'Le niveau de difficulté du critère 1.1 est importé'
);
T::assertSame(
    '2025-03-12',
    $c11['evaluation_date'] ?? null,
    'La date d\'évaluation du critère 1.1 est importée'
);
T::assertSame(
    'Première action',
    $c11['action_text'] ?? null,
    'L\'action du critère 1.1 est importée'
);
T::assertSame(
    ['Alice', 'Bob'],
    $c11['action_who'] ?? null,
    'Les responsables du critère 1.1 sont importés'
);
T::assertSame(
    '2026-10',
    $c11['action_when'] ?? null,
    'L\'échéance du critère 1.1 est importée'
);
T::assertSame(
    "Réponse libre de l'utilisateur au critère 1.1",
    $c11['comment'] ?? null,
    'Le commentaire de la ligne complémentaire du critère 1.1 est importé'
);

T::assertSame(
    '1.1',
    '1.1',
    'Contrôle de cohérence du test'
);

// ── Non-régression : le bug rapporté ─────────────────────────────────────────
//
// 1.2 est le DERNIER critère de l'onglet « 1 Stratégie (2) ». Les deux lignes
// d'en-tête de l'onglet suivant ne doivent pas lui être imputées : son champ
// « Actions à mener » est vide dans le fichier et doit le rester.

T::assertSame(
    '',
    $c12['action_text'] ?? null,
    'Le champ « Actions à mener » du dernier critère d\'un onglet reste vide (non rempli par l\'intitulé de colonne)'
);
T::assertSame(
    '',
    $c12['comment'] ?? null,
    'Le commentaire du dernier critère d\'un onglet ne reçoit pas les lignes d\'en-tête de l\'onglet suivant'
);
T::assertSame(
    'Recommandé',
    $c12['priority'] ?? null,
    'La priorité du dernier critère d\'un onglet reste correcte'
);
T::assertSame(
    'non-conforme',
    $c12['status'] ?? null,
    'Le statut du dernier critère d\'un onglet reste correct'
);
T::assertSame(
    'Fort',
    $c12['difficulty'] ?? null,
    'La difficulté du dernier critère d\'un onglet reste correcte'
);

T::section('Import XLSX — projet et auditeur');

T::assertSame(
    'Mon service',
    $audit['project']['name'] ?? null,
    'Le nom du service est extrait de la feuille « Score d\'avancement »'
);
T::assertSame(
    'https://exemple.fr',
    $audit['project']['url'] ?? null,
    'L\'URL du service est extraite de la feuille « Score d\'avancement »'
);
T::assertSame(
    'Jeanne Dupont',
    $audit['auditor']['name'] ?? null,
    'Le nom de l\'auditeur est extrait de la feuille « Score d\'avancement »'
);

T::section('Import ODS — détection du format et lecture');

$auditOds = rgesn_import_ods(t_audit(), $odsPath);

$c11o = t_criterion($auditOds, '1.1');
$c12o = t_criterion($auditOds, '1.2');

T::assertSame(
    'Prioritaire',
    $c11o['priority'] ?? null,
    'La priorité du critère 1.1 est importée depuis un ODS'
);
T::assertSame(
    'conforme',
    $c11o['status'] ?? null,
    'Le statut du critère 1.1 est importé depuis un ODS'
);
T::assertSame(
    'Première action',
    $c11o['action_text'] ?? null,
    'L\'action du critère 1.1 est importée depuis un ODS'
);
T::assertSame(
    '',
    $c12o['action_text'] ?? null,
    'Le champ « Actions à mener » du dernier critère reste vide en ODS'
);
T::assertSame(
    'Recommandé',
    $c12o['priority'] ?? null,
    'La priorité du dernier critère reste correcte en ODS'
);
T::assertSame(
    'Mon service',
    $auditOds['project']['name'] ?? null,
    'Le nom du service est extrait d\'un ODS'
);
T::assertSame(
    'Jeanne Dupont',
    $auditOds['auditor']['name'] ?? null,
    'Le nom de l\'auditeur est extrait d\'un ODS'
);

T::section('Détection du format — le nom du fichier ne suffit pas');

// Le fichier téléversé est recopié dans un nom temporaire SANS extension :
// la détection doit se faire sur le contenu de l'archive.
$tmpNoExt = $tmpDir . '/upload_sans_extension';
copy($odsPath, $tmpNoExt);

T::assertSame(
    'ods',
    rgesn_detect_spreadsheet_format($tmpNoExt),
    'Un ODS est détecté comme ODS même sans extension'
);
T::assertSame(
    'xlsx',
    rgesn_detect_spreadsheet_format($xlsxPath),
    'Un XLSX est détecté comme XLSX'
);

$viaDispatch = rgesn_import_spreadsheet(t_audit(), $tmpNoExt);
T::assertSame(
    'Prioritaire',
    t_criterion($viaDispatch, '1.1')['priority'] ?? null,
    'L\'import via rgesn_import_spreadsheet() lit correctement un ODS sans extension'
);

T::section('Détection des lignes d\'en-tête');

T::assertTrue(
    rgesn_row_is_header(T_HEADERS),
    'La ligne d\'intitulés de colonnes est reconnue comme en-tête'
);
T::assertTrue(
    rgesn_row_is_header(['A' => T_NOTES]),
    'La ligne de notes de début de feuille est reconnue comme en-tête'
);
T::assertFalse(
    rgesn_row_is_header(['B' => '1.1', 'C' => 'Premier critère', 'D' => 'Prioritaire']),
    'Une ligne de critère n\'est pas considérée comme un en-tête'
);
T::assertFalse(
    rgesn_row_is_header(['C' => "Réponse libre de l'utilisateur au critère 1.1"]),
    'Une ligne de commentaire n\'est pas considérée comme un en-tête'
);

T::section('Nettoyage');

@unlink($xlsxPath);
@unlink($odsPath);
@unlink($tmpNoExt);
@rmdir($tmpDir);

exit(T::summaryAndExitCode());
