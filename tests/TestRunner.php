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
 * RGESN V2 2024 Audit Tool — Mini framework de tests (sans dépendance externe)
 *
 * Composer/PHPUnit ne sont pas installables dans cet environnement (packagist.org
 * n'est pas accessible), donc on utilise un runner "assert" maison, volontairement
 * simple. Chaque fichier de tests/cases/*.php est un script procédural qui appelle
 * T::assert*(...) ; tests/run.php les inclut tous et affiche le bilan.
 */

final class T
{
    private static int $pass = 0;
    private static int $fail = 0;
    private static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public static function assertTrue(bool $cond, string $message): void
    {
        self::record($cond, $message);
    }

    public static function assertFalse(bool $cond, string $message): void
    {
        self::record(!$cond, $message);
    }

    public static function assertNull($value, string $message): void
    {
        self::record($value === null, $message . ' (obtenu : ' . var_export($value, true) . ')');
    }

    public static function assertSame($expected, $actual, string $message): void
    {
        $ok = $expected === $actual;
        self::record(
            $ok,
            $message . ($ok ? '' : ' — attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true))
        );
    }

    public static function assertContains($needle, array $haystack, string $message): void
    {
        self::record(in_array($needle, $haystack, true), $message);
    }

    private static function record(bool $ok, string $message): void
    {
        if ($ok) {
            self::$pass++;
            echo "  \033[32m✓\033[0m {$message}\n";
        } else {
            self::$fail++;
            echo "  \033[31m✗ ÉCHEC\033[0m {$message}\n";
        }
    }

    public static function summaryAndExitCode(): int
    {
        $total = self::$pass + self::$fail;
        echo "\n" . str_repeat('─', 50) . "\n";
        if (self::$fail === 0) {
            echo "\033[32m{$total}/{$total} tests passés.\033[0m\n";
        } else {
            echo "\033[31m" . self::$fail . " échec(s) sur {$total} tests.\033[0m\n";
        }
        // Ligne machine-readable, utilisée par tests/run.php pour agréger les
        // résultats de chaque cas de test (exécuté dans un processus PHP séparé).
        echo "SUMMARY pass=" . self::$pass . " fail=" . self::$fail . "\n";
        return self::$fail === 0 ? 0 : 1;
    }
}
