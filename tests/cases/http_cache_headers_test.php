#!/usr/bin/env php
<?php
/**
 * Tests d'intégration — en-têtes Cache-Control réels sur get_audit/list_audits
 *
 * header() est un no-op silencieux sous le SAPI CLI (headers_list() renvoie
 * toujours un tableau vide), donc impossible à vérifier via un simple
 * sous-processus CLI comme pour les autres tests d'intégration. La seule
 * façon fiable de vérifier un en-tête HTTP réellement émis est de démarrer
 * le serveur intégré de PHP (`php -S`) et de faire de vraies requêtes HTTP
 * avec curl.
 *
 * Limite connue : les fichiers .htaccess (protection de data/, bin/) n'ont
 * aucun effet sous le serveur intégré de PHP (mécanisme propre à Apache).
 * Ce test ne couvre donc PAS cette protection — seulement les en-têtes
 * renvoyés par api.php lui-même.
 *
 * Toute l'application est copiée dans un dossier de fixtures isolé avant
 * chaque exécution : on ne démarre JAMAIS le serveur sur le vrai dossier du
 * projet, pour ne jamais risquer d'exposer ou de modifier les vraies données.
 */

require_once __DIR__ . '/../TestRunner.php';

$projectRoot = realpath(__DIR__ . '/../..');
$fixtureRoot = __DIR__ . '/../tmp/http_fixture';

// ─── Préparation d'une copie isolée de l'application ─────────────────────────

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

// ─── Fixtures : un utilisateur, un audit public, un audit privé ─────────────

$password = 'test-password-123';
$userId   = 'http-test-user';

file_put_contents($fixtureRoot . '/data/users.json', json_encode([
    ['id' => $userId, 'login' => 'httptest', 'name' => 'HTTP Test', 'role' => 'auditeur',
     'password_hash' => password_hash($password, PASSWORD_DEFAULT)],
], JSON_PRETTY_PRINT));

@mkdir($fixtureRoot . '/data/audits', 0755, true);
foreach (glob($fixtureRoot . '/data/audits/*.json') as $f) {
    unlink($f); // on repart d'une liste d'audits propre, sans l'audit de démo du dépôt
}

$publicAuditId  = '550e8400-e29b-41d4-a716-446655440010';
$privateAuditId = '550e8400-e29b-41d4-a716-446655440011';

file_put_contents($fixtureRoot . "/data/audits/{$publicAuditId}.json", json_encode([
    'id' => $publicAuditId, 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
    'status' => 'en cours', 'score' => 0.0, 'owner_id' => $userId, 'contributors' => [], 'visibility' => 'public',
    'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null,
    'project' => ['name' => 'Public', 'url' => ''], 'auditor' => ['name' => 'Test'], 'criteria' => [],
], JSON_PRETTY_PRINT));

file_put_contents($fixtureRoot . "/data/audits/{$privateAuditId}.json", json_encode([
    'id' => $privateAuditId, 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
    'status' => 'en cours', 'score' => 0.0, 'owner_id' => $userId, 'contributors' => [], 'visibility' => 'contributors',
    'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null,
    'project' => ['name' => 'Privé', 'url' => ''], 'auditor' => ['name' => 'Test'], 'criteria' => [],
], JSON_PRETTY_PRINT));

// ─── Démarrage du serveur intégré PHP ─────────────────────────────────────────

$port = 8900 + (getmypid() % 400);
$base = "http://127.0.0.1:{$port}";

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$serverProcess = proc_open(
    'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($fixtureRoot),
    $descriptors,
    $pipes
);

// Laisser le serveur le temps de démarrer, avec une vérification active plutôt
// qu'un simple sleep fixe.
$ready = false;
for ($i = 0; $i < 30; $i++) {
    usleep(100000);
    $check = @file_get_contents($base . '/login.php');
    if ($check !== false) {
        $ready = true;
        break;
    }
}

if (!$ready) {
    fwrite(STDERR, "Le serveur PHP intégré n'a pas démarré à temps sur le port {$port}.\n");
    proc_terminate($serverProcess);
    exit(1);
}

/**
 * @return array{status: int, headers: array<string,string>, body: string}
 */
function http_get(string $url, string $cookieJar = ''): array
{
    $cmd = 'curl -s -D - -o /dev/null ';
    if ($cookieJar !== '') {
        $cmd .= '-b ' . escapeshellarg($cookieJar) . ' ';
    }
    $cmd .= escapeshellarg($url);

    $raw = (string) shell_exec($cmd);
    $lines = explode("\r\n", trim($raw));
    $statusLine = array_shift($lines);
    preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m);
    $status = isset($m[1]) ? (int) $m[1] : 0;

    $headers = [];
    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => ''];
}

/**
 * Extrait le jeton CSRF d'une page HTML (balise <meta name="csrf-token">
 * ou champ caché <input name="csrf_token">).
 */
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

/**
 * Connexion complète via curl : GET pour obtenir un cookie de session + le
 * jeton CSRF du formulaire, puis POST des identifiants + de ce jeton.
 */
function login_via_curl(string $base, string $login, string $password, string $cookieJar): void
{
    $html = (string) shell_exec(
        'curl -s -c ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg("{$base}/login.php")
    );
    $token = extract_csrf_token($html) ?? '';

    shell_exec(
        'curl -s -b ' . escapeshellarg($cookieJar) . ' -c ' . escapeshellarg($cookieJar)
        . ' -d ' . escapeshellarg('login=' . $login . '&password=' . $password . '&csrf_token=' . $token)
        . ' -o /dev/null -D - '
        . escapeshellarg("{$base}/login.php")
    );
}

/**
 * @return array{status: int}
 */
function http_post(string $url, string $cookieJar, array $jsonBody, ?string $csrfToken): array
{
    $cmd = 'curl -s -D - -o /dev/null -X POST -H "Content-Type: application/json" ';
    if ($csrfToken !== null) {
        $cmd .= '-H ' . escapeshellarg('X-CSRF-Token: ' . $csrfToken) . ' ';
    }
    $cmd .= '-b ' . escapeshellarg($cookieJar) . ' ';
    $cmd .= '-d ' . escapeshellarg(json_encode($jsonBody)) . ' ';
    $cmd .= escapeshellarg($url);

    $raw = (string) shell_exec($cmd);
    $lines = explode("\r\n", trim($raw));
    $statusLine = array_shift($lines);
    preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m);

    return ['status' => isset($m[1]) ? (int) $m[1] : 0];
}

try {
    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — Cache-Control sur get_audit/list_audits (visiteur anonyme)');
    // ═════════════════════════════════════════════════════════════════════

    $r = http_get("{$base}/api.php?action=get_audit&id={$publicAuditId}");
    T::assertSame(200, $r['status'], 'get_audit sur un audit public, anonyme : HTTP 200');
    T::assertSame(
        'public, max-age=60',
        $r['headers']['cache-control'] ?? null,
        'get_audit anonyme renvoie bien Cache-Control: public, max-age=60'
    );

    $r = http_get("{$base}/api.php?action=list_audits");
    T::assertSame(200, $r['status'], 'list_audits anonyme : HTTP 200');
    T::assertSame(
        'public, max-age=30',
        $r['headers']['cache-control'] ?? null,
        'list_audits anonyme renvoie bien Cache-Control: public, max-age=30'
    );

    $r = http_get("{$base}/api.php?action=get_audit&id={$privateAuditId}");
    T::assertSame(403, $r['status'], 'get_audit sur un audit PRIVÉ, anonyme : toujours refusé (HTTP 403)');
    T::assertFalse(
        str_contains($r['headers']['cache-control'] ?? '', 'public'),
        'Une réponse en erreur (403) n\'est jamais marquée "public" (rien à mettre en cache partagé)'
    );

    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — Cache-Control sur get_audit/list_audits (utilisateur connecté)');
    // ═════════════════════════════════════════════════════════════════════

    $cookieJar = tempnam(sys_get_temp_dir(), 'rgesn_cookies_') . '.txt';
    login_via_curl($base, 'httptest', $password, $cookieJar);

    $r = http_get("{$base}/api.php?action=get_audit&id={$privateAuditId}", $cookieJar);
    T::assertSame(200, $r['status'], 'get_audit connecté (propriétaire) sur son audit privé : HTTP 200');
    T::assertFalse(
        str_contains($r['headers']['cache-control'] ?? '', 'public'),
        'get_audit CONNECTÉ n\'est jamais "public" — la réponse est personnalisée, un cache partagé ne doit pas la stocker'
    );
    T::assertTrue(
        str_contains($r['headers']['cache-control'] ?? '', 'no-store'),
        'get_audit connecté garde le Cache-Control par défaut (no-store) de l\'API'
    );

    $r = http_get("{$base}/api.php?action=list_audits", $cookieJar);
    T::assertSame(200, $r['status'], 'list_audits connecté : HTTP 200');
    T::assertFalse(
        str_contains($r['headers']['cache-control'] ?? '', 'public'),
        'list_audits CONNECTÉ n\'est jamais "public" non plus'
    );

    // ═════════════════════════════════════════════════════════════════════
    T::section('HTTP réel — protection CSRF sur les actions d\'écriture');
    // ═════════════════════════════════════════════════════════════════════

    $indexHtml = (string) shell_exec('curl -s -b ' . escapeshellarg($cookieJar) . ' ' . escapeshellarg("{$base}/index.php"));
    $apiCsrfToken = extract_csrf_token($indexHtml);
    T::assertTrue(
        $apiCsrfToken !== null && $apiCsrfToken !== '',
        'Le jeton CSRF est bien présent dans la balise <meta> de index.php pour un utilisateur connecté'
    );

    $r = http_post("{$base}/api.php?action=set_visibility", $cookieJar, ['id' => $privateAuditId, 'visibility' => 'public'], null);
    T::assertSame(403, $r['status'], 'POST sans en-tête X-CSRF-Token : refusé (HTTP 403)');

    $r = http_post("{$base}/api.php?action=set_visibility", $cookieJar, ['id' => $privateAuditId, 'visibility' => 'public'], 'jeton-manifestement-invalide');
    T::assertSame(403, $r['status'], 'POST avec un jeton CSRF invalide : refusé (HTTP 403)');

    $r = http_post("{$base}/api.php?action=set_visibility", $cookieJar, ['id' => $privateAuditId, 'visibility' => 'public'], $apiCsrfToken);
    T::assertSame(200, $r['status'], 'POST avec le jeton CSRF correct : accepté (HTTP 200)');

    $audit = json_decode(file_get_contents($fixtureRoot . "/data/audits/{$privateAuditId}.json"), true);
    T::assertSame('public', $audit['visibility'] ?? null, 'La modification a bien été appliquée avec un jeton CSRF valide');

    // Un formulaire de connexion sans jeton (ou avec un jeton invalide) est refusé
    $badLoginHtml = (string) shell_exec(
        'curl -s -c /dev/null -d ' . escapeshellarg('login=httptest&password=' . $password . '&csrf_token=faux')
        . ' ' . escapeshellarg("{$base}/login.php")
    );
    T::assertTrue(
        str_contains($badLoginHtml, 'session a expiré') || str_contains($badLoginHtml, 'expiré'),
        'Une tentative de connexion avec un jeton CSRF invalide est rejetée avec un message dédié'
    );

    @unlink($cookieJar);
} finally {
    // ─── Arrêt du serveur, quoi qu'il arrive ──────────────────────────────
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    shell_exec('rm -rf ' . escapeshellarg($fixtureRoot));
}

exit(T::summaryAndExitCode());
