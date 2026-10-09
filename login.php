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
 * RGESN V2 2024 Audit Tool — Connexion
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Déjà connecté ? On repart directement vers l'accueil (ou la page demandée).
if (is_logged_in()) {
    $target = trim($_GET['redirect'] ?? 'index.php');
    header('Location: ' . (($target !== '' && !str_starts_with($target, '//') && !preg_match('#^https?://#i', $target)) ? $target : 'index.php'));
    exit;
}

$error = '';
$redirect = trim($_REQUEST['redirect'] ?? 'index.php');
if ($redirect === '' || str_starts_with($redirect, '//') || preg_match('#^https?://#i', $redirect)) {
    $redirect = 'index.php'; // on ne redirige jamais vers un domaine externe
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        // Jeton absent/expiré (formulaire ouvert depuis trop longtemps, ou
        // requête forgée depuis un autre site) : on ne tente même pas la
        // vérification des identifiants.
        $error = 'Votre session a expiré. Merci de réessayer.';
    } else {
        $rate = is_login_rate_limited($login);

        if ($rate['blocked']) {
            $minutes = (int) ceil($rate['retry_after'] / 60);
            $error = "Trop de tentatives échouées. Réessayez dans environ {$minutes} minute" . ($minutes > 1 ? 's' : '') . '.';
        } else {
            $user = ($login !== '') ? find_user_by_login($login) : null;

            if ($user !== null && password_verify($password, $user['password_hash'] ?? '')) {
                // Ré-encode le hash si les paramètres recommandés par PHP ont changé,
                // sans que l'utilisateur ait à faire quoi que ce soit.
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    update_user_password_hash($user['id'], password_hash($password, PASSWORD_DEFAULT));
                }

                clear_login_attempts($login);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];

                header('Location: ' . $redirect);
                exit;
            }

            record_failed_login_attempt($login);
            $error = 'Identifiant ou mot de passe incorrect.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — Outil d'audit RGESN V2 2024</title>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

<div class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card shadow-sm" style="max-width:400px;width:100%;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <img src="assets/images/logo.svg" alt="" height="40" class="mb-2">
                <h1 class="h5 fw-bold mb-0">Outil d'audit RGESN</h1>
                <p class="text-muted small">Connexion requise</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2 small" role="alert"><?= esc($error) ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" novalidate>
                <input type="hidden" name="redirect" value="<?= esc($redirect) ?>">
                <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">

                <div class="mb-3">
                    <label for="login" class="form-label small fw-semibold">Identifiant</label>
                    <input type="text" class="form-control" id="login" name="login" required autofocus autocomplete="username">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label small fw-semibold">Mot de passe</label>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-indigo w-100">Se connecter</button>
            <div class="text-center mb-4">
                <img src="assets/images/logo.svg" alt="" height="40" class="mb-2">
                <h1 class="h5 fw-bold mb-0">Outil d'audit RGESN</h1>
                <p class="text-muted small">Connexion requise</p>
            </div>
            </form>
        </div>
    </div>
</div>

<footer class="text-center py-3">
    <a href="legal.php" class="text-muted small">Mentions légales & confidentialité</a>
</footer>

</body>
</html>
