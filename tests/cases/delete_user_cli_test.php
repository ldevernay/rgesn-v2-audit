#!/usr/bin/env php
<?php
/**
 * Tests d'intégration — bin/delete_user.php
 *
 * Le script est entièrement interactif (lit sur STDIN). On le lance donc en
 * sous-processus réel via proc_open(), en lui fournissant les réponses aux
 * prompts sur son entrée standard, et on vérifie l'état des fichiers de
 * fixtures (jamais les vraies données du projet) après exécution.
 */

require_once __DIR__ . '/../TestRunner.php';

$fixturesDir = __DIR__ . '/../tmp/delete_user_cli';
@mkdir($fixturesDir, 0755, true);

/**
 * Lance bin/delete_user.php dans un sous-processus, avec USERS_FILE et
 * AUDITS_DIR redéfinis vers les fixtures de test, en lui fournissant $stdin
 * sur son entrée standard.
 *
 * @return array{stdout: string, stderr: string, exit_code: int}
 */
function run_delete_user_cli(string $usersFile, string $auditsDir, string $stdin): array
{
    $scriptPath = realpath(__DIR__ . '/../../bin/delete_user.php');

    $wrapperCode = "<?php\n"
        . "define('USERS_FILE', " . var_export($usersFile, true) . ");\n"
        . "define('AUDITS_DIR', " . var_export($auditsDir, true) . ");\n"
        . "require " . var_export($scriptPath, true) . ";\n";

    $wrapper = tempnam(sys_get_temp_dir(), 'rgesn_deluser_') . '.php';
    file_put_contents($wrapper, $wrapperCode);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open('php ' . escapeshellarg($wrapper), $descriptors, $pipes);

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    unlink($wrapper);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
}

function reset_fixture_users(string $usersFile, array $users): void
{
    @mkdir(dirname($usersFile), 0755, true);
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function reset_fixture_audit(string $auditsDir, string $id, array $overrides = []): void
{
    @mkdir($auditsDir, 0755, true);
    $audit = array_merge([
        'id' => $id, 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
        'status' => 'en cours', 'score' => 0.0, 'owner_id' => null, 'contributors' => [], 'visibility' => 'contributors',
        'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null,
        'project' => ['name' => 'Fixture', 'url' => ''], 'auditor' => ['name' => 'Test'], 'criteria' => [],
    ], $overrides);
    file_put_contents($auditsDir . $id . '.json', json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$idAdmin1 = 'admin-1'; $idAdmin2 = 'admin-2'; $idAuditor = 'auditor-1'; $idOther = 'other-1';

$baseUsers = [
    ['id' => $idAdmin1,  'login' => 'admin1',  'name' => 'Admin Un',   'role' => 'admin',    'password_hash' => password_hash('x', PASSWORD_DEFAULT)],
    ['id' => $idAdmin2,  'login' => 'admin2',  'name' => 'Admin Deux', 'role' => 'admin',    'password_hash' => password_hash('x', PASSWORD_DEFAULT)],
    ['id' => $idAuditor, 'login' => 'auditor', 'name' => 'Auditeur',   'role' => 'auditeur', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)],
    ['id' => $idOther,   'login' => 'other',   'name' => 'Autre',      'role' => 'auditeur', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)],
];

// ═════════════════════════════════════════════════════════════════════════
T::section('bin/delete_user.php — suppression simple, sans audit possédé ni partagé');
// ═════════════════════════════════════════════════════════════════════════

$usersFile = $fixturesDir . '/users_1.json';
$auditsDir = $fixturesDir . '/audits_1/';
reset_fixture_users($usersFile, $baseUsers);
@mkdir($auditsDir, 0755, true);

$r = run_delete_user_cli($usersFile, $auditsDir, "other\noui\n");
T::assertSame(0, $r['exit_code'], 'Suppression simple : code de sortie 0');
$remaining = json_decode(file_get_contents($usersFile), true);
T::assertSame(3, count($remaining), 'L\'utilisateur a bien été retiré de users.json (4 -> 3)');
T::assertFalse(in_array($idOther, array_column($remaining, 'id'), true), 'L\'id supprimé n\'apparaît plus dans la liste');

// ═════════════════════════════════════════════════════════════════════════
T::section('bin/delete_user.php — audits possédés : réassignation à un autre utilisateur');
// ═════════════════════════════════════════════════════════════════════════

$usersFile = $fixturesDir . '/users_2.json';
$auditsDir = $fixturesDir . '/audits_2/';
reset_fixture_users($usersFile, $baseUsers);
reset_fixture_audit($auditsDir, '550e8400-e29b-41d4-a716-446655440301', ['owner_id' => $idAuditor]);

$r = run_delete_user_cli($usersFile, $auditsDir, "auditor\n1\nadmin1\noui\n");
T::assertSame(0, $r['exit_code'], 'Réassignation : code de sortie 0');
$audit = json_decode(file_get_contents($auditsDir . '550e8400-e29b-41d4-a716-446655440301.json'), true);
T::assertSame($idAdmin1, $audit['owner_id'] ?? null, 'L\'audit a bien été réassigné au nouveau propriétaire choisi');
T::assertTrue(file_exists($auditsDir . '550e8400-e29b-41d4-a716-446655440301.json'), 'Le fichier audit existe toujours (pas supprimé)');

// ═════════════════════════════════════════════════════════════════════════
T::section('bin/delete_user.php — audits possédés : suppression avec le compte');
// ═════════════════════════════════════════════════════════════════════════

$usersFile = $fixturesDir . '/users_3.json';
$auditsDir = $fixturesDir . '/audits_3/';
reset_fixture_users($usersFile, $baseUsers);
reset_fixture_audit($auditsDir, '550e8400-e29b-41d4-a716-446655440302', ['owner_id' => $idAuditor]);

$r = run_delete_user_cli($usersFile, $auditsDir, "auditor\n2\noui\n");
T::assertSame(0, $r['exit_code'], 'Suppression avec le compte : code de sortie 0');
T::assertFalse(file_exists($auditsDir . '550e8400-e29b-41d4-a716-446655440302.json'), 'Le fichier audit a bien été supprimé avec le compte');

// ═════════════════════════════════════════════════════════════════════════
T::section('bin/delete_user.php — audits possédés : annulation (choix 3)');
// ═════════════════════════════════════════════════════════════════════════

$usersFile = $fixturesDir . '/users_4.json';
$auditsDir = $fixturesDir . '/audits_4/';
reset_fixture_users($usersFile, $baseUsers);
reset_fixture_audit($auditsDir, '550e8400-e29b-41d4-a716-446655440303', ['owner_id' => $idAuditor]);

$r = run_delete_user_cli($usersFile, $auditsDir, "auditor\n3\n");
T::assertSame(0, $r['exit_code'], 'Annulation via le menu : code de sortie 0');
$remaining = json_decode(file_get_contents($usersFile), true);
T::assertTrue(in_array($idAuditor, array_column($remaining, 'id'), true), 'L\'utilisateur n\'a PAS été supprimé (annulation)');
T::assertTrue(file_exists($auditsDir . '550e8400-e29b-41d4-a716-446655440303.json'), 'L\'audit n\'a pas été touché');

// ═════════════════════════════════════════════════════════════════════════
T::section('bin/delete_user.php — annulation à la confirmation finale');
// ═════════════════════════════════════════════════════════════════════════

$usersFile = $fixturesDir . '/users_5.json';
$auditsDir = $fixturesDir . '/audits_5/';
reset_fixture_users($usersFile, $baseUsers);
@mkdir($auditsDir, 0755, true);

$r = run_delete_user_cli($usersFile, $auditsDir, "other\nnon\n");
T::assertSame(0, $r['exit_code'], 'Annulation à la confirmation finale : code de sortie 0');
$remaining = json_decode(file_get_contents($usersFile), true);
T::assertTrue(in_array($idOther, array_column($remaining, 'id'), true), 'L\'utilisateur n\'a PAS été supprimé (non confirmé)');

// ═════════════════════════════════════════════════════════════════════════
T::section('bin/delete_user.php — nettoyage des contributions orphelines sur les audits d\'autrui');
// ═════════════════════════════════════════════════════════════════════════

$usersFile = $fixturesDir . '/users_6.json';
$auditsDir = $fixturesDir . '/audits_6/';
reset_fixture_users($usersFile, $baseUsers);
reset_fixture_audit($auditsDir, '550e8400-e29b-41d4-a716-446655440304', [
    'owner_id'     => $idAdmin1,
    'contributors' => [$idOther],
]);

$r = run_delete_user_cli($usersFile, $auditsDir, "other\noui\n");
T::assertSame(0, $r['exit_code'], 'Suppression d\'un simple contributeur : code de sortie 0');
$audit = json_decode(file_get_contents($auditsDir . '550e8400-e29b-41d4-a716-446655440304.json'), true);
T::assertSame([], $audit['contributors'] ?? null, 'Le statut de contributeur du compte supprimé a bien été retiré de l\'audit d\'un autre propriétaire');
T::assertSame($idAdmin1, $audit['owner_id'] ?? null, 'Le propriétaire de cet audit (un tiers) n\'est pas affecté');

// ═════════════════════════════════════════════════════════════════════════
T::section('bin/delete_user.php — refus de supprimer le dernier compte admin');
// ═════════════════════════════════════════════════════════════════════════

$usersFile = $fixturesDir . '/users_solo_admin.json';
$auditsDir = $fixturesDir . '/audits_solo_admin/';
reset_fixture_users($usersFile, [
    ['id' => $idAdmin1, 'login' => 'seuladmin', 'name' => 'Seul Admin', 'role' => 'admin', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)],
    ['id' => $idAuditor, 'login' => 'auditor', 'name' => 'Auditeur', 'role' => 'auditeur', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)],
]);
@mkdir($auditsDir, 0755, true);

$r = run_delete_user_cli($usersFile, $auditsDir, "seuladmin\n");
T::assertSame(1, $r['exit_code'], 'Suppression du dernier admin : code de sortie 1 (refusé)');
T::assertTrue(stripos($r['stderr'], 'dernier') !== false, 'Un message d\'erreur explicite mentionne le refus');
$remaining = json_decode(file_get_contents($usersFile), true);
T::assertSame(2, count($remaining), 'Le compte admin n\'a pas été supprimé (toujours 2 utilisateurs)');

// Vérification symétrique : supprimer l'AUTRE compte (non-admin) doit, lui,
// fonctionner normalement — le garde-fou ne concerne que le dernier admin.
$r = run_delete_user_cli($usersFile, $auditsDir, "auditor\noui\n");
T::assertSame(0, $r['exit_code'], 'Supprimer le compte non-admin restant fonctionne normalement');

// ─── Nettoyage ───────────────────────────────────────────────────────────────

exit(T::summaryAndExitCode());
