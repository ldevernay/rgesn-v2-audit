#!/usr/bin/env php
<?php
/**
 * Tests unitaires — includes/auth.php
 *
 * C'est le fichier le plus critique du point de vue sécurité (qui a accès à
 * quel audit, avec quel niveau de droits), donc on le couvre en détail.
 *
 * Fichier autonome, exécuté dans son propre processus PHP (voir tests/run.php)
 * afin que USERS_FILE puisse être défini librement sans entrer en collision
 * avec les autres cas de test.
 */

require_once __DIR__ . '/../TestRunner.php';

// Fichier utilisateurs isolé, dédié aux tests (jamais le vrai data/users.json)
define('USERS_FILE', __DIR__ . '/../tmp/users_test.json');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// ─── Fixtures ──────────────────────────────────────────────────────────────

$owner    = ['id' => 'user-owner',  'name' => 'Alice', 'role' => 'auditeur'];
$contrib  = ['id' => 'user-contrib', 'name' => 'Bob',   'role' => 'auditeur'];
$stranger = ['id' => 'user-nobody', 'name' => 'Denis', 'role' => 'auditeur'];
$admin    = ['id' => 'user-admin',  'name' => 'Admin', 'role' => 'admin'];

$audit = [
    'id'           => 'audit-1',
    'owner_id'     => $owner['id'],
    'contributors' => [$contrib['id']],
    'visibility'   => 'contributors',
];

// ─── get_user_permission() ──────────────────────────────────────────────────

T::section('auth.php — get_user_permission()');

T::assertSame('owner', get_user_permission($audit, $owner), 'Le propriétaire obtient la permission "owner"');
T::assertSame('contributor', get_user_permission($audit, $contrib), 'Un contributeur obtient la permission "contributor"');
T::assertNull(get_user_permission($audit, $stranger), 'Un utilisateur non lié à l\'audit n\'a aucun accès (null)');
T::assertSame('owner', get_user_permission($audit, $admin), 'Un admin obtient toujours "owner", même sans lien direct avec l\'audit');

$auditNoContributors = ['id' => 'audit-2', 'owner_id' => $owner['id'], 'contributors' => [], 'visibility' => 'contributors'];
T::assertNull(get_user_permission($auditNoContributors, $stranger), 'Sans contributeurs, un utilisateur tiers n\'a aucun accès');

$auditLegacy = ['id' => 'audit-legacy']; // audit créé avant l'ajout de owner_id/contributors/visibility
T::assertNull(get_user_permission($auditLegacy, $owner), 'Un audit sans owner_id (legacy) n\'accorde aucun accès à un utilisateur normal');
T::assertSame('owner', get_user_permission($auditLegacy, $admin), 'Un admin garde accès même à un audit legacy sans owner_id');
T::assertNull(get_user_permission($auditLegacy, $stranger), 'Une visibilité absente (legacy) équivaut à "contributors" (la plus restrictive) par défaut');

T::section('auth.php — get_user_permission() : visibilité à 3 niveaux + visiteur anonyme');

$auditContributorsOnly = ['id' => 'a', 'owner_id' => $owner['id'], 'contributors' => [], 'visibility' => 'contributors'];
$auditAuthenticated    = ['id' => 'b', 'owner_id' => $owner['id'], 'contributors' => [], 'visibility' => 'authenticated'];
$auditPublic           = ['id' => 'c', 'owner_id' => $owner['id'], 'contributors' => [], 'visibility' => 'public'];

T::assertNull(get_user_permission($auditContributorsOnly, $stranger), 'visibility="contributors" : un utilisateur connecté non lié n\'a aucun accès');
T::assertNull(get_user_permission($auditContributorsOnly, null), 'visibility="contributors" : un visiteur anonyme n\'a aucun accès');

T::assertSame('lecture', get_user_permission($auditAuthenticated, $stranger), 'visibility="authenticated" : tout utilisateur connecté a un accès lecture');
T::assertNull(get_user_permission($auditAuthenticated, null), 'visibility="authenticated" : un visiteur anonyme n\'a PAS accès');

T::assertSame('lecture', get_user_permission($auditPublic, $stranger), 'visibility="public" : tout utilisateur connecté a un accès lecture');
T::assertSame('lecture', get_user_permission($auditPublic, null), 'visibility="public" : un visiteur anonyme a aussi un accès lecture');

T::assertFalse(
    permission_can_edit(get_user_permission($auditPublic, null)),
    'Un visiteur anonyme ne peut jamais éditer, même sur un audit public'
);
T::assertFalse(
    permission_can_manage(get_user_permission($auditPublic, null)),
    'Un visiteur anonyme ne peut jamais gérer les contributeurs/la visibilité, même sur un audit public'
);

// Le propriétaire garde "owner" (accès complet) même si l'audit est public.
T::assertSame(
    'owner',
    get_user_permission($auditPublic, $owner),
    'Le propriétaire connecté garde son accès "owner" complet, même si l\'audit est public'
);

// ─── permission_can_view / can_edit / can_manage ────────────────────────────

T::section('auth.php — permission_can_view() / permission_can_edit() / permission_can_manage()');

T::assertTrue(permission_can_view('owner'), 'owner peut voir');
T::assertTrue(permission_can_view('contributor'), 'contributor peut voir');
T::assertTrue(permission_can_view('lecture'), 'lecture peut voir');
T::assertFalse(permission_can_view(null), 'null (aucun accès) ne peut pas voir');

T::assertTrue(permission_can_edit('owner'), 'owner peut éditer');
T::assertTrue(permission_can_edit('contributor'), 'contributor peut éditer');
T::assertFalse(permission_can_edit('lecture'), 'lecture NE peut PAS éditer les critères');
T::assertFalse(permission_can_edit(null), 'null ne peut pas éditer');

T::assertTrue(permission_can_manage('owner'), 'owner peut gérer les contributeurs/la visibilité/supprimer');
T::assertFalse(permission_can_manage('contributor'), 'contributor NE peut PAS gérer les contributeurs/la visibilité/supprimer');
T::assertFalse(permission_can_manage('lecture'), 'lecture NE peut PAS gérer les contributeurs/la visibilité/supprimer');

// ─── load_users() / find_user_by_login() / find_user_by_id() / save_users() ──

T::section('auth.php — persistance des utilisateurs (fichier de test isolé)');

@mkdir(dirname(USERS_FILE), 0755, true);

$testUsers = [
    ['id' => 'u1', 'login' => 'alice', 'name' => 'Alice', 'password_hash' => password_hash('secret123', PASSWORD_DEFAULT), 'role' => 'auditeur'],
    ['id' => 'u2', 'login' => 'bob',   'name' => 'Bob',   'password_hash' => password_hash('secret456', PASSWORD_DEFAULT), 'role' => 'admin'],
];
file_put_contents(USERS_FILE, json_encode($testUsers, JSON_PRETTY_PRINT));

$loaded = load_users();
T::assertSame(2, count($loaded), 'load_users() charge bien les 2 utilisateurs du fixture');

$byLogin = find_user_by_login('alice');
T::assertSame('u1', $byLogin['id'] ?? null, 'find_user_by_login() retrouve le bon utilisateur');
T::assertNull(find_user_by_login('inconnu'), 'find_user_by_login() renvoie null si le login n\'existe pas');

$byId = find_user_by_id('u2');
T::assertSame('bob', $byId['login'] ?? null, 'find_user_by_id() retrouve le bon utilisateur');
T::assertNull(find_user_by_id('id-inexistant'), 'find_user_by_id() renvoie null si l\'id n\'existe pas');

T::assertTrue(
    password_verify('secret123', $byLogin['password_hash']),
    'Le mot de passe stocké est bien vérifiable via password_verify()'
);
T::assertFalse(
    password_verify('mauvais-mot-de-passe', $byLogin['password_hash']),
    'Un mauvais mot de passe est rejeté par password_verify()'
);

// Nettoyage du fichier de test
@unlink(USERS_FILE);

exit(T::summaryAndExitCode());
