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
 * RGESN V2 2024 Audit Tool — Logique de la page audit.php
 *
 * Fonctionnalités :
 * - Sauvegarde automatique avec debounce 800ms
 * - Mise à jour des barres de progression en temps réel
 * - Navigation sidebar (surlignage de la section active)
 * - Bouton "Terminer l'audit"
 * - Indicateur visuel de sauvegarde
 */

'use strict';

(function () {

    // ── État local ──────────────────────────────────────────────────────────

    /** Map des critères modifiés en attente de sauvegarde : { id -> {status, comment} } */
    const pendingChanges = new Map();

    /** Filtre actif sur les stat-cards : null | 'conforme' | 'non-conforme' */
    let activeFilter = null;

    /** Pondérations des priorités pour le calcul du score côté client */
    const WEIGHTS = { 'Prioritaire': 1.5, 'Recommandé': 1.25, 'Modéré': 1.0 };

    // ── Références DOM ──────────────────────────────────────────────────────

    const saveIndicator   = document.getElementById('saveIndicator');
    const globalProgressBar = document.getElementById('globalProgressBar');
    const globalProgressPct = document.getElementById('globalProgressPct');
    const statScore       = document.getElementById('statScore');
    const statConforme    = document.getElementById('statConforme');
    const statNonConforme = document.getElementById('statNonConforme');
    const statNonApplicable = document.getElementById('statNonApplicable');
    const statNonTeste    = document.getElementById('statNonTeste');

    // ── Collecte de l'état initial des critères depuis le DOM ──────────────

    /**
     * Retourne l'état courant de tous les critères depuis le DOM
     * @returns {Map<string, {status: string, comment: string, priority: string}>}
     */
    function getAllCriteriaState() {
        const state = new Map();
        document.querySelectorAll('.criterion-card[data-criterion-id]').forEach(card => {
            const cid = card.dataset.criterionId;
            const priority   = getPriorityFromCard(card);
            const status     = getStatusFromCard(card, cid);
            const comment    = getCommentFromCard(card);
            const actionText = getActionTextFromCard(card);
            const actionWho  = getActionWhoFromCard(card);
            const actionWhen = getActionWhenFromCard(card);
            const actionEasy = getActionEasyFromCard(card);
            state.set(cid, { status, comment, priority, actionText, actionWho, actionWhen, actionEasy });
        });
        return state;
    }

    function getPriorityFromCard(card) {
        const badgeEl = card.querySelector('.badge-indicator');
        if (!badgeEl) return 'Modéré';
        const text = badgeEl.textContent.trim();
        if (text === 'Prioritaire' || text === 'Recommandé' || text === 'Modéré') return text;
        return 'Modéré';
    }

    function getStatusFromCard(card, cid) {
        const safeId = cid.replace('.', '-');
        const checked = card.querySelector(`input[name="status-${safeId}"]:checked`);
        return checked ? checked.value : 'non-testé';
    }

    function getCommentFromCard(card) {
        const ta = card.querySelector('.criterion-comment');
        return ta ? ta.value : '';
    }

    function getActionTextFromCard(card) {
        const ta = card.querySelector('.criterion-action-text');
        return ta ? ta.value : '';
    }

    function getActionWhoFromCard(card) {
        const h = card.querySelector('.criterion-action-who');
        if (!h) return [];
        try { return JSON.parse(h.value) || []; } catch { return []; }
    }

    function getActionWhenFromCard(card) {
        const inp = card.querySelector('.criterion-action-when');
        return inp ? inp.value : '';
    }

    function getActionEasyFromCard(card) {
        const cb = card.querySelector('.criterion-action-easy');
        return cb ? cb.checked : false;
    }

    // ── Calcul du score local (côté client) ────────────────────────────────

    function calculateScoreLocal(criteriaState) {
        let numerator = 0, denominator = 0;
        criteriaState.forEach(({ status, priority }) => {
            const w = WEIGHTS[priority] ?? 1.0;
            if (status === 'conforme') {
                numerator   += w;
                denominator += w;
            } else if (status === 'non-conforme') {
                denominator += w;
            }
        });
        if (denominator === 0) return 0;
        return Math.round(numerator / denominator * 1000) / 10; // 1 décimale
    }

    // ── Mise à jour des compteurs et progressions ───────────────────────────

    function updateAllStats() {
        const allState = getAllCriteriaState();

        // Compteurs globaux
        let counts = { 'conforme': 0, 'non-conforme': 0, 'non-applicable': 0, 'non-testé': 0 };
        let tested = 0;
        allState.forEach(({ status }) => {
            if (counts[status] !== undefined) counts[status]++;
            if (status !== 'non-testé') tested++;
        });

        const total = allState.size;
        const globalPct = total > 0 ? Math.round(tested / total * 100) : 0;
        const score = calculateScoreLocal(allState);

        // Mise à jour du DOM
        if (globalProgressBar) {
            globalProgressBar.style.width = globalPct + '%';
            globalProgressBar.className = 'progress-bar bg-indigo';
        }
        if (globalProgressPct)  globalProgressPct.textContent = globalPct + '%';
        if (statScore)          statScore.textContent = score + '%';
        if (statConforme)       statConforme.textContent = counts['conforme'];
        if (statNonConforme)    statNonConforme.textContent = counts['non-conforme'];
        if (statNonApplicable)  statNonApplicable.textContent = counts['non-applicable'];
        if (statNonTeste)       statNonTeste.textContent = counts['non-testé'];

        // Mise à jour des compteurs par thématique
        updateThematicStats(allState);
    }

    function updateThematicStats(allState) {
        // Grouper par thématique (depuis le DOM)
        document.querySelectorAll('.thematic-section').forEach(section => {
            const tid = section.id.replace('thematic-', '');

            let total = 0, tested = 0;
            section.querySelectorAll('.criterion-card[data-criterion-id]').forEach(card => {
                const cid = card.dataset.criterionId;
                total++;
                const st = allState.get(cid);
                if (st && st.status !== 'non-testé') tested++;
            });

            const pct = total > 0 ? Math.round(tested / total * 100) : 0;

            // Barre de progression dans le header de thématique
            const bar = document.getElementById(`thematicProgressBar-${tid}`);
            const txt = document.getElementById(`thematicProgressTxt-${tid}`);
            if (bar) bar.style.width = pct + '%';
            if (txt) txt.textContent = `${tested}/${total}`;

            // Sidebar
            const sidebarBar = document.getElementById(`sidebarProgress-${tid}`);
            if (sidebarBar) sidebarBar.style.width = pct + '%';

            // Lien sidebar : mise à jour du compteur
            const link = document.querySelector(`.sidebar-link[data-thematic="${tid}"] .sidebar-link-count`);
            if (link) link.textContent = `${tested}/${total}`;
        });
    }

    // ── Gestion des cartes de critères ─────────────────────────────────────

    /**
     * Met à jour l'apparence visuelle d'une carte selon son statut
     */
    function updateCardAppearance(card, status) {
        card.classList.remove('criterion-card-conforme', 'criterion-card-nonconforme', 'criterion-card-na');
        card.dataset.status = status;

        if (status === 'conforme')          card.classList.add('criterion-card-conforme');
        else if (status === 'non-conforme') card.classList.add('criterion-card-nonconforme');
        else if (status === 'non-applicable') card.classList.add('criterion-card-na');

        // Si un filtre est actif, masquer/afficher la carte selon son nouveau statut
        if (activeFilter !== null) {
            card.style.display = status === activeFilter ? '' : 'none';
            const section = card.closest('.thematic-section');
            if (section) {
                const hasVisible = [...section.querySelectorAll('.criterion-card')]
                    .some(c => c.style.display !== 'none');
                section.style.display = hasVisible ? '' : 'none';
            }
        }
    }

    // ── Événements sur les boutons de statut ───────────────────────────────

    document.querySelectorAll('.criterion-card').forEach(card => {
        const cid = card.dataset.criterionId;
        if (!cid) return;

        const safeId = cid.replace('.', '-');
        const inputs = card.querySelectorAll(`input[name="status-${safeId}"]`);

        inputs.forEach(input => {
            // L'input radio est caché (btn-check Bootstrap) : c'est la <label> que l'utilisateur clique
            const label = card.querySelector(`label[for="${input.id}"]`);
            let wasChecked = false;

            const uncheckToNonTeste = () => {
                input.checked = false;
                const newStatus = 'non-testé';
                updateCardAppearance(card, newStatus);
                if (!pendingChanges.has(cid)) pendingChanges.set(cid, {});
                pendingChanges.get(cid).status = newStatus;
                updateAllStats();
                debouncedSave();
                setSaveStatus('saving');
            };

            if (label) {
                // Mémorise l'état avant le clic (mousedown précède le changement d'état)
                label.addEventListener('mousedown', () => {
                    wasChecked = input.checked;
                });

                // Souris : si déjà coché, on annule le re-cochage et on repasse à "Non Testé"
                label.addEventListener('click', (e) => {
                    if (wasChecked) {
                        e.preventDefault(); // empêche le navigateur de re-cocher la radio
                        uncheckToNonTeste();
                    }
                });

                // Clavier : sur un radio déjà coché, certains navigateurs (Firefox) ne
                // déclenchent aucun événement "click" pour Espace/Entrée. On traite donc
                // le décochage directement au keydown plutôt que de dépendre du click.
                input.addEventListener('keydown', e => {
                    if ((e.key === ' ' || e.key === 'Enter') && input.checked) {
                        e.preventDefault();
                        uncheckToNonTeste();
                    }
                });
            }

            input.addEventListener('change', () => {
                const newStatus = input.value;
                updateCardAppearance(card, newStatus);

                // Marquer comme modifié
                if (!pendingChanges.has(cid)) {
                    pendingChanges.set(cid, {});
                }
                pendingChanges.get(cid).status = newStatus;

                updateAllStats();
                debouncedSave();
                setSaveStatus('saving');
            });
        });

        // Événements sur le textarea commentaire
        const textarea = card.querySelector('.criterion-comment');
        if (textarea) {
            textarea.addEventListener('input', () => {
                if (!pendingChanges.has(cid)) {
                    pendingChanges.set(cid, {});
                }
                pendingChanges.get(cid).comment = textarea.value;

                // Mettre à jour le badge "Renseigné" sur l'accordéon commentaire
                const accordionBtn = card.querySelector('.criterion-accordion-btn');
                if (accordionBtn) {
                    const hasContent = textarea.value.trim().length > 0;
                    accordionBtn.classList.toggle('has-content', hasContent);
                    const badge = accordionBtn.querySelector('.badge.bg-indigo');
                    if (hasContent && !badge) {
                        accordionBtn.insertAdjacentHTML('beforeend',
                            '<span class="badge bg-indigo ms-2" style="font-size:0.65rem;">Renseigné</span>');
                    } else if (!hasContent && badge) {
                        badge.remove();
                    }
                }

                debouncedSave();
                setSaveStatus('saving');
            });
        }

        // Événement sur le textarea "Actions à mener"
        const actionTextarea = card.querySelector('.criterion-action-text');
        if (actionTextarea) {
            actionTextarea.addEventListener('input', () => {
                if (!pendingChanges.has(cid)) pendingChanges.set(cid, {});
                pendingChanges.get(cid).actionText = actionTextarea.value;
                updateActionBadge(card);
                debouncedSave();
                setSaveStatus('saving');
            });
        }

        // Événement sur le datepicker "Pour quand ?"
        const actionWhenInput = card.querySelector('.criterion-action-when');
        if (actionWhenInput) {
            actionWhenInput.addEventListener('change', () => {
                if (!pendingChanges.has(cid)) pendingChanges.set(cid, {});
                pendingChanges.get(cid).actionWhen = actionWhenInput.value;
                updateActionBadge(card);
                debouncedSave();
                setSaveStatus('saving');
            });
        }

        // Événement sur la case à cocher "Facile à corriger"
        const actionEasyInput = card.querySelector('.criterion-action-easy');
        if (actionEasyInput) {
            actionEasyInput.addEventListener('change', () => {
                if (!pendingChanges.has(cid)) pendingChanges.set(cid, {});
                pendingChanges.get(cid).actionEasy = actionEasyInput.checked;
                updateActionBadge(card);
                debouncedSave();
                setSaveStatus('saving');
            });
        }
    });

    // ── Badge de l'accordéon Actions ───────────────────────────────────────

    function updateActionBadge(card) {
        const btn = card.querySelector('[data-accordion-type="actions"]');
        if (!btn) return;
        const hasContent = getActionTextFromCard(card).trim() !== ''
                        || getActionWhoFromCard(card).length > 0
                        || getActionWhenFromCard(card) !== ''
                        || getActionEasyFromCard(card);
        btn.classList.toggle('has-content', hasContent);
        const badge = btn.querySelector('.badge.bg-indigo');
        if (hasContent && !badge) {
            btn.insertAdjacentHTML('beforeend', '<span class="badge bg-indigo ms-2" style="font-size:0.65rem;">Renseigné</span>');
        } else if (!hasContent && badge) {
            badge.remove();
        }
    }

    // ── Tag input "Qui fait ?" ──────────────────────────────────────────────

    function initTagInputs() {
        // Registre global des tags déjà utilisés (alimenté au chargement et à l'ajout)
        const globalTags = new Set();
        document.querySelectorAll('.criterion-action-who').forEach(hidden => {
            try { (JSON.parse(hidden.value) || []).forEach(t => globalTags.add(t)); } catch {}
        });

        document.querySelectorAll('.action-who-wrapper').forEach(wrapper => {
            const cid = wrapper.dataset.criterionId;
            const card = wrapper.closest('.criterion-card');
            const textInput    = wrapper.querySelector('.criterion-action-who-input');
            const hiddenInput  = wrapper.querySelector('.criterion-action-who');
            const tagsDisplay  = wrapper.querySelector('.action-tags-display');
            const suggestions  = wrapper.querySelector('.action-who-suggestions');
            if (!textInput || !hiddenInput || !tagsDisplay || !suggestions) return;

            function getTags() {
                try { return JSON.parse(hiddenInput.value) || []; } catch { return []; }
            }

            function setTags(tags) {
                hiddenInput.value = JSON.stringify(tags);
                renderTags(tags);
                if (!pendingChanges.has(cid)) pendingChanges.set(cid, {});
                pendingChanges.get(cid).actionWho = tags;
                debouncedSave();
                setSaveStatus('saving');
                updateActionBadge(card);
            }

            function renderTags(tags) {
                tagsDisplay.innerHTML = tags.map(tag =>
                    `<span class="action-tag badge bg-light text-dark border fw-normal d-inline-flex align-items-center gap-1">` +
                    escHtml(tag) +
                    `<button type="button" class="action-tag-remove btn-close" style="font-size:0.5em;" ` +
                    `aria-label="Retirer" data-tag="${escHtml(tag)}"></button></span>`
                ).join('');
                tagsDisplay.querySelectorAll('.action-tag-remove').forEach(btn => {
                    btn.addEventListener('click', () => setTags(getTags().filter(t => t !== btn.dataset.tag)));
                });
            }

            function addTag(value) {
                value = value.trim();
                if (!value) return;
                const current = getTags();
                if (!current.includes(value)) {
                    current.push(value);
                    globalTags.add(value);
                    setTags(current);
                }
                textInput.value = '';
            }

            function showSuggestions(val) {
                const v = val.trim().toLowerCase();
                if (!v) { hideSuggestions(); return; }
                const current = getTags();
                const matches = [...globalTags]
                    .filter(t => t.toLowerCase().includes(v) && !current.includes(t))
                    .slice(0, 8);
                if (!matches.length) { hideSuggestions(); return; }
                suggestions.innerHTML = matches.map(t =>
                    `<button type="button" class="dropdown-item py-1 px-3 small" data-tag="${escHtml(t)}">${escHtml(t)}</button>`
                ).join('');
                suggestions.classList.add('show');
                suggestions.querySelectorAll('[data-tag]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        addTag(btn.dataset.tag);
                        hideSuggestions();
                    });
                });
            }

            function hideSuggestions() {
                suggestions.classList.remove('show');
                suggestions.innerHTML = '';
            }

            textInput.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    addTag(textInput.value);
                    hideSuggestions();
                } else if (e.key === 'Escape') {
                    hideSuggestions();
                }
            });
            textInput.addEventListener('input',  () => showSuggestions(textInput.value));
            textInput.addEventListener('blur',   e => {
                if (suggestions.contains(e.relatedTarget)) return;
                hideSuggestions();
            });

            // Rendu initial
            renderTags(getTags());
        });
    }

    // ── Sauvegarde ─────────────────────────────────────────────────────────

    /**
     * Met à jour l'indicateur visuel de sauvegarde
     */
    function setSaveStatus(status) {
        if (!saveIndicator) return;
        saveIndicator.className = 'save-indicator';

        if (status === 'saving') {
            saveIndicator.classList.add('save-saving');
            saveIndicator.innerHTML = '<i class="bi bi-arrow-repeat me-1 spin"></i><span>Enregistrement…</span>';
        } else if (status === 'saved') {
            saveIndicator.classList.add('save-idle');
            saveIndicator.innerHTML = '<i class="bi bi-check-circle me-1"></i><span>Enregistré</span>';
        } else if (status === 'error') {
            saveIndicator.classList.add('save-error');
            saveIndicator.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i><span>Erreur d\'enregistrement</span>';
        }
    }

    /**
     * Effectue la sauvegarde des critères modifiés
     */
    async function savePendingChanges() {
        if (pendingChanges.size === 0) {
            setSaveStatus('saved');
            return;
        }

        // Construire le payload avec toutes les modifications en attente
        const criteriaUpdate = [];
        pendingChanges.forEach((changes, cid) => {
            criteriaUpdate.push({
                id: cid,
                ...(changes.status     !== undefined && { status:       changes.status }),
                ...(changes.comment    !== undefined && { comment:      changes.comment }),
                ...(changes.actionText !== undefined && { action_text:  changes.actionText }),
                ...(changes.actionWho  !== undefined && { action_who:   changes.actionWho }),
                ...(changes.actionWhen !== undefined && { action_when:  changes.actionWhen }),
                ...(changes.actionEasy !== undefined && { action_easy:  changes.actionEasy }),
            });
        });

        // Vider le buffer avant l'envoi (optimiste)
        pendingChanges.clear();

        try {
            const result = await apiRequest('POST', 'api.php?action=update_audit', {
                id: AUDIT_ID,
                criteria: criteriaUpdate,
            });

            setSaveStatus('saved');

            // Mettre à jour le score si retourné par le serveur
            if (result.score !== undefined && statScore) {
                statScore.textContent = result.score + '%';
            }
        } catch (err) {
            console.error('Erreur de sauvegarde :', err);
            setSaveStatus('error');
        }
    }

    const debouncedSave = debounce(savePendingChanges, 800);

    // ── Boutons "Terminer l'audit" ──────────────────────────────────────────

    async function finishAudit() {
        const incomplete = [];
        document.querySelectorAll('.criterion-card').forEach(card => {
            const cid = card.dataset.criterionId;
            if (!cid) return;
            const status = getStatusFromCard(card, cid);
            if (status === 'non-testé') incomplete.push(cid);
        });

        if (incomplete.length > 0) {
            const msg = incomplete.length === 1
                ? `1 critère n'a pas encore été évalué (${incomplete[0]}). Voulez-vous terminer l'audit quand même ?`
                : `${incomplete.length} critères n'ont pas encore été évalués. Voulez-vous terminer l'audit quand même ?`;
            if (!confirm(msg)) return;
        }

        // Sauvegarder tous les changements en attente
        await savePendingChanges();

        try {
            await apiRequest('POST', 'api.php?action=update_audit', {
                id: AUDIT_ID,
                status: 'terminé',
            });
            window.location.href = `report.php?id=${AUDIT_ID}`;
        } catch (err) {
            alert('Erreur lors de la finalisation de l\'audit : ' + err.message);
        }
    }

    const btnFinish = document.getElementById('btnFinishAudit');
    if (btnFinish) {
        btnFinish.addEventListener('click', finishAudit);
    }

    const btnFinishBottom = document.getElementById('btnFinishAuditBottom');
    if (btnFinishBottom) {
        btnFinishBottom.addEventListener('click', finishAudit);
    }

    // ── Navigation sidebar : lien actif lors du scroll ─────────────────────

    const sectionObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const tid = entry.target.id.replace('thematic-', '');
                document.querySelectorAll('.sidebar-link').forEach(l => {
                    l.classList.remove('active');
                    l.removeAttribute('aria-current');
                });
                const activeLink = document.querySelector(`.sidebar-link[data-thematic="${tid}"]`);
                if (activeLink) {
                    activeLink.classList.add('active');
                    activeLink.setAttribute('aria-current', 'true');
                }
            }
        });
    }, {
        rootMargin: '-10% 0px -80% 0px',
        threshold: 0,
    });

    document.querySelectorAll('.thematic-section').forEach(section => {
        sectionObserver.observe(section);
    });

    // Activer le premier lien par défaut
    const firstLink = document.querySelector('.sidebar-link');
    if (firstLink) {
        firstLink.classList.add('active');
        firstLink.setAttribute('aria-current', 'true');
    }

    // ── Sauvegarde avant fermeture de la page ──────────────────────────────

    window.addEventListener('beforeunload', () => {
        if (pendingChanges.size > 0) {
            // Tentative de sauvegarde synchrone (best effort)
            const allState = getAllCriteriaState();
            const criteriaUpdate = [];
            allState.forEach(({ status, comment, actionText, actionWho, actionWhen, actionEasy }, cid) => {
                criteriaUpdate.push({ id: cid, status, comment, action_text: actionText, action_who: actionWho, action_when: actionWhen, action_easy: actionEasy });
            });

            const blob = new Blob([JSON.stringify({
                id: AUDIT_ID,
                criteria: criteriaUpdate,
            })], { type: 'application/json' });

            navigator.sendBeacon('api.php?action=update_audit', blob);
        }
    });

    // ── Filtrage depuis les blocs statistiques ──────────────────────────────

    /**
     * Applique ou retire un filtre par statut sur toutes les cartes de critères.
     * @param {string|null} filterStatus - 'conforme', 'non-conforme', ou null (tout afficher)
     */
    function applyFilter(filterStatus) {
        document.querySelectorAll('.criterion-card').forEach(card => {
            card.style.display = !filterStatus || card.dataset.status === filterStatus ? '' : 'none';
        });

        // Masquer les sections thématiques entièrement vides après filtrage
        document.querySelectorAll('.thematic-section').forEach(section => {
            const hasVisible = [...section.querySelectorAll('.criterion-card')]
                .some(c => c.style.display !== 'none');
            section.style.display = hasVisible ? '' : 'none';
        });
    }

    [
        { id: 'statCardNc', status: 'non-conforme' },
        { id: 'statCardOk', status: 'conforme' },
        { id: 'statCardNa', status: 'non-applicable' },
        { id: 'statCardNt', status: 'non-testé' },
    ].forEach(({ id, status }) => {
        const card = document.getElementById(id);
        if (!card) return;

        const toggleCard = () => {
            if (activeFilter === status) {
                // Désactiver le filtre
                activeFilter = null;
                card.classList.remove('stat-card-active');
                card.setAttribute('aria-pressed', 'false');
            } else {
                // Activer ce filtre, désactiver l'autre
                document.querySelectorAll('.stat-card-clickable').forEach(c => {
                    c.classList.remove('stat-card-active');
                    c.setAttribute('aria-pressed', 'false');
                });
                activeFilter = status;
                card.classList.add('stat-card-active');
                card.setAttribute('aria-pressed', 'true');
            }
            applyFilter(activeFilter);
        };

        card.addEventListener('click', toggleCard);
        card.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleCard();
            }
        });
    });

    // Initialisation des stats au chargement
    updateAllStats();
    setSaveStatus('saved');
    initTagInputs();

    // ── Bouton retour en haut ───────────────────────────────────────────────

    const backToTop = document.getElementById('backToTop');
    if (backToTop) {
        window.addEventListener('scroll', () => {
            backToTop.classList.toggle('visible', window.scrollY > 200);
        }, { passive: true });

        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // ── Style pour l'animation de rotation ────────────────────────────────
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .spin { display: inline-block; animation: spin 1s linear infinite; }
    `;
    document.head.appendChild(style);

})();
