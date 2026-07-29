[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

# Outil d'audit RGESN V2 2024

Plateforme web d'audit d'écoconception numérique basée sur le Référentiel Général d'Écoconception des Services Numériques (RGESN V2 de 2024). Il permet de créer et gérer des audits, d'évaluer les 78 critères répartis en 9 thématiques du RGESN V2, et de générer des rapports publics partageables.

Vous pouvez visualiser l'outil et le tester en démonstration : [Outil d'audit RGESN V2 2024](https://axenum.fr/rgesn-v2-audit/)

## Public-cible

- Les **consultants spécialistes du numérique responsable** qui font des audits d'écoconception avec le RGESN V2.
- Les **agences web et digitales** qui font des auto-audits d'écoconception en interne sur les services numériques développés pour leurs clients.

## Conseil de déploiement

Cet outil est destiné à un déploiement sur votre infrastructure, soit en local, soit sur vos serveurs, afin que vous puissiez centraliser l'ensemble de vos audits d'écoconception.
L'outil ne dispose pas actuellement de fonctionnalité d'authentification, afin de ne pas rajouter une base de données à celui-ci. Les données sont directement enregistrées dans un fichier JSON par audit. Ce qui facilite les transferts si besoin.

## Stack technique

- PHP 8.2+ (pas de framework)
- Bootstrap 5.3.3, Bootstrap Icons, Vanilla JS
- Stockage JSON (aucune base de données)

## Architecture

- Pas de framework PHP : fichiers PHP directs (`index.php`, `audit.php`, `report.php`, `api.php`).
- **API** : `api.php` gère toutes les requêtes `fetch()` du front-end via le paramètre `action`.
- **Données** : chaque audit est un fichier `UUID.json` dans `data/audits/`. Écriture atomique (`.tmp` + `rename()`).
- **Critères** : référentiel centralisé dans `data/criteria/criteria_settings.json` (78 entrées).
- **JS** : pattern IIFE, `async`/`await`, helper `apiRequest()`. `audit.js` gère toute la logique de la page audit.
- **CSS** : `assets/css/style.css` avec des custom properties CSS (`--rgesn-indigo`, `--rgesn-nc`, etc.).

## Structure des fichiers clés

```
rgesn-v2-audit/
├── index.php                        # Tableau de bord (liste des audits, création)
├── audit.php                        # Page d'audit (évaluation des 78 critères)
├── report.php                       # Rapport public (lecture seule, partageable)
├── api.php                          # API back-end (toutes les actions AJAX)
├── release-notes.php                # Notes de version
├── includes/
│   ├── functions.php                # Fonctions et constantes partagées (esc, nl2p, THEMATICS...)
│   └── favicon.php                  # Balises favicon/meta communes à toutes les pages
├── data/
│   ├── audits/                      # Un fichier UUID.json par audit
│   └── criteria/
│       └── criteria_settings.json   # Référentiel : 78 critères RGESN (thematic, title, priority, difficulty, target, jobs, objective, implementation, control)
└── assets/
    ├── css/style.css
    ├── js/
    │   ├── app.js                   # JS partagé
    │   └── audit.js                 # JS de la page audit
    ├── images/logo.svg
    └── favicon/                     # apple-touch-icon, favicon-*.png, site.webmanifest, browserconfig.xml, etc.
```

## Modèle de données - Audit JSON

```json
{
  "id": "uuid-v4",
  "created_at": "ISO8601",
  "updated_at": "ISO8601",
  "project": { "name": "...", "url": "..." },
  "auditor": { "name": "..." },
  "criteria": [
    { "thematic_id": 1, "id": "1.1", "priority": "Prioritaire", "difficulty": "Faible", "status": "conforme|non-conforme|non-applicable|non-testé", "comment": "" }
  ]
}
```

## Actions API disponibles (`api.php`)

| Action | Méthode | Description |
|--------|---------|-------------|
| `list_audits` | GET | Liste tous les audits |
| `create_audit` | POST | Crée un audit (name, url, auditor requis) |
| `get_audit` | GET | Retourne un audit par UUID (non utilisée par le front-end actuel — audit.php/report.php lisent le JSON directement côté serveur) |
| `update_audit` | POST | Met à jour project/auditor/status **et** les critères (statut, commentaire, actions) via un tableau `criteria` — il n'y a pas d'action `update_criterion` séparée |
| `delete_audit` | POST | Supprime un audit |
| `duplicate_audit` | POST | Duplique un audit (critères copiés, dates et statut réinitialisés — voir `DOCUMENTATION.md`) |

## Dépendances

| Dépendance | Version | Licence | Chargement |
|---|---|---|---|
| [Bootstrap](https://getbootstrap.com/) | 5.3.3 | [MIT](https://github.com/twbs/bootstrap/blob/main/LICENSE) | CDN (jsDelivr) |
| [Bootstrap Icons](https://icons.getbootstrap.com/) | 1.11.3 | [MIT](https://github.com/twbs/icons/blob/main/LICENSE) | CDN (jsDelivr) |

> **Conseil d'écoconception** : par défaut, le projet charge Bootstrap et Bootstrap Icons depuis le CDN jsDelivr. Cela évite d'avoir à gérer les fichiers soi-même, mais ajoute une requête réseau vers un service tiers à chaque chargement de page, ce qui va à l'encontre des principes de sobriété numérique que cet outil évalue. Pour un déploiement plus écoresponsable, il est recommandé de télécharger Bootstrap et Bootstrap Icons et de les servir en local depuis `assets/`, en remplaçant les balises `<link>`/`<script>` correspondantes dans chaque page PHP (`index.php`, `audit.php`, `report.php`, `release-notes.php`).

## Conventions

- **PHP** : fonctions en `snake_case` ; échappement HTML systématique via le helper `esc()` (basé sur `htmlspecialchars`).
- **JavaScript** : `camelCase`, pattern IIFE, `async`/`await` ; pas de framework, pas de bundler.
- **Nommage des actions API** (`api.php`) : `snake_case` (ex. `create_audit`, `update_audit`, `duplicate_audit`).
- **IDs HTML** des blocs de statistiques : `statCardNc`, `statCardOk`, `statCardNa`, `statCardNt`.
- **Classes CSS de statut** : `non-conforme`, `conforme`, `non-applicable`, `non-testé`.
- **Écriture des fichiers d'audit** : toujours atomique (écriture dans un `.tmp` puis `rename()`) pour éviter toute corruption en cas d'interruption.

## Remerciements

Merci aux personnes qui ont testé les premières versions de cet outil et contribué à son amélioration avant sa publication en open source :

- Laurent Devernay Satyagraha, expert en numérique responsable
- Laurent Nguyen-Van de Imagence
- Anne Faubry des Designers Éthiques
- Christophe Clouzeau, Julien Wilhelm et Sébastien Rufer de Temesis

## Licence

Ce projet est distribué sous licence [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html).

Copyright (C) 2026 Grégory Biondo
