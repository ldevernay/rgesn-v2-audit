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
 * RGESN V2 2024 Audit Tool — Fonctions et constantes partagées
 */

// ── Noms des thématiques ──────────────────────────────────────────────────────
const THEMATICS = [
    1 => 'Stratégie',
    2 => 'Spécifications',
    3 => 'Architecture',
    4 => 'UX/UI',
    5 => 'Contenus',
    6 => 'Frontend',
    7 => 'Backend',
    8 => 'Hébergement',
    9 => 'Algorithmie',
];

// ── Validation de l'UUID ──────────────────────────────────────────────────────
function is_valid_uuid(string $uuid): bool
{
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $uuid
    );
}

// ── Génération d'un UUID v4 (audits, utilisateurs) ────────────────────────────
function generate_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Mutex de fichier (flock) ───────────────────────────────────────────────────
//
// Protège tout cycle lire → modifier → écrire sur un fichier JSON partagé
// (data/users.json, un audit donné) contre les écritures concurrentes : sans
// ça, deux requêtes qui lisent le même fichier "en même temps" peuvent
// chacune écrire leur propre version modifiée, et l'une écrase silencieusement
// le travail de l'autre (perte de mise à jour), même si chaque écriture prise
// isolément est atomique (fichier temporaire + rename()).
//
// $path est verrouillé via un fichier compagnon "<path>.lock" : le verrou
// existe indépendamment du fichier de données lui-même (qui peut ne pas
// encore exister au premier appel), et n'est jamais lui-même écrasé par
// l'écriture atomique (rename()) du fichier de données.
//
// $callback reçoit le contenu actuel du fichier (chaîne, '' si le fichier
// n'existe pas encore) et doit renvoyer un tuple [$toWrite, $result] :
//   - $toWrite : chaîne à écrire dans le fichier, ou null pour ne rien écrire
//     (callback en lecture seule, ou abandon après vérification d'une
//     condition — par ex. "le verrou sémantique est déjà pris par un autre").
//   - $result : valeur renvoyée telle quelle par with_file_lock() à l'appelant
//     (ex : le tableau de données décodé, un booléen de succès...).
function with_file_lock(string $path, callable $callback): mixed
{
    $lockPath = $path . '.lock';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $handle = fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException("Impossible d'ouvrir le fichier de verrou : {$lockPath}");
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException("Impossible d'acquérir le verrou : {$lockPath}");
        }

        $current = file_exists($path) ? file_get_contents($path) : '';
        [$toWrite, $result] = $callback($current);

        if ($toWrite !== null) {
            $tmp = $path . '.tmp';
            file_put_contents($tmp, $toWrite, LOCK_EX);
            rename($tmp, $path);
        }

        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// ── Échappement HTML ──────────────────────────────────────────────────────────
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Texte brut vers HTML (paragraphes + listes à puces "- ") ─────────────────
function nl2p(string $text): string {
    $text = esc($text);
    $html = '';
    foreach (array_filter(explode("\n\n", $text)) as $para) {
        $lines = array_filter(array_map('trim', explode("\n", trim($para))), fn($l) => $l !== '');
        $textBuffer = [];
        $listBuffer = [];
        foreach ($lines as $line) {
            if (preg_match('/^-\s+(.+)$/', $line, $m)) {
                if ($textBuffer) {
                    $html .= '<p>' . implode('<br>', $textBuffer) . '</p>';
                    $textBuffer = [];
                }
                $listBuffer[] = $m[1];
            } else {
                if ($listBuffer) {
                    $html .= '<ul>' . implode('', array_map(fn($i) => "<li>{$i}</li>", $listBuffer)) . '</ul>';
                    $listBuffer = [];
                }
                $textBuffer[] = $line;
            }
        }
        if ($textBuffer) {
            $html .= '<p>' . implode('<br>', $textBuffer) . '</p>';
        }
        if ($listBuffer) {
            $html .= '<ul>' . implode('', array_map(fn($i) => "<li>{$i}</li>", $listBuffer)) . '</ul>';
        }
    }
    return $html;
}
