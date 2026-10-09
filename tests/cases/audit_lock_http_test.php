#!/usr/bin/env php
<?php
/**
 * Tests d'intégration HTTP réels — verrou sémantique acquis au chargement de
 * audit.php (pas couvert par api_actions_test.php, qui appelle les
 * action_*() directement sans passer par le rendu de la page elle-même).
 */

require_once __DIR__ . '/../TestRunner.php';

$projectRoot = realpath(__DIR__ . '/../..');
$fixtureRoot = __DIR__ . '/../tmp/http_fixture_audit_lock';

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

$password = 'test-password-123';
$ownerId  = 'lock-owner-1';
$contribId = 'lock-contrib-1';

file_put_contents($fixtureRoot . '/data/users.json', json_encode([
    ['id' => $ownerId, 'login' => 'lockowner', 'name' => 'Propriétaire Verrou', 'role' => 'auditeur',
     'password_hash' => password_hash($password, PASSWORD_DEFAULT)],
    ['id' => $contribId, 'login' => 'lockcontrib', 'name' => 'Contributeur Verrou', 'role' => 'auditeur',
     'password_hash' => password_hash($password, PASSWORD_DEFAULT)],
], JSON_PRETTY_PRINT));

@mkdir($fixtureRoot . '/data/audits', 0755, true);
foreach (glob($fixtureRoot . '/data/audits/*.json') as $f) {
    unlink($f);
}

$auditId = '550e8400-e29b-41d4-a716-446655440401';
file_put_contents($fixtureRoot . "/data/audits/{$auditId}.json", json_encode([
    'id' => $auditId, 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
    'status' => 'en cours', 'score' => 0.0, 'owner_id' => $ownerId, 'contributors' => [$contribId],
    'visibility' => 'contributors', 'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null,
    'project' => ['name' => 'Audit verrou', 'url' => ''], 'auditor' => ['name' => 'Test'], 'criteria' => [],
], JSON_UNESCAPED_UNICODE));

$port = 9100 + (getmypid() % 300);
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

function login_via_curl(string $base, string $login, string $password, string $cookieJar): void
{
    $html = (string) shell_exec('curl -s -c ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg("{$base}/login.php"));
    $token = extract_csrf_token($html) ?? '';
    shell_exec(
        'curl -s -b ' . escapeshellarg($cookieJar) . ' -c ' . escapeshellarg($cookieJar)
        . ' -d ' . escapeshellarg('login=' . $login . '&password=' . $password . '&csrf_token=' . $token)
        . ' -o /dev/null -D - ' . escapeshellarg("{$base}/login.php")
    );
}

function http_get_body(string $url, string $cookieJar): string
{
    return (string) shell_exec('curl -s -b ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg($url));
}

/** @return array{status:int} */
function http_post_status(string $url, string $cookieJar, array $post): array
{
    $cmd = 'curl -s -o /dev/null -w "%{http_code}" -b ' . escapeshellarg($cookieJar) . ' -c ' . escapeshellarg($cookieJar) . ' ';
    foreach ($post as $k => $v) {
        $cmd .= '--data-urlencode ' . escapeshellarg("{$k}={$v}") . ' ';
    }
    $cmd .= escapeshellarg($url);
    return ['status' => (int) trim((string) shell_exec($cmd))];
}

try {
    T::section('HTTP réel — audit.php acquiert le verrou au chargement, pour qui peut éditer');

    $ownerCookies = tempnam(sys_get_temp_dir(), 'rgesn_lockowner_');
    login_via_curl($base, 'lockowner', $password, $ownerCookies);

    $page1 = http_get_body("{$base}/audit.php?id={$auditId}", $ownerCookies);
    T::assertFalse(
        str_contains($page1, 'en cours de modification par'),
        'Le premier arrivé (propriétaire) ne voit aucune bannière de verrou'
    );

    $auditOnDisk = json_decode(file_get_contents($fixtureRoot . "/data/audits/{$auditId}.json"), true);
    T::assertSame($ownerId, $auditOnDisk['locked_by'] ?? null, 'Le fichier audit est bien verrouillé au nom du propriétaire après le chargement de la page');

    T::section('HTTP réel — un second contributeur voit la page en lecture seule avec bannière');

    $contribCookies = tempnam(sys_get_temp_dir(), 'rgesn_lockcontrib_');
    login_via_curl($base, 'lockcontrib', $password, $contribCookies);

    $page2 = http_get_body("{$base}/audit.php?id={$auditId}", $contribCookies);
    T::assertTrue(
        str_contains($page2, 'en cours de modification par') && str_contains($page2, 'Propriétaire Verrou'),
        'Le second contributeur voit bien la bannière nommant le détenteur du verrou'
    );

    T::section('HTTP réel — update_audit refusé pendant que le verrou est détenu par quelqu\'un d\'autre');

    $csrfContrib = extract_csrf_token($page2);
    $r = http_post_status("{$base}/api.php?action=update_audit", $contribCookies, [
        'id' => $auditId, 'status' => 'terminé', 'csrf_token' => $csrfContrib,
    ]);
    T::assertSame(409, $r['status'], 'La tentative de modification par le second contributeur est bloquée (HTTP 409)');

    T::section('HTTP réel — libération du verrou puis reprise par un autre');

    $csrfOwner = extract_csrf_token($page1);
    $r = http_post_status("{$base}/api.php?action=release_audit_lock", $ownerCookies, [
        'id' => $auditId, 'csrf_token' => $csrfOwner,
    ]);
    T::assertSame(200, $r['status'], 'Le propriétaire peut libérer son propre verrou (HTTP 200)');

    $page3 = http_get_body("{$base}/audit.php?id={$auditId}", $contribCookies);
    T::assertFalse(
        str_contains($page3, 'en cours de modification par'),
        'Une fois le verrou libéré, le second contributeur peut maintenant éditer (plus de bannière)'
    );

    @unlink($ownerCookies);
    @unlink($contribCookies);
} finally {
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    shell_exec('rm -rf ' . escapeshellarg($fixtureRoot));
}

exit(T::summaryAndExitCode());
