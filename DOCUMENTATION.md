# Documentation

Ce fichier détaille les règles métier utilisées au sein du projet.

## Statuts d'un critère

Chaque critère d'un audit a l'un de ces quatre statuts :

- **Conforme**
- **Non conforme**
- **Non applicable**
- **Non testé** (statut par défaut à la création de l'audit)

## Calcul du score d'un audit

Chaque critère est pondéré selon sa priorité :

| Priorité | Poids |
|---|---|
| Prioritaire | ×1,5 |
| Recommandé | ×1,25 |
| Modéré | ×1,0 |

Le score se calcule uniquement à partir des critères **conformes** et **non-conformes** :

```
Score = (Σ poids des critères conformes) / (Σ poids des critères conformes et non-conformes) × 100
```

Les critères **non applicables** et **non testés** sont exclus du calcul (ni au numérateur, ni au dénominateur).

Exemple avec 2 critères Prioritaires conformes (poids 1,5) et 1 critère Modéré non conforme (poids 1,0) :

```
Score = (1,5 + 1,5) / (1,5 + 1,5 + 1,0) × 100 = 3 / 4 × 100 = 75%
```

Si aucun critère n'est encore conforme ni non-conforme (dénominateur nul), le score est de 0%.

Le score est recalculé et sauvegardé à chaque modification de l'audit.

## Taux de complétion

Le taux de complétion mesure la part des critères déjà évalués (peu importe leur statut, y compris non applicable) :

```
Taux de complétion = (Nombre de critères dont le statut n'est pas "Non testé") / (Nombre total de critères) × 100
```

Il est calculé à la fois globalement pour l'audit et pour chaque thématique.

## Statut d'un audit

Un audit est :

- **En cours** dès sa création, et le reste tant que l'utilisateur n'a pas explicitement terminé l'audit.
- **Terminé** uniquement après un clic sur le bouton « Terminer l'audit ». Si des critères sont encore « Non testé » à ce moment-là, une confirmation est demandée, mais l'audit peut être terminé quand même.

Ce passage à « Terminé » est déclenché uniquement par une action explicite de l'utilisateur, il n'y a pas de passage automatique basé sur le taux de complétion, et aucune action de l'interface ne permet de repasser un audit terminé à l'état « En cours ».

Le rapport public (`report.php`) reste accessible dès que l'audit existe, qu'il soit en cours ou terminé.

## Dates

### Création de l'audit

`created_at` est fixée une seule fois, à la soumission du formulaire de création (ou de duplication) de l'audit.

### Mise à jour de l'audit

`updated_at` est actualisée à chaque sauvegarde de l'audit : modification des informations du projet/auditeur, changement de statut d'un critère, saisie d'un commentaire ou d'une action, ou passage à « Terminé ». La liste des audits est triée par `updated_at` décroissant.

### Duplication d'un audit

Dupliquer un audit crée un nouvel audit indépendant :

- `created_at` et `updated_at` sont réinitialisées à la date de la duplication (elles ne reprennent pas celles de l'audit source).
- Le statut repart à « En cours », même si l'audit source était « Terminé ».
- Tous les critères (statuts, commentaires, actions) sont copiés tels quels depuis l'audit source, et le score est recalculé à partir de cette copie.
