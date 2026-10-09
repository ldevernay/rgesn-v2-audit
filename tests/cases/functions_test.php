#!/usr/bin/env php
<?php
/**
 * Tests unitaires — includes/functions.php
 *
 * Fichier autonome : exécutable seul (`php tests/cases/functions_test.php`)
 * ou via tests/run.php, qui lance chaque cas dans son propre processus PHP
 * (isolation des constantes — voir tests/run.php).
 */

require_once __DIR__ . '/../TestRunner.php';
require_once __DIR__ . '/../../includes/functions.php';

T::section('functions.php — is_valid_uuid()');

T::assertTrue(
    is_valid_uuid('550e8400-e29b-41d4-a716-446655440000'),
    'Un UUID v4 valide est reconnu'
);
T::assertFalse(
    is_valid_uuid('not-a-uuid'),
    'Une chaîne quelconque est rejetée'
);
T::assertFalse(
    is_valid_uuid('550e8400-e29b-31d4-a716-446655440000'), // version 3, pas 4
    'Un UUID de version différente de 4 est rejeté'
);
T::assertFalse(
    is_valid_uuid('550e8400e29b41d4a716446655440000'), // sans tirets
    'Un UUID sans tirets est rejeté'
);
T::assertTrue(
    is_valid_uuid('550E8400-E29B-41D4-A716-446655440000'), // majuscules
    'Un UUID en majuscules est accepté (insensible à la casse)'
);

T::section('functions.php — generate_uuid()');

$uuid1 = generate_uuid();
$uuid2 = generate_uuid();

T::assertTrue(is_valid_uuid($uuid1), 'generate_uuid() produit un UUID valide');
T::assertFalse($uuid1 === $uuid2, 'Deux appels successifs produisent des UUID différents');

T::section('functions.php — esc()');

T::assertSame(
    '&lt;script&gt;alert(1)&lt;/script&gt;',
    esc('<script>alert(1)</script>'),
    'Les balises HTML sont échappées'
);
T::assertSame(
    'L&#039;audit',
    esc("L'audit"),
    "Les apostrophes sont échappées (ENT_QUOTES)"
);

T::section('functions.php — nl2p()');

$html = nl2p("Premier paragraphe.\n\nDeuxième paragraphe avec\ndeux lignes.");
T::assertTrue(
    str_contains($html, '<p>Premier paragraphe.</p>'),
    'Un simple paragraphe est entouré de balises <p>'
);
T::assertTrue(
    str_contains($html, '<br>'),
    'Les retours à la ligne simples au sein d\'un paragraphe deviennent des <br>'
);

$htmlList = nl2p("Texte avant.\n- Item 1\n- Item 2");
T::assertTrue(
    str_contains($htmlList, '<ul>') && str_contains($htmlList, '<li>Item 1</li>'),
    'Les lignes préfixées par "- " deviennent une liste à puces'
);

$htmlEscaped = nl2p('<b>gras</b>');
T::assertFalse(
    str_contains($htmlEscaped, '<b>gras</b>'),
    'Le contenu est échappé avant transformation (pas d\'injection HTML)'
);

exit(T::summaryAndExitCode());
