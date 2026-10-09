#!/usr/bin/env php
<?php
/**
 * Tests unitaires — includes/auth.php : limitation des tentatives de connexion
 *
 * Fichier autonome, exécuté dans son propre processus PHP (voir tests/run.php).
 */

require_once __DIR__ . '/../TestRunner.php';

define('USERS_FILE', __DIR__ . '/../tmp/rate_limit_users.json'); // non utilisé ici mais requis par auth.php
define('LOGIN_ATTEMPTS_FILE', __DIR__ . '/../tmp/rate_limit_attempts.json');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

@unlink(LOGIN_ATTEMPTS_FILE);

T::section('auth.php — is_login_rate_limited() / record_failed_login_attempt()');

$login = 'alice';

$status = is_login_rate_limited($login);
T::assertFalse($status['blocked'], 'Aucune tentative enregistrée : pas de blocage');

for ($i = 0; $i < LOGIN_MAX_ATTEMPTS - 1; $i++) {
    record_failed_login_attempt($login);
}
$status = is_login_rate_limited($login);
T::assertFalse($status['blocked'], 'Juste en dessous du seuil (' . (LOGIN_MAX_ATTEMPTS - 1) . '/' . LOGIN_MAX_ATTEMPTS . ') : toujours pas bloqué');

record_failed_login_attempt($login); // atteint LOGIN_MAX_ATTEMPTS
$status = is_login_rate_limited($login);
T::assertTrue($status['blocked'], 'Seuil atteint (' . LOGIN_MAX_ATTEMPTS . ' échecs) : bloqué');
T::assertTrue($status['retry_after'] > 0 && $status['retry_after'] <= LOGIN_WINDOW_SECONDS, 'retry_after est une durée positive et cohérente avec la fenêtre');

T::section('auth.php — clear_login_attempts()');

clear_login_attempts($login);
$status = is_login_rate_limited($login);
T::assertFalse($status['blocked'], 'Après clear_login_attempts() (connexion réussie), le compteur est remis à zéro');

T::section('auth.php — isolation par login (une attaque sur un compte ne bloque pas les autres)');

for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) {
    record_failed_login_attempt('bob');
}
T::assertTrue(is_login_rate_limited('bob')['blocked'], 'bob est bloqué après ses propres échecs');
T::assertFalse(is_login_rate_limited('carla')['blocked'], 'carla n\'est pas affectée par les échecs de bob (même IP, login différent)');

T::section('auth.php — expiration de la fenêtre glissante');

// On simule le passage du temps en réécrivant directement le fichier avec des
// timestamps antérieurs à la fenêtre de 15 minutes.
$data = load_login_attempts();
$key  = rate_limit_key('denis');
$data[$key] = array_fill(0, LOGIN_MAX_ATTEMPTS, time() - LOGIN_WINDOW_SECONDS - 60); // tout expiré
save_login_attempts($data);

T::assertFalse(is_login_rate_limited('denis')['blocked'], 'Des tentatives hors fenêtre (expirées) ne bloquent plus');

// ─── Nettoyage ───────────────────────────────────────────────────────────────
@unlink(LOGIN_ATTEMPTS_FILE);
@unlink(USERS_FILE);

exit(T::summaryAndExitCode());
