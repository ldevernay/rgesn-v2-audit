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
 * RGESN V2 2024 Audit Tool — Notes de version
 */

$versions = [
    [
        'version' => '1.5',
        'date'    => 'Juillet 2026',
        'label'   => 'Accessibilité RGAA 4.1.2',
        'sections' => [
            [
                'title' => 'Tableau de bord',
                'items' => [
                    'Légende ajoutée au tableau des audits et en-têtes de colonnes correctement déclarés',
                    'Étiquettes accessibles sur les champs de filtre (Projet, Auditeur, Statut) et sur les boutons de filtre par date/nombre',
                    'Boutons de tri des colonnes accessibles au clavier avec indication de l\'état de tri',
                    'Bouton "Filtres" annonçant son état ouvert/fermé aux lecteurs d\'écran',
                    'Lien "Retour à l\'accueil" explicite sur le logo du bandeau',
                    'Contrastes de couleurs renforcés (bouton Filtres, bordures des champs de filtre)',
                    'Indicateur de focus clavier visible sur les boutons d\'action de chaque audit (Rapport, Modifier/Continuer)',
                ],
            ],
            [
                'title' => 'Page Audit',
                'items' => [
                    'Structure des titres corrigée pour une hiérarchie cohérente au sein de chaque critère',
                    'Étiquette accessible propre à chaque critère sur son champ de commentaire',
                    'Étiquettes correctement associées aux champs de l\'accordéon Actions (Actions à mener, Qui fait, Pour quand)',
                    'Listes à puces de la documentation des critères restituées comme de véritables listes',
                    'Décochage d\'un statut de conformité désormais possible au clavier (et pas seulement à la souris)',
                    'Indicateur de focus clavier visible sur les boutons de statut de conformité',
                    'Suggestions de tags du champ "Qui fait ?" accessibles au clavier',
                    'Lien "actif" de la thématique en cours signalé aux lecteurs d\'écran dans la navigation latérale',
                    'Contrastes de couleurs renforcés (bordures des champs, boutons de statut, textes secondaires)',
                    'Zone de contenu principal (titre de l\'audit, statistiques, critères) regroupée dans une seule zone identifiable',
                ],
            ],
            [
                'title' => 'Rapport public',
                'items' => [
                    'Structure des titres corrigée (thématique, critère, sous-sections Commentaire/Actions)',
                    'Zones de contenu principal et de pied de page identifiées',
                    'Légendes ajoutées aux deux tableaux de synthèse',
                    'Listes à puces des commentaires et actions saisis par l\'auditeur restituées comme de véritables listes',
                    'Contrastes de couleurs renforcés (étiquettes de statut, en-têtes de tableau, indicateurs de synthèse)',
                ],
            ],
        ],
    ],
    [
        'version' => '1.4',
        'date'    => 'Juillet 2026',
        'label'   => 'Tableau de bord et suivi des actions',
        'sections' => [
            [
                'title' => 'Tableau de bord',
                'items' => [
                    'Pagination des audits par lot de 20',
                    'Tri par colonne (Projet, Auditeur, Création, Mise à jour, Statut, Taux, Avancement) en cliquant sur l\'en-tête',
                    'Ligne de filtres par colonne, masquée par défaut et affichable via un bouton dédié à côté du nombre d\'audits',
                    'Recherche sur "Projet" et "Auditeur" insensible aux accents et à la casse',
                    'Filtre par plage de dates pour "Création" et "Mise à jour" via une modale accessible (sélecteurs de date natifs)',
                    'Filtre par plage de valeurs pour "Taux" et "Avancement" via une modale avec curseur et champs Minimum/Maximum accessibles',
                ],
            ],
            [
                'title' => 'Page Audit',
                'items' => [
                    'Nouvelle case à cocher "Facile à corriger" dans l\'accordéon "Actions" de chaque critère',
                ],
            ],
            [
                'title' => 'Rapport public',
                'items' => [
                    'Affichage de l\'indicateur "Facile à corriger" dans le détail des actions du rapport et dans l\'export Markdown',
                ],
            ],
        ],
    ],
    [
        'version' => '1.3',
        'date'    => 'Juin 2026',
        'label'   => 'Plan d\'actions',
        'sections' => [
            [
                'title' => 'Tableau de bord',
                'items' => [
                    'Duplication d\'un audit existant depuis le tableau de bord (bouton "Dupliquer" sur chaque ligne)',
                    'Au moment de la duplication, possibilité de modifier le nom du projet, l\'URL et l\'auditeur·ice',
                    'Tous les critères, statuts, commentaires et actions sont copiés dans le nouvel audit',
                ],
            ],
            [
                'title' => 'Page Audit',
                'items' => [
                    'Nouvel accordéon "Actions" sur chaque critère, permettant de planifier les mesures correctives directement dans l\'audit',
                    'Champ "Actions à mener" : zone de texte libre pour décrire les actions correctives ou préventives',
                    'Champ "Qui fait ?" : saisie par tags avec réutilisation automatique des tags déjà saisis sur les autres critères',
                    'Champ "Pour quand ?" : sélecteur mois + année natif (sans dépendance externe)',
                    'Les trois champs sont optionnels ; l\'accordéon affiche un badge "Renseigné" dès qu\'une action est saisie',
                    'Les actions sont sauvegardées automatiquement avec le reste de l\'audit',
                ],
            ],
            [
                'title' => 'Rapport public',
                'items' => [
                    'Bouton "Afficher les actions" pour révéler dans le rapport les actions saisies lors de l\'audit (actions, responsables, échéance)',
                    'Les actions sont masquées par défaut et n\'apparaissent pas à l\'impression ni dans l\'export Markdown',
                    'Lorsqu\'elles sont affichées, elles sont incluses dans l\'impression/export PDF et dans le fichier Markdown téléchargé',
                ],
            ],
        ],
    ],
    [
        'version' => '1.2',
        'date'    => 'Juin 2026',
        'label'   => 'Export Markdown',
        'sections' => [
            [
                'title' => 'Rapport public',
                'items' => [
                    'Téléchargement du rapport au format Markdown (.md) depuis le rapport public',
                    'Le fichier généré est structuré pour être exploité directement par un LLM afin de rédiger une déclaration d\'écoconception',
                    'Contenu complet : score global, synthèse par thématique, critères non conformes avec commentaires, critères conformes, détail des 78 critères',
                ],
            ],
        ],
    ],
    [
        'version' => '1.1',
        'date'    => 'Février 2026',
        'label'   => 'Améliorations de l\'interface',
        'sections' => [
            [
                'title' => 'Page Audit',
                'items' => [
                    'Filtrage des critères par statut en cliquant sur les blocs de synthèse (Conformes, Non conformes, Non applicables, Non testés) — clic à nouveau pour réinitialiser',
                    'Modification des informations d\'un audit en cours (nom du projet, URL, auditeur·ice) via un bouton crayon dans l\'en-tête',
                    'Bouton flottant discret pour remonter en haut de page, affiché uniquement après avoir commencé à scroller',
                ],
            ],
        ],
    ],
    [
        'version' => '1.0',
        'date'    => 'Février 2026',
        'label'   => 'Première version',
        'sections' => [
            [
                'title' => 'Gestion des audits',
                'items' => [
                    'Création d\'un audit avec nom de projet, URL et nom de l\'auditeur·ice',
                    'Tableau de bord listant tous les audits avec leur état d\'avancement',
                    'Sauvegarde automatique des réponses au fil de la saisie',
                    'Suppression d\'un audit',
                ],
            ],
            [
                'title' => 'Évaluation des critères',
                'items' => [
                    'Évaluation des 78 critères du RGESN V2 2024 répartis en 9 thématiques',
                    'Quatre statuts disponibles par critère : Conforme, Non conforme, Non applicable, Non testé',
                    'Commentaire libre par critère (observations, recommandations)',
                    'Documentation intégrée par critère : objectif, mise en œuvre, moyen de contrôle',
                    'Filtrage des critères par statut depuis les blocs de synthèse',
                    'Navigation rapide entre les thématiques via la barre latérale',
                ],
            ],
            [
                'title' => 'Score et synthèse',
                'items' => [
                    'Calcul du taux de conformité pondéré selon la priorité des critères (Prioritaire ×1,5 · Recommandé ×1,25 · Modéré ×1,0)',
                    'Indicateurs en temps réel : conformes, non conformes, non applicables, non testés',
                    'Progression de l\'audit par thématique',
                ],
            ],
            [
                'title' => 'Rapport public',
                'items' => [
                    'Génération d\'un rapport de synthèse à l\'issue de l\'audit',
                    'Détail des critères non conformes et conformes avec commentaires de l\'auditeur',
                    'Tableau récapitulatif par thématique',
                    'Export PDF via la fonction d\'impression du navigateur',
                ],
            ],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes de version — Outil d'audit RGESN V2 2024</title>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

<!-- Header -->
<header class="app-header shadow-sm">
    <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
        <a href="index.php" class="header-brand d-flex align-items-center gap-2 text-decoration-none">
            <img src="assets/images/logo.svg" alt="" height="32">
            <span class="header-title">Audit RGESN</span>
            <span class="badge bg-indigo ms-1">V2 2024</span>
            <span class="visually-hidden">Retour à l'accueil</span>
        </a>
    </div>
</header>

<!-- Contenu -->
<main class="container py-5" style="max-width:760px;">

    <a href="index.php" class="text-muted small text-decoration-none d-inline-flex align-items-center gap-1 mb-4">
        <i class="bi bi-arrow-left"></i>Retour au tableau de bord
    </a>

    <h1 class="h3 fw-bold mb-1">Notes de version</h1>
    <p class="text-muted mb-5">Historique des évolutions de l'outil d'audit RGESN V2 2024.</p>

    <?php foreach ($versions as $v): ?>
    <div class="mb-5">
        <div class="d-flex align-items-center gap-3 mb-4">
            <span class="badge bg-indigo fs-6 px-3 py-2">v<?= htmlspecialchars($v['version']) ?></span>
            <div>
                <div class="fw-bold"><?= htmlspecialchars($v['label']) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($v['date']) ?></div>
            </div>
        </div>

        <?php foreach ($v['sections'] as $section): ?>
        <div class="mb-4">
            <h2 class="h6 fw-bold text-uppercase text-muted mb-2" style="letter-spacing:.05em;">
                <i class="bi bi-chevron-right me-1 text-indigo"></i><?= htmlspecialchars($section['title']) ?>
            </h2>
            <ul class="list-unstyled mb-0 ps-3">
                <?php foreach ($section['items'] as $item): ?>
                <li class="d-flex align-items-start gap-2 py-1 border-bottom border-light">
                    <i class="bi bi-check2 text-indigo flex-shrink-0 mt-1"></i>
                    <span class="small"><?= htmlspecialchars($item) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

</main>

<!-- Pied de page -->
<footer class="text-center py-3">
    <span class="text-muted small">Outil d'audit RGESN V2 2024 développé par Grégory Biondo sous</span>
    <a href="https://www.gnu.org/licenses/agpl-3.0.txt" class="text-muted small" target="_blank">licence Open Source GNU AGPL v3</a>
    <span class="text-muted small"> - </span>
    <a href="https://github.com/GregoryBiondo/rgesn-v2-audit" class="text-muted small" target="_blank">Code source (AGPL v3)</a>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
