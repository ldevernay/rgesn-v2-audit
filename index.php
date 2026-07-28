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
 * RGESN V2 2024 Audit Tool — Dashboard
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outil d'audit RGESN V2 2024</title>
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
        <button class="btn btn-indigo" data-bs-toggle="modal" data-bs-target="#modalNewAudit">
            <i class="bi bi-plus-lg me-1"></i> Nouvel audit
        </button>
    </div>
</header>

<!-- Main -->
<main class="container-fluid px-4 py-4" style="max-width:1400px;margin:0 auto;">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 fw-bold mb-0">Tableau de bord des audits</h1>
            <p class="text-muted small mb-0">Référentiel Général d'Écoconception des Services Numériques</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div id="auditCount" class="text-muted small"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnToggleFilters" aria-expanded="false" aria-controls="filtersRow">
                <i class="bi bi-funnel me-1"></i>Filtres
            </button>
        </div>
    </div>

    <!-- État de chargement -->
    <div id="loadingState" class="text-center py-5">
        <div class="spinner-border text-indigo" role="status">
            <span class="visually-hidden">Chargement...</span>
        </div>
        <p class="mt-2 text-muted">Chargement des audits…</p>
    </div>

    <!-- État vide -->
    <div id="emptyState" class="d-none text-center py-5">
        <div class="mb-3">
            <i class="bi bi-clipboard2-check text-muted" style="font-size:3rem;"></i>
        </div>
        <h2 class="h5 fw-semibold">Aucun audit pour l'instant</h2>
        <p class="text-muted">Créez votre premier audit pour commencer à évaluer un service numérique.</p>
        <button class="btn btn-indigo" data-bs-toggle="modal" data-bs-target="#modalNewAudit">
            <i class="bi bi-plus-lg me-1"></i> Créer un audit
        </button>
    </div>

    <!-- Tableau des audits -->
    <div id="auditsTableWrapper" class="d-none">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table id="auditsTable" class="table table-hover align-middle mb-0 audits-table" tabindex="-1">
                    <caption class="caption-sr-only">Liste des audits RGESN, triable par colonne. Une ligne de filtres est disponible sous les en-têtes pour affiner l'affichage par colonne ; la dernière colonne regroupe les actions disponibles pour chaque audit (continuer, rapport, dupliquer, supprimer).</caption>
                    <colgroup>
                        <col style="width:260px;">
                        <col style="width:140px;">
                        <col style="width:100px;">
                        <col style="width:130px;">
                        <col style="width:120px;">
                        <col style="width:90px;">
                        <col style="width:180px;">
                        <col style="width:300px;">
                    </colgroup>
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4 sortable" scope="col" data-sort="project" aria-sort="none"><button type="button" class="sort-btn">Projet <i class="bi bi-caret-down sort-icon"></i></button></th>
                            <th class="sortable" scope="col" data-sort="auditor" aria-sort="none"><button type="button" class="sort-btn">Auditeur <i class="bi bi-caret-down sort-icon"></i></button></th>
                            <th class="sortable" scope="col" data-sort="created_at" aria-sort="none"><button type="button" class="sort-btn">Création <i class="bi bi-caret-down sort-icon"></i></button></th>
                            <th class="sortable" scope="col" data-sort="updated_at" aria-sort="none"><button type="button" class="sort-btn">Mise à jour <i class="bi bi-caret-down sort-icon"></i></button></th>
                            <th class="sortable" scope="col" data-sort="status" aria-sort="none"><button type="button" class="sort-btn">Statut <i class="bi bi-caret-down sort-icon"></i></button></th>
                            <th class="sortable" scope="col" data-sort="score" aria-sort="none"><button type="button" class="sort-btn">Taux <i class="bi bi-caret-down sort-icon"></i></button></th>
                            <th class="sortable" scope="col" style="min-width:160px;" data-sort="completion" aria-sort="none"><button type="button" class="sort-btn">Avancement <i class="bi bi-caret-down sort-icon"></i></button></th>
                            <th class="text-end pe-4" scope="col">Actions</th>
                        </tr>
                        <tr class="filters-row d-none" id="filtersRow">
                            <th class="ps-4" scope="col">
                                <input type="text" class="form-control form-control-sm filter-input" data-filter="project" placeholder="Filtrer…" aria-label="Projet : Filtrer">
                            </th>
                            <th scope="col">
                                <input type="text" class="form-control form-control-sm filter-input" data-filter="auditor" placeholder="Filtrer…" aria-label="Auditeur : Filtrer">
                            </th>
                            <th scope="col">
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary date-filter-btn w-100"
                                        data-filter-key="created_at"
                                        data-filter-label="Date de création"
                                        aria-label="Date de création : Filtrer">
                                    <i class="bi bi-calendar-range me-1"></i><span class="date-filter-btn-label">Filtrer</span>
                                </button>
                            </th>
                            <th scope="col">
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary date-filter-btn w-100"
                                        data-filter-key="updated_at"
                                        data-filter-label="Date de mise à jour"
                                        aria-label="Date de mise à jour : Filtrer">
                                    <i class="bi bi-calendar-range me-1"></i><span class="date-filter-btn-label">Filtrer</span>
                                </button>
                            </th>
                            <th scope="col">
                                <select class="form-select form-select-sm filter-input" data-filter="status" aria-label="Statut : Filtrer">
                                    <option value="">Tous</option>
                                    <option value="en cours">En cours</option>
                                    <option value="terminé">Terminé</option>
                                </select>
                            </th>
                            <th scope="col">
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary number-filter-btn w-100"
                                        data-filter-key="score"
                                        data-filter-label="Taux"
                                        aria-label="Taux : Filtrer">
                                    <i class="bi bi-sliders me-1"></i><span class="number-filter-btn-label">Filtrer</span>
                                </button>
                            </th>
                            <th scope="col">
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary number-filter-btn w-100"
                                        data-filter-key="completion"
                                        data-filter-label="Avancement"
                                        aria-label="Avancement : Filtrer">
                                    <i class="bi bi-sliders me-1"></i><span class="number-filter-btn-label">Filtrer</span>
                                </button>
                            </th>
                            <th class="text-end pe-4" scope="col">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnResetFilters" title="Réinitialiser les filtres">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="auditsTableBody">
                    </tbody>
                </table>
            </div>
        </div>

        <nav id="auditsPagination" class="d-flex align-items-center justify-content-between mt-3 d-none" aria-label="Pagination des audits">
            <div class="text-muted small" id="paginationInfo"></div>
            <ul class="pagination pagination-sm mb-0" id="paginationControls"></ul>
        </nav>
    </div>

</main>

<!-- Pied de page avec Version -->
<footer class="text-center py-3">
    <span class="text-muted small">Outil d'audit RGESN V2 2024</span>
    <a href="release-notes.php" class="text-muted small" aria-label="Notes de version pour la Version 1.5">Version 1.5</a>
    <span class="text-muted small">développé par Grégory Biondo sous</span>
    <a href="https://www.gnu.org/licenses/agpl-3.0.txt" class="text-muted small" target="_blank">licence Open Source GNU AGPL v3</a>
    <span class="text-muted small"> - </span>
    <a href="https://github.com/GregoryBiondo/rgesn-v2-audit" class="text-muted small" target="_blank">Code source (AGPL v3)</a>
</footer>

<!-- Modal : Nouvel audit -->
<div class="modal fade" id="modalNewAudit" tabindex="-1" aria-labelledby="modalNewAuditLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="modalNewAuditLabel">
                    <i class="bi bi-clipboard2-plus text-indigo me-2"></i>Nouvel audit RGESN
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body pt-3">
                <form id="formNewAudit" novalidate>
                    <div class="mb-3">
                        <label for="projectName" class="form-label fw-medium">Nom du projet <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="projectName" placeholder="ex : Site institutionnel Mairie de Lyon" required>
                        <div class="invalid-feedback">Le nom du projet est requis.</div>
                    </div>
                    <div class="mb-3">
                        <label for="projectUrl" class="form-label fw-medium">URL du service audité <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" id="projectUrl" placeholder="https://www.exemple.fr" required>
                        <div class="invalid-feedback">L'URL du service est requise.</div>
                    </div>
                    <div class="mb-3">
                        <label for="auditorName" class="form-label fw-medium">Nom de l'auditeur·ice <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="auditorName" placeholder="ex : Marie Dupont" required>
                        <div class="invalid-feedback">Le nom de l'auditeur·ice est requis.</div>
                    </div>
                    <div id="formError" class="alert alert-danger d-none" role="alert"></div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-indigo" id="btnCreateAudit">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="createSpinner"></span>
                    Créer l'audit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal : Confirmer suppression -->
<div class="modal fade" id="modalDeleteAudit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h2 class="modal-title h5 fw-bold">Supprimer l'audit ?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body pt-0">
                <p class="text-muted mb-0">Cette action est irréversible. L'audit et toutes ses données seront définitivement supprimés.</p>
                <p class="fw-medium mt-2 mb-0" id="deleteAuditName"></p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-sm btn-danger" id="btnConfirmDelete">Supprimer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal : Dupliquer un audit -->
<div class="modal fade" id="modalDuplicateAudit" tabindex="-1" aria-labelledby="modalDuplicateAuditLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="modalDuplicateAuditLabel">
                    <i class="bi bi-copy text-indigo me-2"></i>Dupliquer l'audit
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body pt-3">
                <p class="text-muted small mb-3">
                    Tous les critères, statuts, commentaires et actions seront copiés.
                    Modifiez les informations ci-dessous pour le nouvel audit.
                </p>
                <form id="formDuplicateAudit" novalidate>
                    <div class="mb-3">
                        <label for="duplicateProjectName" class="form-label fw-medium">Nom du projet <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="duplicateProjectName" required>
                        <div class="invalid-feedback">Le nom du projet est requis.</div>
                    </div>
                    <div class="mb-3">
                        <label for="duplicateProjectUrl" class="form-label fw-medium">URL du service audité <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" id="duplicateProjectUrl" required>
                        <div class="invalid-feedback">L'URL du service est requise.</div>
                    </div>
                    <div class="mb-3">
                        <label for="duplicateAuditorName" class="form-label fw-medium">Nom de l'auditeur·ice <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="duplicateAuditorName" required>
                        <div class="invalid-feedback">Le nom de l'auditeur·ice est requis.</div>
                    </div>
                    <div id="duplicateError" class="alert alert-danger d-none" role="alert"></div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-indigo" id="btnConfirmDuplicate">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="duplicateSpinner"></span>
                    Dupliquer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal : Filtre par plage de dates (Création / Mise à jour) -->
<div class="modal fade" id="modalDateRangeFilter" tabindex="-1" aria-labelledby="modalDateRangeFilterLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="modalDateRangeFilterLabel">
                    <i class="bi bi-calendar-range text-indigo me-2"></i><span id="modalDateRangeFilterTitle">Filtrer par date</span>
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body pt-3">
                <form id="formDateRangeFilter" novalidate>
                    <div class="mb-3">
                        <label for="dateFilterStart" class="form-label fw-medium">Date de début</label>
                        <input type="date" class="form-control" id="dateFilterStart">
                    </div>
                    <div class="mb-3">
                        <label for="dateFilterEnd" class="form-label fw-medium">Date de fin</label>
                        <input type="date" class="form-control" id="dateFilterEnd">
                    </div>
                    <div id="dateFilterError" class="alert alert-danger d-none small py-2" role="alert"></div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" id="btnClearDateFilter">Réinitialiser</button>
                <button type="button" class="btn btn-indigo" id="btnApplyDateFilter">Appliquer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal : Filtre par plage numérique (Taux / Avancement) -->
<div class="modal fade" id="modalNumberRangeFilter" tabindex="-1" aria-labelledby="modalNumberRangeFilterLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="modalNumberRangeFilterLabel">
                    <i class="bi bi-sliders text-indigo me-2"></i><span id="modalNumberRangeFilterTitle">Filtrer par plage</span>
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body pt-3">
                <p class="text-muted small mb-3" id="numberRangeSummary">De 0 % à 100 %</p>

                <div class="range-slider position-relative mb-4">
                    <div class="range-slider-track"></div>
                    <div class="range-slider-fill" id="numberRangeFill"></div>
                    <input type="range" class="range-slider-input" id="numberFilterMinRange"
                           min="0" max="100" step="1" value="0" aria-label="Valeur minimum de la plage">
                    <input type="range" class="range-slider-input" id="numberFilterMaxRange"
                           min="0" max="100" step="1" value="100" aria-label="Valeur maximum de la plage">
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <label for="numberFilterMin" class="form-label fw-medium">Minimum</label>
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control" id="numberFilterMin" min="0" max="100" step="1" value="0">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <label for="numberFilterMax" class="form-label fw-medium">Maximum</label>
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control" id="numberFilterMax" min="0" max="100" step="1" value="100">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>

                <div id="numberFilterError" class="alert alert-danger d-none small py-2 mt-3" role="alert"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" id="btnClearNumberFilter">Réinitialiser</button>
                <button type="button" class="btn btn-indigo" id="btnApplyNumberFilter">Appliquer</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
(function () {
    'use strict';

    // ── État : audits, tri, filtres, pagination ─────────────────────────────
    let allAudits  = [];
    let sortKey    = 'updated_at';
    let sortDir    = 'desc';
    let currentPage = 1;
    const PAGE_SIZE = 20;
    const filters = {
        project: '', auditor: '', status: '',
        created_at: { start: '', end: '' },
        updated_at: { start: '', end: '' },
        score:      { min: 0, max: 100 },
        completion: { min: 0, max: 100 },
    };

    // ── Chargement initial ──────────────────────────────────────────────────
    loadAudits();

    async function loadAudits() {
        try {
            allAudits = await apiRequest('GET', 'api.php?action=list_audits');
            render();
        } catch (e) {
            document.getElementById('loadingState').innerHTML =
                '<div class="alert alert-danger">Erreur de chargement des audits.</div>';
        }
    }

    // ── Filtrage et tri ──────────────────────────────────────────────────────

    /**
     * Vérifie qu'une date ISO 8601 tombe dans une plage {start, end} (format YYYY-MM-DD, bornes incluses)
     */
    function isDateInRange(iso, range) {
        if (!range || (!range.start && !range.end)) return true;
        if (!iso) return false;
        const day = iso.slice(0, 10); // partie date de l'ISO 8601, comparable lexicographiquement
        if (range.start && day < range.start) return false;
        if (range.end && day > range.end) return false;
        return true;
    }

    /**
     * Normalise une chaîne pour une recherche insensible aux accents et à la casse
     * (ex : "Greg" retrouve "Grégory")
     */
    function normalizeForSearch(str) {
        return String(str || '')
            .normalize('NFD')
            .replace(/\p{Diacritic}/gu, '')
            .toLowerCase();
    }

    /** Un filtre de plage numérique n'est actif que si la plage a été restreinte */
    function isNumberFilterActive(range) {
        return range.min > 0 || range.max < 100;
    }

    function getFilteredSortedAudits() {
        let list = allAudits.filter(a => {
            if (filters.project && !normalizeForSearch(a.project.name).includes(normalizeForSearch(filters.project))) return false;
            if (filters.auditor && !normalizeForSearch(a.auditor.name).includes(normalizeForSearch(filters.auditor))) return false;
            if (!isDateInRange(a.created_at, filters.created_at)) return false;
            if (!isDateInRange(a.updated_at, filters.updated_at)) return false;
            if (filters.status && a.status !== filters.status) return false;
            if (isNumberFilterActive(filters.score)) {
                if (a.status !== 'terminé') return false;
                if (a.score < filters.score.min || a.score > filters.score.max) return false;
            }
            if (isNumberFilterActive(filters.completion)) {
                const completion = a.completion ?? 0;
                if (completion < filters.completion.min || completion > filters.completion.max) return false;
            }
            return true;
        });

        list.sort((a, b) => {
            let va, vb;
            switch (sortKey) {
                case 'project':    va = a.project.name.toLowerCase(); vb = b.project.name.toLowerCase(); break;
                case 'auditor':    va = (a.auditor.name || '').toLowerCase(); vb = (b.auditor.name || '').toLowerCase(); break;
                case 'status':     va = a.status; vb = b.status; break;
                case 'score':      va = a.score ?? 0; vb = b.score ?? 0; break;
                case 'completion': va = a.completion ?? 0; vb = b.completion ?? 0; break;
                case 'created_at':
                case 'updated_at':
                default:           va = a[sortKey] || ''; vb = b[sortKey] || '';
            }
            if (va < vb) return sortDir === 'asc' ? -1 : 1;
            if (va > vb) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        return list;
    }

    // ── Rendu ────────────────────────────────────────────────────────────────

    function render() {
        const loading  = document.getElementById('loadingState');
        const empty    = document.getElementById('emptyState');
        const wrapper  = document.getElementById('auditsTableWrapper');
        const tbody    = document.getElementById('auditsTableBody');
        const counter  = document.getElementById('auditCount');

        loading.classList.add('d-none');

        if (!allAudits || allAudits.length === 0) {
            empty.classList.remove('d-none');
            wrapper.classList.add('d-none');
            return;
        }

        empty.classList.add('d-none');
        wrapper.classList.remove('d-none');

        const filtered = getFilteredSortedAudits();
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;
        const start = (currentPage - 1) * PAGE_SIZE;
        const pageItems = filtered.slice(start, start + PAGE_SIZE);

        counter.textContent = total === allAudits.length
            ? `${total} audit${total > 1 ? 's' : ''}`
            : `${total} audit${total > 1 ? 's' : ''} (sur ${allAudits.length})`;

        tbody.innerHTML = '';
        if (pageItems.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Aucun audit ne correspond aux filtres.</td></tr>';
        } else {
            pageItems.forEach(a => tbody.appendChild(createAuditRow(a)));
        }

        renderPagination(total, totalPages);
        updateSortIndicators();
    }

    // ── Pagination ───────────────────────────────────────────────────────────

    function renderPagination(total, totalPages) {
        const nav      = document.getElementById('auditsPagination');
        const info     = document.getElementById('paginationInfo');
        const controls = document.getElementById('paginationControls');

        if (total === 0) {
            nav.classList.add('d-none');
            return;
        }
        nav.classList.remove('d-none');

        const start = (currentPage - 1) * PAGE_SIZE + 1;
        const end   = Math.min(currentPage * PAGE_SIZE, total);
        info.textContent = `${start}–${end} sur ${total}`;

        let html = pageButton(currentPage - 1, '<i class="bi bi-chevron-left"></i>', currentPage === 1);
        getPageWindow(currentPage, totalPages).forEach(p => {
            html += p === '...'
                ? '<li class="page-item disabled"><span class="page-link">…</span></li>'
                : `<li class="page-item ${p === currentPage ? 'active' : ''}"><button type="button" class="page-link" data-page="${p}">${p}</button></li>`;
        });
        html += pageButton(currentPage + 1, '<i class="bi bi-chevron-right"></i>', currentPage === totalPages);

        controls.innerHTML = html;
    }

    function pageButton(page, label, disabled) {
        return `<li class="page-item ${disabled ? 'disabled' : ''}">
                    <button type="button" class="page-link" data-page="${page}" ${disabled ? 'disabled' : ''}>${label}</button>
                </li>`;
    }

    function getPageWindow(current, total) {
        const delta = 2;
        const range = [];
        for (let i = Math.max(1, current - delta); i <= Math.min(total, current + delta); i++) range.push(i);
        if (range[0] > 1) {
            range.unshift(1);
            if (range[1] > 2) range.splice(1, 0, '...');
        }
        if (range[range.length - 1] < total) {
            if (range[range.length - 1] < total - 1) range.push('...');
            range.push(total);
        }
        return range;
    }

    document.getElementById('paginationControls').addEventListener('click', e => {
        const btn = e.target.closest('button[data-page]');
        if (!btn || btn.disabled) return;
        currentPage = parseInt(btn.dataset.page, 10);
        render();
    });

    // ── Tri par colonne ──────────────────────────────────────────────────────

    document.querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const key = th.dataset.sort;
            if (sortKey === key) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortKey = key;
                sortDir = 'asc';
            }
            currentPage = 1;
            render();
        });
    });

    function updateSortIndicators() {
        document.querySelectorAll('th.sortable').forEach(th => {
            const icon = th.querySelector('.sort-icon');
            const active = th.dataset.sort === sortKey;
            th.classList.toggle('sort-active', active);
            th.setAttribute('aria-sort', active ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none');
            if (icon) {
                icon.className = 'bi sort-icon ' + (active ? (sortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-caret-down');
            }
        });
    }

    // ── Filtres par colonne ──────────────────────────────────────────────────

    const applyFiltersDebounced = debounce(() => {
        currentPage = 1;
        render();
    }, 250);

    document.querySelectorAll('.filter-input').forEach(input => {
        const key = input.dataset.filter;
        const evt = input.tagName === 'SELECT' ? 'change' : 'input';
        input.addEventListener(evt, () => {
            filters[key] = input.value.trim();
            applyFiltersDebounced();
        });
    });

    document.getElementById('btnResetFilters').addEventListener('click', () => {
        document.querySelectorAll('.filter-input').forEach(input => { input.value = ''; });
        filters.project = '';
        filters.auditor = '';
        filters.status = '';
        filters.created_at = { start: '', end: '' };
        filters.updated_at = { start: '', end: '' };
        filters.score      = { min: 0, max: 100 };
        filters.completion = { min: 0, max: 100 };
        document.querySelectorAll('.date-filter-btn').forEach(updateDateFilterBtn);
        document.querySelectorAll('.number-filter-btn').forEach(updateNumberFilterBtn);
        currentPage = 1;
        render();
    });

    /**
     * Ramène le focus sur le tableau (plutôt que le haut de page) après fermeture
     * d'une modale de filtre, qu'elle ait été validée ou annulée.
     */
    function focusAuditsTable() {
        const table = document.getElementById('auditsTable');
        if (table) table.focus({ preventScroll: true });
    }

    // ── Filtre par plage de dates (Création / Mise à jour) ──────────────────

    let currentDateFilterKey = null;
    const dateRangeModalEl = document.getElementById('modalDateRangeFilter');
    const dateRangeModal   = new bootstrap.Modal(dateRangeModalEl);
    const dateFilterStart  = document.getElementById('dateFilterStart');
    const dateFilterEnd    = document.getElementById('dateFilterEnd');
    const dateFilterError  = document.getElementById('dateFilterError');

    /**
     * Formate une plage {start, end} en libellé court pour le bouton de filtre (ex : "01/03 → 15/03")
     */
    function formatRangeLabel(range) {
        const short = iso => {
            const d = new Date(iso + 'T00:00:00');
            return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
        };
        if (range.start && range.end) return `${short(range.start)} → ${short(range.end)}`;
        if (range.start) return `≥ ${short(range.start)}`;
        if (range.end)   return `≤ ${short(range.end)}`;
        return 'Filtrer';
    }

    function updateDateFilterBtn(btn) {
        const key = btn.dataset.filterKey;
        const range = filters[key];
        const active = !!(range.start || range.end);
        const label = btn.querySelector('.date-filter-btn-label');
        label.textContent = formatRangeLabel(range);
        btn.setAttribute('aria-label', `${btn.dataset.filterLabel} : ${label.textContent}`);
        btn.classList.toggle('btn-indigo', active);
        btn.classList.toggle('btn-outline-secondary', !active);
    }

    document.querySelectorAll('.date-filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            currentDateFilterKey = btn.dataset.filterKey;
            const range = filters[currentDateFilterKey];
            dateFilterStart.value = range.start;
            dateFilterEnd.value   = range.end;
            dateFilterError.classList.add('d-none');
            document.getElementById('modalDateRangeFilterTitle').textContent = btn.dataset.filterLabel;
            dateRangeModal.show();
        });
    });

    document.getElementById('btnApplyDateFilter').addEventListener('click', () => {
        if (!currentDateFilterKey) return;
        const start = dateFilterStart.value;
        const end   = dateFilterEnd.value;

        if (start && end && start > end) {
            dateFilterError.textContent = 'La date de début doit être antérieure ou égale à la date de fin.';
            dateFilterError.classList.remove('d-none');
            return;
        }

        filters[currentDateFilterKey] = { start, end };
        const btn = document.querySelector(`.date-filter-btn[data-filter-key="${currentDateFilterKey}"]`);
        if (btn) updateDateFilterBtn(btn);

        dateRangeModal.hide();
        currentPage = 1;
        render();
    });

    document.getElementById('btnClearDateFilter').addEventListener('click', () => {
        if (!currentDateFilterKey) return;
        filters[currentDateFilterKey] = { start: '', end: '' };
        dateFilterStart.value = '';
        dateFilterEnd.value = '';
        dateFilterError.classList.add('d-none');
        const btn = document.querySelector(`.date-filter-btn[data-filter-key="${currentDateFilterKey}"]`);
        if (btn) updateDateFilterBtn(btn);

        dateRangeModal.hide();
        currentPage = 1;
        render();
    });

    dateRangeModalEl.addEventListener('hidden.bs.modal', () => {
        dateFilterError.classList.add('d-none');
        currentDateFilterKey = null;
        focusAuditsTable();
    });

    // ── Filtre par plage numérique (Taux / Avancement) ──────────────────────

    let currentNumberFilterKey = null;
    const numberRangeModalEl = document.getElementById('modalNumberRangeFilter');
    const numberRangeModal   = new bootstrap.Modal(numberRangeModalEl);
    const numberMinRange     = document.getElementById('numberFilterMinRange');
    const numberMaxRange     = document.getElementById('numberFilterMaxRange');
    const numberMinInput     = document.getElementById('numberFilterMin');
    const numberMaxInput     = document.getElementById('numberFilterMax');
    const numberRangeFill    = document.getElementById('numberRangeFill');
    const numberRangeSummary = document.getElementById('numberRangeSummary');
    const numberFilterError  = document.getElementById('numberFilterError');

    function updateNumberRangeUI(min, max) {
        numberRangeFill.style.left  = min + '%';
        numberRangeFill.style.right = (100 - max) + '%';
        numberRangeSummary.textContent = `De ${min} % à ${max} %`;
    }

    function setNumberRangeValues(min, max) {
        numberMinRange.value = min;
        numberMaxRange.value = max;
        numberMinInput.value = min;
        numberMaxInput.value = max;
        updateNumberRangeUI(min, max);
    }

    // Curseurs : chaque poignée reste dans les bornes de l'autre
    numberMinRange.addEventListener('input', () => {
        let min = Math.min(parseInt(numberMinRange.value, 10), parseInt(numberMaxRange.value, 10));
        numberMinRange.value = min;
        numberMinInput.value = min;
        updateNumberRangeUI(min, parseInt(numberMaxRange.value, 10));
    });
    numberMaxRange.addEventListener('input', () => {
        let max = Math.max(parseInt(numberMaxRange.value, 10), parseInt(numberMinRange.value, 10));
        numberMaxRange.value = max;
        numberMaxInput.value = max;
        updateNumberRangeUI(parseInt(numberMinRange.value, 10), max);
    });

    // Champs numériques (alternative accessible au curseur) : mêmes contraintes
    numberMinInput.addEventListener('input', () => {
        let min = Math.min(Math.max(parseInt(numberMinInput.value, 10) || 0, 0), 100);
        min = Math.min(min, parseInt(numberMaxInput.value, 10) || 100);
        numberMinRange.value = min;
        updateNumberRangeUI(min, parseInt(numberMaxRange.value, 10));
    });
    numberMaxInput.addEventListener('input', () => {
        let max = Math.min(Math.max(parseInt(numberMaxInput.value, 10) || 100, 0), 100);
        max = Math.max(max, parseInt(numberMinInput.value, 10) || 0);
        numberMaxRange.value = max;
        updateNumberRangeUI(parseInt(numberMinRange.value, 10), max);
    });

    function updateNumberFilterBtn(btn) {
        const key = btn.dataset.filterKey;
        const range = filters[key];
        const active = isNumberFilterActive(range);
        const label = btn.querySelector('.number-filter-btn-label');
        label.textContent = active ? `${range.min} % → ${range.max} %` : 'Filtrer';
        btn.setAttribute('aria-label', `${btn.dataset.filterLabel} : ${label.textContent}`);
        btn.classList.toggle('btn-indigo', active);
        btn.classList.toggle('btn-outline-secondary', !active);
    }

    document.querySelectorAll('.number-filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            currentNumberFilterKey = btn.dataset.filterKey;
            const range = filters[currentNumberFilterKey];
            setNumberRangeValues(range.min, range.max);
            numberFilterError.classList.add('d-none');
            document.getElementById('modalNumberRangeFilterTitle').textContent = btn.dataset.filterLabel;
            numberRangeModal.show();
        });
    });

    document.getElementById('btnApplyNumberFilter').addEventListener('click', () => {
        if (!currentNumberFilterKey) return;
        const min = parseInt(numberMinInput.value, 10);
        const max = parseInt(numberMaxInput.value, 10);

        if (isNaN(min) || isNaN(max) || min < 0 || max > 100 || min > max) {
            numberFilterError.textContent = 'La valeur minimum doit être inférieure ou égale à la valeur maximum (0 à 100).';
            numberFilterError.classList.remove('d-none');
            return;
        }

        filters[currentNumberFilterKey] = { min, max };
        const btn = document.querySelector(`.number-filter-btn[data-filter-key="${currentNumberFilterKey}"]`);
        if (btn) updateNumberFilterBtn(btn);

        numberRangeModal.hide();
        currentPage = 1;
        render();
    });

    document.getElementById('btnClearNumberFilter').addEventListener('click', () => {
        if (!currentNumberFilterKey) return;
        filters[currentNumberFilterKey] = { min: 0, max: 100 };
        setNumberRangeValues(0, 100);
        numberFilterError.classList.add('d-none');
        const btn = document.querySelector(`.number-filter-btn[data-filter-key="${currentNumberFilterKey}"]`);
        if (btn) updateNumberFilterBtn(btn);

        numberRangeModal.hide();
        currentPage = 1;
        render();
    });

    numberRangeModalEl.addEventListener('hidden.bs.modal', () => {
        numberFilterError.classList.add('d-none');
        currentNumberFilterKey = null;
        focusAuditsTable();
    });

    // ── Affichage / masquage de la ligne de filtres ─────────────────────────

    const btnToggleFilters = document.getElementById('btnToggleFilters');
    const filtersRow = document.querySelector('.filters-row');
    btnToggleFilters.addEventListener('click', () => {
        const visible = filtersRow.classList.toggle('d-none') === false;
        btnToggleFilters.classList.toggle('btn-secondary', visible);
        btnToggleFilters.classList.toggle('btn-outline-secondary', !visible);
        btnToggleFilters.setAttribute('aria-expanded', String(visible));
    });

    function createAuditRow(a) {
        const tr = document.createElement('tr');

        const statusBadge = a.status === 'terminé'
            ? '<span class="badge badge-status-done"><i class="bi bi-check-circle me-1"></i>Terminé</span>'
            : '<span class="badge badge-status-progress"><i class="bi bi-hourglass-split me-1"></i>En cours</span>';

        const scoreDisplay = a.status === 'terminé' && a.score > 0
            ? `<span class="fw-bold text-indigo">${a.score}%</span>`
            : '<span class="text-muted">—</span>';

        const completion = a.completion ?? 0;

        const reportBtn = a.status === 'terminé'
            ? `<a href="report.php?id=${a.id}" class="btn btn-sm btn-outline-indigo" title="Voir le rapport" aria-label="Voir le rapport de l'audit ${escHtml(a.project.name)}" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-bar-graph"></i> Rapport
               </a>`
            : `<button class="btn btn-sm btn-outline-secondary" disabled title="Disponible une fois l'audit terminé">
                    <i class="bi bi-file-earmark-bar-graph"></i> Rapport
               </button>`;

        tr.innerHTML = `
            <td class="ps-4">
                <div class="fw-semibold text-truncate" style="max-width:240px;" title="${escHtml(a.project.name)}">${escHtml(a.project.name)}</div>
                ${a.project.url ? `<a href="${escHtml(a.project.url)}" target="_blank" rel="noopener" class="text-muted small text-truncate d-block" style="max-width:240px;" title="${escHtml(a.project.url)}">${escHtml(a.project.url)}</a>` : ''}
            </td>
            <td class="text-muted small">${escHtml(a.auditor.name || '—')}</td>
            <td class="text-muted small">${formatDate(a.created_at)}</td>
            <td class="text-muted small">${formatDate(a.updated_at)}</td>
            <td>${statusBadge}</td>
            <td>${scoreDisplay}</td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:6px;" role="progressbar" aria-valuenow="${completion}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar bg-indigo" style="width:${completion}%"></div>
                    </div>
                    <span class="text-muted small" style="min-width:36px;">${completion}%</span>
                </div>
            </td>
            <td class="text-end pe-4 text-nowrap">
                <div class="d-flex justify-content-end gap-1 flex-nowrap">
                    <a href="audit.php?id=${a.id}" class="btn btn-sm btn-indigo" aria-label="${a.status === 'terminé' ? 'Modifier' : 'Continuer'} l'audit ${escHtml(a.project.name)}">
                        <i class="bi bi-pencil-square me-1"></i>${a.status === 'terminé' ? 'Modifier' : 'Continuer'}
                    </a>
                    ${reportBtn}
                    <button class="btn btn-sm btn-outline-secondary btn-duplicate"
                            data-id="${a.id}"
                            data-name="${escHtml(a.project.name)}"
                            data-url="${escHtml(a.project.url || '')}"
                            data-auditor="${escHtml(a.auditor.name || '')}"
                            title="Dupliquer">
                        <i class="bi bi-copy"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger btn-delete" data-id="${a.id}" data-name="${escHtml(a.project.name)}" title="Supprimer">
                        <i class="bi bi-trash3"></i>
                    </button>
                </div>
            </td>
        `;

        return tr;
    }

    // ── Création d'un nouvel audit ──────────────────────────────────────────
    document.getElementById('btnCreateAudit').addEventListener('click', async () => {
        const form        = document.getElementById('formNewAudit');
        const nameInput   = document.getElementById('projectName');
        const urlInput    = document.getElementById('projectUrl');
        const errorDiv    = document.getElementById('formError');
        const spinner     = document.getElementById('createSpinner');

        const auditorInput = document.getElementById('auditorName');

        form.classList.add('was-validated');
        errorDiv.classList.add('d-none');

        nameInput.setCustomValidity(!nameInput.value.trim() ? 'required' : '');
        urlInput.setCustomValidity(!urlInput.value.trim() ? 'required' : '');
        auditorInput.setCustomValidity(!auditorInput.value.trim() ? 'required' : '');

        if (!nameInput.value.trim() || !urlInput.value.trim() || !auditorInput.value.trim()) {
            return;
        }

        spinner.classList.remove('d-none');

        try {
            const result = await apiRequest('POST', 'api.php?action=create_audit', {
                project_name: nameInput.value.trim(),
                project_url:  urlInput.value.trim(),
                auditor_name: document.getElementById('auditorName').value.trim(),
            });

            if (result.id) {
                window.location.href = `audit.php?id=${result.id}`;
            } else {
                throw new Error(result.error || 'Erreur inconnue');
            }
        } catch (e) {
            errorDiv.textContent = e.message || 'Une erreur est survenue.';
            errorDiv.classList.remove('d-none');
            spinner.classList.add('d-none');
        }
    });

    // Réinitialiser le formulaire à l'ouverture de la modal
    document.getElementById('modalNewAudit').addEventListener('show.bs.modal', () => {
        document.getElementById('formNewAudit').reset();
        document.getElementById('formNewAudit').classList.remove('was-validated');
        document.getElementById('formError').classList.add('d-none');
        document.getElementById('createSpinner').classList.add('d-none');
        document.getElementById('projectName').setCustomValidity('');
    });

    // ── Suppression d'un audit ──────────────────────────────────────────────
    let pendingDeleteId = null;

    document.addEventListener('click', e => {
        const btn = e.target.closest('.btn-delete');
        if (!btn) return;
        pendingDeleteId = btn.dataset.id;
        document.getElementById('deleteAuditName').textContent = btn.dataset.name;
        new bootstrap.Modal(document.getElementById('modalDeleteAudit')).show();
    });

    document.getElementById('btnConfirmDelete').addEventListener('click', async () => {
        if (!pendingDeleteId) return;
        const modal = bootstrap.Modal.getInstance(document.getElementById('modalDeleteAudit'));

        try {
            await apiRequest('POST', 'api.php?action=delete_audit', { id: pendingDeleteId });
            modal.hide();
            loadAudits();
        } catch (e) {
            alert('Erreur lors de la suppression : ' + e.message);
        }
        pendingDeleteId = null;
    });

    // ── Duplication d'un audit ──────────────────────────────────────────────
    let pendingDuplicateId = null;

    document.addEventListener('click', e => {
        const btn = e.target.closest('.btn-duplicate');
        if (!btn) return;
        pendingDuplicateId = btn.dataset.id;
        document.getElementById('duplicateProjectName').value = btn.dataset.name;
        document.getElementById('duplicateProjectUrl').value  = btn.dataset.url;
        document.getElementById('duplicateAuditorName').value = btn.dataset.auditor;
        document.getElementById('formDuplicateAudit').classList.remove('was-validated');
        document.getElementById('duplicateError').classList.add('d-none');
        document.getElementById('duplicateSpinner').classList.add('d-none');
        new bootstrap.Modal(document.getElementById('modalDuplicateAudit')).show();
    });

    document.getElementById('btnConfirmDuplicate').addEventListener('click', async () => {
        if (!pendingDuplicateId) return;

        const form        = document.getElementById('formDuplicateAudit');
        const nameInput   = document.getElementById('duplicateProjectName');
        const urlInput    = document.getElementById('duplicateProjectUrl');
        const auditorInput = document.getElementById('duplicateAuditorName');
        const errorDiv    = document.getElementById('duplicateError');
        const spinner     = document.getElementById('duplicateSpinner');

        form.classList.add('was-validated');
        errorDiv.classList.add('d-none');

        nameInput.setCustomValidity(!nameInput.value.trim() ? 'required' : '');
        urlInput.setCustomValidity(!urlInput.value.trim() ? 'required' : '');
        auditorInput.setCustomValidity(!auditorInput.value.trim() ? 'required' : '');

        if (!nameInput.value.trim() || !urlInput.value.trim() || !auditorInput.value.trim()) return;

        spinner.classList.remove('d-none');

        try {
            const result = await apiRequest('POST', 'api.php?action=duplicate_audit', {
                source_id:    pendingDuplicateId,
                project_name: nameInput.value.trim(),
                project_url:  urlInput.value.trim(),
                auditor_name: auditorInput.value.trim(),
            });

            if (result.id) {
                window.location.href = `audit.php?id=${result.id}`;
            } else {
                throw new Error(result.error || 'Erreur inconnue');
            }
        } catch (e) {
            errorDiv.textContent = e.message || 'Une erreur est survenue.';
            errorDiv.classList.remove('d-none');
            spinner.classList.add('d-none');
        }
    });

})();
</script>
</body>
</html>
