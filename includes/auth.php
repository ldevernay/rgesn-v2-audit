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
 * RGESN V2 2024 Audit Tool — Authentification, sessions et permissions
 *
 * La gestion des comptes (création, modification, suppression) se fait via
 * des pages web dédiées (users.php, user_form.php, user_delete.php),
 * réservées aux admins, et jamais via l'API JSON (api.php). Les scripts CLI
 * bin/create_user.php et bin/delete_user.php restent disponibles comme
 * solution de secours ("break glass") si l'interface web est inaccessible
 * (par ex. plus aucun compte admin valide), mais ne sont plus le chemin
 * normal d'utilisation.
 */

if (!defined('USERS_FILE')) {
    define('USERS_FILE', __DIR__ . '/../data/users.json');
}

if (session_status() === PHP_SESSION_NONE) {
    // Durcissement du cookie de session.
    // 'secure' est activé automatiquement dès que la connexion est en HTTPS,
    // pour ne pas casser un environnement de développement local en HTTP.
    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $is_https,
    ]);
    session_start();
}

// ─── Chargement des utilisateurs ──────────────────────────────────────────────

function load_users(): array
{
    if (!file_exists(USERS_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(USERS_FILE), true);
    return is_array($data) ? $data : [];
}

function find_user_by_login(string $login): ?array
{
    foreach (load_users() as $u) {
        if (($u['login'] ?? '') === $login) {
            return $u;
        }
    }
    return null;
}

function find_user_by_id(string $id): ?array
{
    foreach (load_users() as $u) {
        if (($u['id'] ?? '') === $id) {
            return $u;
        }
    }
    return null;
}

// Écriture atomique du fichier users.json. N'est plus utilisé pour des
// cycles lire-modifier-écrire (voir les fonctions atomiques plus bas, qui
// font tout sous un même verrou via with_file_lock) — seulement pour un
// remplacement complet et déjà décidé du contenu.
function save_users(array $users): bool
{
    return (bool) with_file_lock(USERS_FILE, function () use ($users) {
        $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return [$json, true];
    });
}

function update_user_password_hash(string $user_id, string $new_hash): void
{
    with_file_lock(USERS_FILE, function (string $raw) use ($user_id, $new_hash) {
        $users = json_decode($raw, true);
        $users = is_array($users) ? $users : [];
        foreach ($users as $k => $u) {
            if (($u['id'] ?? '') === $user_id) {
                $users[$k]['password_hash'] = $new_hash;
                break;
            }
        }
        $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return [$json, true];
    });
}

// ─── Verrou sémantique "en cours d'édition" ───────────────────────────────────
//
// Complète with_file_lock() (qui empêche toute CORRUPTION de fichier) par un
// verrou visible par les humains, empêchant deux personnes de modifier le
// MÊME enregistrement (utilisateur, audit...) en même temps sans le savoir.
// Porté par 3 champs sur l'enregistrement lui-même : locked_by (id),
// locked_by_name (affichage), locked_at (horodatage ISO 8601).
//
// Se libère : explicitement (bouton "Terminer"), automatiquement à la
// fermeture de l'onglet (navigator.sendBeacon côté client), ou par expiration
// après EDIT_LOCK_TIMEOUT_SECONDS d'inactivité (navigateur planté, perte
// réseau...). Un verrou expiré est considéré comme libre : quelqu'un d'autre
// peut le reprendre sans action manuelle, mais sans jamais écraser
// silencieusement un verrou repris entre-temps par un tiers — voir la
// comparaison par by_id dans les fonctions d'acquisition ci-dessous.

if (!defined('EDIT_LOCK_TIMEOUT_SECONDS')) {
    define('EDIT_LOCK_TIMEOUT_SECONDS', 600); // 10 minutes
}

function lock_is_stale(?string $locked_at): bool
{
    if ($locked_at === null) {
        return true;
    }
    $ts = strtotime($locked_at);
    if ($ts === false) {
        return true;
    }
    return (time() - $ts) > EDIT_LOCK_TIMEOUT_SECONDS;
}

/**
 * @return array{locked: bool, by_id: ?string, by_name: ?string, since: ?string, stale: bool}
 */
function lock_status(array $record): array
{
    $lockedBy = $record['locked_by'] ?? null;
    if ($lockedBy === null) {
        return ['locked' => false, 'by_id' => null, 'by_name' => null, 'since' => null, 'stale' => false];
    }

    $lockedAt = $record['locked_at'] ?? null;
    $stale = lock_is_stale($lockedAt);

    return [
        'locked'  => !$stale,
        'by_id'   => $lockedBy,
        'by_name' => $record['locked_by_name'] ?? null,
        'since'   => $lockedAt,
        'stale'   => $stale,
    ];
}

function apply_lock(array $record, array $user): array
{
    $record['locked_by']      = $user['id'];
    $record['locked_by_name'] = $user['name'] ?? $user['login'] ?? '';
    $record['locked_at']      = date('c');
    return $record;
}

function clear_lock(array $record): array
{
    $record['locked_by']      = null;
    $record['locked_by_name'] = null;
    $record['locked_at']      = null;
    return $record;
}

// ─── Verrou sémantique appliqué aux utilisateurs (fichier partagé) ───────────

/**
 * @return array{success: bool, status: ?array, error?: string}
 */
function acquire_user_lock(string $user_id, array $current_user): array
{
    return with_file_lock(USERS_FILE, function (string $raw) use ($user_id, $current_user) {
        $users = json_decode($raw, true);
        $users = is_array($users) ? $users : [];

        foreach ($users as $k => $u) {
            if (($u['id'] ?? '') !== $user_id) {
                continue;
            }

            $status = lock_status($u);
            if ($status['locked'] && $status['by_id'] !== $current_user['id']) {
                // Verrouillé par quelqu'un d'autre, verrou toujours valide : refus.
                return [null, ['success' => false, 'status' => $status]];
            }

            $users[$k] = apply_lock($u, $current_user);
            $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return [$json, ['success' => true, 'status' => lock_status($users[$k])]];
        }

        return [null, ['success' => false, 'status' => null, 'error' => 'not_found']];
    });
}

/**
 * $force permet à un admin de reprendre la main sur un verrou actif détenu
 * par quelqu'un d'autre (cas d'un verrou manifestement bloqué avant son
 * expiration naturelle).
 */
function release_user_lock(string $user_id, array $current_user, bool $force = false): bool
{
    return (bool) with_file_lock(USERS_FILE, function (string $raw) use ($user_id, $current_user, $force) {
        $users = json_decode($raw, true);
        $users = is_array($users) ? $users : [];

        foreach ($users as $k => $u) {
            if (($u['id'] ?? '') !== $user_id) {
                continue;
            }

            $status = lock_status($u);
            if ($status['locked'] && $status['by_id'] !== $current_user['id'] && !$force) {
                return [null, false];
            }

            $users[$k] = clear_lock($u);
            $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return [$json, true];
        }

        return [null, false];
    });
}

/**
 * Renouvelle le verrou (appelé périodiquement en JS tant que la page
 * d'édition reste ouverte). Ne renouvelle QUE si le verrou est toujours
 * détenu par ce même utilisateur — jamais un vol silencieux d'un verrou
 * détenu par quelqu'un d'autre.
 */
function renew_user_lock(string $user_id, array $current_user): bool
{
    return with_file_lock(USERS_FILE, function (string $raw) use ($user_id, $current_user) {
        $users = json_decode($raw, true);
        $users = is_array($users) ? $users : [];

        foreach ($users as $k => $u) {
            if (($u['id'] ?? '') !== $user_id) {
                continue;
            }

            $status = lock_status($u);
            if (!$status['locked'] || $status['by_id'] !== $current_user['id']) {
                return [null, false];
            }

            $users[$k] = apply_lock($u, $current_user); // renouvelle locked_at
            $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return [$json, true];
        }

        return [null, false];
    });
}

// ─── Opérations atomiques sur les utilisateurs (créer / modifier / supprimer) ─
//
// Tout le cycle lire → valider → modifier → écrire se fait DANS un seul appel
// à with_file_lock(), pour qu'aucune vérification (unicité du login, dernier
// admin restant...) ne puisse être invalidée par une écriture concurrente
// survenue entre la lecture et l'écriture.

const USER_ROLES = ['auditeur', 'admin'];

/**
 * @return array{success: bool, error?: string, user?: array}
 */
function create_user_record(string $login, string $name, string $role, string $password): array
{
    $login = trim($login);
    $name  = trim($name);

    if ($login === '') {
        return ['success' => false, 'error' => "L'identifiant est requis."];
    }
    if ($name === '') {
        return ['success' => false, 'error' => 'Le nom affiché est requis.'];
    }
    if (!in_array($role, USER_ROLES, true)) {
        return ['success' => false, 'error' => 'Rôle invalide.'];
    }
    if (strlen($password) < 8) {
        return ['success' => false, 'error' => 'Le mot de passe doit faire au moins 8 caractères.'];
    }

    return with_file_lock(USERS_FILE, function (string $raw) use ($login, $name, $role, $password) {
        $users = json_decode($raw, true);
        $users = is_array($users) ? $users : [];

        foreach ($users as $u) {
            if (($u['login'] ?? '') === $login) {
                return [null, ['success' => false, 'error' => 'Cet identifiant est déjà utilisé.']];
            }
        }

        $newUser = [
            'id'            => generate_uuid(),
            'login'         => $login,
            'name'          => $name,
            'role'          => $role,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'locked_by'     => null,
            'locked_by_name'=> null,
            'locked_at'     => null,
        ];
        $users[] = $newUser;

        $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return [$json, ['success' => true, 'user' => $newUser]];
    });
}

/**
 * $changes peut contenir : 'name', 'role', 'password' (toutes optionnelles —
 * seuls les champs présents sont modifiés). $acting_user est la personne qui
 * effectue la modification (pour les garde-fous d'auto-rétrogradation).
 *
 * @return array{success: bool, error?: string}
 */
function update_user_record(string $user_id, array $changes, array $acting_user): array
{
    if (isset($changes['role']) && !in_array($changes['role'], USER_ROLES, true)) {
        return ['success' => false, 'error' => 'Rôle invalide.'];
    }
    if (isset($changes['password']) && strlen($changes['password']) < 8) {
        return ['success' => false, 'error' => 'Le mot de passe doit faire au moins 8 caractères.'];
    }
    if (isset($changes['name']) && trim($changes['name']) === '') {
        return ['success' => false, 'error' => 'Le nom affiché est requis.'];
    }

    return with_file_lock(USERS_FILE, function (string $raw) use ($user_id, $changes, $acting_user) {
        $users = json_decode($raw, true);
        $users = is_array($users) ? $users : [];

        $targetIndex = null;
        foreach ($users as $k => $u) {
            if (($u['id'] ?? '') === $user_id) {
                $targetIndex = $k;
                break;
            }
        }
        if ($targetIndex === null) {
            return [null, ['success' => false, 'error' => 'Utilisateur introuvable.']];
        }

        $target = $users[$targetIndex];

        // Garde-fou : un admin ne peut pas se rétrograder lui-même.
        if (
            isset($changes['role'])
            && $changes['role'] !== 'admin'
            && $user_id === $acting_user['id']
            && ($target['role'] ?? '') === 'admin'
        ) {
            return [null, ['success' => false, 'error' => 'Vous ne pouvez pas vous rétrograder vous-même.']];
        }

        // Garde-fou : ne jamais retirer le dernier admin.
        if (isset($changes['role']) && $changes['role'] !== 'admin' && ($target['role'] ?? '') === 'admin') {
            $otherAdmins = array_filter(
                $users,
                fn($u) => ($u['role'] ?? '') === 'admin' && ($u['id'] ?? '') !== $user_id
            );
            if (count($otherAdmins) === 0) {
                return [null, ['success' => false, 'error' => 'Impossible de retirer le dernier compte admin.']];
            }
        }

        if (array_key_exists('name', $changes)) {
            $target['name'] = trim($changes['name']);
        }
        if (array_key_exists('role', $changes)) {
            $target['role'] = $changes['role'];
        }
        if (array_key_exists('password', $changes) && $changes['password'] !== '') {
            $target['password_hash'] = password_hash($changes['password'], PASSWORD_DEFAULT);
        }

        $users[$targetIndex] = $target;
        $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return [$json, ['success' => true]];
    });
}

/**
 * Change le mot de passe de l'utilisateur COURANT (self-service), après
 * vérification de son mot de passe actuel.
 *
 * @return array{success: bool, error?: string}
 */
function change_own_password(array $current_user, string $current_password, string $new_password): array
{
    if (strlen($new_password) < 8) {
        return ['success' => false, 'error' => 'Le nouveau mot de passe doit faire au moins 8 caractères.'];
    }

    return with_file_lock(USERS_FILE, function (string $raw) use ($current_user, $current_password, $new_password) {
        $users = json_decode($raw, true);
        $users = is_array($users) ? $users : [];

        foreach ($users as $k => $u) {
            if (($u['id'] ?? '') !== $current_user['id']) {
                continue;
            }

            if (!password_verify($current_password, $u['password_hash'] ?? '')) {
                return [null, ['success' => false, 'error' => 'Mot de passe actuel incorrect.']];
            }

            $users[$k]['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
            $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return [$json, ['success' => true]];
        }

        return [null, ['success' => false, 'error' => 'Utilisateur introuvable.']];
    });
}

/**
 * Supprime un compte. Garde-fous : dernier admin, auto-suppression.
 *
 * Ne s'occupe PAS (encore) du sort des audits dont ce compte serait
 * propriétaire/contributeur — cette logique sera ajoutée avec le nouveau
 * modèle contributeurs/visibilité des audits (prochaine étape). Pour
 * l'instant, user_delete.php porte la même logique de réassignation que le
 * script CLI historique, sur l'ancien modèle owner_id/shares.
 *
 * @return array{success: bool, error?: string}
 */
function delete_user_record(string $user_id, array $acting_user): array
{
    if ($user_id === $acting_user['id']) {
        return ['success' => false, 'error' => 'Vous ne pouvez pas supprimer votre propre compte.'];
    }

    return with_file_lock(USERS_FILE, function (string $raw) use ($user_id) {
        $users = json_decode($raw, true);
        $users = is_array($users) ? $users : [];

        $target = null;
        foreach ($users as $u) {
            if (($u['id'] ?? '') === $user_id) {
                $target = $u;
                break;
            }
        }
        if ($target === null) {
            return [null, ['success' => false, 'error' => 'Utilisateur introuvable.']];
        }

        if (($target['role'] ?? '') === 'admin') {
            $otherAdmins = array_filter(
                $users,
                fn($u) => ($u['role'] ?? '') === 'admin' && ($u['id'] ?? '') !== $user_id
            );
            if (count($otherAdmins) === 0) {
                return [null, ['success' => false, 'error' => 'Impossible de supprimer le dernier compte admin.']];
            }
        }

        $remaining = array_values(array_filter($users, fn($u) => ($u['id'] ?? '') !== $user_id));
        $json = json_encode($remaining, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return [$json, ['success' => true]];
    });
}

// ─── Limitation des tentatives de connexion (anti brute-force) ───────────────
//
// Stockage : data/security/login_attempts.json — protégé par data/.htaccess
// comme le reste du dossier data/. Clé = IP + login tenté (pas seulement
// l'IP, pour ne pas bloquer tout un bureau/VPN partageant la même IP sortante
// à cause d'une seule personne qui se trompe de mot de passe).

if (!defined('LOGIN_ATTEMPTS_FILE')) {
    define('LOGIN_ATTEMPTS_FILE', __DIR__ . '/../data/security/login_attempts.json');
}
const LOGIN_MAX_ATTEMPTS  = 5;
const LOGIN_WINDOW_SECONDS = 900; // 15 minutes

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rate_limit_key(string $login): string
{
    return client_ip() . '|' . mb_strtolower(trim($login));
}

function load_login_attempts(): array
{
    if (!file_exists(LOGIN_ATTEMPTS_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(LOGIN_ATTEMPTS_FILE), true);
    return is_array($data) ? $data : [];
}

function save_login_attempts(array $data): void
{
    $dir = dirname(LOGIN_ATTEMPTS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = LOGIN_ATTEMPTS_FILE . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, LOGIN_ATTEMPTS_FILE);
}

/**
 * @return array{blocked: bool, retry_after: int} retry_after en secondes
 */
function is_login_rate_limited(string $login): array
{
    $data      = load_login_attempts();
    $key       = rate_limit_key($login);
    $now       = time();
    $attempts  = array_values(array_filter(
        $data[$key] ?? [],
        fn($t) => $t > $now - LOGIN_WINDOW_SECONDS
    ));

    if (count($attempts) >= LOGIN_MAX_ATTEMPTS) {
        $retry_after = LOGIN_WINDOW_SECONDS - ($now - min($attempts));
        return ['blocked' => true, 'retry_after' => max(1, $retry_after)];
    }

    return ['blocked' => false, 'retry_after' => 0];
}

function record_failed_login_attempt(string $login): void
{
    $data = load_login_attempts();
    $key  = rate_limit_key($login);
    $now  = time();

    $attempts   = array_filter($data[$key] ?? [], fn($t) => $t > $now - LOGIN_WINDOW_SECONDS);
    $attempts[] = $now;
    $data[$key] = array_values($attempts);

    // Nettoyage au passage : on retire les clés devenues vides (fenêtre
    // expirée), pour ne pas laisser grossir le fichier indéfiniment.
    foreach ($data as $k => $timestamps) {
        $remaining = array_filter($timestamps, fn($t) => $t > $now - LOGIN_WINDOW_SECONDS);
        if (empty($remaining)) {
            unset($data[$k]);
        } else {
            $data[$k] = array_values($remaining);
        }
    }

    save_login_attempts($data);
}

function clear_login_attempts(string $login): void
{
    $data = load_login_attempts();
    unset($data[rate_limit_key($login)]);
    save_login_attempts($data);
}

// ─── Protection CSRF ───────────────────────────────────────────────────────────
//
// Jeton opaque stocké en session, transmis au client via une balise <meta>
// (pages HTML) ou un champ caché (formulaire de login, avant toute session
// authentifiée). Vérifié sur toute action d'écriture de l'API. Le cookie de
// session étant déjà SameSite=Lax, ceci est une protection en profondeur
// plutôt que l'unique rempart.

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $submitted): bool
{
    if (empty($_SESSION['csrf_token']) || $submitted === null || $submitted === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * À utiliser dans api.php pour les actions d'écriture : vérifie le jeton
 * transmis via l'en-tête X-CSRF-Token, répond 403 et arrête l'exécution s'il
 * est absent ou invalide.
 */
function require_csrf_api(): void
{
    // En-tête standard pour les appels fetch() normaux ; repli sur un champ
    // de formulaire pour les requêtes envoyées via navigator.sendBeacon(),
    // qui ne permet de poser AUCUN en-tête personnalisé (ex : libération du
    // verrou d'édition à la fermeture d'un onglet — voir audit.php/user_lock.php).
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        echo json_encode(
            ['error' => 'Jeton de sécurité invalide ou expiré — rechargez la page et réessayez.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}

/**
 * Combine require_login_api() et require_csrf_api() : à utiliser pour toute
 * action de l'API qui modifie des données.
 */
function require_login_and_csrf_api(): array
{
    $user = require_login_api();
    require_csrf_api();
    return $user;
}

// ─── Utilisateur courant ───────────────────────────────────────────────────────

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return find_user_by_id($_SESSION['user_id']);
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && ($user['role'] ?? '') === 'admin';
}

// ─── Garde d'accès — pages web (redirige vers login.php) ─────────────────────

function require_login_page(): array
{
    $user = current_user();
    if ($user === null) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header('Location: login.php?redirect=' . $redirect);
        exit;
    }
    return $user;
}

// Comme require_login_page(), mais exige en plus le rôle admin. Un utilisateur
// connecté mais non-admin est renvoyé vers l'accueil (sans révéler l'existence
// de la page — pas de message d'erreur explicite qui inviterait à insister).
function require_admin_page(): array
{
    $user = require_login_page();
    if (!is_admin($user)) {
        header('Location: index.php');
        exit;
    }
    return $user;
}

// ─── Garde d'accès — API (réponse JSON 401) ──────────────────────────────────

function require_login_api(): array
{
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentification requise'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}

// ─── Permissions sur un audit ─────────────────────────────────────────────────
//
// Modèle (Phase B) : un audit a UN propriétaire (owner_id), une liste de
// contributeurs (contributors[], simple liste d'id — tous avec les mêmes
// droits d'édition, plus de distinction lecture/modification au sein des
// contributeurs), et une visibilité à 3 niveaux (visibility) :
//   - 'contributors'  : visible uniquement par le propriétaire et les contributeurs
//   - 'authenticated' : visible en lecture par tout utilisateur connecté
//   - 'public'        : visible en lecture par tout le monde, y compris sans connexion
//
// La visibilité n'accorde QUE la lecture. Éditer exige d'être contributeur
// (ou propriétaire, ou admin).

const AUDIT_VISIBILITIES = ['contributors', 'authenticated', 'public'];

/**
 * Renvoie le niveau d'accès de $user sur $audit :
 * 'owner' | 'contributor' | 'lecture' | null (aucun accès)
 *
 * $user peut être null (visiteur non connecté) : dans ce cas, seul
 * visibility === 'public' peut lui accorder une lecture seule — jamais un
 * accès lié à owner_id ou à contributors[], qui nécessitent un compte.
 */
function get_user_permission(array $audit, ?array $user): ?string
{
    if ($user !== null) {
        // Un admin a un accès complet à tous les audits, comme un propriétaire.
        if (is_admin($user)) {
            return 'owner';
        }

        if (($audit['owner_id'] ?? null) === $user['id']) {
            return 'owner';
        }

        if (in_array($user['id'], $audit['contributors'] ?? [], true)) {
            return 'contributor';
        }
    }

    // Accès en lecture via la visibilité. 'contributors' (ou absence du
    // champ, pour un audit créé avant ce modèle) n'accorde rien de plus ici
    // — on est déjà passé par les cas owner/contributor ci-dessus.
    $visibility = $audit['visibility'] ?? 'contributors';

    if ($visibility === 'public') {
        return 'lecture';
    }
    if ($visibility === 'authenticated' && $user !== null) {
        return 'lecture';
    }

    return null;
}

function permission_can_view(?string $permission): bool
{
    return $permission !== null;
}

function permission_can_edit(?string $permission): bool
{
    return in_array($permission, ['owner', 'contributor'], true);
}

function permission_can_manage(?string $permission): bool
{
    // Gérer les contributeurs, la visibilité, céder la propriété, supprimer
    // l'audit : réservé au propriétaire (ou admin, mappé sur 'owner').
    return $permission === 'owner';
}
