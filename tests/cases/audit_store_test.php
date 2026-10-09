#!/usr/bin/env php
<?php
/**
 * Tests unitaires — includes/audit_store.php (modèle propriétaire /
 * contributeurs / visibilité, verrou sémantique, mutations atomiques).
 *
 * Fichier autonome, exécuté dans son propre processus PHP (voir tests/run.php).
 * AUDITS_DIR et USERS_FILE pointent vers des fixtures isolées.
 */

require_once __DIR__ . '/../TestRunner.php';

define('AUDITS_DIR', __DIR__ . '/../tmp/audit_store_test/audits/');
define('USERS_FILE', __DIR__ . '/../tmp/audit_store_test/users.json');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_store.php';

function reset_audit_fixtures(): void
{
    if (is_dir(AUDITS_DIR)) {
        foreach (glob(AUDITS_DIR . '*') as $f) {
            @unlink($f);
        }
    } else {
        mkdir(AUDITS_DIR, 0755, true);
    }
}

function make_audit(string $id, ?string $owner_id, array $contributors = [], string $visibility = 'contributors'): void
{
    $audit = [
        'id' => $id, 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
        'status' => 'en cours', 'score' => 0.0, 'owner_id' => $owner_id, 'contributors' => $contributors,
        'visibility' => $visibility, 'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null,
        'project' => ['name' => "Audit {$id}", 'url' => ''], 'auditor' => ['name' => 'Test'], 'criteria' => [],
    ];
    file_put_contents(AUDITS_DIR . $id . '.json', json_encode($audit, JSON_UNESCAPED_UNICODE));
}

@mkdir(dirname(USERS_FILE), 0755, true);
$admin = ['id' => 'admin-id', 'login' => 'admin', 'name' => 'Admin', 'role' => 'admin'];
$alice = ['id' => 'alice-id', 'login' => 'alice', 'name' => 'Alice', 'role' => 'auditeur'];
$bob   = ['id' => 'bob-id', 'login' => 'bob', 'name' => 'Bob', 'role' => 'auditeur'];
$carla = ['id' => 'carla-id', 'login' => 'carla', 'name' => 'Carla', 'role' => 'auditeur'];
file_put_contents(USERS_FILE, json_encode([
    array_merge($admin, ['password_hash' => 'x']),
    array_merge($alice, ['password_hash' => 'x']),
    array_merge($bob, ['password_hash' => 'x']),
    array_merge($carla, ['password_hash' => 'x']),
]));

$auditA = '550e8400-e29b-41d4-a716-446655440101';
$auditB = '550e8400-e29b-41d4-a716-446655440102';
$auditC = '550e8400-e29b-41d4-a716-446655440103';
$auditD = '550e8400-e29b-41d4-a716-446655440104';

// ═════════════════════════════════════════════════════════════════════════
T::section('audit_store.php — add_audit_contributor() / remove_audit_contributor()');
// ═════════════════════════════════════════════════════════════════════════

reset_audit_fixtures();
make_audit($auditA, $alice['id']);

$r = add_audit_contributor($auditA, $bob['id'], $alice);
T::assertTrue($r['success'], 'Le propriétaire peut ajouter un contributeur');
T::assertSame([$bob['id']], $r['contributors'], 'Bob apparaît bien dans la liste des contributeurs');

$r = add_audit_contributor($auditA, $bob['id'], $alice); // doublon
T::assertTrue($r['success'], 'Ajouter deux fois le même contributeur ne plante pas');
T::assertSame([$bob['id']], $r['contributors'], 'Pas de doublon dans la liste');

$r = add_audit_contributor($auditA, $carla['id'], $bob); // Bob n'est que contributeur, pas owner
T::assertFalse($r['success'], 'Un simple contributeur ne peut pas gérer les autres contributeurs');

$r = add_audit_contributor($auditA, $alice['id'], $alice); // le owner s'ajoute lui-même
T::assertFalse($r['success'], 'Le propriétaire ne peut pas s\'ajouter lui-même comme contributeur (déjà implicite)');

$r = add_audit_contributor($auditA, $carla['id'], $admin); // un admin peut gérer, même sans être owner
T::assertTrue($r['success'], 'Un admin peut gérer les contributeurs même sans être propriétaire');

$r = remove_audit_contributor($auditA, $bob['id'], $alice);
T::assertTrue($r['success'], 'Le propriétaire peut retirer un contributeur');
T::assertSame([$carla['id']], $r['contributors'], 'Bob n\'apparaît plus, Carla reste');

$r = remove_audit_contributor($auditA, $carla['id'], $bob); // Bob a été retiré juste avant, n'a plus de droits
T::assertFalse($r['success'], 'Un non-propriétaire/non-admin ne peut pas retirer un contributeur');

// ═════════════════════════════════════════════════════════════════════════
T::section('audit_store.php — set_audit_visibility()');
// ═════════════════════════════════════════════════════════════════════════

reset_audit_fixtures();
make_audit($auditA, $alice['id']);

$r = set_audit_visibility($auditA, 'public', $alice);
T::assertTrue($r['success'], 'Le propriétaire peut rendre l\'audit public');
T::assertSame('public', read_audit($auditA)['visibility'], 'La visibilité "public" est bien persistée');

$r = set_audit_visibility($auditA, 'authenticated', $bob); // Bob n'a aucun lien avec l'audit
T::assertFalse($r['success'], 'Un utilisateur sans lien avec l\'audit ne peut pas changer sa visibilité');

$r = set_audit_visibility($auditA, 'valeur-invalide', $alice);
T::assertFalse($r['success'], 'Une valeur de visibilité invalide est rejetée');

// ═════════════════════════════════════════════════════════════════════════
T::section('audit_store.php — transfer_audit_owner()');
// ═════════════════════════════════════════════════════════════════════════

reset_audit_fixtures();
make_audit($auditA, $alice['id'], [$carla['id']]);

$r = transfer_audit_owner($auditA, $bob['id'], $alice);
T::assertTrue($r['success'], 'Le propriétaire peut céder la propriété à quelqu\'un d\'autre');
T::assertSame($bob['id'], $r['owner_id'], 'Le nouveau propriétaire est bien Bob');

$updated = read_audit($auditA);
T::assertSame($bob['id'], $updated['owner_id'], 'owner_id est bien persisté');
T::assertTrue(
    in_array($alice['id'], $updated['contributors'], true),
    'L\'ancien propriétaire (Alice) devient automatiquement contributeur (ne perd pas l\'accès)'
);
T::assertTrue(
    in_array($carla['id'], $updated['contributors'], true),
    'Les contributeurs déjà présents (Carla) sont conservés'
);
T::assertFalse(
    in_array($bob['id'], $updated['contributors'], true),
    'Le nouveau propriétaire n\'est pas listé en doublon comme contributeur'
);

$r = transfer_audit_owner($auditA, 'id-inexistant', $bob);
T::assertFalse($r['success'], 'Céder la propriété à un utilisateur inexistant est rejeté');

$r = transfer_audit_owner($auditA, $bob['id'], $bob);
T::assertFalse($r['success'], 'Céder la propriété à soi-même (déjà propriétaire) est rejeté');

$r = transfer_audit_owner($auditA, $carla['id'], $alice); // Alice n'est plus que contributrice depuis le transfert plus haut
T::assertFalse($r['success'], 'Un simple contributeur (ex-propriétaire) ne peut plus céder la propriété');

// ═════════════════════════════════════════════════════════════════════════
T::section('audit_store.php — verrou sémantique (acquire/release/renew)');
// ═════════════════════════════════════════════════════════════════════════

reset_audit_fixtures();
make_audit($auditA, $alice['id']);

$r = acquire_audit_lock($auditA, $alice);
T::assertTrue($r['success'], 'Premier verrouillage réussit');

$r = acquire_audit_lock($auditA, $bob);
T::assertFalse($r['success'], 'Un autre utilisateur ne peut pas verrouiller un audit déjà verrouillé');
T::assertSame($alice['id'], $r['status']['by_id'] ?? null, 'Le statut indique bien qui détient le verrou');

T::assertFalse(release_audit_lock($auditA, $bob), 'Un tiers ne peut pas libérer un verrou qu\'il ne détient pas');
T::assertTrue(release_audit_lock($auditA, $bob, force: true), 'Un forçage explicite permet de libérer le verrou');
T::assertFalse(lock_status(read_audit($auditA))['locked'], 'L\'audit est bien déverrouillé après le forçage');

acquire_audit_lock($auditA, $alice);
T::assertTrue(renew_audit_lock($auditA, $alice), 'Le détenteur peut renouveler son verrou');
T::assertFalse(renew_audit_lock($auditA, $bob), 'Un tiers ne peut pas renouveler un verrou qu\'il ne détient pas');

// ═════════════════════════════════════════════════════════════════════════
T::section('audit_store.php — find_user_audit_involvement()');
// ═════════════════════════════════════════════════════════════════════════

reset_audit_fixtures();
make_audit($auditA, $alice['id']);                       // Alice propriétaire
make_audit($auditB, $bob['id'], [$alice['id']]);          // Alice contributrice
make_audit($auditC, $bob['id']);                          // sans lien avec Alice
make_audit($auditD, $alice['id'], [$carla['id']]);        // Alice propriétaire + contributrice tierce

$involvement = find_user_audit_involvement($alice['id']);
T::assertSame(2, count($involvement['owned']), 'Alice est recensée comme propriétaire de 2 audits (A et D)');
T::assertSame(1, count($involvement['contributing']), 'Alice est recensée comme contributrice sur 1 audit (B)');

$ownedIds = array_column($involvement['owned'], 'id');
T::assertTrue(in_array($auditA, $ownedIds, true) && in_array($auditD, $ownedIds, true), 'Les bons audits sont identifiés comme possédés');

$involvementNobody = find_user_audit_involvement('id-inconnu');
T::assertSame(0, count($involvementNobody['owned']) + count($involvementNobody['contributing']), 'Un utilisateur sans aucun lien n\'apparaît nulle part');

// ═════════════════════════════════════════════════════════════════════════
T::section('audit_store.php — reassign_or_delete_owned_audits() : suppression (new_owner_id = null)');
// ═════════════════════════════════════════════════════════════════════════

reset_audit_fixtures();
make_audit($auditA, $alice['id']);
make_audit($auditB, $bob['id'], [$alice['id']]);
make_audit($auditD, $alice['id'], [$carla['id']]);

$result = reassign_or_delete_owned_audits($alice['id'], null);

T::assertSame(2, $result['deleted'], '2 audits possédés par Alice sont supprimés');
T::assertSame(0, $result['reassigned'], 'Aucune réassignation demandée (new_owner_id = null)');
T::assertSame(1, $result['contributions_cleaned'], 'Le statut de contributrice d\'Alice sur l\'audit B est nettoyé');
T::assertSame([], $result['errors'], 'Aucune erreur');

T::assertFalse(file_exists(AUDITS_DIR . $auditA . '.json'), 'Le fichier audit A a bien été supprimé');
T::assertFalse(file_exists(AUDITS_DIR . $auditD . '.json'), 'Le fichier audit D a bien été supprimé');

$auditBData = read_audit($auditB);
T::assertSame([], $auditBData['contributors'], 'Alice a bien été retirée des contributeurs de l\'audit B');
T::assertSame($bob['id'], $auditBData['owner_id'], 'Le propriétaire de l\'audit B (Bob) n\'est pas affecté');

// ═════════════════════════════════════════════════════════════════════════
T::section('audit_store.php — reassign_or_delete_owned_audits() : réassignation');
// ═════════════════════════════════════════════════════════════════════════

reset_audit_fixtures();
make_audit($auditA, $alice['id']);
make_audit($auditD, $alice['id'], [$carla['id']]);

$result = reassign_or_delete_owned_audits($alice['id'], $bob['id']);

T::assertSame(2, $result['reassigned'], '2 audits possédés par Alice sont réassignés');
T::assertSame(0, $result['deleted'], 'Rien n\'est supprimé quand on réassigne');

T::assertSame($bob['id'], read_audit($auditA)['owner_id'], 'Le nouveau propriétaire de l\'audit A est bien Bob');

$auditDData = read_audit($auditD);
T::assertSame($bob['id'], $auditDData['owner_id'], 'Le nouveau propriétaire de l\'audit D est bien Bob');
T::assertSame([$carla['id']], $auditDData['contributors'], 'Les contributeurs existants (Carla) sont conservés');
T::assertFalse(in_array($alice['id'], $auditDData['contributors'], true), 'Alice (ancienne propriétaire, supprimée) n\'est pas ajoutée comme contributrice ici');

// ─── Nettoyage ───────────────────────────────────────────────────────────────
reset_audit_fixtures();
@unlink(USERS_FILE);

exit(T::summaryAndExitCode());
