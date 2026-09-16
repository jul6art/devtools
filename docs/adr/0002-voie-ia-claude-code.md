# ADR-0002 — Voie IA du MVP : Claude Code rédige, DevTools reste déterministe

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 3 (couche Claude), § 4.3 étapes 2, 3, 7, § 4.6.2 étape 4, § 11 (coût, non-déterminisme), § 12 question 1

## Contexte

Le pipeline a besoin de Claude à trois endroits : rédiger une page (§ 4.3 étape 7), produire les
connaissances d'une stack inconnue (étape 2), trouver les points d'entrée d'une stack sans adaptateur
(étape 3). Le § 12 laisse ouvert : API Claude ou Claude Code ?

L'équipe travaille **déjà dans Claude Code**. Un client API impose une clé, un coût facturé à part,
une gestion d'erreurs réseau, et rend le cœur non testable sans mocks — pour un MVP dont
l'utilisateur a déjà Claude ouvert à côté.

Alternatives écartées :
- *Client API Claude dans le cœur* — clé, coût, réseau ; utile en CI, donc après le MVP.
- *DevTools lance `claude -p` en sous-processus* — dépend d'un binaire et de son authentification,
  sortie difficile à valider, et contourne le contexte de la session en cours.
- *Laisser Claude écrire directement dans `.devtools/`* — plus rien ne garantit le gabarit ni
  l'absence de chemins inventés (§ 4.5).

## Décision

### Un échange en trois temps, par fichiers

```
1. devtools workflows:inspect        → écrit les faits + un BRIEF par tâche d'IA
                                        .devtools/pending/<tâche>.brief.xml
2. Claude Code (skill devtools-inspect) → lit chaque brief, écrit un BROUILLON
                                        .devtools/pending/<tâche>.draft.{md,xml}
3. devtools workflows:apply          → valide chaque brouillon ; accepté → intégré, pending nettoyé ;
                                        refusé → erreurs listées, brouillon conservé
```

- **DevTools ne parle jamais à Claude.** Il prépare et il valide ; le cœur reste déterministe et se
  teste avec des brouillons enregistrés.
- **Trois types de tâche**, un format de brief chacun : `page` (ADR-0011), `knowledge`
  (ADR-0012), `discovery` (ADR-0013).
- **Tout brouillon est une donnée non fiable.** Il est validé (gabarit, schéma, chemins existants)
  avant d'écrire quoi que ce soit hors de `pending/`. Ce qui vient du code analysé et arrive dans
  un brief est cité comme donnée, jamais comme instruction.
- **`.devtools/pending/` est ignoré par git** : c'est un espace de travail, pas de la documentation.
  La liste des tâches se recalcule à chaque `inspect`.
- **Le skill Claude Code** est livré par DevTools (`resources/claude/skills/devtools-inspect/`) et
  installé dans le projet par `devtools claude:install`, qui écrit
  `.claude/skills/devtools-inspect/SKILL.md`. Le skill enchaîne : `inspect`, traitement des briefs,
  `apply`, puis relance jusqu'à ce qu'aucune tâche ne reste.
- **Les prompts sont versionnés** (`resources/prompts/<tâche>/v<N>.md`) ; la version utilisée est
  inscrite dans le brief et recopiée dans le XML de suivi (`<generated prompt="page/1">`).
- **`--no-ai`** : aucun brief n'est écrit ; les pages restent factuelles (ADR-0008). C'est le mode de
  la CI et de tous les snapshots de test.

## Budget d'exécution

- Zéro appel réseau depuis le cœur.
- **Un brief par workflow à (ré)écrire, jamais un par workflow existant** : la fraîcheur
  (ADR-0010) décide de la liste, et c'est elle qui tient le coût IA du § 11.

## Hors périmètre

Client API Claude et exécution en CI avec rédaction ; parallélisation de la rédaction par
sous-agents ; serveur MCP (qui pourra servir les briefs plus tard).

## Critères d'acceptation

- [ ] Le format des trois briefs est défini par un XSD dans `resources/schemas/` (livré par les
      ADR-0011, 0012, 0013)
- [ ] Aucune classe du cœur n'ouvre de connexion réseau (test d'architecture)
- [ ] `devtools claude:install` écrit le skill, et ne l'écrase pas s'il a été modifié sans
      `--force`
- [ ] Un brouillon invalide ne modifie aucun fichier hors de `.devtools/pending/`

## Conséquences

- La rédaction IA exige une session Claude Code : sans elle, la documentation reste factuelle mais
  complète et à jour.
- Le jour où l'API arrive, elle ne sera qu'un autre producteur de brouillons : `apply` et sa
  validation ne changent pas.

## Dépendances

ADR-0001.
