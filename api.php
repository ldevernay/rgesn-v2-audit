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
 * RGESN V2 2024 Audit Tool — API Endpoint
 * Gère toutes les requêtes fetch() du front-end
 */

if (!defined('AUDITS_DIR')) {
    define('AUDITS_DIR', __DIR__ . '/data/audits/');
}
if (!defined('CRITERES_FILE')) {
    define('CRITERES_FILE', __DIR__ . '/data/criteria/criteria_settings.json');
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_store.php';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Calcul du score RGESN ───────────────────────────────────────────────────

function calculate_score(array $criteria): float
{
    $weights = [
        'Prioritaire' => 1.5,
        'Recommandé'  => 1.25,
        'Modéré'      => 1.0,
    ];

    // Charger le référentiel pour utiliser les priorités à jour
    $ref = [];
    $raw = json_decode(file_get_contents(CRITERES_FILE), true) ?? [];
    foreach ($raw as $item) {
        $ref[$item['id']] = $item;
    }

    $numerator   = 0.0;
    $denominator = 0.0;

    foreach ($criteria as $c) {
        $status   = $c['status'] ?? 'non-testé';
        $priority = $ref[$c['id']]['priority'] ?? $c['priority'] ?? 'Modéré';
        $weight   = $weights[$priority] ?? 1.0;

        if ($status === 'conforme') {
            $numerator   += $weight;
            $denominator += $weight;
        } elseif ($status === 'non-conforme') {
            $denominator += $weight;
        }
        // non-applicable et non-testé : exclus du calcul
    }

    if ($denominator == 0.0) {
        return 0.0;
    }

    return round(($numerator / $denominator) * 100, 1);
}

// ─── Taux de complétion ───────────────────────────────────────────────────────

function calculate_completion(array $criteria): float
{
    $total = count($criteria);
    if ($total === 0) {
        return 0.0;
    }

    $tested = 0;
    foreach ($criteria as $c) {
        if (($c['status'] ?? 'non-testé') !== 'non-testé') {
            $tested++;
        }
    }

    return round(($tested / $total) * 100, 1);
}

// ─── Initialisation des critères depuis le référentiel ───────────────────────

function get_initial_criteria(): array
{
    if (!file_exists(CRITERES_FILE)) {
        return [];
    }

    $raw = file_get_contents(CRITERES_FILE);
    $ref = json_decode($raw, true);
    if (!is_array($ref)) {
        return [];
    }

    $criteria = [];
    foreach ($ref as $item) {
        $criteria[] = [
            'thematic_id' => (int) ($item['thematic_id'] ?? 0),
            'id'          => $item['id'] ?? '',
            'priority'    => $item['priority'] ?? 'Modéré',
            'difficulty'  => $item['difficulty'] ?? 'Moyen',
            'status'      => 'non-testé',
            'comment'     => '',
            'action_text' => '',
            'action_who'  => [],
            'action_when' => '',
            'action_easy' => false,
        ];
    }

    return $criteria;
}

// ─── Routeur ─────────────────────────────────────────────────────────────────
// Isolé derrière RGESN_API_TESTING pour permettre aux tests unitaires d'inclure
// ce fichier et de réutiliser calculate_score(), calculate_completion(), etc.
// sans déclencher une vraie requête HTTP (headers, session, exit...).

if (!defined('RGESN_API_TESTING')) {

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    // Certaines actions sont accessibles sans connexion (liste des audits
    // publics, consultation d'un audit public) : on ne force donc plus la
    // connexion globalement ici. Chaque action décide si elle l'exige, via
    // require_login_api() appelé au moment du routage ci-dessous.
    $current_user = current_user(); // peut être null (visiteur anonyme)

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // Lecture du body JSON pour les requêtes POST
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw_body = file_get_contents('php://input');
        if (!empty($raw_body)) {
            $decoded = json_decode($raw_body, true);
            if (is_array($decoded)) {
                $input = $decoded;
                // action peut venir du body JSON
                if (empty($action) && isset($input['action'])) {
                    $action = $input['action'];
                }
            }
        }
        // Fallback sur $_POST
        if (empty($input)) {
            $input = $_POST;
        }
    }

    if (!is_dir(AUDITS_DIR)) {
        mkdir(AUDITS_DIR, 0755, true);
    }

    switch ($action) {
        // ── Actions ouvertes aux visiteurs non connectés ───────────────────
        case 'get_audit':
            action_get_audit($current_user);
            break;
        case 'list_audits':
            action_list_audits($current_user);
            break;

        // ── Actions nécessitant d'être connecté ────────────────────────────
        case 'create_audit':
            action_create_audit($input, require_login_and_csrf_api());
            break;
        case 'update_audit':
            action_update_audit($input, require_login_and_csrf_api());
            break;
        case 'delete_audit':
            action_delete_audit($input, require_login_and_csrf_api());
            break;
        case 'duplicate_audit':
            action_duplicate_audit($input, require_login_and_csrf_api());
            break;
        case 'add_contributor':
            action_add_contributor($input, require_login_and_csrf_api());
            break;
        case 'remove_contributor':
            action_remove_contributor($input, require_login_and_csrf_api());
            break;
        case 'set_visibility':
            action_set_visibility($input, require_login_and_csrf_api());
            break;
        case 'transfer_owner':
            action_transfer_owner($input, require_login_and_csrf_api());
            break;
        case 'acquire_audit_lock':
            action_acquire_audit_lock($input, require_login_and_csrf_api());
            break;
        case 'release_audit_lock':
            action_release_audit_lock($input, require_login_and_csrf_api());
            break;
        case 'renew_audit_lock':
            action_renew_audit_lock($input, require_login_and_csrf_api());
            break;
        case 'list_users_basic':
            action_list_users_basic(require_login_api());
            break;
        default:
            json_response(['error' => 'Action inconnue ou manquante'], 400);
    }

}

// ─── Actions ─────────────────────────────────────────────────────────────────

function action_create_audit(array $input, array $current_user): never
{
    $project_name = trim($input['project_name'] ?? '');
    $project_url  = trim($input['project_url'] ?? '');
    $auditor_name = trim($input['auditor_name'] ?? '');

    if ($project_name === '') {
        json_response(['error' => 'Le nom du projet est requis'], 400);
    }

    // Sanitize URL
    if ($project_url !== '' && !filter_var($project_url, FILTER_VALIDATE_URL)) {
        $project_url = '';
    }

    $id  = generate_uuid();
    $now = date('c');

    $audit = [
        'id'             => $id,
        'created_at'     => $now,
        'updated_at'     => $now,
        'status'         => 'en cours',
        'score'          => 0.0,
        'owner_id'       => $current_user['id'],
        'contributors'   => [],
        'visibility'     => 'contributors', // le plus restrictif par défaut
        'locked_by'      => null,
        'locked_by_name' => null,
        'locked_at'      => null,
        'project'        => [
            'name' => $project_name,
            'url'  => $project_url,
        ],
        'auditor' => [
            'name' => $auditor_name,
        ],
        'criteria' => get_initial_criteria(),
    ];

    if (!write_audit($id, $audit)) {
        json_response(['error' => 'Impossible de créer l\'audit (erreur d\'écriture)'], 500);
    }

    json_response(['success' => true, 'id' => $id]);
}

function action_get_audit(?array $current_user): never
{
    $id = trim($_GET['id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $audit = read_audit($id);
    if ($audit === null) {
        json_response(['error' => 'Audit introuvable'], 404);
    }

    $permission = get_user_permission($audit, $current_user);
    if (!permission_can_view($permission)) {
        json_response(['error' => 'Accès refusé à cet audit'], 403);
    }

    // On ne sait que MAINTENANT que la requête va réussir. Un cache PARTAGÉ
    // (proxy, CDN) ne doit jamais mettre en cache une réponse personnalisée
    // (dès qu'un utilisateur est connecté, potentiellement seul à la voir) —
    // seule la vue d'un visiteur anonyme sur un audit public, forcément
    // identique pour tout le monde, peut être mise en cache sans risque.
    if ($current_user === null) {
        header('Cache-Control: public, max-age=60');
    }

    $audit['_permission'] = $permission;
    $audit['_lock']       = lock_status($audit); // staleness déjà résolue, pratique côté front
    json_response($audit);
}

function action_update_audit(array $input, array $current_user): never
{
    $id = trim($input['id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $result = atomic_audit_update($id, function (array $audit) use ($input, $current_user) {
        $permission = get_user_permission($audit, $current_user);
        if (!permission_can_edit($permission)) {
            return [null, ['success' => false, 'error' => 'Vous n\'avez pas les droits pour modifier cet audit', '_status' => 403]];
        }

        // Un audit verrouillé par quelqu'un d'autre ne peut être modifié par
        // personne tant que le verrou est valide — même un propriétaire/admin
        // doit forcer le déverrouillage explicitement (voir release_audit_lock).
        $lock = lock_status($audit);
        if ($lock['locked'] && $lock['by_id'] !== $current_user['id']) {
            return [null, [
                'success' => false,
                'error'   => "Cet audit est en cours de modification par {$lock['by_name']}.",
                '_status' => 409,
            ]];
        }

        return action_update_audit_mutate($audit, $input);
    });

    $status = $result['_status']
        ?? ($result['success'] ? 200 : (($result['error'] ?? '') === 'Audit introuvable.' ? 404 : 500));
    unset($result['_status']);
    json_response($result, $status);
}

/**
 * Applique les modifications de contenu (projet, auditeur, statut, critères)
 * à $audit, déjà chargé et verrouillé par atomic_audit_update(). Séparé de
 * action_update_audit() uniquement pour garder cette dernière lisible.
 *
 * @return array{0: array, 1: array} tuple [$newAudit, $result] attendu par atomic_audit_update()
 */
function action_update_audit_mutate(array $audit, array $input): array
{
    // Mise à jour des informations projet
    if (isset($input['project']) && is_array($input['project'])) {
        if (isset($input['project']['name'])) {
            $audit['project']['name'] = trim($input['project']['name']);
        }
        if (isset($input['project']['url'])) {
            $url = trim($input['project']['url']);
            $audit['project']['url'] = ($url === '' || filter_var($url, FILTER_VALIDATE_URL)) ? $url : $audit['project']['url'];
        }
    }

    // Mise à jour de l'auditeur
    if (isset($input['auditor']) && is_array($input['auditor'])) {
        if (isset($input['auditor']['name'])) {
            $audit['auditor']['name'] = trim($input['auditor']['name']);
        }
    }

    // Mise à jour du statut de l'audit
    if (isset($input['status'])) {
        $allowed = ['en cours', 'terminé'];
        if (in_array($input['status'], $allowed, true)) {
            $audit['status'] = $input['status'];
        }
    }

    // Mise à jour des critères
    if (isset($input['criteria']) && is_array($input['criteria'])) {
        $allowed_statuses = ['conforme', 'non-conforme', 'non-applicable', 'non-testé'];

        // Indexer les critères de l'audit pour un accès rapide
        $index = [];
        foreach ($audit['criteria'] as $k => $c) {
            $index[$c['id']] = $k;
        }

        foreach ($input['criteria'] as $update) {
            if (!is_array($update)) {
                continue;
            }
            $cid = $update['id'] ?? '';
            if ($cid === '' || !isset($index[$cid])) {
                continue;
            }

            $k = $index[$cid];

            if (isset($update['status']) && in_array($update['status'], $allowed_statuses, true)) {
                $audit['criteria'][$k]['status'] = $update['status'];
            }
            if (isset($update['comment'])) {
                $audit['criteria'][$k]['comment'] = mb_substr(trim($update['comment']), 0, 5000);
            }
            if (isset($update['action_text'])) {
                $audit['criteria'][$k]['action_text'] = mb_substr(trim($update['action_text']), 0, 2000);
            }
            if (isset($update['action_who']) && is_array($update['action_who'])) {
                $audit['criteria'][$k]['action_who'] = array_values(array_slice(
                    array_map(
                        fn($t) => mb_substr(trim((string) $t), 0, 100),
                        array_filter($update['action_who'], fn($t) => is_string($t) || is_numeric($t))
                    ),
                    0, 20
                ));
            }
            if (isset($update['action_when'])) {
                $when = trim($update['action_when']);
                $audit['criteria'][$k]['action_when'] = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $when) ? $when : '';
            }
            if (isset($update['action_easy'])) {
                $audit['criteria'][$k]['action_easy'] = (bool) $update['action_easy'];
            }
        }
    }

    $audit['updated_at'] = date('c');
    $audit['score']      = calculate_score($audit['criteria']);

    return [$audit, [
        'success'    => true,
        'score'      => $audit['score'],
        'updated_at' => $audit['updated_at'],
    ]];
}

function action_list_audits(?array $current_user): never
{
    // Pour un visiteur anonyme, la réponse ne contient QUE des audits publics
    // — identique pour tout le monde, donc sûre à mettre en cache, même par
    // un cache partagé. Dès qu'un utilisateur est connecté, la liste lui est
    // personnalisée (ses propres audits, ses partages) : jamais "public".
    if ($current_user === null) {
        header('Cache-Control: public, max-age=30');
    }

    $audits = [];

    if (!is_dir(AUDITS_DIR)) {
        json_response($audits);
    }

    $files = glob(AUDITS_DIR . '*.json');
    if (!$files) {
        json_response($audits);
    }

    // Uniquement pour un admin : qui possède réellement chaque audit (compte,
    // pas le champ texte libre "auditeur"), utile pour la supervision.
    // Chargé une seule fois, hors de la boucle, pour éviter de relire
    // users.json à chaque audit.
    $owner_names = [];
    if (is_admin($current_user)) {
        foreach (load_users() as $u) {
            $owner_names[$u['id']] = $u['name'] ?? $u['login'] ?? '';
        }
    }

    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || empty($data['id'])) {
            continue;
        }

        $permission = get_user_permission($data, $current_user);
        if (!permission_can_view($permission)) {
            continue; // audit ni possédé, ni partagé, ni admin : invisible pour cet utilisateur
        }

        $completion = calculate_completion($data['criteria'] ?? []);

        // Compteurs par statut
        $counts = ['conforme' => 0, 'non-conforme' => 0, 'non-applicable' => 0, 'non-testé' => 0];
        foreach ($data['criteria'] ?? [] as $c) {
            $s = $c['status'] ?? 'non-testé';
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        $audits[] = [
            'id'                => $data['id'],
            'created_at'        => $data['created_at'] ?? '',
            'updated_at'        => $data['updated_at'] ?? '',
            'status'            => $data['status'] ?? 'en cours',
            'score'             => $data['score'] ?? 0.0,
            'completion'        => $completion,
            'counts'            => $counts,
            'project'           => $data['project'] ?? ['name' => '', 'url' => ''],
            'auditor'           => $data['auditor'] ?? ['name' => ''],
            'permission'        => $permission, // 'owner' | 'contributor' | 'lecture'
            'visibility'        => $data['visibility'] ?? 'contributors',
            'lock'              => lock_status($data), // {locked, by_id, by_name, since, stale}
            // Vide pour un non-admin (minimisation : seul un admin a besoin de
            // savoir qui possède chaque audit pour sa supervision).
            'owner_name'        => $owner_names[$data['owner_id'] ?? ''] ?? null,
            'contributor_count' => count($data['contributors'] ?? []),
        ];
    }

    // Tri par updated_at décroissant
    usort($audits, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

    json_response($audits);
}

function action_duplicate_audit(array $input, array $current_user): never
{
    $source_id    = trim($input['source_id'] ?? '');
    $project_name = trim($input['project_name'] ?? '');
    $project_url  = trim($input['project_url'] ?? '');
    $auditor_name = trim($input['auditor_name'] ?? '');

    if (!is_valid_uuid($source_id)) {
        json_response(['error' => 'UUID source invalide'], 400);
    }
    if ($project_name === '') {
        json_response(['error' => 'Le nom du projet est requis'], 400);
    }

    $source = read_audit($source_id);
    if ($source === null) {
        json_response(['error' => 'Audit source introuvable'], 404);
    }

    // La duplication est autorisée dès qu'on a un accès en lecture — elle crée
    // un nouvel audit indépendant, dont le duplicateur devient propriétaire.
    $permission = get_user_permission($source, $current_user);
    if (!permission_can_view($permission)) {
        json_response(['error' => 'Accès refusé à l\'audit source'], 403);
    }

    if ($project_url !== '' && !filter_var($project_url, FILTER_VALIDATE_URL)) {
        $project_url = '';
    }

    $id  = generate_uuid();
    $now = date('c');

    $audit = [
        'id'             => $id,
        'created_at'     => $now,
        'updated_at'     => $now,
        'status'         => 'en cours',
        'score'          => calculate_score($source['criteria']),
        'owner_id'       => $current_user['id'],
        'contributors'   => [],
        'visibility'     => 'contributors', // une copie démarre privée, même si la source était publique
        'locked_by'      => null,
        'locked_by_name' => null,
        'locked_at'      => null,
        'project'        => ['name' => $project_name, 'url'  => $project_url],
        'auditor'        => ['name' => $auditor_name],
        'criteria'       => $source['criteria'],
    ];

    if (!write_audit($id, $audit)) {
        json_response(['error' => 'Impossible de créer l\'audit (erreur d\'écriture)'], 500);
    }

    json_response(['success' => true, 'id' => $id]);
}

function action_delete_audit(array $input, array $current_user): never
{
    $id = trim($input['id'] ?? $_GET['id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $audit = read_audit($id);
    if ($audit === null) {
        json_response(['error' => 'Audit introuvable'], 404);
    }

    // Seul le propriétaire (ou un admin) peut supprimer — un accès en
    // modification ne suffit pas.
    $permission = get_user_permission($audit, $current_user);
    if (!permission_can_manage($permission)) {
        json_response(['error' => 'Seul le propriétaire de l\'audit peut le supprimer'], 403);
    }

    $file = AUDITS_DIR . $id . '.json';
    if (!unlink($file)) {
        json_response(['error' => 'Impossible de supprimer l\'audit'], 500);
    }

    json_response(['success' => true]);
}

// ─── Contributeurs, visibilité, transfert de propriété ───────────────────────
//
// Toutes ces actions délèguent à includes/audit_store.php, qui fait tout le
// cycle lire → vérifier la permission → modifier → écrire sous un seul verrou
// de fichier (atomic_audit_update()) — la vérification de permission ne peut
// donc jamais être invalidée par une écriture concurrente.

function action_add_contributor(array $input, array $current_user): never
{
    $id             = trim($input['id'] ?? '');
    $contributor_id = trim($input['user_id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $result = add_audit_contributor($id, $contributor_id, $current_user);
    json_response($result, $result['success'] ? 200 : (isset($result['error']) && str_contains($result['error'], 'introuvable') ? 404 : 403));
}

function action_remove_contributor(array $input, array $current_user): never
{
    $id             = trim($input['id'] ?? '');
    $contributor_id = trim($input['user_id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $result = remove_audit_contributor($id, $contributor_id, $current_user);
    json_response($result, $result['success'] ? 200 : 403);
}

function action_set_visibility(array $input, array $current_user): never
{
    $id         = trim($input['id'] ?? '');
    $visibility = trim($input['visibility'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }
    if (!in_array($visibility, AUDIT_VISIBILITIES, true)) {
        json_response(['error' => 'Visibilité invalide'], 400);
    }

    $result = set_audit_visibility($id, $visibility, $current_user);
    json_response($result, $result['success'] ? 200 : 403);
}

function action_transfer_owner(array $input, array $current_user): never
{
    $id           = trim($input['id'] ?? '');
    $new_owner_id = trim($input['new_owner_id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }
    if ($new_owner_id === '') {
        json_response(['error' => 'Nouveau propriétaire requis'], 400);
    }

    $result = transfer_audit_owner($id, $new_owner_id, $current_user);
    json_response($result, $result['success'] ? 200 : (($result['error'] ?? '') === 'Utilisateur introuvable.' ? 404 : 403));
}

// ─── Verrou sémantique d'édition d'un audit ───────────────────────────────────

function action_acquire_audit_lock(array $input, array $current_user): never
{
    $id = trim($input['id'] ?? '');
    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $audit = read_audit($id);
    if ($audit === null) {
        json_response(['error' => 'Audit introuvable'], 404);
    }
    if (!permission_can_edit(get_user_permission($audit, $current_user))) {
        json_response(['error' => 'Vous n\'avez pas les droits pour éditer cet audit'], 403);
    }

    $result = acquire_audit_lock($id, $current_user);
    json_response($result, $result['success'] ? 200 : 409);
}

function action_release_audit_lock(array $input, array $current_user): never
{
    $id = trim($input['id'] ?? '');
    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $ok = release_audit_lock($id, $current_user);
    json_response(['success' => $ok]);
}

function action_renew_audit_lock(array $input, array $current_user): never
{
    $id = trim($input['id'] ?? '');
    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $ok = renew_audit_lock($id, $current_user);
    json_response(['success' => $ok]);
}

// ─── Liste minimale des utilisateurs (pour choisir contributeurs/propriétaire) ─

function action_list_users_basic(array $current_user): never
{
    $users = array_map(
        fn($u) => ['id' => $u['id'], 'name' => $u['name'] ?? $u['login'] ?? ''],
        array_filter(load_users(), fn($u) => ($u['id'] ?? null) !== $current_user['id'])
    );

    usort($users, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    json_response(array_values($users));
}
