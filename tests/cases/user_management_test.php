#!/usr/bin/env php
<?php
/**
 * Tests unitaires — verrou sémantique (lock_status/apply_lock/clear_lock) et
 * opérations atomiques sur les utilisateurs (create/update/delete/lock).
 *
 * Fichier autonome, exécuté dans son propre processus PHP (voir tests/run.php).
 * USERS_FILE pointe vers un fichier de fixture isolé, jamais le vrai
 * data/users.json.
 */

require_once __DIR__ . '/../TestRunner.php';

define('USERS_FILE', __DIR__ . '/../tmp/user_mgmt_users.json');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

function reset_fixture(array $users): void
{
    @mkdir(dirname(USERS_FILE), 0755, true);
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ═════════════════════════════════════════════════════════════════════════
T::section('auth.php — lock_status() / apply_lock() / clear_lock() / lock_is_stale() [pur]');
// ═════════════════════════════════════════════════════════════════════════

$alice = ['id' => 'u-alice', 'login' => 'alice', 'name' => 'Alice'];

$unlocked = ['id' => 'x'];
T::assertFalse(lock_status($unlocked)['locked'], 'Un enregistrement sans locked_by est considéré non verrouillé');

$fresh = apply_lock($unlocked, $alice);
$status = lock_status($fresh);
T::assertTrue($status['locked'], 'apply_lock() verrouille bien l\'enregistrement');
T::assertSame('u-alice', $status['by_id'], 'lock_status() renvoie le bon id du détenteur');
T::assertSame('Alice', $status['by_name'], 'lock_status() renvoie le bon nom du détenteur');
T::assertFalse($status['stale'], 'Un verrou qui vient d\'être posé n\'est pas expiré (stale)');

$cleared = clear_lock($fresh);
T::assertFalse(lock_status($cleared)['locked'], 'clear_lock() libère bien le verrou');

$oldRecord = $unlocked;
$oldRecord['locked_by'] = 'u-alice';
$oldRecord['locked_at'] = date('c', time() - EDIT_LOCK_TIMEOUT_SECONDS - 60);
$oldStatus = lock_status($oldRecord);
T::assertTrue($oldStatus['stale'], 'Un verrou posé il y a plus de EDIT_LOCK_TIMEOUT_SECONDS est expiré (stale)');
T::assertFalse($oldStatus['locked'], 'Un verrou expiré n\'est plus considéré comme "locked" (peut être repris)');

T::assertTrue(lock_is_stale(null), 'lock_is_stale(null) est vrai (pas de verrou = "expiré")');
T::assertTrue(lock_is_stale('date-invalide'), 'Une date de verrou illisible est traitée comme expirée, par prudence');

// ═════════════════════════════════════════════════════════════════════════
T::section('auth.php — create_user_record()');
// ═════════════════════════════════════════════════════════════════════════

reset_fixture([
    ['id' => 'u1', 'login' => 'existant', 'name' => 'Existant', 'role' => 'auditeur', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)],
]);

$r = create_user_record('nouveau', 'Nouveau Compte', 'auditeur', 'motdepasse123');
T::assertTrue($r['success'], 'Création avec des données valides réussit');
T::assertSame('nouveau', $r['user']['login'] ?? null, 'Le login est bien enregistré');
T::assertTrue(
    password_verify('motdepasse123', $r['user']['password_hash'] ?? ''),
    'Le mot de passe est bien haché et vérifiable'
);
T::assertTrue(array_key_exists('locked_by', $r['user']) && $r['user']['locked_by'] === null, 'Un nouvel utilisateur démarre sans verrou');

$r = create_user_record('existant', 'Doublon', 'auditeur', 'motdepasse123');
T::assertFalse($r['success'], 'Création avec un login déjà utilisé échoue');

$r = create_user_record('', 'Sans login', 'auditeur', 'motdepasse123');
T::assertFalse($r['success'], 'Un login vide est rejeté');

$r = create_user_record('court', 'Mdp court', 'auditeur', '1234');
T::assertFalse($r['success'], 'Un mot de passe de moins de 8 caractères est rejeté');

$r = create_user_record('mauvais-role', 'Test', 'superadmin', 'motdepasse123');
T::assertFalse($r['success'], 'Un rôle invalide est rejeté');

$after = json_decode(file_get_contents(USERS_FILE), true);
T::assertSame(2, count($after), 'Seules les créations valides ont été persistées (2 au total)');

// ═════════════════════════════════════════════════════════════════════════
T::section('auth.php — update_user_record()');
// ═════════════════════════════════════════════════════════════════════════

$admin1 = ['id' => 'admin-1', 'login' => 'admin1', 'name' => 'Admin Un', 'role' => 'admin', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)];
$admin2 = ['id' => 'admin-2', 'login' => 'admin2', 'name' => 'Admin Deux', 'role' => 'admin', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)];
$auditeur1 = ['id' => 'aud-1', 'login' => 'aud1', 'name' => 'Auditeur Un', 'role' => 'auditeur', 'password_hash' => password_hash('x', PASSWORD_DEFAULT)];

reset_fixture([$admin1, $admin2, $auditeur1]);

$r = update_user_record('aud-1', ['name' => 'Nouveau Nom'], $admin1);
T::assertTrue($r['success'], 'Changer le nom d\'un utilisateur réussit');
$u = find_user_by_id('aud-1');
T::assertSame('Nouveau Nom', $u['name'], 'Le nouveau nom est bien persisté');

$r = update_user_record('aud-1', ['role' => 'admin'], $admin1);
T::assertTrue($r['success'], 'Promouvoir un utilisateur au rôle admin réussit');
T::assertSame('admin', find_user_by_id('aud-1')['role'], 'Le rôle admin est bien persisté');

$r = update_user_record('admin-1', ['role' => 'auditeur'], $admin1);
T::assertFalse($r['success'], 'Un admin ne peut pas se rétrograder lui-même');
T::assertSame('admin', find_user_by_id('admin-1')['role'], 'Son rôle n\'a pas changé malgré la tentative');

$r = update_user_record('admin-2', ['role' => 'auditeur'], $admin1);
T::assertTrue($r['success'], 'Un admin PEUT rétrograder un AUTRE admin');

// À ce stade : admin-1 (admin), admin-2 (auditeur), aud-1 (admin, promu plus haut)
$r = update_user_record('aud-1', ['role' => 'auditeur'], $admin1);
T::assertTrue($r['success'], 'Rétrograder un admin qui n\'est pas le dernier fonctionne');

$r = update_user_record('admin-1', ['role' => 'auditeur'], $admin1);
// Ici on retente sur soi-même : ce garde-fou (auto-rétrogradation) prime,
// donc le message d'erreur est bien celui-là et pas "dernier admin".
T::assertFalse($r['success'], 'admin-1 reste protégé (auto-rétrogradation), même s\'il est aussi le dernier admin');

reset_fixture([$admin1, $auditeur1]); // un seul admin, acteur différent du cible pour isoler le test
$r = update_user_record('admin-1', ['role' => 'auditeur'], $auditeur1);
T::assertFalse($r['success'], 'Impossible de rétrograder le DERNIER admin, même par quelqu\'un d\'autre');

$r = update_user_record('aud-1', ['password' => 'nouveaumotdepasse'], $admin1);
T::assertTrue($r['success'], 'Changer le mot de passe d\'un utilisateur réussit');
T::assertTrue(
    password_verify('nouveaumotdepasse', find_user_by_id('aud-1')['password_hash']),
    'Le nouveau mot de passe est bien appliqué'
);

$r = update_user_record('aud-1', ['password' => 'x'], $admin1);
T::assertFalse($r['success'], 'Un nouveau mot de passe trop court est rejeté');

$r = update_user_record('id-inexistant', ['name' => 'X'], $admin1);
T::assertFalse($r['success'], 'Modifier un utilisateur inexistant échoue proprement');

// ═════════════════════════════════════════════════════════════════════════
T::section('auth.php — delete_user_record()');
// ═════════════════════════════════════════════════════════════════════════

reset_fixture([$admin1, $admin2, $auditeur1]);

$r = delete_user_record('admin-1', $admin1);
T::assertFalse($r['success'], 'Un admin ne peut pas supprimer son propre compte');

$r = delete_user_record('aud-1', $admin1);
T::assertTrue($r['success'], 'Un admin peut supprimer un autre utilisateur');
T::assertNull(find_user_by_id('aud-1'), 'L\'utilisateur supprimé n\'existe plus');

reset_fixture([$admin1]);
$r = delete_user_record('admin-1', $admin2); // acteur fictif, juste pour ne pas déclencher le garde-fou "soi-même"
T::assertFalse($r['success'], 'Impossible de supprimer le DERNIER compte admin');

// ═════════════════════════════════════════════════════════════════════════
T::section('auth.php — change_own_password()');
// ═════════════════════════════════════════════════════════════════════════

$plainPassword = 'motdepasseinitial';
reset_fixture([
    ['id' => 'u-self', 'login' => 'self', 'name' => 'Self', 'role' => 'auditeur', 'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT)],
]);
$self = ['id' => 'u-self', 'login' => 'self', 'name' => 'Self', 'role' => 'auditeur'];

$r = change_own_password($self, 'mauvais-mot-de-passe', 'nouveaumotdepasse123');
T::assertFalse($r['success'], 'Un mauvais mot de passe actuel est rejeté');

$r = change_own_password($self, $plainPassword, 'nouveaumotdepasse123');
T::assertTrue($r['success'], 'Changer son propre mot de passe avec le bon mot de passe actuel réussit');
T::assertTrue(
    password_verify('nouveaumotdepasse123', find_user_by_id('u-self')['password_hash']),
    'Le nouveau mot de passe est bien appliqué'
);

$r = change_own_password($self, 'nouveaumotdepasse123', 'x');
T::assertFalse($r['success'], 'Un nouveau mot de passe trop court est rejeté');

// ═════════════════════════════════════════════════════════════════════════
T::section('auth.php — acquire_user_lock() / release_user_lock() / renew_user_lock()');
// ═════════════════════════════════════════════════════════════════════════

$editor = ['id' => 'u-editor', 'login' => 'editor', 'name' => 'Éditeur'];
$other  = ['id' => 'u-other', 'login' => 'other', 'name' => 'Autre'];

reset_fixture([
    ['id' => 'target', 'login' => 'cible', 'name' => 'Cible', 'role' => 'auditeur', 'password_hash' => 'x', 'locked_by' => null, 'locked_by_name' => null, 'locked_at' => null],
]);

$r = acquire_user_lock('target', $editor);
T::assertTrue($r['success'], 'Premier verrouillage d\'un enregistrement libre réussit');

$r = acquire_user_lock('target', $other);
T::assertFalse($r['success'], 'Un second utilisateur ne peut pas verrouiller un enregistrement déjà verrouillé');
T::assertSame('u-editor', $r['status']['by_id'] ?? null, 'Le statut renvoyé indique bien qui détient le verrou');

$r = acquire_user_lock('target', $editor);
T::assertTrue($r['success'], 'Le détenteur du verrou peut le "re-verrouiller" (rouvrir sa propre session d\'édition)');

$r = release_user_lock('target', $other);
T::assertFalse($r, 'Un tiers ne peut pas libérer un verrou qu\'il ne détient pas (sans forcer)');

$r = release_user_lock('target', $other, force: true);
T::assertTrue($r, 'Un forçage explicite permet de libérer un verrou détenu par quelqu\'un d\'autre');

$status = lock_status(find_user_by_id('target'));
T::assertFalse($status['locked'], 'Après libération (forcée), l\'enregistrement est bien déverrouillé');

// Renouvellement (heartbeat)
acquire_user_lock('target', $editor);
$before = find_user_by_id('target')['locked_at'];
sleep(1);
$renewed = renew_user_lock('target', $editor);
T::assertTrue($renewed, 'Le détenteur du verrou peut le renouveler (heartbeat)');
$after = find_user_by_id('target')['locked_at'];
T::assertTrue($after !== $before, 'locked_at a bien été mis à jour par le renouvellement');

$renewed = renew_user_lock('target', $other);
T::assertFalse($renewed, 'Un tiers ne peut pas renouveler un verrou qu\'il ne détient pas');

// ─── Nettoyage ───────────────────────────────────────────────────────────────
@unlink(USERS_FILE);
@unlink(USERS_FILE . '.lock');

exit(T::summaryAndExitCode());
