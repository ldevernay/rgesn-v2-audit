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
 * RGESN V2 2024 Audit Tool — Suppression d'un utilisateur (admin uniquement)
 *
 * Les audits dont la personne supprimée est propriétaire sont automatiquement
 * réassignés à l'admin qui effectue la suppression (jamais supprimés avec le
 * compte) : c'est le choix le plus sûr par défaut, et le plus simple pour
 * l'opérateur — pas de décision à prendre dans l'urgence sur "à qui donner
 * quoi". Libre à cet admin de les réattribuer ensuite depuis la gestion des
 * audits.
 *
 * Les partages nominatifs (contributions) référençant le compte supprimé,
 * sur des audits appartenant à d'autres personnes, sont simplement retirés.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit_store.php';

$current_user = require_admin_page();

$user_id = trim($_GET['id'] ?? $_POST['id'] ?? '');
$target  = $user_id !== '' ? find_user_by_id($user_id) : null;

if ($target === null) {
    header('Location: users.php');
    exit;
}

if ($user_id === $current_user['id']) {
    // On ne se supprime jamais soi-même.
    header('Location: users.php');
    exit;
}

$all_users = load_users();

$involvement  = find_user_audit_involvement($user_id);
$owned_count  = count($involvement['owned']);
$shared_count = count($involvement['contributing']);

$error = '';

// Un admin qui serait le dernier ne peut pas être supprimé : on l'affiche
// clairement plutôt que de laisser remplir un formulaire pour rien
// (delete_user_record() referait de toute façon le même refus).
$is_last_admin = ($target['role'] ?? '') === 'admin'
    && count(array_filter($all_users, fn($u) => ($u['role'] ?? '') === 'admin')) <= 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_last_admin) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Votre session a expiré. Rechargez la page et réessayez.';
    } else {
        // Toujours réassigné à l'admin qui supprime — jamais de suppression
        // silencieuse des audits, jamais de choix à faire dans l'urgence.
        $audit_result = reassign_or_delete_owned_audits($user_id, $current_user['id']);

        if (!empty($audit_result['errors'])) {
            $error = 'Erreur lors du traitement des audits : ' . implode(' ', $audit_result['errors']);
        } else {
            $delete_result = delete_user_record($user_id, $current_user);
            if ($delete_result['success']) {
                $flash = $owned_count > 0 ? 'deleted_reassigned' : 'deleted';
                header('Location: users.php?flash=' . $flash . '&reassigned_count=' . $owned_count);
                exit;
            }
            $error = $delete_result['error'] ?? 'Une erreur est survenue.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supprimer un utilisateur — Outil d'audit RGESN V2 2024</title>
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

    <h1 class="h4 fw-bold mb-4">Supprimer un utilisateur</h1>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= esc($error) ?></div>
    <?php endif; ?>

    <?php if ($is_last_admin): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Impossible de supprimer <strong><?= esc($target['name']) ?></strong> : c'est le dernier compte admin.
            Créez ou promouvez un autre admin avant de pouvoir supprimer celui-ci.
        </div>
        <a href="users.php" class="btn btn-outline-secondary">Retour</a>
    <?php else: ?>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                <p>
                    Vous êtes sur le point de supprimer <strong><?= esc($target['name']) ?></strong>
                    (<?= esc($target['login']) ?>).
                </p>

                <?php if ($owned_count > 0): ?>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        <?= $owned_count ?> audit(s) dont il/elle est propriétaire vous
                        <?= $owned_count > 1 ? 'seront automatiquement réassignés' : 'sera automatiquement réassigné' ?>
                        (à <strong><?= esc($current_user['name']) ?></strong>). Vous pourrez ensuite les
                        réattribuer à qui vous voulez depuis la gestion des audits.
                    </div>
                <?php endif; ?>

                <?php if ($shared_count > 0): ?>
                    <p class="text-muted small">
                        Contributeur sur <?= $shared_count ?> audit(s) d'autres propriétaires (son accès y sera
                        simplement retiré, sans aucun effet sur le propriétaire ou les autres contributeurs).
                    </p>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= esc($user_id) ?>">

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-outline-danger flex-grow-1">
                            <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Confirmer la suppression
                        </button>
                        <a href="users.php" class="btn btn-outline-secondary">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

</main>

</body>
</html>
