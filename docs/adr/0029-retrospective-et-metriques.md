# ADR-0029 — Rétrospective et métriques

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 2 (« historique conçu pour que les retours diminuent »), § 3.1 (module Retro), § 5.5, § 8 (retours humains par tâche livrée : −50 % à 3 mois)

## Contexte

Le besoin n'est pas de stocker des retours mais qu'ils **diminuent dans le temps** (§ 1.2). Sans mesure,
impossible de savoir si une règle sert, si une catégorie recule, ou si la métrique cible du § 8 est
tenue.

La métrique « retours humains par **tâche livrée** » exige de savoir ce qu'est une tâche : rien dans
les phases précédentes ne l'enregistre.

## Décision

### Journal des tâches

- Une **tâche** = une fin de travail passée par la gate avec succès (hook `Stop`, ADR-0023).
- `.devtools/journal/<AAAA-MM>.xml` (versionné, `journal.xsd`) : une entrée par tâche — date, commit
  de base et de fin, workflows impactés, résultat de la gate à chaque tentative (nombre d'échecs avant
  le succès, étapes en échec), règles ayant bloqué.
- Les retours (ADR-0025) référencent déjà un commit : un retour est **attribué** à la tâche dont la
  plage de commits le contient, ou à la dernière tâche livrée touchant son workflow.

### `devtools retro [--since=<date>] [--format=md|xml]`

Rapport `.devtools/reports/retro-<date>.md` (§ 5.5) :

| Section | Calcul |
|---|---|
| Retours humains par tâche livrée | par mois, et tendance vs les trois mois précédents (cible § 8) |
| Catégories récurrentes | occurrences par catégorie, en hausse / en baisse |
| Règles ayant bloqué la gate | `blocked` par règle — une règle qui bloque régulièrement est utile |
| Règles inactives | aucune injection ni blocage depuis N jours → **proposées à l'archivage** |
| Retours non promus | ≥ 2 occurrences et toujours `open` |
| Échecs de gate avant succès | par tâche : combien de passes pour arriver au vert |
| Retours du vérificateur vs humains | ce que le vérificateur trouve, et ce qu'il laisse passer |

`devtools rules:archive <id>` applique une proposition ; rien n'est archivé automatiquement.

## Budget d'exécution

Lecture du journal et des index ; aucun scénario, aucun git au-delà d'un `log` pour attribuer les
retours.

## Hors périmètre

Tableau de bord web ; métriques multi-projets ; export vers un outil externe.

## Critères d'acceptation

- [ ] Journal : une entrée par gate verte en fin de tâche, avec le nombre d'échecs précédents
- [ ] Attribution d'un retour à une tâche par plage de commits (dépôt temporaire)
- [ ] `retro` sur un jeu de données de fixture sur quatre mois : chaque section chiffrée comme attendu
      (snapshot)
- [ ] Règle inactive proposée à l'archivage, pas archivée
- [ ] README : section « Rétrospective »

## Conséquences

- La cible du § 8 (−50 % à 3 mois) devient mesurable, et le journal ajoute un fichier versionné par
  mois au projet.

## Dépendances

ADR-0027, ADR-0028.
