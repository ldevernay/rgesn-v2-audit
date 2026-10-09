#!/usr/bin/env php
<?php
/**
 * Tests d'intégration HTTP réels — users.php, user_form.php, user_delete.php,
 * account.php, user_lock.php.
 *
 * Même approche que tests/cases/http_cache_headers_test.php : serveur PHP
 * intégré démarré sur une copie isolée de l'application (jamais les vraies
 * données), requêtes via curl.
 */

require_once __DIR__ . '/../TestRunner.php';

$projectRoot = realpath(__DIR__ . '/../..');
$fixtureRoot = __DIR__ . '/../tmp/http_fixture_users';

shell_exec('rm -rf ' . escapeshellarg($fixtureRoot));
mkdir($fixtureRoot, 0755, true);
shell_exec(
    'tar --exclude=tests --exclude=.git -cf - -C ' . escapeshellarg($projectRoot) . ' . '
    . '| tar xf - -C ' . escapeshellarg($fixtureRoot)
);

if (!file_exists($fixtureRoot . '/api.php')) {
    fwrite(STDERR, "Échec de la copie de fixture : api.php introuvable dans {$fixtureRoot}\n");
    exit(1);
}

// ─── Fixtures : deux admins, un auditeur ─────────────────────────────────────

$adminPassword    = 'admin-password-123';
$auditeurPassword = 'auditeur-password-123';

$adminId1  = 'http-admin-1';
$adminId2  = 'http-admin-2';
$auditeurId = 'http-auditeur-1';

function write_users_fixture(string $fixtureRoot, array $users): void
{
    file_put_contents($fixtureRoot . '/data/users.json', json_encode($users, JSON_PRETTY_PRINT));
}

write_users_fixture($fixtureRoot, [
    ['id' => $adminId1, 'login' => 'admin1', 'name' => 'Admin Un', 'role' => 'admin',
     'password_hash' => password_hash($GLOBALS['adminPassword'], PASSWORD_DEFAULT),
     'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
    ['id' => $adminId2, 'login' => 'admin2', 'name' => 'Admin Deux', 'role' => 'admin',
     'password_hash' => password_hash($GLOBALS['adminPassword'], PASSWORD_DEFAULT),
     'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
    ['id' => $auditeurId, 'login' => 'auditeur1', 'name' => 'Auditeur Un', 'role' => 'auditeur',
     'password_hash' => password_hash($GLOBALS['auditeurPassword'], PASSWORD_DEFAULT),
     'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
]);

@mkdir($fixtureRoot . '/data/audits', 0755, true);
foreach (glob($fixtureRoot . '/data/audits/*.json') as $f) {
    unlink($f);
}

// ─── Démarrage du serveur intégré PHP ─────────────────────────────────────────

$port = 8950 + (getmypid() % 300);
$base = "http://127.0.0.1:{$port}";

$serverProcess = proc_open(
    'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($fixtureRoot),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

$ready = false;
for ($i = 0; $i < 30; $i++) {
    usleep(100000);
    if (@file_get_contents($base . '/login.php') !== false) {
        $ready = true;
        break;
    }
}
if (!$ready) {
    fwrite(STDERR, "Le serveur PHP intégré n'a pas démarré à temps sur le port {$port}.\n");
    proc_terminate($serverProcess);
    exit(1);
}

// ─── Helpers HTTP ─────────────────────────────────────────────────────────────

function extract_csrf_token(string $html): ?string
{
    if (preg_match('/name="csrf-token"\s+content="([^"]*)"/', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    if (preg_match('/name="csrf_token"\s+value="([^"]*)"/', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }
    return null;
}

function login_via_curl(string $base, string $login, string $password, string $cookieJar): string
{
    $html = (string) shell_exec('curl -s -c ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg("{$base}/login.php"));
    $token = extract_csrf_token($html) ?? '';
    shell_exec(
        'curl -s -b ' . escapeshellarg($cookieJar) . ' -c ' . escapeshellarg($cookieJar)
        . ' -d ' . escapeshellarg('login=' . $login . '&password=' . $password . '&csrf_token=' . $token)
        . ' -o /dev/null -D - ' . escapeshellarg("{$base}/login.php")
    );
    return $cookieJar;
}

/** @return array{status:int, body:string, headers: array<string,string>} */
function http_request(string $method, string $url, string $cookieJar, array $post = []): array
{
    $cmd = 'curl -s -D - ';
    $cmd .= '-b ' . escapeshellarg($cookieJar) . ' -c ' . escapeshellarg($cookieJar) . ' ';
    if ($method === 'POST') {
        $cmd .= '-X POST ';
        foreach ($post as $k => $v) {
            $cmd .= '--data-urlencode ' . escapeshellarg("{$k}={$v}") . ' ';
        }
    }
    $cmd .= escapeshellarg($url);

    $raw = (string) shell_exec($cmd);
    // Sépare l'en-tête (première partie HTTP) du corps.
    $parts = preg_split('/\r\n\r\n/', $raw, 2);
    $headerBlock = $parts[0] ?? '';
    $body = $parts[1] ?? '';

    $lines = explode("\r\n", trim($headerBlock));
    $statusLine = $lines[0] ?? '';
    preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m);
    $status = isset($m[1]) ? (int) $m[1] : 0;

    $headers = [];
    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers];
}

try {
    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — contrôle d\'accès admin sur users.php');
    // ═════════════════════════════════════════════════════════════════════

    $r = http_request('GET', "{$base}/users.php", tempnam(sys_get_temp_dir(), 'rgesn_anon_'));
    T::assertTrue(in_array($r['status'], [301, 302, 303, 307, 308], true), 'Un visiteur anonyme est redirigé (pas d\'accès direct à users.php)');
    T::assertTrue(
        str_contains($r['headers']['location'] ?? '', 'login.php'),
        'La redirection anonyme pointe vers login.php'
    );

    $auditeurCookies = login_via_curl($base, 'auditeur1', $auditeurPassword, tempnam(sys_get_temp_dir(), 'rgesn_aud_'));
    $r = http_request('GET', "{$base}/users.php", $auditeurCookies);
    T::assertTrue(in_array($r['status'], [301, 302, 303, 307, 308], true), 'Un utilisateur non-admin connecté est redirigé (pas d\'accès à users.php)');
    T::assertFalse(str_contains($r['headers']['location'] ?? '', 'login.php'), 'La redirection non-admin ne pointe PAS vers login.php (il est bien connecté, juste pas autorisé)');

    $admin1Cookies = login_via_curl($base, 'admin1', $adminPassword, tempnam(sys_get_temp_dir(), 'rgesn_admin1_'));
    $r = http_request('GET', "{$base}/users.php", $admin1Cookies);
    T::assertSame(200, $r['status'], 'Un admin connecté accède bien à users.php (HTTP 200)');
    T::assertTrue(str_contains($r['body'], 'Admin Un'), 'La page liste bien les utilisateurs existants');

    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — création d\'un utilisateur (CSRF + validation)');
    // ═════════════════════════════════════════════════════════════════════

    $formHtml = (string) shell_exec('curl -s -b ' . escapeshellarg($admin1Cookies) . ' ' . escapeshellarg("{$base}/user_form.php"));
    $csrfToken = extract_csrf_token($formHtml);
    T::assertTrue($csrfToken !== null && $csrfToken !== '', 'Le formulaire de création contient bien un jeton CSRF');

    // Sans jeton CSRF : refusé
    $r = http_request('POST', "{$base}/user_form.php", $admin1Cookies, [
        'login' => 'nouveluser', 'name' => 'Nouvel Utilisateur', 'role' => 'auditeur', 'password' => 'motdepasse123',
    ]);
    T::assertFalse(
        str_contains($r['headers']['location'] ?? '', 'flash=created'),
        'La création sans jeton CSRF valide n\'aboutit pas (pas de redirection de succès)'
    );

    // Avec le bon jeton : accepté
    $r = http_request('POST', "{$base}/user_form.php", $admin1Cookies, [
        'login' => 'nouveluser', 'name' => 'Nouvel Utilisateur', 'role' => 'auditeur',
        'password' => 'motdepasse123', 'csrf_token' => $csrfToken,
    ]);
    T::assertTrue(
        str_contains($r['headers']['location'] ?? '', 'flash=created'),
        'La création avec un jeton CSRF valide réussit (redirection flash=created)'
    );

    $users = json_decode(file_get_contents($fixtureRoot . '/data/users.json'), true);
    T::assertTrue(
        in_array('nouveluser', array_column($users, 'login'), true),
        'Le nouvel utilisateur apparaît bien dans data/users.json'
    );

    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — verrou sémantique entre deux sessions admin');
    // ═════════════════════════════════════════════════════════════════════

    $admin2Cookies = login_via_curl($base, 'admin2', $adminPassword, tempnam(sys_get_temp_dir(), 'rgesn_admin2_'));

    // admin1 ouvre l'édition de l'auditeur : verrouille l'enregistrement.
    $r1 = http_request('GET', "{$base}/user_form.php?id={$auditeurId}", $admin1Cookies);
    T::assertSame(200, $r1['status'], 'admin1 accède à l\'édition (HTTP 200)');
    T::assertFalse(str_contains($r1['body'], 'en cours de modification par'), 'admin1 (premier arrivé) ne voit PAS de bannière de verrou');

    // admin2 tente d'éditer le MÊME enregistrement : doit voir la bannière lecture seule.
    $r2 = http_request('GET', "{$base}/user_form.php?id={$auditeurId}", $admin2Cookies);
    T::assertSame(200, $r2['status'], 'admin2 accède à la page (HTTP 200, mais en lecture seule)');
    T::assertTrue(
        str_contains($r2['body'], 'en cours de modification par') && str_contains($r2['body'], 'Admin Un'),
        'admin2 voit bien la bannière indiquant que admin1 a la main'
    );

    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — garde-fous dernier admin / auto-suppression');
    // ═════════════════════════════════════════════════════════════════════

    // On isole ce scénario avec un seul admin restant (admin2), pour tester
    // le refus d'auto-rétrogradation ET de dernier-admin sans interférence.
    write_users_fixture($fixtureRoot, [
        ['id' => $adminId2, 'login' => 'admin2', 'name' => 'Admin Deux', 'role' => 'admin',
         'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
         'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
        ['id' => $auditeurId, 'login' => 'auditeur1', 'name' => 'Auditeur Un', 'role' => 'auditeur',
         'password_hash' => password_hash($auditeurPassword, PASSWORD_DEFAULT),
         'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
    ]);

    $formHtml = (string) shell_exec('curl -s -b ' . escapeshellarg($admin2Cookies) . ' ' . escapeshellarg("{$base}/user_form.php?id={$adminId2}"));
    $csrfToken2 = extract_csrf_token($formHtml);

    $r = http_request('POST', "{$base}/user_form.php", $admin2Cookies, [
        'id' => $adminId2, 'name' => 'Admin Deux', 'role' => 'auditeur', 'password' => '', 'csrf_token' => $csrfToken2,
    ]);
    T::assertTrue(
        str_contains($r['body'], 'rétrograder') || str_contains($r['body'], 'dernier'),
        'La tentative d\'auto-rétrogradation (seul admin restant) affiche un message d\'erreur explicite'
    );
    $stillAdmin = json_decode(file_get_contents($fixtureRoot . '/data/users.json'), true);
    $adminEntry = current(array_filter($stillAdmin, fn($u) => $u['id'] === $adminId2));
    T::assertSame('admin', $adminEntry['role'] ?? null, 'Le rôle n\'a pas changé malgré la tentative');

    // Auto-suppression : redirection silencieuse vers users.php (pas d'action).
    $r = http_request('GET', "{$base}/user_delete.php?id={$adminId2}", $admin2Cookies);
    T::assertTrue(in_array($r['status'], [301, 302, 303, 307, 308], true), 'Tenter de se supprimer soi-même redirige sans rien faire');
    $stillThere = json_decode(file_get_contents($fixtureRoot . '/data/users.json'), true);
    T::assertTrue(in_array($adminId2, array_column($stillThere, 'id'), true), 'Le compte admin existe toujours après la tentative d\'auto-suppression');

    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — user_delete.php : réassignation automatique des audits possédés');
    // ═════════════════════════════════════════════════════════════════════

    // On réécrit explicitement l'état complet attendu (admin1 + admin2 +
    // auditeur1 + le compte dédié à ce scénario) : une section précédente a
    // pu réduire users.json à un sous-ensemble (ex. "garde-fous dernier
    // admin" ne garde que admin2+auditeur1), on ne peut pas supposer que
    // ce qui restait en place avant cette section est le bon point de départ.
    $ownerToDeleteId = 'http-owner-to-delete';
    write_users_fixture($fixtureRoot, [
        ['id' => $adminId1, 'login' => 'admin1', 'name' => 'Admin Un', 'role' => 'admin',
         'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
         'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
        ['id' => $adminId2, 'login' => 'admin2', 'name' => 'Admin Deux', 'role' => 'admin',
         'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
         'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
        ['id' => $auditeurId, 'login' => 'auditeur1', 'name' => 'Auditeur Un', 'role' => 'auditeur',
         'password_hash' => password_hash($auditeurPassword, PASSWORD_DEFAULT),
         'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
        ['id' => $ownerToDeleteId, 'login' => 'owner-a-supprimer', 'name' => 'Propriétaire À Supprimer', 'role' => 'auditeur',
         'password_hash' => password_hash('peu-importe-1234', PASSWORD_DEFAULT),
         'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
    ]);

    $ownedAuditId = '550e8400-e29b-41d4-a716-446655440201';
    file_put_contents($fixtureRoot . "/data/audits/{$ownedAuditId}.json", json_encode([
        'id' => $ownedAuditId, 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
        'status' => 'en cours', 'score' => 0.0, 'owner_id' => $ownerToDeleteId, 'contributors' => [], 'visibility' => 'contributors',
        'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null,
        'project' => ['name' => 'Audit à réassigner', 'url' => ''], 'auditor' => ['name' => 'Propriétaire À Supprimer'], 'criteria' => [],
    ]));

    $admin1CookiesFresh = login_via_curl($base, 'admin1', $adminPassword, tempnam(sys_get_temp_dir(), 'rgesn_admin1b_'));

    // La page de confirmation ne propose plus AUCUN choix de réassignation :
    // juste un message informatif.
    $confirmHtml = (string) shell_exec('curl -s -b ' . escapeshellarg($admin1CookiesFresh) . ' ' . escapeshellarg("{$base}/user_delete.php?id={$ownerToDeleteId}"));
    T::assertFalse(str_contains($confirmHtml, 'name="new_owner_id"'), 'La page de confirmation ne propose plus de choisir un destinataire');
    T::assertTrue(str_contains($confirmHtml, 'Admin Un'), 'Le message informatif mentionne bien l\'admin qui effectue la suppression');

    $deleteCsrf = extract_csrf_token($confirmHtml);
    $r = http_request('POST', "{$base}/user_delete.php", $admin1CookiesFresh, [
        'id' => $ownerToDeleteId, 'csrf_token' => $deleteCsrf,
    ]);
    T::assertTrue(
        str_contains($r['headers']['location'] ?? '', 'deleted_reassigned'),
        'La suppression redirige avec le flash "deleted_reassigned"'
    );

    $auditAfter = json_decode(file_get_contents($fixtureRoot . "/data/audits/{$ownedAuditId}.json"), true);
    T::assertSame(
        $adminId1,
        $auditAfter['owner_id'] ?? null,
        'L\'audit a bien été réassigné automatiquement à admin1 (celui qui a supprimé le compte), pas laissé au choix'
    );

    $usersAfter = json_decode(file_get_contents($fixtureRoot . '/data/users.json'), true);
    T::assertFalse(
        in_array($ownerToDeleteId, array_column($usersAfter, 'id'), true),
        'Le compte supprimé n\'existe plus'
    );
    T::assertTrue(
        in_array($auditeurId, array_column($usersAfter, 'id'), true),
        'auditeur1 (sans rapport avec ce scénario) existe toujours, pour les sections suivantes'
    );

    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — account.php (changement de mot de passe personnel)');
    // ═════════════════════════════════════════════════════════════════════

    $auditeurCookies2 = login_via_curl($base, 'auditeur1', $auditeurPassword, tempnam(sys_get_temp_dir(), 'rgesn_aud2_'));
    $accountHtml = (string) shell_exec('curl -s -b ' . escapeshellarg($auditeurCookies2) . ' ' . escapeshellarg("{$base}/account.php"));
    $accountCsrf = extract_csrf_token($accountHtml);

    $r = http_request('POST', "{$base}/account.php", $auditeurCookies2, [
        'current_password' => 'mauvais-mot-de-passe',
        'new_password' => 'nouveaumotdepasse123',
        'confirm_password' => 'nouveaumotdepasse123',
        'csrf_token' => $accountCsrf,
    ]);
    T::assertTrue(str_contains($r['body'], 'incorrect'), 'Un mauvais mot de passe actuel est rejeté avec un message clair');

    $r = http_request('POST', "{$base}/account.php", $auditeurCookies2, [
        'current_password' => $auditeurPassword,
        'new_password' => 'nouveaumotdepasse123',
        'confirm_password' => 'nouveaumotdepasse123',
        'csrf_token' => $accountCsrf,
    ]);
    T::assertTrue(str_contains($r['body'], 'succès'), 'Le changement de mot de passe personnel réussit avec le bon mot de passe actuel');

    $updatedUsers = json_decode(file_get_contents($fixtureRoot . '/data/users.json'), true);
    $auditeurEntry = current(array_filter($updatedUsers, fn($u) => $u['id'] === $auditeurId));
    T::assertTrue(
        password_verify('nouveaumotdepasse123', $auditeurEntry['password_hash']),
        'Le nouveau mot de passe est bien celui appliqué en base'
    );

} finally {
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    shell_exec('rm -rf ' . escapeshellarg($fixtureRoot));
}

exit(T::summaryAndExitCode());
