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
 * RGESN V2 2024 Audit Tool — Créer ou modifier un utilisateur (admin uniquement)
 *
 * Sans ?id= : création. Avec ?id= : édition, avec acquisition du verrou
 * sémantique (voir includes/auth.php) — si quelqu'un d'autre a déjà la main
 * dessus, la page s'affiche en lecture seule.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$current_user = require_admin_page();

$user_id   = trim($_GET['id'] ?? $_POST['id'] ?? '');
$is_edit   = $user_id !== '';
$target    = null;
$readonly  = false;
$lock_info = null;
$error     = '';

if ($is_edit) {
    $target = find_user_by_id($user_id);
    if ($target === null) {
        header('Location: users.php');
        exit;
    }
}

// ── Traitement du POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Votre session a expiré. Rechargez la page et réessayez.';
    } elseif ($_POST['cancel'] ?? false) {
        if ($is_edit) {
            release_user_lock($user_id, $current_user);
        }
        header('Location: users.php');
        exit;
    } else {
        $name     = trim($_POST['name'] ?? '');
        $role     = $_POST['role'] ?? 'auditeur';
        $password = (string) ($_POST['password'] ?? '');

        if ($is_edit) {
            // On ne peut valider que si on détient toujours le verrou : il a
            // pu expirer entre l'ouverture du formulaire et la soumission.
            $current_lock = lock_status(find_user_by_id($user_id) ?? []);
            if ($current_lock['locked'] && $current_lock['by_id'] !== $current_user['id']) {
                $error = "Cet utilisateur est en cours de modification par {$current_lock['by_name']}. Réessayez dans quelques instants.";
            } else {
                $changes = ['name' => $name, 'role' => $role];
                if ($password !== '') {
                    $changes['password'] = $password;
                }
                $result = update_user_record($user_id, $changes, $current_user);
                if ($result['success']) {
                    release_user_lock($user_id, $current_user);
                    header('Location: users.php?flash=updated');
                    exit;
                }
                $error = $result['error'] ?? 'Une erreur est survenue.';
            }
        } else {
            $login = trim($_POST['login'] ?? '');
            $result = create_user_record($login, $name, $role, $password);
            if ($result['success']) {
                header('Location: users.php?flash=created');
                exit;
            }
            $error = $result['error'] ?? 'Une erreur est survenue.';
        }
    }

    // En cas d'erreur, on réaffiche le formulaire avec les valeurs saisies.
    if ($is_edit) {
        $target = array_merge($target ?? [], [
            'name' => $_POST['name'] ?? ($target['name'] ?? ''),
            'role' => $_POST['role'] ?? ($target['role'] ?? 'auditeur'),
        ]);
    }
}

// ── Acquisition du verrou (affichage initial en édition) ──────────────────────
if ($is_edit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $lock_result = acquire_user_lock($user_id, $current_user);
    if (!$lock_result['success']) {
        $readonly  = true;
        $lock_info = $lock_result['status'];
        $target = find_user_by_id($user_id); // valeurs à jour, pas de verrou obtenu donc pas de risque à relire
    }
}

$page_title = $is_edit ? 'Modifier un utilisateur' : 'Créer un utilisateur';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <title><?= esc($page_title) ?> — Outil d'audit RGESN V2 2024</title>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

<header class="bg-white border-bottom">
    <div class="container-fluid px-4 py-3 d-flex align-items-center justify-content-between" style="max-width:1400px;margin:0 auto;">
        <a href="users.php" class="d-flex align-items-center text-decoration-none">
            <img src="assets/images/logo.svg" alt="" height="32" class="me-2">
            <span class="fw-bold text-dark">Outil d'audit RGESN</span>
        </a>
        <a href="users.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Retour à la liste
        </a>
    </div>
</header>

<main class="container py-5" style="max-width:600px;">

    <h1 class="h4 fw-bold mb-4"><?= esc($page_title) ?></h1>

    <?php if ($readonly): ?>
        <div class="alert alert-warning">
            <i class="bi bi-lock me-1" aria-hidden="true"></i>
            Cet utilisateur est actuellement en cours de modification. Vous ne pouvez le consulter qu'en lecture seule pour l'instant.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= esc($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="post" novalidate id="userForm">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                <?php if ($is_edit): ?>
                    <input type="hidden" name="id" value="<?= esc($user_id) ?>">
                <?php endif; ?>

                <?php if (!$is_edit): ?>
                    <div class="mb-3">
                        <label for="login" class="form-label fw-medium">Identifiant de connexion</label>
                        <input type="text" class="form-control" id="login" name="login" required
                               value="<?= esc($_POST['login'] ?? '') ?>" <?= $readonly ? 'disabled' : '' ?>>
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Identifiant de connexion</label>
                        <input type="text" class="form-control" value="<?= esc($target['login'] ?? '') ?>" disabled>
                        <div class="form-text">L'identifiant ne peut pas être modifié.</div>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="name" class="form-label fw-medium">Nom affiché</label>
                    <input type="text" class="form-control" id="name" name="name" required
                           value="<?= esc($target['name'] ?? ($_POST['name'] ?? '')) ?>" <?= $readonly ? 'disabled' : '' ?>>
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label fw-medium">Rôle</label>
                    <select class="form-select" id="role" name="role" <?= $readonly ? 'disabled' : '' ?>>
                        <?php $currentRole = $target['role'] ?? ($_POST['role'] ?? 'auditeur'); ?>
                        <option value="auditeur" <?= $currentRole === 'auditeur' ? 'selected' : '' ?>>Auditeur</option>
                        <option value="admin" <?= $currentRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                    <?php if ($is_edit && $user_id === $current_user['id']): ?>
                        <div class="form-text text-warning">
                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Vous ne pouvez pas vous rétrograder vous-même.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label fw-medium">
                        Mot de passe <?= $is_edit ? '(laisser vide pour ne pas le changer)' : '' ?>
                    </label>
                    <input type="password" class="form-control" id="password" name="password"
                           minlength="8" <?= $is_edit ? '' : 'required' ?> <?= $readonly ? 'disabled' : '' ?>
                           autocomplete="new-password">
                    <div class="form-text">8 caractères minimum.</div>
                </div>

                <?php if (!$readonly): ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-indigo flex-grow-1"><?= $is_edit ? 'Enregistrer' : 'Créer' ?></button>
                        <button type="submit" name="cancel" value="1" class="btn btn-outline-secondary" formnovalidate>Annuler</button>
                    </div>
                <?php else: ?>
                    <a href="users.php" class="btn btn-outline-secondary w-100">Retour</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

</main>

</body>
<?php if ($is_edit && !$readonly): ?>
<script>
// Renouvelle le verrou périodiquement tant que la page reste ouverte
// (heartbeat), et le libère à la fermeture de l'onglet.
(function () {
    'use strict';
    const USER_ID = <?= json_encode($user_id) ?>;
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
    const RENEW_INTERVAL_MS = 2 * 60 * 1000; // 2 minutes (verrou valide 10 min)

    setInterval(() => {
        fetch('user_lock.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: `action=renew&user_id=${encodeURIComponent(USER_ID)}`,
        }).catch(() => {}); // une panne ponctuelle du heartbeat n'est pas bloquante
    }, RENEW_INTERVAL_MS);

    window.addEventListener('pagehide', () => {
        // sendBeacon ne permet pas d'en-tête personnalisé : le jeton passe
        // dans le corps de la requête pour cette action précise.
        const data = new Blob(
            [`action=release&user_id=${encodeURIComponent(USER_ID)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`],
            { type: 'application/x-www-form-urlencoded' }
        );
        navigator.sendBeacon('user_lock.php', data);
    });
})();
</script>
<?php endif; ?>
</html>
