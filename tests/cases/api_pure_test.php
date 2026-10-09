#!/usr/bin/env php
<?php
/**
 * Tests unitaires — api.php (logique métier pure, hors routage HTTP)
 *
 * RGESN_API_TESTING empêche api.php d'exécuter le routage (headers, session,
 * switch $action) : seules les déclarations de fonctions sont chargées.
 * AUDITS_DIR / CRITERES_FILE sont redéfinis vers des fichiers de test isolés,
 * jamais les vraies données du projet.
 *
 * Fichier autonome, exécuté dans son propre processus PHP (voir tests/run.php).
 */

require_once __DIR__ . '/../TestRunner.php';

define('RGESN_API_TESTING', true);
define('AUDITS_DIR', __DIR__ . '/../tmp/audits_test/');
define('CRITERES_FILE', __DIR__ . '/../tmp/criteria_test.json');

require_once __DIR__ . '/../../api.php';

@mkdir(AUDITS_DIR, 0755, true);

// Référentiel de test : 3 critères de priorités différentes, poids connus
file_put_contents(CRITERES_FILE, json_encode([
    ['id' => 'c-prio',  'priority' => 'Prioritaire'], // poids 1.5
    ['id' => 'c-reco',  'priority' => 'Recommandé'],  // poids 1.25
    ['id' => 'c-mod',   'priority' => 'Modéré'],      // poids 1.0
]));

// ─── calculate_score() ───────────────────────────────────────────────────────

T::section('api.php — calculate_score()');

T::assertSame(0.0, calculate_score([]), 'Aucun critère => score 0');

$allConforme = [
    ['id' => 'c-prio', 'status' => 'conforme'],
    ['id' => 'c-reco', 'status' => 'conforme'],
    ['id' => 'c-mod',  'status' => 'conforme'],
];
T::assertSame(100.0, calculate_score($allConforme), 'Tous les critères conformes => score 100');

$allNonConforme = [
    ['id' => 'c-prio', 'status' => 'non-conforme'],
    ['id' => 'c-reco', 'status' => 'non-conforme'],
    ['id' => 'c-mod',  'status' => 'non-conforme'],
];
T::assertSame(0.0, calculate_score($allNonConforme), 'Tous les critères non conformes => score 0');

$onlyPrioConforme = [
    ['id' => 'c-prio', 'status' => 'conforme'],      // poids 1.5, conforme
    ['id' => 'c-reco', 'status' => 'non-conforme'],  // poids 1.25, non-conforme
];
// numerator = 1.5 ; denominator = 1.5 + 1.25 = 2.75 => 54.5 %
T::assertSame(54.5, calculate_score($onlyPrioConforme), 'Le poids "Prioritaire" (1.5) pèse plus que "Recommandé" (1.25) dans le score');

$excluded = [
    ['id' => 'c-prio', 'status' => 'conforme'],
    ['id' => 'c-reco', 'status' => 'non-applicable'],
    ['id' => 'c-mod',  'status' => 'non-testé'],
];
T::assertSame(100.0, calculate_score($excluded), 'Les critères "non-applicable" et "non-testé" sont exclus du calcul (ne pénalisent pas le score)');

// ─── calculate_completion() ─────────────────────────────────────────────────

T::section('api.php — calculate_completion()');

T::assertSame(0.0, calculate_completion([]), 'Aucun critère => complétion 0');

$half = [
    ['id' => 'a', 'status' => 'conforme'],
    ['id' => 'b', 'status' => 'non-testé'],
];
T::assertSame(50.0, calculate_completion($half), 'Un critère testé sur deux => 50% de complétion');

$fullyTested = [
    ['id' => 'a', 'status' => 'conforme'],
    ['id' => 'b', 'status' => 'non-applicable'], // compte comme "testé" pour la complétion
];
T::assertSame(100.0, calculate_completion($fullyTested), '"non-applicable" compte comme testé pour la complétion (contrairement au score)');

// ─── get_initial_criteria() ─────────────────────────────────────────────────

T::section('api.php — get_initial_criteria()');

$initial = get_initial_criteria();
T::assertSame(3, count($initial), 'get_initial_criteria() crée un critère par entrée du référentiel');
T::assertSame('non-testé', $initial[0]['status'] ?? null, 'Chaque critère initial démarre au statut "non-testé"');
T::assertSame('', $initial[0]['comment'] ?? null, 'Chaque critère initial démarre avec un commentaire vide');

// ─── read_audit() / write_audit() ───────────────────────────────────────────

T::section('api.php — read_audit() / write_audit()');

$fakeId = '550e8400-e29b-41d4-a716-446655440099';
$fakeAudit = ['id' => $fakeId, 'owner_id' => 'u1', 'shares' => [], 'criteria' => []];

T::assertTrue(write_audit($fakeId, $fakeAudit), 'write_audit() réussit son écriture');
T::assertTrue(file_exists(AUDITS_DIR . $fakeId . '.json'), 'Le fichier JSON de l\'audit est bien créé sur disque');

$reread = read_audit($fakeId);
T::assertSame('u1', $reread['owner_id'] ?? null, 'read_audit() relit correctement owner_id après écriture');

T::assertNull(read_audit('id-invalide'), 'read_audit() renvoie null pour un UUID invalide');
T::assertNull(read_audit('550e8400-e29b-41d4-a716-000000000000'), 'read_audit() renvoie null pour un UUID valide mais inexistant');

// ─── Nettoyage ───────────────────────────────────────────────────────────────

@unlink(AUDITS_DIR . $fakeId . '.json');
@unlink(CRITERES_FILE);

exit(T::summaryAndExitCode());
