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
 * RGESN V2 2024 Audit Tool — Création d'un utilisateur (CLI uniquement)
 *
 * Usage : php bin/create_user.php
 *
 * Ce script n'est volontairement pas exposé via le web : la gestion des
 * comptes se fait "à la main" par la personne ayant un accès au serveur,
 * jamais via api.php.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Accès refusé : ce script ne peut être exécuté qu'en ligne de commande.\n");
}

require_once __DIR__ . '/../includes/functions.php';
// On charge uniquement les fonctions de données utilisateurs, sans démarrer
// de session (inutile en CLI et session_start() y échouerait de toute façon).
if (!defined('USERS_FILE')) {
    define('USERS_FILE', __DIR__ . '/../data/users.json');
}

function cli_load_users(): array
{
    if (!file_exists(USERS_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(USERS_FILE), true);
    return is_array($data) ? $data : [];
}

function cli_save_users(array $users): bool
{
    $tmp = USERS_FILE . '.tmp';
    $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, USERS_FILE);
}

function prompt(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

function prompt_password(string $label): string
{
    echo $label;
    // Masque la saisie si possible (Linux/macOS avec `stty` disponible)
    $has_stty = trim((string) shell_exec('command -v stty 2>/dev/null')) !== '';
    if ($has_stty) {
        shell_exec('stty -echo');
    }
    $pw = trim((string) fgets(STDIN));
    if ($has_stty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $pw;
}

echo "=== Création d'un utilisateur — Outil d'audit RGESN V2 2024 ===\n\n";

$users = cli_load_users();

$login = prompt('Identifiant de connexion (login) : ');
if ($login === '') {
    fwrite(STDERR, "Erreur : l'identifiant ne peut pas être vide.\n");
    exit(1);
}
foreach ($users as $u) {
    if (($u['login'] ?? '') === $login) {
        fwrite(STDERR, "Erreur : cet identifiant est déjà utilisé.\n");
        exit(1);
    }
}

$name = prompt('Nom affiché : ');
if ($name === '') {
    fwrite(STDERR, "Erreur : le nom affiché ne peut pas être vide.\n");
    exit(1);
}

$role = '';
while (!in_array($role, ['admin', 'auditeur'], true)) {
    $role = prompt('Rôle (admin/auditeur) : ');
}

$password = prompt_password('Mot de passe : ');
$password_confirm = prompt_password('Confirmer le mot de passe : ');

if (strlen($password) < 8) {
    fwrite(STDERR, "Erreur : le mot de passe doit faire au moins 8 caractères.\n");
    exit(1);
}
if ($password !== $password_confirm) {
    fwrite(STDERR, "Erreur : les deux mots de passe ne correspondent pas.\n");
    exit(1);
}

$new_user = [
    'id'            => generate_uuid(),
    'login'         => $login,
    'name'          => $name,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role'          => $role,
];

$users[] = $new_user;

if (!cli_save_users($users)) {
    fwrite(STDERR, "Erreur : impossible d'écrire dans " . USERS_FILE . "\n");
    exit(1);
}

echo "\nUtilisateur '{$login}' ({$role}) créé avec succès. id : {$new_user['id']}\n";
