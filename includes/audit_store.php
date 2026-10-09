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
 * RGESN V2 2024 Audit Tool — Accès bas niveau aux fichiers d'audit
 *
 * Partagé entre api.php, user_delete.php et bin/delete_user.php. Suppose que
 * includes/functions.php (with_file_lock, esc...) ET includes/auth.php
 * (get_user_permission, permission_can_manage, lock_status, apply_lock,
 * clear_lock, find_user_by_id) sont déjà chargés par l'appelant.
 *
 * Modèle d'un audit (Phase B) :
 *   - owner_id      : id du propriétaire (un seul), ou null (legacy)
 *   - contributors  : liste d'id, tous avec les mêmes droits d'édition
 *   - visibility    : 'contributors' | 'authenticated' | 'public'
 *   - locked_by / locked_by_name / locked_at : verrou sémantique d'édition
 *     (mêmes champs et mêmes fonctions que pour les utilisateurs — voir
 *     includes/auth.php)
 */

if (!defined('AUDITS_DIR')) {
    define('AUDITS_DIR', __DIR__ . '/../data/audits/');
}

function read_audit(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    $file = AUDITS_DIR . $id . '.json';
    if (!file_exists($file)) {
        return null;
    }

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function write_audit(string $id, array $audit): bool
{
    $file = AUDITS_DIR . $id . '.json';
    $tmp  = $file . '.tmp';

    $json = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, $file);
}

function list_audit_files(): array
{
    if (!is_dir(AUDITS_DIR)) {
        return [];
    }
    return glob(AUDITS_DIR . '*.json') ?: [];
}

// ─── Mutation atomique d'un audit ─────────────────────────────────────────────
//
// Généralise with_file_lock() (functions.php) au cas "un fichier = un audit
// JSON" : lit, laisse $mutator inspecter/modifier le tableau décodé, puis
// réécrit — le tout sous un même verrou de fichier, pour qu'aucune vérification
// (permission, verrou sémantique...) ne puisse être invalidée par une écriture
// concurrente survenue entre temps.
//
// $mutator reçoit le tableau audit actuel et doit renvoyer un tuple
// [$newAudit, $result] : $newAudit est le tableau à écrire (ou null pour
// abandonner sans rien écrire — permission refusée, verrou déjà pris...) ;
// $result est renvoyé tel quel à l'appelant de atomic_audit_update().
function atomic_audit_update(string $audit_id, callable $mutator): mixed
{
    if (!is_valid_uuid($audit_id)) {
        return ['success' => false, 'error' => 'UUID invalide.'];
    }

    $file = AUDITS_DIR . $audit_id . '.json';

    return with_file_lock($file, function (string $raw) use ($mutator) {
        $audit = json_decode($raw, true);
        if (!is_array($audit)) {
            return [null, ['success' => false, 'error' => 'Audit introuvable.']];
        }

        [$newAudit, $result] = $mutator($audit);

        if ($newAudit === null) {
            return [null, $result];
        }

        $json = json_encode($newAudit, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return [$json, $result];
    });
}

// ─── Verrou sémantique appliqué aux audits ────────────────────────────────────
// Mêmes règles que pour les utilisateurs (includes/auth.php::lock_status() et
// consorts) : verrouillage "en cours d'édition", expirant après
// EDIT_LOCK_TIMEOUT_SECONDS, renouvelable (heartbeat), forçable par le
// propriétaire ou un admin.

/**
 * @return array{success: bool, status: ?array}
 */
function acquire_audit_lock(string $audit_id, array $user): array
{
    return atomic_audit_update($audit_id, function (array $audit) use ($user) {
        $status = lock_status($audit);
        if ($status['locked'] && $status['by_id'] !== $user['id']) {
            return [null, ['success' => false, 'status' => $status]];
        }
        $audit = apply_lock($audit, $user);
        return [$audit, ['success' => true, 'status' => lock_status($audit)]];
    });
}

function release_audit_lock(string $audit_id, array $user, bool $force = false): bool
{
    $result = atomic_audit_update($audit_id, function (array $audit) use ($user, $force) {
        $status = lock_status($audit);
        if ($status['locked'] && $status['by_id'] !== $user['id'] && !$force) {
            return [null, false];
        }
        $audit = clear_lock($audit);
        return [$audit, true];
    });
    return $result === true;
}

function renew_audit_lock(string $audit_id, array $user): bool
{
    $result = atomic_audit_update($audit_id, function (array $audit) use ($user) {
        $status = lock_status($audit);
        if (!$status['locked'] || $status['by_id'] !== $user['id']) {
            return [null, false];
        }
        $audit = apply_lock($audit, $user); // renouvelle locked_at
        return [$audit, true];
    });
    return $result === true;
}

// ─── Gestion des contributeurs, de la visibilité, du propriétaire ────────────
// Réservé au propriétaire de l'audit (ou à un admin) — vérifié via
// permission_can_manage(get_user_permission(...)) À L'INTÉRIEUR du verrou,
// pour ne jamais agir sur un état de permission qui aurait changé entre la
// lecture et l'écriture.

/**
 * @return array{success: bool, error?: string, contributors?: array}
 */
function add_audit_contributor(string $audit_id, string $contributor_id, array $acting_user): array
{
    return atomic_audit_update($audit_id, function (array $audit) use ($contributor_id, $acting_user) {
        $permission = get_user_permission($audit, $acting_user);
        if (!permission_can_manage($permission)) {
            return [null, ['success' => false, 'error' => 'Seul le propriétaire (ou un admin) peut gérer les contributeurs.']];
        }
        if (($audit['owner_id'] ?? null) === $contributor_id) {
            return [null, ['success' => false, 'error' => 'Le propriétaire est déjà contributeur de fait.']];
        }
        if (find_user_by_id($contributor_id) === null) {
            return [null, ['success' => false, 'error' => 'Utilisateur introuvable.']];
        }

        $contributors = $audit['contributors'] ?? [];
        if (!in_array($contributor_id, $contributors, true)) {
            $contributors[] = $contributor_id;
        }
        $audit['contributors'] = array_values($contributors);

        return [$audit, ['success' => true, 'contributors' => $audit['contributors']]];
    });
}

/**
 * @return array{success: bool, error?: string, contributors?: array}
 */
function remove_audit_contributor(string $audit_id, string $contributor_id, array $acting_user): array
{
    return atomic_audit_update($audit_id, function (array $audit) use ($contributor_id, $acting_user) {
        $permission = get_user_permission($audit, $acting_user);
        if (!permission_can_manage($permission)) {
            return [null, ['success' => false, 'error' => 'Seul le propriétaire (ou un admin) peut gérer les contributeurs.']];
        }

        $audit['contributors'] = array_values(array_filter(
            $audit['contributors'] ?? [],
            fn($id) => $id !== $contributor_id
        ));

        return [$audit, ['success' => true, 'contributors' => $audit['contributors']]];
    });
}

/**
 * @return array{success: bool, error?: string, visibility?: string}
 */
function set_audit_visibility(string $audit_id, string $visibility, array $acting_user): array
{
    if (!in_array($visibility, AUDIT_VISIBILITIES, true)) {
        return ['success' => false, 'error' => 'Visibilité invalide.'];
    }

    return atomic_audit_update($audit_id, function (array $audit) use ($visibility, $acting_user) {
        $permission = get_user_permission($audit, $acting_user);
        if (!permission_can_manage($permission)) {
            return [null, ['success' => false, 'error' => 'Seul le propriétaire (ou un admin) peut changer la visibilité.']];
        }

        $audit['visibility'] = $visibility;

        return [$audit, ['success' => true, 'visibility' => $visibility]];
    });
}

/**
 * Cède la propriété de l'audit à $new_owner_id. L'ancien propriétaire devient
 * automatiquement contributeur (il ne perd pas brutalement tout accès à un
 * audit sur lequel il travaillait) ; le nouveau propriétaire est retiré de
 * la liste des contributeurs s'il y figurait (redondant une fois propriétaire).
 *
 * @return array{success: bool, error?: string, owner_id?: string, contributors?: array}
 */
function transfer_audit_owner(string $audit_id, string $new_owner_id, array $acting_user): array
{
    if (find_user_by_id($new_owner_id) === null) {
        return ['success' => false, 'error' => 'Utilisateur introuvable.'];
    }

    return atomic_audit_update($audit_id, function (array $audit) use ($new_owner_id, $acting_user) {
        $permission = get_user_permission($audit, $acting_user);
        if (!permission_can_manage($permission)) {
            return [null, ['success' => false, 'error' => 'Seul le propriétaire (ou un admin) peut céder la propriété.']];
        }

        $oldOwnerId = $audit['owner_id'] ?? null;
        if ($oldOwnerId === $new_owner_id) {
            return [null, ['success' => false, 'error' => 'Cette personne est déjà propriétaire.']];
        }

        $audit['owner_id'] = $new_owner_id;

        $contributors = $audit['contributors'] ?? [];
        if ($oldOwnerId !== null && !in_array($oldOwnerId, $contributors, true)) {
            $contributors[] = $oldOwnerId;
        }
        $contributors = array_values(array_filter($contributors, fn($id) => $id !== $new_owner_id));
        $audit['contributors'] = $contributors;

        return [$audit, ['success' => true, 'owner_id' => $new_owner_id, 'contributors' => $contributors]];
    });
}

// ─── Implication d'un utilisateur dans les audits (pour la suppression d'un compte) ─

/**
 * Recense, SANS RIEN MODIFIER, les audits dont $user_id est propriétaire et
 * ceux pour lesquels il/elle est contributeur — utilisé pour l'écran de
 * confirmation avant suppression d'un compte.
 *
 * @return array{owned: array, contributing: array}
 */
function find_user_audit_involvement(string $user_id): array
{
    $owned        = [];
    $contributing = [];

    foreach (list_audit_files() as $file) {
        $audit = json_decode(file_get_contents($file), true);
        if (!is_array($audit)) {
            continue;
        }

        if (($audit['owner_id'] ?? null) === $user_id) {
            $owned[] = $audit;
            continue;
        }

        if (in_array($user_id, $audit['contributors'] ?? [], true)) {
            $contributing[] = $audit;
        }
    }

    return ['owned' => $owned, 'contributing' => $contributing];
}

/**
 * Réassigne à $new_owner_id (ou supprime si null) tous les audits dont
 * $user_id est propriétaire, et retire $user_id de la liste des
 * contributeurs de tous les audits appartenant à d'autres personnes. Appelé
 * lors de la suppression d'un compte (bin/delete_user.php ou user_delete.php).
 *
 * @return array{reassigned: int, deleted: int, contributions_cleaned: int, errors: string[]}
 */
function reassign_or_delete_owned_audits(string $user_id, ?string $new_owner_id): array
{
    $reassigned           = 0;
    $deleted              = 0;
    $contributionsCleaned = 0;
    $errors               = [];

    foreach (list_audit_files() as $file) {
        $audit = json_decode(file_get_contents($file), true);
        if (!is_array($audit) || empty($audit['id'])) {
            continue;
        }

        $auditId = $audit['id'];
        $isOwner = ($audit['owner_id'] ?? null) === $user_id;

        if ($isOwner) {
            if ($new_owner_id !== null) {
                $result = atomic_audit_update($auditId, function (array $a) use ($user_id, $new_owner_id) {
                    $a['owner_id'] = $new_owner_id;
                    $a['contributors'] = array_values(array_filter(
                        $a['contributors'] ?? [],
                        fn($id) => $id !== $user_id && $id !== $new_owner_id
                    ));
                    return [$a, true];
                });
                if ($result === true) {
                    $reassigned++;
                } else {
                    $errors[] = "Échec de réécriture : {$file}";
                }
            } else {
                if (unlink($file)) {
                    $deleted++;
                } else {
                    $errors[] = "Échec de suppression : {$file}";
                }
            }
            continue; // un audit possédé n'a pas aussi besoin du nettoyage "contributeur" ci-dessous
        }

        if (in_array($user_id, $audit['contributors'] ?? [], true)) {
            $result = atomic_audit_update($auditId, function (array $a) use ($user_id) {
                $a['contributors'] = array_values(array_filter(
                    $a['contributors'] ?? [],
                    fn($id) => $id !== $user_id
                ));
                return [$a, true];
            });
            if ($result === true) {
                $contributionsCleaned++;
            } else {
                $errors[] = "Échec de mise à jour des contributeurs : {$file}";
            }
        }
    }

    return [
        'reassigned'             => $reassigned,
        'deleted'                => $deleted,
        'contributions_cleaned'  => $contributionsCleaned,
        'errors'                 => $errors,
    ];
}
