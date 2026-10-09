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
 * RGESN V2 2024 Audit Tool — Renouvellement/libération du verrou d'édition
 * d'un utilisateur (appelé en JS depuis user_form.php).
 *
 * Volontairement séparé de api.php : la gestion des utilisateurs ne passe
 * pas par l'API JSON générale de l'outil.
 *
 * Actions : renew (heartbeat périodique), release (fermeture explicite de
 * l'onglet, via navigator.sendBeacon — d'où l'acceptation de la requête sans
 * en-tête personnalisé, sendBeacon ne permettant pas d'en fixer).
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$current_user = require_login_api();
if (!is_admin($current_user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action  = $_POST['action'] ?? '';
$user_id = trim($_POST['user_id'] ?? '');

if ($user_id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'user_id requis'], JSON_UNESCAPED_UNICODE);
    exit;
}

// require_csrf_api() accepte l'en-tête X-CSRF-Token (cas normal, "renew" via
// fetch()) OU $_POST['csrf_token'] (repli nécessaire pour "release", envoyé
// via navigator.sendBeacon() qui ne permet de poser aucun en-tête personnalisé).
require_csrf_api();

switch ($action) {
    case 'renew':
        $ok = renew_user_lock($user_id, $current_user);
        echo json_encode(['success' => $ok], JSON_UNESCAPED_UNICODE);
        break;

    case 'release':
        $ok = release_user_lock($user_id, $current_user);
        echo json_encode(['success' => $ok], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action inconnue'], JSON_UNESCAPED_UNICODE);
}
