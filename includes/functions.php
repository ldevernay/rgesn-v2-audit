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
