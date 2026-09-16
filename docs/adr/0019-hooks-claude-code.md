# ADR-0019 — Hooks Claude Code : l'impact avant la modification

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 3 (hooks et skills), § 4.8 (carte d'impact via `PreToolUse`), § 5.1, § 9 (phase 1)

## Contexte

Un outil MCP n'est utilisé que si Claude pense à l'appeler. Un hook, lui, s'exécute à coup sûr. Le
§ 4.8 propose un hook `PreToolUse` qui donne l'impact d'un fichier au moment où Claude s'apprête à le
modifier. C'est aussi le mécanisme sur lequel la gate (ADR-0023) et l'injection de règles
(ADR-0026) s'appuieront : le fixer ici évite trois intégrations différentes.

## Décision

### Commande d'entrée unique

`devtools claude:hook <événement>` lit sur l'entrée standard le JSON que Claude Code transmet au hook,
et écrit sur la sortie la réponse attendue. Un seul point d'entrée, un sous-traitant par événement
(`Claude\Hook\PreToolUseHandler`…), pour que les ADR suivantes ajoutent un traitement sans ajouter de
commande.

### Événements du lot

| Événement | Filtre | Effet |
|---|---|---|
| `PreToolUse` | outils `Edit`, `Write`, `MultiEdit` | calcule l'impact du fichier visé (ADR-0016) et l'**ajoute au contexte** de Claude : workflows touchés, pages à relire, tests existants. **Ne bloque jamais.** |
| `SessionStart` | — | ajoute une ligne de contexte : nombre de workflows, workflows périmés, rappel des outils MCP s'ils sont installés |

- Silence quand l'impact est vide et que le fichier est couvert : pas de bruit dans le contexte.
- Fichier **non couvert** : une ligne le signale (« aucun workflow ne référence ce fichier »).
- Toute erreur du hook (index absent, XML invalide) → sortie 0, aucune injection, message sur la
  sortie d'erreur : **un hook DevTools ne doit jamais empêcher Claude de travailler**.

### Installation

`devtools claude:install --hooks` fusionne les entrées dans `.claude/settings.json` du projet
(versionné, partagé par l'équipe). Les entrées ajoutées portent la commande `devtools claude:hook`,
ce qui permet à `claude:uninstall --hooks` de retirer exactement celles-là.

⚠️ Le format exact des entrées et de la réponse attendue est lu dans la documentation Claude Code **au
moment d'implémenter** et figé par des fixtures ; il n'est pas recopié ici pour ne pas le figer faux.

## Budget d'exécution

`PreToolUse` : **< 300 ms de bout en bout**, démarrage PHP compris — il s'exécute à chaque
modification. Aucune extraction, aucun hash : lecture de l'index uniquement.

## Hors périmètre

Hook `Stop` et blocage de fin de tâche (ADR-0023) ; injection des règles (ADR-0026) ; hooks sur les
lectures de fichiers ; configuration utilisateur globale (`~/.claude`).

## Critères d'acceptation

- [ ] Fixtures d'entrée JSON par événement ; réponses figées en snapshot
- [ ] `Edit` d'un service partagé → contexte listant ses workflows et pages
- [ ] Fichier non couvert → une ligne ; fichier couvert sans impact → rien
- [ ] Index absent ou XML corrompu → sortie 0, rien injecté, cause sur stderr
- [ ] Installation qui fusionne un `settings.json` existant ; désinstallation qui le restaure
- [ ] < 300 ms mesurés sur le projet généré de 300 routes
- [ ] Session réelle vérifiée une fois dans Claude Code
- [ ] README : section « Hooks Claude Code »

## Conséquences

- Claude modifie un fichier en sachant ce qu'il touche, sans avoir à le demander — ce qui rend la
  carte d'impact aussi critique que sa fraîcheur.

## Dépendances

ADR-0016.
