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
 * RGESN V2 2024 Audit Tool — Mon compte (changement de mot de passe personnel)
 *
 * Accessible à tout utilisateur connecté (pas réservé aux admins).
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$current_user = require_login_page();

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Votre session a expiré. Rechargez la page et réessayez.';
    } else {
        $current_password = (string) ($_POST['current_password'] ?? '');
        $new_password      = (string) ($_POST['new_password'] ?? '');
        $confirm_password  = (string) ($_POST['confirm_password'] ?? '');

        if ($new_password !== $confirm_password) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } else {
            $result = change_own_password($current_user, $current_password, $new_password);
            if ($result['success']) {
                $success = true;
            } else {
                $error = $result['error'] ?? 'Une erreur est survenue.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon compte — Outil d'audit RGESN V2 2024</title>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

<header class="bg-white border-bottom">
    <div class="container-fluid px-4 py-3 d-flex align-items-center justify-content-between" style="max-width:1400px;margin:0 auto;">
        <a href="index.php" class="d-flex align-items-center text-decoration-none">
            <img src="assets/images/logo.svg" alt="" height="32" class="me-2">
            <span class="fw-bold text-dark">Outil d'audit RGESN</span>
        </a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Retour
        </a>
    </div>
</header>

<main class="container py-5" style="max-width:500px;">

    <h1 class="h4 fw-bold mb-4">Mon compte</h1>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <dl class="row mb-0">
                <dt class="col-4 text-muted">Identifiant</dt>
                <dd class="col-8"><?= esc($current_user['login']) ?></dd>
                <dt class="col-4 text-muted">Nom affiché</dt>
                <dd class="col-8"><?= esc($current_user['name']) ?></dd>
                <dt class="col-4 text-muted">Rôle</dt>
                <dd class="col-8"><?= $current_user['role'] === 'admin' ? 'Admin' : 'Auditeur' ?></dd>
            </dl>
        </div>
    </div>

    <h2 class="h5 fw-semibold mb-3">Changer mon mot de passe</h2>

    <?php if ($success): ?>
        <div class="alert alert-success">Mot de passe modifié avec succès.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= esc($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">

                <div class="mb-3">
                    <label for="current_password" class="form-label fw-medium">Mot de passe actuel</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                </div>

                <div class="mb-3">
                    <label for="new_password" class="form-label fw-medium">Nouveau mot de passe</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                    <div class="form-text">8 caractères minimum.</div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label fw-medium">Confirmer le nouveau mot de passe</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-indigo w-100">Mettre à jour</button>
            </form>
        </div>
    </div>

</main>

</body>
</html>
