#!/usr/bin/env php
<?php
/**
 * Tests d'intégration — actions sensibles de api.php, modèle Phase B
 * (propriétaire / contributeurs / visibilité à 3 niveaux / verrou sémantique).
 *
 * Ces action_*() appellent json_response(), qui fait exit() après avoir
 * imprimé le JSON. On ne peut donc pas les appeler directement dans ce
 * processus (ça tuerait le runner). Chaque scénario est donc exécuté dans un
 * SOUS-PROCESSUS PHP dédié (helper run_scenario() ci-dessous), dont on
 * capture la sortie JSON et le code HTTP (récupéré via un shutdown handler,
 * qui s'exécute même après un exit()).
 */

require_once __DIR__ . '/../TestRunner.php';

$fixturesDir  = __DIR__ . '/../tmp/api_actions';
$auditsDir    = $fixturesDir . '/audits/';
$criteriaFile = $fixturesDir . '/criteria.json';
$usersFile    = $fixturesDir . '/users.json';

@mkdir($auditsDir, 0755, true);

const FIXED_AUDIT_ID = '550e8400-e29b-41d4-a716-446655440001';

// ─── Fixtures utilisateurs (fichier partagé par tous les scénarios) ─────────

$owner       = ['id' => 'owner-1',       'login' => 'owner',       'name' => 'Ophélie (owner)',       'role' => 'auditeur'];
$contributor = ['id' => 'contributor-1', 'login' => 'contributor', 'name' => 'Camille (contributor)', 'role' => 'auditeur'];
$stranger    = ['id' => 'stranger-1',    'login' => 'stranger',    'name' => 'Sacha (stranger)',      'role' => 'auditeur'];
$admin       = ['id' => 'admin-1',       'login' => 'admin',       'name' => 'Ada (admin)',           'role' => 'admin'];

file_put_contents($usersFile, json_encode([$owner, $contributor, $stranger, $admin], JSON_PRETTY_PRINT));
file_put_contents($criteriaFile, json_encode([
    ['id' => 'c-1', 'priority' => 'Modéré'],
], JSON_PRETTY_PRINT));

function resetAuditFixture(string $auditsDir, array $owner, array $contributor, string $visibility = 'contributors'): void
{
    $audit = [
        'id'             => FIXED_AUDIT_ID,
        'created_at'     => '2026-01-01T00:00:00+00:00',
        'updated_at'     => '2026-01-01T00:00:00+00:00',
        'status'         => 'en cours',
        'score'          => 0.0,
        'owner_id'       => $owner['id'],
        'contributors'   => [$contributor['id']],
        'visibility'     => $visibility,
        'locked_by'      => null,
        'locked_by_name' => null,
        'locked_at'      => null,
        'project'        => ['name' => 'Fixture', 'url' => ''],
        'auditor'        => ['name' => 'Test'],
        'criteria'       => [[
            'id' => 'c-1', 'thematic_id' => 1, 'priority' => 'Modéré',
            'status' => 'non-testé', 'comment' => '', 'action_text' => '',
            'action_who' => [], 'action_when' => '', 'action_easy' => false,
        ]],
    ];
    file_put_contents(
        $auditsDir . FIXED_AUDIT_ID . '.json',
        json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Exécute $body dans un sous-processus PHP disposant de toutes les fonctions
 * de api.php (AUDITS_DIR / CRITERES_FILE / USERS_FILE pointant vers les
 * fixtures de test), avec le routage HTTP désactivé (RGESN_API_TESTING).
 * $body doit se terminer par un appel à une fonction action_*() (qui fait
 * exit() après avoir imprimé le JSON — c'est attendu).
 *
 * @return array{json: ?array, status: ?int, raw: string}
 */
function run_scenario(string $auditsDir, string $criteriaFile, string $usersFile, string $body): array
{
    $projectRoot = realpath(__DIR__ . '/../..');

    $code = "<?php\n"
        . "define('RGESN_API_TESTING', true);\n"
        . "define('AUDITS_DIR', " . var_export($auditsDir, true) . ");\n"
        . "define('CRITERES_FILE', " . var_export($criteriaFile, true) . ");\n"
        . "define('USERS_FILE', " . var_export($usersFile, true) . ");\n"
        . "require_once " . var_export($projectRoot . '/includes/functions.php', true) . ";\n"
        . "require_once " . var_export($projectRoot . '/includes/auth.php', true) . ";\n"
        . "require_once " . var_export($projectRoot . '/api.php', true) . ";\n"
        . "register_shutdown_function(function () { echo \"\\n__STATUS__:\" . http_response_code(); });\n"
        . $body . "\n";

    $tmp = tempnam(sys_get_temp_dir(), 'rgesn_scenario_') . '.php';
    file_put_contents($tmp, $code);

    $raw = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);

    $status = null;
    if (preg_match('/__STATUS__:(\d+)/', $raw, $m)) {
        $status = (int) $m[1];
    }
    $jsonPart = preg_replace('/\n__STATUS__:\d+\s*$/', '', $raw);
    $json = json_decode($jsonPart, true);

    return ['json' => is_array($json) ? $json : null, 'status' => $status, 'raw' => $raw];
}

function php_var(array $v): string
{
    return var_export($v, true);
}

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_add_contributor() / action_remove_contributor()');
// ═════════════════════════════════════════════════════════════════════════

resetAuditFixture($auditsDir, $owner, $contributor);

// Le propriétaire peut ajouter un inconnu jusque-là
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_add_contributor(' . php_var(['id' => FIXED_AUDIT_ID, 'user_id' => $stranger['id']]) . ', ' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'Le propriétaire peut ajouter un contributeur (HTTP 200)');
T::assertTrue($r['json']['success'] ?? false, 'La réponse indique success=true');
T::assertSame(2, count($r['json']['contributors'] ?? []), 'Le contributeur a bien été ajouté à la liste (2 au total)');

// Un simple contributeur ne peut PAS gérer les autres contributeurs
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_add_contributor(' . php_var(['id' => FIXED_AUDIT_ID, 'user_id' => $stranger['id']]) . ', ' . php_var($contributor) . ');'
);
T::assertSame(403, $r['status'], 'Un simple contributeur ne peut pas gérer les contributeurs (HTTP 403)');

// Un admin peut gérer les contributeurs même sans être owner
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_add_contributor(' . php_var(['id' => FIXED_AUDIT_ID, 'user_id' => $stranger['id']]) . ', ' . php_var($admin) . ');'
);
T::assertSame(200, $r['status'], 'Un admin peut gérer les contributeurs même sans être propriétaire (HTTP 200)');

// Impossible d'ajouter le propriétaire lui-même comme contributeur
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_add_contributor(' . php_var(['id' => FIXED_AUDIT_ID, 'user_id' => $owner['id']]) . ', ' . php_var($owner) . ');'
);
T::assertSame(403, $r['status'], 'Ajouter le propriétaire comme contributeur de lui-même est rejeté');

// remove_contributor : le propriétaire retire le contributeur
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_remove_contributor(' . php_var(['id' => FIXED_AUDIT_ID, 'user_id' => $contributor['id']]) . ', ' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'Le propriétaire peut retirer un contributeur (HTTP 200)');
T::assertSame(0, count($r['json']['contributors'] ?? ['x']), 'Il ne reste plus aucun contributeur après retrait');

// remove_contributor : un simple contributeur ne peut pas retirer un autre contributeur
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_remove_contributor(' . php_var(['id' => FIXED_AUDIT_ID, 'user_id' => $contributor['id']]) . ', ' . php_var($contributor) . ');'
);
T::assertSame(403, $r['status'], 'Un simple contributeur ne peut pas retirer un contributeur (même pas lui-même, HTTP 403)');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_transfer_owner()');
// ═════════════════════════════════════════════════════════════════════════

resetAuditFixture($auditsDir, $owner, $contributor);

$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_transfer_owner(' . php_var(['id' => FIXED_AUDIT_ID, 'new_owner_id' => $stranger['id']]) . ', ' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'Le propriétaire peut céder la propriété (HTTP 200)');
T::assertSame($stranger['id'], $r['json']['owner_id'] ?? null, 'Le nouveau propriétaire est bien renvoyé');

$saved = json_decode(file_get_contents($auditsDir . FIXED_AUDIT_ID . '.json'), true);
T::assertSame($stranger['id'], $saved['owner_id'] ?? null, 'owner_id est bien persisté sur le disque');
T::assertTrue(in_array($owner['id'], $saved['contributors'] ?? [], true), 'L\'ancien propriétaire devient contributeur (conserve l\'accès)');

// Un simple contributeur ne peut pas céder la propriété
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_transfer_owner(' . php_var(['id' => FIXED_AUDIT_ID, 'new_owner_id' => $stranger['id']]) . ', ' . php_var($contributor) . ');'
);
T::assertSame(403, $r['status'], 'Un simple contributeur ne peut pas céder la propriété (HTTP 403)');

// Céder à un utilisateur inexistant est rejeté
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_transfer_owner(' . php_var(['id' => FIXED_AUDIT_ID, 'new_owner_id' => 'id-inexistant']) . ', ' . php_var($owner) . ');'
);
T::assertSame(404, $r['status'], 'Céder la propriété à un utilisateur inexistant est rejeté (HTTP 404)');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — permissions sur action_update_audit() / action_delete_audit()');
// ═════════════════════════════════════════════════════════════════════════

// update_audit : un utilisateur avec juste un accès lecture (visibilité) ne peut pas modifier
resetAuditFixture($auditsDir, $owner, $contributor, 'authenticated');
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_update_audit(' . php_var(['id' => FIXED_AUDIT_ID, 'status' => 'terminé']) . ', ' . php_var($stranger) . ');'
);
T::assertSame(403, $r['status'], 'Un accès en lecture seule (via la visibilité) ne permet pas de modifier (HTTP 403)');

// update_audit : le contributeur peut modifier
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_update_audit(' . php_var(['id' => FIXED_AUDIT_ID, 'status' => 'terminé']) . ', ' . php_var($contributor) . ');'
);
T::assertSame(200, $r['status'], 'Un contributeur peut modifier l\'audit (HTTP 200)');

// update_audit : un inconnu sans aucun accès ne peut pas modifier
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_update_audit(' . php_var(['id' => FIXED_AUDIT_ID, 'status' => 'terminé']) . ', ' . php_var($stranger) . ');'
);
T::assertSame(403, $r['status'], 'Un utilisateur sans aucun lien avec l\'audit ne peut pas le modifier (HTTP 403)');

// delete_audit : un contributeur ne peut PAS supprimer
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_delete_audit(' . php_var(['id' => FIXED_AUDIT_ID]) . ', ' . php_var($contributor) . ');'
);
T::assertSame(403, $r['status'], 'Un contributeur ne peut PAS supprimer l\'audit (HTTP 403)');
T::assertTrue(file_exists($auditsDir . FIXED_AUDIT_ID . '.json'), 'Le fichier audit existe toujours après la tentative refusée');

// delete_audit : le propriétaire peut supprimer
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_delete_audit(' . php_var(['id' => FIXED_AUDIT_ID]) . ', ' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'Le propriétaire peut supprimer l\'audit (HTTP 200)');
T::assertFalse(file_exists($auditsDir . FIXED_AUDIT_ID . '.json'), 'Le fichier audit a bien été supprimé du disque');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_update_audit() respecte le verrou sémantique');
// ═════════════════════════════════════════════════════════════════════════
//
// acquire_audit_lock() (la fonction BRUTE de audit_store.php, pas son
// wrapper action_acquire_audit_lock() de api.php) ne fait pas json_response()
// / exit() : on peut donc l'utiliser pour préparer l'état du verrou AVANT
// l'appel testé, qui lui reste la dernière instruction du scénario.

// Le propriétaire a la main : un contributeur ne peut plus modifier en attendant.
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'acquire_audit_lock(' . var_export(FIXED_AUDIT_ID, true) . ', ' . php_var($owner) . '); '
    . 'action_update_audit(' . php_var(['id' => FIXED_AUDIT_ID, 'status' => 'terminé']) . ', ' . php_var($contributor) . ');'
);
T::assertSame(409, $r['status'], 'Un audit verrouillé par quelqu\'un d\'autre refuse toute modification (HTTP 409)');
T::assertTrue(str_contains($r['json']['error'] ?? '', 'Ophélie'), 'Le message d\'erreur mentionne qui détient le verrou');

// Le détenteur du verrou, lui, peut toujours modifier (ce n'est pas un blocage général).
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'acquire_audit_lock(' . var_export(FIXED_AUDIT_ID, true) . ', ' . php_var($owner) . '); '
    . 'action_update_audit(' . php_var(['id' => FIXED_AUDIT_ID, 'status' => 'terminé']) . ', ' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'Le détenteur du verrou peut lui-même continuer à modifier (HTTP 200)');

// Un verrou expiré (stale) ne bloque plus personne.
resetAuditFixture($auditsDir, $owner, $contributor);
$staleAudit = json_decode(file_get_contents($auditsDir . FIXED_AUDIT_ID . '.json'), true);
$staleAudit['locked_by'] = $owner['id'];
$staleAudit['locked_by_name'] = $owner['name'];
$staleAudit['locked_at'] = date('c', time() - 3600); // il y a 1h, largement expiré
file_put_contents($auditsDir . FIXED_AUDIT_ID . '.json', json_encode($staleAudit, JSON_UNESCAPED_UNICODE));

$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_update_audit(' . php_var(['id' => FIXED_AUDIT_ID, 'status' => 'terminé']) . ', ' . php_var($contributor) . ');'
);
T::assertSame(200, $r['status'], 'Un verrou expiré (stale) ne bloque plus la modification (HTTP 200)');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_acquire_audit_lock() / action_release_audit_lock() / action_renew_audit_lock()');
// ═════════════════════════════════════════════════════════════════════════

resetAuditFixture($auditsDir, $owner, $contributor);

$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_acquire_audit_lock(' . php_var(['id' => FIXED_AUDIT_ID]) . ', ' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'Le propriétaire peut acquérir le verrou (HTTP 200)');
T::assertTrue($r['json']['status']['locked'] ?? false, 'Le statut renvoyé indique bien verrouillé');

// Un utilisateur sans droit d'édition (lecture seule via visibilité) ne peut pas verrouiller.
resetAuditFixture($auditsDir, $owner, $contributor, 'authenticated');
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_acquire_audit_lock(' . php_var(['id' => FIXED_AUDIT_ID]) . ', ' . php_var($stranger) . ');'
);
T::assertSame(403, $r['status'], 'Un accès en lecture seule ne permet pas d\'acquérir le verrou d\'édition (HTTP 403)');

// Un second contributeur ne peut pas acquérir un verrou déjà pris.
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'acquire_audit_lock(' . var_export(FIXED_AUDIT_ID, true) . ', ' . php_var($owner) . '); '
    . 'action_acquire_audit_lock(' . php_var(['id' => FIXED_AUDIT_ID]) . ', ' . php_var($contributor) . ');'
);
T::assertSame(409, $r['status'], 'Acquérir un verrou déjà pris par quelqu\'un d\'autre échoue (HTTP 409)');

// release_audit_lock : fonctionne même sans détenir le verrou (pas de "force" ici, donc renvoie juste success=false côté métier).
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'acquire_audit_lock(' . var_export(FIXED_AUDIT_ID, true) . ', ' . php_var($owner) . '); '
    . 'action_release_audit_lock(' . php_var(['id' => FIXED_AUDIT_ID]) . ', ' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'Le détenteur du verrou peut le libérer (HTTP 200)');
T::assertTrue($r['json']['success'] ?? false, 'La libération indique success=true');
$releasedAudit = json_decode(file_get_contents($auditsDir . FIXED_AUDIT_ID . '.json'), true);
T::assertNull($releasedAudit['locked_by'], 'L\'audit est bien déverrouillé sur le disque');

// renew_audit_lock : le détenteur peut renouveler.
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'acquire_audit_lock(' . var_export(FIXED_AUDIT_ID, true) . ', ' . php_var($owner) . '); '
    . 'action_renew_audit_lock(' . php_var(['id' => FIXED_AUDIT_ID]) . ', ' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'Le détenteur du verrou peut le renouveler (HTTP 200)');
T::assertTrue($r['json']['success'] ?? false, 'Le renouvellement indique success=true');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_duplicate_audit() accessible dès la lecture seule');
// ═════════════════════════════════════════════════════════════════════════

resetAuditFixture($auditsDir, $owner, $contributor, 'authenticated');

// Un utilisateur en lecture seule (via la visibilité) peut dupliquer (ça crée
// un NOUVEL audit, dont il devient propriétaire)
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_duplicate_audit(' . php_var(['source_id' => FIXED_AUDIT_ID, 'project_name' => 'Copie', 'project_url' => '', 'auditor_name' => 'Sacha']) . ', ' . php_var($stranger) . ');'
);
T::assertSame(200, $r['status'], 'Un utilisateur en lecture seule peut dupliquer l\'audit (HTTP 200)');
$newId = $r['json']['id'] ?? null;
T::assertTrue($newId !== null && $newId !== FIXED_AUDIT_ID, 'La duplication crée un nouvel audit avec un nouvel id');

if ($newId) {
    $copy = json_decode(file_get_contents($auditsDir . $newId . '.json'), true);
    T::assertSame($stranger['id'], $copy['owner_id'] ?? null, 'Le duplicateur devient propriétaire de la copie, pas l\'auteur original');
    T::assertSame([], $copy['contributors'] ?? null, 'La copie démarre sans aucun contributeur (pas d\'héritage)');
    T::assertSame('contributors', $copy['visibility'] ?? null, 'La copie démarre avec la visibilité la plus restrictive, même si la source était plus ouverte');
    @unlink($auditsDir . $newId . '.json');
}

// Un inconnu (aucun accès, audit resté "contributors") ne peut pas dupliquer
resetAuditFixture($auditsDir, $owner, $contributor, 'contributors');
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_duplicate_audit(' . php_var(['source_id' => FIXED_AUDIT_ID, 'project_name' => 'Copie', 'project_url' => '', 'auditor_name' => 'X']) . ', ' . php_var($stranger) . ');'
);
T::assertSame(403, $r['status'], 'Un utilisateur sans accès à l\'audit source ne peut pas le dupliquer (HTTP 403)');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_list_users_basic()');
// ═════════════════════════════════════════════════════════════════════════

$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_list_users_basic(' . php_var($owner) . ');'
);
T::assertSame(200, $r['status'], 'list_users_basic répond HTTP 200');
$names = array_column($r['json'] ?? [], 'name');
T::assertFalse(in_array($owner['name'], $names, true), 'La liste exclut l\'utilisateur courant lui-même');
T::assertTrue(in_array($contributor['name'], $names, true), 'La liste contient les autres utilisateurs');

$firstUser = $r['json'][0] ?? [];
T::assertSame(['id', 'name'], array_keys($firstUser), 'Chaque entrée ne contient QUE id et name (jamais password_hash, login ou role)');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_list_audits() filtre par permission et expose visibility/lock');
// ═════════════════════════════════════════════════════════════════════════

resetAuditFixture($auditsDir, $owner, $contributor, 'contributors');

$r = run_scenario($auditsDir, $criteriaFile, $usersFile, '$_SERVER["REQUEST_METHOD"] = "GET"; action_list_audits(' . php_var($stranger) . ');');
T::assertSame(200, $r['status'], 'list_audits répond HTTP 200 même sans aucun audit visible');
T::assertSame(0, count($r['json'] ?? []), 'Un utilisateur sans accès à aucun audit reçoit une liste vide (audit invisible, pas une erreur)');

$r = run_scenario($auditsDir, $criteriaFile, $usersFile, 'action_list_audits(' . php_var($contributor) . ');');
T::assertSame(1, count($r['json'] ?? []), 'Un contributeur voit l\'audit dans sa liste');
T::assertSame('contributor', $r['json'][0]['permission'] ?? null, 'Le niveau de permission est bien renvoyé dans list_audits');
T::assertSame('contributors', $r['json'][0]['visibility'] ?? null, 'La visibilité est bien renvoyée dans list_audits');
T::assertFalse($r['json'][0]['lock']['locked'] ?? true, 'Le statut de verrou (non verrouillé) est bien renvoyé dans list_audits');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_list_audits() : owner_name/contributor_count réservés à l\'admin');
// ═════════════════════════════════════════════════════════════════════════

resetAuditFixture($auditsDir, $owner, $contributor);

$r = run_scenario($auditsDir, $criteriaFile, $usersFile, 'action_list_audits(' . php_var($admin) . ');');
T::assertSame($owner['name'], $r['json'][0]['owner_name'] ?? null, 'Un admin voit le nom du propriétaire réel dans list_audits');
T::assertSame(1, $r['json'][0]['contributor_count'] ?? null, 'Un admin voit le nombre de contributeurs');

$r = run_scenario($auditsDir, $criteriaFile, $usersFile, 'action_list_audits(' . php_var($contributor) . ');');
T::assertTrue(
    array_key_exists('owner_name', $r['json'][0] ?? []) && $r['json'][0]['owner_name'] === null,
    'Un non-admin NE voit PAS le nom du propriétaire (minimisation)'
);

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — action_set_visibility() : les 3 niveaux');
// ═════════════════════════════════════════════════════════════════════════

resetAuditFixture($auditsDir, $owner, $contributor);

foreach (['authenticated', 'public', 'contributors'] as $level) {
    $r = run_scenario($auditsDir, $criteriaFile, $usersFile,
        'action_set_visibility(' . php_var(['id' => FIXED_AUDIT_ID, 'visibility' => $level]) . ', ' . php_var($owner) . ');'
    );
    T::assertSame(200, $r['status'], "Le propriétaire peut régler la visibilité sur \"{$level}\" (HTTP 200)");
    T::assertSame($level, $r['json']['visibility'] ?? null, "La réponse confirme visibility=\"{$level}\"");
    $saved = json_decode(file_get_contents($auditsDir . FIXED_AUDIT_ID . '.json'), true);
    T::assertSame($level, $saved['visibility'] ?? null, "visibility=\"{$level}\" est bien persisté sur le disque");
}

// Un contributeur ne peut PAS changer la visibilité
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_set_visibility(' . php_var(['id' => FIXED_AUDIT_ID, 'visibility' => 'public']) . ', ' . php_var($contributor) . ');'
);
T::assertSame(403, $r['status'], 'Un contributeur ne peut pas changer la visibilité (HTTP 403)');

// Un admin peut le faire même sans être propriétaire
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_set_visibility(' . php_var(['id' => FIXED_AUDIT_ID, 'visibility' => 'public']) . ', ' . php_var($admin) . ');'
);
T::assertSame(200, $r['status'], 'Un admin peut changer la visibilité même sans être propriétaire (HTTP 200)');

// Une valeur de visibilité invalide est rejetée
resetAuditFixture($auditsDir, $owner, $contributor);
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    'action_set_visibility(' . php_var(['id' => FIXED_AUDIT_ID, 'visibility' => 'valeur-invalide']) . ', ' . php_var($owner) . ');'
);
T::assertSame(400, $r['status'], 'Une valeur de visibilité invalide est rejetée (HTTP 400)');

// ═════════════════════════════════════════════════════════════════════════
T::section('api.php — accès anonyme (visiteur non connecté) aux audits publics');
// ═════════════════════════════════════════════════════════════════════════

resetAuditFixture($auditsDir, $owner, $contributor, 'contributors');

// get_audit : un visiteur anonyme ne peut pas voir un audit privé (contributors-only)
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    '$_GET["id"] = ' . var_export(FIXED_AUDIT_ID, true) . '; action_get_audit(null);'
);
T::assertSame(403, $r['status'], 'Un visiteur anonyme ne peut pas voir un audit privé via get_audit (HTTP 403)');

// Un audit "authenticated" reste invisible pour un anonyme
resetAuditFixture($auditsDir, $owner, $contributor, 'authenticated');
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    '$_GET["id"] = ' . var_export(FIXED_AUDIT_ID, true) . '; action_get_audit(null);'
);
T::assertSame(403, $r['status'], 'Un audit "visible par tous les utilisateurs" reste invisible pour un visiteur anonyme (HTTP 403)');

// On rend l'audit public, puis on revérifie
resetAuditFixture($auditsDir, $owner, $contributor, 'public');
$r = run_scenario($auditsDir, $criteriaFile, $usersFile,
    '$_GET["id"] = ' . var_export(FIXED_AUDIT_ID, true) . '; action_get_audit(null);'
);
T::assertSame(200, $r['status'], 'Un visiteur anonyme peut voir un audit public via get_audit (HTTP 200)');
T::assertSame('lecture', $r['json']['_permission'] ?? null, 'get_audit indique bien la permission "lecture" pour un visiteur anonyme');

// list_audits : un visiteur anonyme ne voit que les audits publics
$r = run_scenario($auditsDir, $criteriaFile, $usersFile, '$_SERVER["REQUEST_METHOD"] = "GET"; action_list_audits(null);');
T::assertSame(200, $r['status'], 'list_audits répond HTTP 200 pour un visiteur anonyme');
T::assertSame(1, count($r['json'] ?? []), 'Un visiteur anonyme voit uniquement l\'audit public dans la liste');
T::assertSame('lecture', $r['json'][0]['permission'] ?? null, 'La permission renvoyée pour un visiteur anonyme est "lecture"');
T::assertSame('public', $r['json'][0]['visibility'] ?? null, 'visibility="public" est bien renvoyé dans list_audits');

// Remise en état privé pour ne pas fausser d'éventuels tests suivants
resetAuditFixture($auditsDir, $owner, $contributor);

// ─── Nettoyage ───────────────────────────────────────────────────────────────

@unlink($auditsDir . FIXED_AUDIT_ID . '.json');
@unlink($criteriaFile);
@unlink($usersFile);

exit(T::summaryAndExitCode());
