#!/usr/bin/env php
<?php
/**
 * Copyright (C) 2026  Grégory Biondo
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * RGESN V2 2024 Audit Tool — Lance l'intégralité de la suite de tests
 *
 * Chaque fichier tests/cases/*_test.php est exécuté dans SON PROPRE processus
 * PHP (`php <fichier>`). C'est volontaire : plusieurs cas ont besoin de
 * redéfinir les mêmes constantes (USERS_FILE, AUDITS_DIR, CRITERES_FILE) vers
 * des chemins de test différents, et PHP ne permet pas de redéfinir une
 * constante au sein d'un même processus. L'isolation par sous-processus
 * évite aussi tout effet de bord entre cas de test (fichiers temporaires,
 * données globales, etc.) — un cas de test ne peut jamais en polluer un autre.
 *
 * Usage : php tests/run.php
 */

$cases = glob(__DIR__ . '/cases/*_test.php');
sort($cases);

if (!$cases) {
    fwrite(STDERR, "Aucun fichier de test trouvé dans tests/cases/\n");
    exit(1);
}

$totalPass = 0;
$totalFail = 0;
$anyFailure = false;

foreach ($cases as $case) {
    $label = basename($case);
    echo "\n\033[1;34m▶ " . $label . "\033[0m\n";

    $output = shell_exec('php ' . escapeshellarg($case) . ' 2>&1');
    echo $output;

    if (preg_match('/SUMMARY pass=(\d+) fail=(\d+)/', $output, $m)) {
        $totalPass += (int) $m[1];
        $totalFail += (int) $m[2];
        if ((int) $m[2] > 0) {
            $anyFailure = true;
        }
    } else {
        // Le fichier n'a pas produit de ligne SUMMARY exploitable : on le
        // considère en échec pour ne jamais masquer un crash silencieusement.
        fwrite(STDERR, "\033[31mAvertissement : {$label} n'a pas produit de résumé exploitable (crash ?)\033[0m\n");
        $anyFailure = true;
    }
}

echo "\n" . str_repeat('═', 50) . "\n";
echo "\033[1mBilan global : {$totalPass} réussi(s), {$totalFail} échoué(s)\033[0m\n";

exit($anyFailure ? 1 : 0);
