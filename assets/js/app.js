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
 * RGESN V2 2024 Audit Tool — Utilitaires partagés
 */

'use strict';

/* ── Helper : requête API ──────────────────────────────────────────────────── */

/**
 * Effectue une requête vers api.php
 * @param {string} method  - 'GET' ou 'POST'
 * @param {string} url     - URL avec query string
 * @param {object} [body]  - Corps JSON pour les requêtes POST
 * @returns {Promise<any>}
 */
async function apiRequest(method, url, body = null) {
    const options = {
        method,
        headers: { 'Accept': 'application/json' },
    };

    if (body !== null && method === 'POST') {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);

    let data;
    try {
        data = await response.json();
    } catch {
        throw new Error(`Erreur de communication avec le serveur (HTTP ${response.status})`);
    }

    if (!response.ok) {
        throw new Error(data.error || `Erreur serveur (HTTP ${response.status})`);
    }

    return data;
}

/* ── Helper : formatage de dates ──────────────────────────────────────────── */

/**
 * Formate une date ISO 8601 en format français
 * @param {string} iso
 * @returns {string}
 */
function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('fr-FR', {
        day: '2-digit', month: '2-digit', year: 'numeric'
    });
}

/**
 * Formate une date ISO 8601 en format datetime français
 * @param {string} iso
 * @returns {string}
 */
function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('fr-FR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

/* ── Helper : échappement HTML ────────────────────────────────────────────── */

/**
 * Échappe les caractères HTML dangereux
 * @param {string} str
 * @returns {string}
 */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ── Helper : debounce ────────────────────────────────────────────────────── */

/**
 * Crée une version debounced d'une fonction
 * @param {Function} fn
 * @param {number} delay - Délai en ms
 * @returns {Function}
 */
function debounce(fn, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}
