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
 * RGESN V2 2024 Audit Tool — Suppression d'un utilisateur (CLI, secours)
 *
 * Usage : php bin/delete_user.php
 *
 * Depuis l'ajout des écrans web (users.php), ce script n'est plus le chemin
 * normal : il reste disponible comme solution de secours ("break glass"),
 * par exemple si plus aucun compte admin valide ne permet de se connecter.
 *
 * Réutilise les mêmes fonctions que l'interface web
 * (includes/auth.php::delete_user_record(), includes/audit_store.php) pour
 * ne jamais avoir deux implémentations divergentes de cette logique.
 *
 * NOTE : ce script n'a pas de notion de "session" — il n'applique donc pas
 * le garde-fou web "on ne peut pas se supprimer soi-même" (qui n'a de sens
 * que dans un contexte de session authentifiée). Le garde-fou "dernier
 * admin", lui, s'applique dans tous les cas.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Accès refusé : ce script ne peut être exécuté qu'en ligne de commande.\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_store.php';

function prompt(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

function prompt_yes_no(string $label): bool
{
    $answer = strtolower(prompt($label . ' (oui/non) : '));
    return in_array($answer, ['oui', 'o', 'yes', 'y'], true);
}

echo "=== Suppression d'un utilisateur — Outil d'audit RGESN V2 2024 ===\n\n";

$login  = prompt('Identifiant de connexion (login) à supprimer : ');
$target = find_user_by_login($login);

if ($target === null) {
    fwrite(STDERR, "Erreur : aucun utilisateur avec cet identifiant.\n");
    exit(1);
}

// ─── Garde-fou précoce : ne jamais supprimer le dernier compte admin ────────
// (delete_user_record() l'impose de toute façon en dernier recours, mais on
// le vérifie ici AVANT de faire répondre l'opérateur à tous les prompts
// suivants pour rien.)

if (($target['role'] ?? '') === 'admin') {
    $remainingAdmins = array_filter(
        load_users(),
        fn($u) => ($u['role'] ?? '') === 'admin' && ($u['id'] ?? '') !== $target['id']
    );
    if (count($remainingAdmins) === 0) {
        fwrite(STDERR, "Erreur : impossible de supprimer le DERNIER compte admin — plus personne ne pourrait alors gérer les comptes ni les audits orphelins.\n");
        fwrite(STDERR, "Créez d'abord un autre compte admin (via users.php ou bin/create_user.php) si vous souhaitez vraiment retirer celui-ci.\n");
        exit(1);
    }
}

$involvement  = find_user_audit_involvement($target['id']);
$ownedCount   = count($involvement['owned']);
$sharedCount  = count($involvement['contributing']);

echo "\nUtilisateur trouvé : {$target['name']} ({$target['login']}, rôle : {$target['role']})\n";
echo "  - Audits dont il/elle est propriétaire : {$ownedCount}\n";
echo "  - Audits où il/elle est contributeur (sans en être propriétaire) : {$sharedCount}\n\n";

// ─── Décision sur les audits possédés ────────────────────────────────────────

$newOwnerId = null;

if ($ownedCount > 0) {
    echo "Que faire des audits dont {$target['login']} est propriétaire ?\n";
    echo "  1) Les réassigner à un autre utilisateur existant\n";
    echo "  2) Les supprimer définitivement avec le compte\n";
    echo "  3) Annuler (ne rien supprimer)\n";

    $choice = '';
    while (!in_array($choice, ['1', '2', '3'], true)) {
        $choice = prompt('Choix (1/2/3) : ');
    }

    if ($choice === '3') {
        echo "Suppression annulée.\n";
        exit(0);
    }

    if ($choice === '1') {
        $newOwnerLogin = prompt('Identifiant du nouveau propriétaire : ');
        $newOwner = find_user_by_login($newOwnerLogin);
        if ($newOwner === null || $newOwner['id'] === $target['id']) {
            fwrite(STDERR, "Erreur : utilisateur de destination introuvable (ou identique au compte supprimé).\n");
            exit(1);
        }
        $newOwnerId = $newOwner['id'];
    }
}

// ─── Confirmation finale ─────────────────────────────────────────────────────

echo "\nRécapitulatif :\n";
echo "  - Suppression du compte : {$target['login']}\n";
if ($ownedCount > 0) {
    echo $newOwnerId !== null
        ? "  - {$ownedCount} audit(s) réassigné(s)\n"
        : "  - {$ownedCount} audit(s) SUPPRIMÉ(S) définitivement\n";
}
if ($sharedCount > 0) {
    echo "  - Retrait de son statut de contributeur sur {$sharedCount} audit(s) d'autres propriétaires\n";
}

if (!prompt_yes_no('Confirmer ?')) {
    echo "Suppression annulée.\n";
    exit(0);
}

// ─── Exécution ────────────────────────────────────────────────────────────────

$auditResult = reassign_or_delete_owned_audits($target['id'], $newOwnerId);

if (!empty($auditResult['errors'])) {
    fwrite(STDERR, "\nErreur(s) lors du traitement des audits :\n");
    foreach ($auditResult['errors'] as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    exit(1);
}

// Acteur synthétique sans session réelle : le garde-fou "auto-suppression"
// ne s'applique pas ici (voir note en tête de fichier).
$deleteResult = delete_user_record($target['id'], ['id' => '']);

if (!$deleteResult['success']) {
    fwrite(STDERR, "\nErreur : " . ($deleteResult['error'] ?? 'suppression impossible') . "\n");
    exit(1);
}

echo "\nUtilisateur '{$target['login']}' supprimé avec succès.\n";
