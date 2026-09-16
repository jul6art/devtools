# ADR-0011 — Rédaction des pages par Claude Code

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.3 étape 7, § 4.5 (règles), § 4.8 (changelog par workflow), § 7.2 (famille IA), § 11 (coût, non-déterminisme)

## Contexte

La page factuelle (ADR-0008) dit **quoi** ; elle ne dit ni à quoi sert le workflow, ni comment il se
déroule, ni où sont les pièges. C'est l'apport de Claude (§ 4.3 étape 7), à condition qu'il reste
dans ses sections et n'invente aucun fait (§ 4.5). Ce lot met en œuvre l'échange de l'ADR-0002 pour
la tâche `page`.

## Décision

### Brief `page` (`.devtools/pending/page.<id>.brief.xml`, `page-brief.xsd`)

Écrit par `workflows:inspect` (sauf `--no-ai`) pour chaque workflow dont la décision de fraîcheur est
`create` ou `rewrite`, **et** pour chaque workflow `keep` dont la page n'a jamais été rédigée
(`mode="no-ai"`) — ce qui permet de rédiger plus tard une documentation d'abord produite en factuel.

Contenu : le `Workflow` sérialisé (ADR-0003), le chemin du `knowledge` de la stack (ADR-0012, absent
tant qu'il n'existe pas), la page actuelle, les **raisons** de fraîcheur (ADR-0010), le diff des
`<files>` depuis la dernière révision, la version du prompt (`resources/prompts/page/v1.md`), la
langue de rédaction (`config.xml`), et la **liste fermée des sections à rédiger** (`PageSection`
propriétaire Claude, ADR-0008) plus une ligne d'historique.

### Brouillon (`.devtools/pending/page.<id>.draft.md`)

Un Markdown qui ne contient **que** : les sections Claude (`## Résumé`, `## Parcours`, `## Données`,
`## Mécanismes transverses`, `## Points d'attention`), un bloc `## Préconditions` d'une ligne (inséré
dans la table « Déclencheur », seule cellule de cette section que Claude remplit) et un bloc
`## Changement` d'une ligne (la ligne d'« Historique », § 4.8).

### `devtools workflows:apply [path]`

Pour chaque brouillon, `PageDraftValidator` refuse si :
- une section hors de la liste fermée est présente, ou une section attendue manque ;
- `## Parcours` ne contient pas exactement un bloc ` ```mermaid ` de type `sequenceDiagram` ou
  `flowchart` ;
- **un chemin de fichier cité n'est pas dans le modèle** : toute chaîne en code inline qui ressemble à
  un chemin (contient `/` et une extension connue) doit appartenir à `Workflow::files` ou
  `Workflow::tests` — c'est la garde mécanique du « aucun chemin inventé » ;
- le brouillon référence une révision plus ancienne que le XML actuel (le code a changé depuis le
  brief) → refus `outdated`, le brief est régénéré au prochain `inspect`.

Accepté : la page est reconstruite = sections DevTools **régénérées depuis le modèle** + sections
Claude du brouillon ; ligne « Historique » ajoutée ; XML mis à jour (`mode="ai"`, `model` déclaré
par le brouillon en front-matter, `prompt="page/1"`) ; brief et brouillon supprimés. Refusé : erreurs
listées par brouillon, fichiers conservés, code de sortie 1.

### Le skill Claude Code

`devtools claude:install` installe `.claude/skills/devtools-inspect/SKILL.md` (ADR-0002), qui
prescrit : lancer `inspect` ; pour chaque brief, lire le `knowledge`, la page actuelle et
**les fichiers listés** ; écrire le brouillon ; lancer `apply` ; corriger les refus ; s'arrêter quand
`apply` ne trouve plus rien. Le prompt versionné est la seule source des consignes de rédaction ; le
skill y renvoie au lieu de les recopier.

### Menu

« À vérifier » liste les workflows avec un brief en attente (« rédaction en attente »), en plus des
statuts de fraîcheur.

## Budget d'exécution

- Rédactions demandées = workflows `create` + `rewrite` + jamais rédigés. **Un re-scan sans
  changement d'un projet entièrement rédigé ne produit aucun brief.**
- `apply` : validation locale, aucun processus lancé.

## Hors périmètre

Client API et rédaction en CI ; rédaction parallèle par sous-agents ; relecture de la qualité
rédactionnelle (au-delà des gardes mécaniques) ; glossaire métier (§ 4.8).

## Critères d'acceptation

Avec des brouillons enregistrés (« réponses de Claude » versionnées dans les fixtures) :

- [x] `inspect` sans `--no-ai` écrit un brief valide par workflow à rédiger, et aucun pour un
      workflow `keep` déjà rédigé
- [x] Brouillon conforme → page conforme au gabarit (tests de l'ADR-0008), sections DevTools
      identiques au rendu factuel, XML `mode="ai"`, ligne d'historique ajoutée, pending nettoyé
- [x] Un refus par règle : section en trop, section manquante, Parcours sans Mermaid, chemin inventé,
      brouillon `outdated` — chacun avec un message qui nomme la règle et la ligne
- [x] Un brouillon qui tente de réécrire « Composants impliqués » est refusé ; la page n'est pas
      modifiée
- [x] Une réécriture factuelle ultérieure (`--no-ai`) conserve les sections rédigées
- [x] `claude:install` : installation, refus d'écraser un skill modifié, `--force`
- [x] Session réelle vérifiée une fois à la main sur `symfony-minimal` avec Claude Code, et le
      résultat (pages rédigées) consigné en fixture de démonstration
- [x] README : section « Rédaction avec Claude Code » (install, boucle inspect → skill → apply, coût)

## Conséquences

- La qualité rédactionnelle dépend du prompt versionné : l'améliorer = une nouvelle version de
  prompt, visible dans chaque XML.
- Deux rédactions du même workflow diffèrent (§ 11) ; seules les sections Claude varient, les faits
  non.

## Dépendances

ADR-0002, ADR-0008, ADR-0010.
