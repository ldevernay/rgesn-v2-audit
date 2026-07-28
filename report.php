<?php
/**
 * Copyright (C) 2026  Grégory Biondo
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * RGESN V2 2024 Audit Tool — Rapport public (lecture seule)
 */

require_once __DIR__ . '/includes/functions.php';

function generate_markdown(array $audit, array $ref_criteria, array $by_thematic, array $thematic_stats, array $global_counts, float $score, array $non_conformes, array $conformes, bool $show_actions = false): string {
    $thematics = [
        1 => 'Stratégie', 2 => 'Spécifications', 3 => 'Architecture',
        4 => 'UX/UI',     5 => 'Contenus',        6 => 'Frontend',
        7 => 'Backend',   8 => 'Hébergement',      9 => 'Algorithmie',
    ];
    $total        = count($audit['criteria']);
    $date         = format_date($audit['updated_at']);
    $status_label = ($audit['status'] ?? '') === 'terminé' ? 'Terminé' : 'En cours';

    $md  = "# Rapport d'audit RGESN V2 2024 — " . $audit['project']['name'] . "\n\n";
    if (!empty($audit['project']['url'])) {
        $md .= "**URL du projet :** " . $audit['project']['url'] . "  \n";
    }
    if (!empty($audit['auditor']['name'])) {
        $md .= "**Auditeur :** " . $audit['auditor']['name'] . "  \n";
    }
    $md .= "**Date du rapport :** " . $date . "  \n";
    $md .= "**Statut :** " . $status_label . "\n\n";
    $md .= "---\n\n";

    $md .= "## Score global d'écoconception\n\n";
    $md .= "**" . $score . "%** — calculé selon la formule officielle RGESN V2 2024 avec pondération par niveau de priorité (Prioritaire ×1,5 · Recommandé ×1,25 · Modéré ×1,0).\n\n";
    $md .= "---\n\n";

    $md .= "## Synthèse globale\n\n";
    $md .= "| Statut | Nombre | % du total |\n";
    $md .= "|--------|-------:|-----------:|\n";
    $statuses = [
        'conforme'       => 'Conformes',
        'non-conforme'   => 'Non conformes',
        'non-applicable' => 'Non applicables',
        'non-testé'      => 'Non testés',
    ];
    foreach ($statuses as $key => $label) {
        $count = $global_counts[$key] ?? 0;
        $pct   = $total > 0 ? round($count / $total * 100) : 0;
        $md .= "| " . $label . " | " . $count . " | " . $pct . "% |\n";
    }
    $md .= "| **Total** | **" . $total . "** | **100%** |\n\n";
    $md .= "---\n\n";

    $md .= "## Résultats par thématique\n\n";
    $md .= "| Thématique | Critères | Conformes | Non conformes | Non applicables | Taux |\n";
    $md .= "|-----------|:--------:|:---------:|:-------------:|:---------------:|:----:|\n";
    foreach ($thematics as $tid => $tname) {
        $s    = $thematic_stats[$tid] ?? ['conforme' => 0, 'non-conforme' => 0, 'non-applicable' => 0, 'total' => 0, 'score' => null];
        $taux = $s['score'] !== null ? $s['score'] . '%' : 'N/A';
        $md .= "| " . $tid . " — " . $tname . " | " . $s['total'] . " | " . $s['conforme'] . " | " . $s['non-conforme'] . " | " . $s['non-applicable'] . " | " . $taux . " |\n";
    }
    $md .= "| **Total** | **" . $total . "** | **" . $global_counts['conforme'] . "** | **" . $global_counts['non-conforme'] . "** | **" . $global_counts['non-applicable'] . "** | **" . $score . "%** |\n\n";
    $md .= "---\n\n";

    if (!empty($non_conformes)) {
        $md .= "## Critères non conformes (" . count($non_conformes) . ")\n\n";
        $md .= "Les critères suivants n'ont pas été validés lors de cet audit.\n\n";
        foreach ($thematics as $tid => $tname) {
            $nc = array_filter($non_conformes, fn($c) => ($c['thematic_id'] ?? 0) === $tid);
            if (empty($nc)) continue;
            $md .= "### " . $tid . " — " . $tname . "\n\n";
            foreach ($nc as $c) {
                $cid      = $c['id'];
                $title    = $ref_criteria[$cid]['title'] ?? "Critère " . $cid;
                $priority = $c['priority'] ?? 'Modéré';
                $comment  = trim($c['comment'] ?? '');
                $md .= "#### " . $cid . " — " . $title . "\n\n";
                $md .= "**Priorité :** " . $priority . "\n\n";
                if ($comment) {
                    $md .= "> **Commentaire de l'auditeur :** " . str_replace("\n", "\n> ", $comment) . "\n\n";
                } else {
                    $md .= "*Aucun commentaire renseigné.*\n\n";
                }
                if ($show_actions) {
                    $at    = trim($c['action_text'] ?? '');
                    $aw    = $c['action_who'] ?? [];
                    $awhen = trim($c['action_when'] ?? '');
                    $aeasy = $c['action_easy'] ?? false;
                    if ($at || !empty($aw) || $awhen || $aeasy) {
                        $md .= "**Actions :**" . ($aeasy ? " *(Facile à corriger)*" : "") . "\n\n";
                        if ($at) {
                            $md .= "> *Actions à mener :* " . str_replace("\n", "\n> ", $at) . "\n\n";
                        }
                        if (!empty($aw)) {
                            $md .= "> *Qui fait ?* " . implode(', ', array_map('strval', $aw)) . "\n\n";
                        }
                        if ($awhen && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $awhen, $mm)) {
                            $_mo = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
                            $md .= "> *Pour quand ?* " . $_mo[(int)$mm[2]] . ' ' . $mm[1] . "\n\n";
                        }
                    }
                }
            }
        }
        $md .= "---\n\n";
    }

    if (!empty($conformes)) {
        $md .= "## Critères conformes (" . count($conformes) . ")\n\n";
        foreach ($thematics as $tid => $tname) {
            $ck = array_filter($conformes, fn($c) => ($c['thematic_id'] ?? 0) === $tid);
            if (empty($ck)) continue;
            $md .= "### " . $tid . " — " . $tname . "\n\n";
            foreach ($ck as $c) {
                $cid      = $c['id'];
                $title    = $ref_criteria[$cid]['title'] ?? "Critère " . $cid;
                $priority = $c['priority'] ?? 'Modéré';
                $comment  = trim($c['comment'] ?? '');
                $md .= "- **" . $cid . "** — " . $title . " *(" . $priority . ")*";
                if ($comment) {
                    $md .= "\n  > " . str_replace("\n", "\n  > ", $comment);
                }
                if ($show_actions) {
                    $at    = trim($c['action_text'] ?? '');
                    $aw    = $c['action_who'] ?? [];
                    $awhen = trim($c['action_when'] ?? '');
                    $aeasy = $c['action_easy'] ?? false;
                    if ($at || !empty($aw) || $awhen || $aeasy) {
                        if ($aeasy) {
                            $md .= "\n  > *Facile à corriger*";
                        }
                        if ($at) {
                            $md .= "\n  > *Actions :* " . str_replace("\n", "\n  > ", $at);
                        }
                        if (!empty($aw)) {
                            $md .= "\n  > *Qui fait ?* " . implode(', ', array_map('strval', $aw));
                        }
                        if ($awhen && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $awhen, $mm)) {
                            $_mo = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
                            $md .= "\n  > *Pour quand ?* " . $_mo[(int)$mm[2]] . ' ' . $mm[1];
                        }
                    }
                }
                $md .= "\n";
            }
            $md .= "\n";
        }
        $md .= "---\n\n";
    }

    $md .= "## Détail complet des critères\n\n";
    $status_labels = [
        'conforme'       => 'Conforme',
        'non-conforme'   => 'Non conforme',
        'non-applicable' => 'Non applicable',
        'non-testé'      => 'Non testé',
    ];
    foreach ($thematics as $tid => $tname) {
        $thematic_crits = $by_thematic[$tid] ?? [];
        if (empty($thematic_crits)) continue;
        $md .= "### " . $tid . " — " . $tname . "\n\n";
        $md .= "| ID | Critère | Priorité | Difficulté | Statut |\n";
        $md .= "|----|---------|----------|------------|--------|\n";
        foreach ($thematic_crits as $c) {
            $cid        = $c['id'];
            $title      = $ref_criteria[$cid]['title'] ?? "Critère " . $cid;
            $status     = $c['status'] ?? 'non-testé';
            $priority   = $ref_criteria[$cid]['priority']   ?? $c['priority']   ?? 'Modéré';
            $difficulty = $ref_criteria[$cid]['difficulty'] ?? $c['difficulty'] ?? 'Moyen';
            $slabel     = $status_labels[$status] ?? $status;
            $md .= "| " . $cid . " | " . $title . " | " . $priority . " | " . $difficulty . " | " . $slabel . " |\n";
        }
        $md .= "\n";
    }

    $md .= "---\n\n";
    $md .= "*Rapport généré avec l'Outil d'audit RGESN V2 2024*  \n";
    $md .= "*Basé sur le Référentiel Général d'Écoconception des Services Numériques (RGESN) V2 — 2024.*\n";

    return $md;
}

function format_date(string $iso): string {
    $ts = strtotime($iso);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function format_month(string $ym): string {
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym, $m)) {
        return '';
    }
    $months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
               'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $months[(int)$m[2]] . ' ' . $m[1];
}

function difficulty_badge(string $d): string {
    $map = [
        'Fort'   => 'badge-diff-high',
        'Moyen'  => 'badge-diff-medium',
        'Faible' => 'badge-diff-low',
    ];
    $cls = $map[$d] ?? 'badge-diff-medium';
    return "<span class=\"{$cls}\">{$d}</span>";
}

function priority_badge(string $p): string {
    $map = [
        'Prioritaire' => 'badge-priority-high',
        'Recommandé'  => 'badge-priority-medium',
        'Modéré'      => 'badge-priority-low',
    ];
    $cls = $map[$p] ?? 'badge-priority-low';
    return "<span class=\"badge-indicator {$cls}\">{$p}</span>";
}

// ── Chargement ────────────────────────────────────────────────────────────────
$audit_id = trim($_GET['id'] ?? '');
if (!is_valid_uuid($audit_id)) {
    header('Location: index.php');
    exit;
}

$audit_file = __DIR__ . '/data/audits/' . $audit_id . '.json';
if (!file_exists($audit_file)) {
    header('Location: index.php');
    exit;
}

$audit = json_decode(file_get_contents($audit_file), true);
if (!is_array($audit)) {
    header('Location: index.php');
    exit;
}

// Organiser les critères par thématique
$by_thematic = [];
foreach ($audit['criteria'] as $c) {
    $tid = $c['thematic_id'] ?? 1;
    $by_thematic[$tid][] = $c;
}

// Calcul des statistiques par thématique
$thematic_stats = [];
foreach ($by_thematic as $tid => $crits) {
    $stats = ['conforme' => 0, 'non-conforme' => 0, 'non-applicable' => 0, 'non-testé' => 0, 'total' => count($crits)];
    foreach ($crits as $c) {
        $s = $c['status'] ?? 'non-testé';
        if (isset($stats[$s])) $stats[$s]++;
    }
    // Score pondéré pour cette thématique
    $num = 0; $den = 0;
    $weights = ['Prioritaire' => 1.5, 'Recommandé' => 1.25, 'Modéré' => 1.0];
    foreach ($crits as $c) {
        $w = $weights[$c['priority'] ?? 'Modéré'] ?? 1.0;
        if ($c['status'] === 'conforme')     { $num += $w; $den += $w; }
        elseif ($c['status'] === 'non-conforme') { $den += $w; }
    }
    $stats['score'] = $den > 0 ? round($num / $den * 100, 1) : null;
    $thematic_stats[$tid] = $stats;
}

// Compteurs globaux
$global_counts = ['conforme' => 0, 'non-conforme' => 0, 'non-applicable' => 0, 'non-testé' => 0];
foreach ($audit['criteria'] as $c) {
    $s = $c['status'] ?? 'non-testé';
    if (isset($global_counts[$s])) $global_counts[$s]++;
}

$score = $audit['score'] ?? 0.0;
$is_done = $audit['status'] === 'terminé';

// Charger le référentiel des critères (titres + données documentaires)
$ref_criteria = [];
$ref_file = __DIR__ . '/data/criteria/criteria_settings.json';
if (file_exists($ref_file)) {
    $ref_raw = json_decode(file_get_contents($ref_file), true);
    if (is_array($ref_raw)) {
        foreach ($ref_raw as $item) {
            $ref_criteria[$item['id']] = $item;
        }
    }
}

// Critères non conformes et conformes
$non_conformes = array_filter($audit['criteria'], fn($c) => ($c['status'] ?? '') === 'non-conforme');
$conformes     = array_filter($audit['criteria'], fn($c) => ($c['status'] ?? '') === 'conforme');

$markdown_content              = generate_markdown($audit, $ref_criteria, $by_thematic, $thematic_stats, $global_counts, $score, $non_conformes, $conformes, false);
$markdown_content_with_actions = generate_markdown($audit, $ref_criteria, $by_thematic, $thematic_stats, $global_counts, $score, $non_conformes, $conformes, true);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapport RGESN — <?= esc($audit['project']['name']) ?></title>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="report-page bg-white">

<!-- Header d'impression / page -->
<header class="app-header shadow-sm d-print-none">
    <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
        <div class="header-brand d-flex align-items-center gap-2">
            <img src="assets/images/logo.svg" alt="" height="32">
            <span class="header-title">Audit RGESN</span>
            <span class="badge bg-indigo ms-1">V2 2024</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm d-print-none" id="btnToggleActions">
                <i class="bi bi-lightning me-1"></i>Afficher les actions
            </button>
            <button class="btn btn-outline-indigo btn-sm d-print-none" id="btnDownloadMd">
                <i class="bi bi-markdown me-1"></i>Télécharger en Markdown
            </button>
            <button class="btn btn-indigo btn-sm d-print-none" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimer / Exporter PDF
            </button>
        </div>
    </div>
</header>

<!-- Contenu principal du rapport -->
<main class="container py-5" style="max-width:960px;" id="reportContent">

    <!-- En-tête du rapport (imprimable) -->
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-5">
        <div>
            <div class="text-muted small text-uppercase fw-semibold letter-spacing mb-1">
                Rapport d'audit RGESN V2 2024
            </div>
            <h1 class="display-6 fw-bold mb-1"><?= esc($audit['project']['name']) ?></h1>
            <?php if ($audit['project']['url']): ?>
            <a href="<?= esc($audit['project']['url']) ?>" target="_blank" rel="noopener"
               class="text-muted text-decoration-none d-print-none">
                <i class="bi bi-box-arrow-up-right me-1"></i><?= esc($audit['project']['url']) ?>
            </a>
            <p class="text-muted d-none d-print-block mb-0"><?= esc($audit['project']['url']) ?></p>
            <?php endif; ?>
        </div>
        <div class="text-end">
            <img src="assets/images/logo.svg" alt="Axenum" height="40" class="mb-2">
            <div class="small text-muted">
                <?php if ($audit['auditor']['name']): ?>
                <div><i class="bi bi-person me-1"></i>Audité par <?= esc($audit['auditor']['name']) ?></div>
                <?php endif; ?>
                <div><i class="bi bi-calendar me-1"></i>Rapport en date du <?= format_date($audit['updated_at']) ?></div>
            </div>
        </div>
    </div>

    <!-- Score global -->
    <div class="report-score-block mb-5">
        <div class="row g-3 align-items-center">
            <div class="col-auto">
                <div class="report-score-circle">
                    <span class="report-score-value"><?= $score ?></span>
                    <span class="report-score-unit">%</span>
                </div>
            </div>
            <div class="col">
                <h2 class="h4 fw-bold mb-1">Score global d'écoconception</h2>
                <p class="text-muted mb-2">
                    Calculé selon la formule officielle RGESN V2 2024 avec pondération par niveau de priorité
                    (Prioritaire ×1,5 · Recommandé ×1,25 · Modéré ×1,0).
                </p>
                <div class="progress" style="height:12px;max-width:400px;" role="progressbar"
                     aria-valuenow="<?= $score ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar report-score-bar" style="width:<?= $score ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Synthèse globale -->
    <h2 class="h5 fw-bold mb-3">
        <i class="bi bi-bar-chart-fill me-2 text-indigo"></i>Synthèse globale
    </h2>
    <div class="row g-3 mb-5 summary-cards-row">
        <?php
        $summary_cards = [
            ['label' => 'Conformes',       'key' => 'conforme',       'icon' => 'check-circle-fill', 'bg' => '#d1fae5', 'clr' => '#065f46'],
            ['label' => 'Non conformes',   'key' => 'non-conforme',   'icon' => 'x-circle-fill',     'bg' => '#fde8e8', 'clr' => '#b91c1c'],
            ['label' => 'Non applicables', 'key' => 'non-applicable', 'icon' => 'dash-circle-fill',  'bg' => '#f0f0f0', 'clr' => '#555555'],
        ];
        foreach ($summary_cards as $card):
            $count = $global_counts[$card['key']];
            $pct   = count($audit['criteria']) > 0 ? round($count / count($audit['criteria']) * 100) : 0;
        ?>
        <div class="col-12 col-md-4">
            <div class="card border-0 text-center h-100" style="background:<?= $card['bg'] ?>;">
                <div class="card-body py-4">
                    <i class="bi bi-<?= $card['icon'] ?> mb-2" style="font-size:1.75rem;color:<?= $card['clr'] ?>;"></i>
                    <div class="h2 fw-bold mb-0" style="color:<?= $card['clr'] ?>;"><?= $count ?></div>
                    <div class="fw-semibold small mt-1" style="color:<?= $card['clr'] ?>;"><?= $card['label'] ?></div>
                    <div class="small mt-1" style="color:<?= $card['clr'] ?>;"><?= $pct ?>% du total</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tableau par thématique -->
    <h2 class="h5 fw-bold mb-3">
        <i class="bi bi-table me-2 text-indigo"></i>Résultats des critères par thématique
    </h2>
    <div class="table-responsive mb-5">
        <table class="table table-bordered align-middle">
            <caption class="caption-sr-only">Résultats des critères d'audit RGESN par thématique : nombre total de critères, conformes, non conformes, non applicables et taux de conformité pour chaque thématique, avec un total général.</caption>
            <thead class="table-light">
                <tr>
                    <th>Thématique</th>
                    <th class="text-center">Critères</th>
                    <th class="text-center text-success-emphasis">Conformes</th>
                    <th class="text-center text-danger-emphasis">Non conformes</th>
                    <th class="text-center text-secondary-emphasis">Non applicables</th>
                    <th class="text-center">Taux</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (THEMATICS as $tid => $tname):
                    $s = $thematic_stats[$tid] ?? ['conforme'=>0,'non-conforme'=>0,'non-applicable'=>0,'non-testé'=>0,'total'=>0,'score'=>null];
                    $score_display = $s['score'] !== null
                        ? '<span class="fw-semibold text-indigo">' . $s['score'] . '%</span>'
                        : '<span class="text-muted">N/A</span>';
                ?>
                <tr>
                    <td>
                        <span class="badge bg-light text-dark border me-2"><?= $tid ?></span>
                        <?= esc($tname) ?>
                    </td>
                    <td class="text-center"><?= $s['total'] ?></td>
                    <td class="text-center fw-semibold text-success"><?= $s['conforme'] ?></td>
                    <td class="text-center fw-semibold text-danger"><?= $s['non-conforme'] ?></td>
                    <td class="text-center text-secondary"><?= $s['non-applicable'] ?></td>
                    <td class="text-center"><?= $score_display ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td>Total</td>
                    <td class="text-center"><?= count($audit['criteria']) ?></td>
                    <td class="text-center text-success-emphasis"><?= $global_counts['conforme'] ?></td>
                    <td class="text-center text-danger-emphasis"><?= $global_counts['non-conforme'] ?></td>
                    <td class="text-center text-secondary-emphasis"><?= $global_counts['non-applicable'] ?></td>
                    <td class="text-center text-indigo"><?= $score ?>%</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Critères non conformes -->
    <?php if (!empty($non_conformes)): ?>
    <h2 class="h5 fw-bold mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>
        Critères non conformes (<?= count($non_conformes) ?>)
    </h2>
    <p class="text-muted mb-4">
        Les critères suivants n'ont pas été validés lors de cet audit. Des recommandations et commentaires
        de l'auditeur sont inclus le cas échéant.
    </p>

    <?php foreach (THEMATICS as $tid => $tname):
        $nc_in_thematic = array_filter($non_conformes, fn($c) => ($c['thematic_id'] ?? 0) === $tid);
        if (empty($nc_in_thematic)) continue;
    ?>
    <div class="mb-4">
        <div class="d-flex align-items-center mb-3 border-bottom pb-2">
            <span class="badge bg-light text-dark border me-2"><?= $tid ?></span>
            <h3 class="h6 fw-bold text-muted text-uppercase mb-0"><?= esc($tname) ?></h3>
        </div>
        <?php foreach ($nc_in_thematic as $c):
            $cid = $c['id'];
            $title = $ref_criteria[$cid]['title'] ?? "Critère {$cid}";
            $comment = $c['comment'] ?? '';
            $priority = $c['priority'] ?? 'Modéré';
        ?>
        <div class="nc-criterion-card mb-3">
            <div class="d-flex align-items-start gap-3">
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold flex-shrink-0 mt-1">
                    <?= esc($cid) ?>
                </span>
                <div class="flex-grow-1">
                    <h4 class="fs-6 lh-base fw-semibold mb-1"><?= esc($title) ?></h4>
                    <?= priority_badge($priority) ?>
                    <?php if ($comment): ?>
                    <div class="mt-2 p-3 bg-light rounded">
                        <h5 class="small lh-base fw-semibold text-muted mb-1">
                            <i class="bi bi-chat-left-text me-1"></i>Commentaire de l'auditeur
                        </h5>
                        <div class="small report-rich-text"><?= nl2p($comment) ?></div>
                    </div>
                    <?php else: ?>
                    <div class="mt-2 text-muted small fst-italic">Aucun commentaire renseigné</div>
                    <?php endif; ?>
                    <?php
                    $action_text = trim($c['action_text'] ?? '');
                    $action_who  = $c['action_who'] ?? [];
                    $action_when = trim($c['action_when'] ?? '');
                    $action_easy = $c['action_easy'] ?? false;
                    if ($action_text !== '' || !empty($action_who) || $action_when !== '' || $action_easy):
                    ?>
                    <div class="report-actions-block mt-2 p-3 rounded" style="background:#f5f0ff;">
                        <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                            <h5 class="small lh-base fw-semibold text-indigo mb-0"><i class="bi bi-lightning me-1"></i>Actions</h5>
                            <?php if ($action_easy): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-stars me-1"></i>Facile à corriger
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($action_text): ?>
                        <div class="mb-2">
                            <div class="small text-muted fw-semibold mb-1">Actions à mener</div>
                            <div class="small report-rich-text"><?= nl2p($action_text) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($action_who)): ?>
                        <div class="mb-2">
                            <div class="small text-muted fw-semibold mb-1">Qui fait ?</div>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($action_who as $tag): ?>
                                <span class="badge text-white small" style="background:var(--rgesn-indigo);"><?= esc($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($action_when): ?>
                        <div>
                            <div class="small text-muted fw-semibold mb-1">Pour quand ?</div>
                            <div class="small"><?= esc(format_month($action_when)) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill me-2"></i>
        Aucun critère non conforme. Félicitations !
    </div>
    <?php endif; ?>

    <!-- Critères conformes -->
    <?php if (!empty($conformes)): ?>
    <h2 class="h5 fw-bold mb-3 mt-5">
        <i class="bi bi-check-circle-fill me-2 text-success"></i>
        Critères conformes (<?= count($conformes) ?>)
    </h2>
    <p class="text-muted mb-4">
        Les critères suivants ont été validés lors de cet audit.
    </p>

    <?php foreach (THEMATICS as $tid => $tname):
        $c_in_thematic = array_filter($conformes, fn($c) => ($c['thematic_id'] ?? 0) === $tid);
        if (empty($c_in_thematic)) continue;
    ?>
    <div class="mb-4">
        <div class="d-flex align-items-center mb-3 border-bottom pb-2">
            <span class="badge bg-light text-dark border me-2"><?= $tid ?></span>
            <h3 class="h6 fw-bold text-muted text-uppercase mb-0"><?= esc($tname) ?></h3>
        </div>
        <?php foreach ($c_in_thematic as $c):
            $cid = $c['id'];
            $title = $ref_criteria[$cid]['title'] ?? "Critère {$cid}";
            $comment = $c['comment'] ?? '';
            $priority = $c['priority'] ?? 'Modéré';
        ?>
        <div class="nc-criterion-card mb-3" style="border-left-color: #198754;">
            <div class="d-flex align-items-start gap-3">
                <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold flex-shrink-0 mt-1">
                    <?= esc($cid) ?>
                </span>
                <div class="flex-grow-1">
                    <h4 class="fs-6 lh-base fw-semibold mb-1"><?= esc($title) ?></h4>
                    <?= priority_badge($priority) ?>
                    <?php if ($comment): ?>
                    <div class="mt-2 p-3 bg-light rounded">
                        <h5 class="small lh-base fw-semibold text-muted mb-1">
                            <i class="bi bi-chat-left-text me-1"></i>Commentaire de l'auditeur
                        </h5>
                        <div class="small report-rich-text"><?= nl2p($comment) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php
                    $action_text = trim($c['action_text'] ?? '');
                    $action_who  = $c['action_who'] ?? [];
                    $action_when = trim($c['action_when'] ?? '');
                    $action_easy = $c['action_easy'] ?? false;
                    if ($action_text !== '' || !empty($action_who) || $action_when !== '' || $action_easy):
                    ?>
                    <div class="report-actions-block mt-2 p-3 rounded" style="background:#f5f0ff;">
                        <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                            <h5 class="small lh-base fw-semibold text-indigo mb-0"><i class="bi bi-lightning me-1"></i>Actions</h5>
                            <?php if ($action_easy): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-stars me-1"></i>Facile à corriger
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($action_text): ?>
                        <div class="mb-2">
                            <div class="small text-muted fw-semibold mb-1">Actions à mener</div>
                            <div class="small report-rich-text"><?= nl2p($action_text) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($action_who)): ?>
                        <div class="mb-2">
                            <div class="small text-muted fw-semibold mb-1">Qui fait ?</div>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($action_who as $tag): ?>
                                <span class="badge text-white small" style="background:var(--rgesn-indigo);"><?= esc($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($action_when): ?>
                        <div>
                            <div class="small text-muted fw-semibold mb-1">Pour quand ?</div>
                            <div class="small"><?= esc(format_month($action_when)) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

    <!-- Détail complet de tous les critères -->
    <h2 class="h5 fw-bold mb-3 mt-5 page-break-before">
        <i class="bi bi-list-check me-2 text-indigo"></i>Détail de l'ensemble des critères
    </h2>

    <?php foreach (THEMATICS as $tid => $tname):
        $thematic_crits = $by_thematic[$tid] ?? [];
        if (empty($thematic_crits)) continue;
    ?>
    <div class="mb-4">
        <div class="d-flex align-items-center mb-3 border-bottom pb-2">
            <span class="badge bg-light text-dark border me-2"><?= $tid ?></span>
            <h3 class="h6 fw-bold text-muted text-uppercase mb-0"><?= esc($tname) ?></h3>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <caption class="caption-sr-only">Détail des critères de la thématique <?= esc($tname) ?> : identifiant, intitulé, priorité, difficulté et statut de conformité de chaque critère.</caption>
                <thead class="table-light">
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>Critère</th>
                        <th style="width:100px">Priorité</th>
                        <th style="width:100px">Difficulté</th>
                        <th style="width:130px">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($thematic_crits as $c):
                        $cid = $c['id'];
                        $title = $ref_criteria[$cid]['title'] ?? "Critère {$cid}";
                        $status = $c['status'] ?? 'non-testé';
                        $priority   = $ref_criteria[$cid]['priority']   ?? $c['priority']   ?? 'Modéré';
                        $difficulty = $ref_criteria[$cid]['difficulty'] ?? $c['difficulty'] ?? 'Moyen';

                        $status_badge_cls = [
                            'conforme'       => 'success',
                            'non-conforme'   => 'danger',
                            'non-applicable' => 'secondary',
                            'non-testé'      => 'warning',
                        ][$status] ?? 'secondary';
                        $status_label = [
                            'conforme'       => 'Conforme',
                            'non-conforme'   => 'Non conforme',
                            'non-applicable' => 'Non applicable',
                            'non-testé'      => 'Non testé',
                        ][$status] ?? $status;
                    ?>
                    <tr>
                        <td class="fw-semibold text-indigo"><?= esc($cid) ?></td>
                        <td class="small"><?= esc($title) ?></td>
                        <td><?= priority_badge($priority) ?></td>
                        <td><?= difficulty_badge($difficulty) ?></td>
                        <td>
                            <span class="badge bg-<?= $status_badge_cls ?>-subtle text-<?= $status_badge_cls ?> border border-<?= $status_badge_cls ?>-subtle">
                                <?= esc($status_label) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

</main><!-- /#reportContent -->

<!-- Pied de page du rapport -->
<footer class="container border-top pt-4 pb-5 text-center text-muted small" style="max-width:960px;">
    <p class="mb-1">
        Rapport généré avec l'Outil d'audit RGESN V2 2024
    </p>
    <p class="mb-0">
        Basé sur le
        <a href="https://www.arcep.fr/uploads/tx_gspublication/referentiel_general_ecoconception_des_services_numeriques_version_2024.pdf"
           target="_blank" rel="noopener" class="d-print-none">
            Référentiel Général d'Écoconception des Services Numériques (RGESN) V2 — 2024
        </a>
        <span class="d-none d-print-inline">Référentiel Général d'Écoconception des Services Numériques (RGESN) V2 — 2024</span>
    </p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
let actionsVisible = false;

const btnToggleActions = document.getElementById('btnToggleActions');
btnToggleActions?.addEventListener('click', function () {
    actionsVisible = !actionsVisible;
    document.body.classList.toggle('report-show-actions', actionsVisible);
    this.innerHTML = actionsVisible
        ? '<i class="bi bi-lightning-fill me-1"></i>Masquer les actions'
        : '<i class="bi bi-lightning me-1"></i>Afficher les actions';
    this.classList.toggle('btn-secondary', actionsVisible);
    this.classList.toggle('btn-outline-secondary', !actionsVisible);
});

document.getElementById('btnDownloadMd')?.addEventListener('click', function () {
    const mdContent = actionsVisible
        ? <?= json_encode($markdown_content_with_actions) ?>
        : <?= json_encode($markdown_content) ?>;
    const projectName = <?= json_encode($audit['project']['name']) ?>;
    const slug = projectName.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    const blob = new Blob([mdContent], { type: 'text/markdown; charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'rapport-rgesn-' + slug + '.md';
    a.click();
    URL.revokeObjectURL(url);
});
</script>
</body>
</html>
