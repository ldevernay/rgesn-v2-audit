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
 * RGESN V2 2024 Audit Tool — Mentions légales & politique de confidentialité
 *
 * ⚠️ CE FICHIER CONTIENT DES CHAMPS À COMPLÉTER (repérables par [À COMPLÉTER]).
 * Le contenu ci-dessous est un CANEVAS, pas un texte juridique prêt à l'emploi :
 * il doit être relu et complété avec les informations réelles de la structure
 * qui exploite cet outil (raison sociale, hébergeur, contact DPO, etc.), et
 * idéalement relu par un service juridique avant mise en production.
 *
 * Page volontairement accessible sans connexion (comme l'exige la loi pour
 * les mentions légales), même si le reste de l'outil nécessite une session.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$current_user = current_user(); // nullable, page publique
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentions légales & confidentialité — Outil d'audit RGESN V2 2024</title>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

<header class="bg-white border-bottom">
    <div class="container-fluid px-4 py-3 d-flex align-items-center justify-content-between" style="max-width:1400px;margin:0 auto;">
        <a href="<?= $current_user !== null ? 'index.php' : 'login.php' ?>" class="d-flex align-items-center text-decoration-none">
            <img src="assets/images/logo.svg" alt="" height="32" class="me-2">
            <span class="fw-bold text-dark">Outil d'audit RGESN</span>
        </a>
        <a href="<?= $current_user !== null ? 'index.php' : 'login.php' ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Retour
        </a>
    </div>
</header>

<main class="container py-5" style="max-width:840px;">

    <h1 class="h3 fw-bold mb-4">Mentions légales & politique de confidentialité</h1>

    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
        Cette page est un canevas généré automatiquement. Les champs marqués
        <strong>[À COMPLÉTER]</strong> doivent être renseignés avec les informations
        réelles de la structure qui exploite cet outil avant mise en production.
    </div>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Éditeur du site</h2>
        <p class="text-muted">
            [À COMPLÉTER] — Raison sociale, forme juridique, adresse du siège social,
            numéro SIRET, capital social (le cas échéant), directeur de la publication.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Hébergement</h2>
        <p class="text-muted">
            [À COMPLÉTER] — Raison sociale de l'hébergeur, adresse, contact.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Données personnelles collectées</h2>
        <p>Cet outil est réservé aux personnes disposant d'un compte (auditeurs, administrateurs). Les données suivantes sont enregistrées pour les besoins de son fonctionnement :</p>
        <ul>
            <li><strong>Comptes utilisateurs</strong> : identifiant de connexion, nom affiché, mot de passe (jamais stocké en clair — uniquement sous forme de hash cryptographique irréversible), rôle (auditeur ou administrateur).</li>
            <li><strong>Contenu des audits</strong> : nom et URL des projets audités, nom de l'auditeur, réponses et commentaires saisis lors de l'audit.</li>
            <li><strong>Journal technique</strong> : horodatage des tentatives de connexion échouées, à des fins de sécurité (protection contre les attaques par force brute), conservé 15 minutes glissantes.</li>
        </ul>
    </section>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Finalité et base légale</h2>
        <p>
            Ces données sont traitées dans le cadre de la gestion des accès à un outil
            professionnel interne et de la réalisation des audits d'écoconception.
            La base légale du traitement est [À COMPLÉTER : intérêt légitime de
            l'employeur / exécution d'un contrat de travail ou de prestation, selon
            le contexte réel d'utilisation].
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Durée de conservation</h2>
        <p>
            [À COMPLÉTER] — Les comptes et audits sont conservés tant qu'ils sont
            nécessaires à l'activité, puis supprimés ou archivés selon une politique
            à définir par la structure exploitant l'outil.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Destinataires des données</h2>
        <p>
            Les données d'un compte sont visibles par la personne concernée, par les
            administrateurs de l'outil, et par toute personne avec qui un audit a été
            explicitement partagé par son propriétaire. Un audit marqué "public" est
            consultable en lecture seule par toute personne disposant du lien, y
            compris sans connexion.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Vos droits</h2>
        <p>
            Conformément au Règlement Général sur la Protection des Données (RGPD),
            vous disposez d'un droit d'accès, de rectification, d'effacement et de
            portabilité de vos données. Pour exercer ces droits, contactez
            [À COMPLÉTER : adresse e-mail ou contact du référent RGPD / DPO].
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Cookies</h2>
        <p>
            Un seul cookie technique est utilisé (identifiant de session), strictement
            nécessaire au fonctionnement de la connexion. Il est exempté de consentement
            au sens de la réglementation ePrivacy, et n'est utilisé à aucune fin de
            mesure d'audience ou de publicité.
        </p>
    </section>

    <section class="mb-5">
        <h2 class="h5 fw-semibold">Ressources tierces</h2>
        <p>
            Cet outil n'effectue aucun appel à un service tiers externe pendant son
            utilisation : les bibliothèques logicielles utilisées (Bootstrap) sont
            hébergées localement, sur ce même serveur.
        </p>
    </section>

</main>

</body>
</html>
