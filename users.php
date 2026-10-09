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
 * RGESN V2 2024 Audit Tool — Liste des utilisateurs (admin uniquement)
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$current_user = require_admin_page();
$users = load_users();
usort($users, fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

$flash = $_GET['flash'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
    <title>Utilisateurs — Outil d'audit RGESN V2 2024</title>
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
        <div class="d-flex align-items-center gap-2">
            <a href="account.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-person me-1" aria-hidden="true"></i>Mon compte
            </a>
            <span class="text-muted small ms-2 d-none d-md-inline"><?= esc($current_user['name']) ?></span>
            <a href="logout.php" class="btn btn-outline-secondary btn-sm" title="Se déconnecter" aria-label="Se déconnecter">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</header>

<main class="container-fluid px-4 py-4" style="max-width:1000px;margin:0 auto;">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h4 fw-bold mb-0">Utilisateurs</h1>
        <a href="user_form.php" class="btn btn-indigo">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Créer un utilisateur
        </a>
    </div>

    <?php if ($flash === 'created'): ?>
        <div class="alert alert-success">Utilisateur créé avec succès.</div>
    <?php elseif ($flash === 'updated'): ?>
        <div class="alert alert-success">Utilisateur modifié avec succès.</div>
    <?php elseif ($flash === 'deleted'): ?>
        <div class="alert alert-success">Utilisateur supprimé avec succès.</div>
    <?php elseif ($flash === 'deleted_reassigned'): ?>
        <?php $n = (int) ($_GET['reassigned_count'] ?? 0); ?>
        <div class="alert alert-success">
            Utilisateur supprimé avec succès. <?= $n ?> audit(s) vous <?= $n > 1 ? 'ont été réassignés' : 'a été réassigné' ?> —
            pensez à les réattribuer si besoin depuis la gestion des audits.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">Nom</th>
                        <th>Identifiant</th>
                        <th>Rôle</th>
                        <th>Statut</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php $lock = lock_status($u); ?>
                        <tr>
                            <td class="ps-4"><?= esc($u['name'] ?? '') ?></td>
                            <td class="text-muted"><?= esc($u['login'] ?? '') ?></td>
                            <td>
                                <?php if (($u['role'] ?? '') === 'admin'): ?>
                                    <span class="badge bg-indigo">Admin</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Auditeur</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lock['locked']): ?>
                                    <span class="badge bg-warning text-dark" title="Verrouillé pour édition">
                                        <i class="bi bi-lock me-1" aria-hidden="true"></i>Édition par <?= esc($lock['by_name'] ?? '?') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="user_form.php?id=<?= esc($u['id']) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Modifier <?= esc($u['name'] ?? '') ?>">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </a>
                                <?php if ($u['id'] !== $current_user['id']): ?>
                                    <a href="user_delete.php?id=<?= esc($u['id']) ?>" class="btn btn-sm btn-outline-danger" aria-label="Supprimer <?= esc($u['name'] ?? '') ?>">
                                        <i class="bi bi-trash3" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Aucun utilisateur.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<footer class="text-center py-3">
    <a href="legal.php" class="text-muted small">Mentions légales & confidentialité</a>
</footer>

</body>
</html>
