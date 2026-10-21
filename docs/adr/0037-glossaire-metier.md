# ADR-0037 — Glossaire métier

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16)
- **Décideurs** : jul6art
- **Specs** : § 4.8 (glossaire métier)

## Contexte

Deux rédactions du même projet appellent la même chose « commande », « ordre » ou « order » ; un
nouveau venu ne sait pas qu'un `Item` est ce que l'interface nomme « objet » (cegeta). Le § 4.8
propose un `glossary.md` alimenté à partir des noms d'entités, de routes et de messages, **réutilisé
dans les pages** pour un vocabulaire cohérent.

## Décision

### Sources de termes (déterministes)

Collectés à chaque `inspect` depuis le modèle : noms courts des entités (rôle `entity`), des messages
(`message`), des composants `ui`, et segments d'identifiants de workflows qui reviennent dans au moins
trois workflows. Liste → `.devtools/glossary.xml` (`glossary.xsd`) : terme, occurrences, fichiers,
définition (vide), statut `candidate | defined | ignored`.

### Définitions

- Tâche IA `glossary` : brief = termes `candidate`, extraits des pages où ils apparaissent, knowledge ;
  brouillon = définitions d'une à deux phrases + synonymes observés + libellé utilisateur si des
  traductions du projet le révèlent.
- Validation : chaque terme existe dans `glossary.xml`, définition ≤ 2 phrases.
- `.devtools/glossary.md` rendu depuis le XML, trié, lié au menu `workflows.md`.

### Réutilisation

Le brief `page` (ADR-0011) reçoit les termes `defined` qui apparaissent dans le workflow, avec la
consigne de les employer. `PageDraftValidator` **avertit** (sans refuser) quand un synonyme déclaré
est utilisé à la place du terme.

## Budget d'exécution

Collecte en mémoire pendant `inspect` ; une tâche IA par lot de nouveaux termes, zéro sinon.

## Hors périmètre

Glossaire multilingue ; import d'un glossaire existant ; lien vers la documentation produit.

## Critères d'acceptation

- [x] Collecte sur `symfony-minimal` : termes attendus, seuil de trois workflows respecté
  (`GlossaryTest`, et l'instantané de la matrice pour chaque fixture)
- [x] Brouillon de définitions appliqué ; définition trop longue refusée
- [x] Terme `ignored` jamais reproposé
- [x] Brief `page` enrichi des termes du workflow ; synonyme → avertissement
- [x] README : section « Glossary »

## Mise en œuvre (2026-10-21)

- Paquet `src/Glossary/` : `GlossaryCollector` (collecte déterministe), `Glossary` (fusion avec ce que le
  projet a décidé : une définition et un statut survivent, un `candidate` qui n'est plus rencontré s'en va),
  `XmlGlossaryStore`, `GlossaryRenderer`, `GlossaryBriefWriter`, `GlossaryDraftApplier`. Schémas
  `glossary.xsd`, `glossary-brief.xsd`, `glossary-draft.xsd` ; prompt `resources/prompts/glossary/v1.md`.
- Un segment au pluriel rejoint le terme singulier qu'une entité déclare (`orders` → `Order`) ; une liste
  close de mots d'action ou de forme (`new`, `show`, `index`, `api`…) n'est jamais un terme.
- La page est `docs/workflows/glossary.md` (et non `.devtools/glossary.md`) : elle se lit à côté des
  pages, que le menu et le site exporté (ADR-0034) lient ; elle n'existe que si un terme est défini.
- L'avertissement de synonyme est donné par `DraftApplier`, à l'application du brouillon de page : il
  n'y a pas de `PageDraftValidator` distinct.
- Le brief nomme, pour chaque terme, les workflows, leurs pages et les fichiers qui le déclarent, plutôt
  que des extraits : Claude lit ce dont il a besoin, et le brief reste petit.
- Le brouillon est appliqué avant les pages, qui lisent ensuite les termes qu'il définit ; un brouillon
  qui enfreint une règle est refusé en entier et gardé.
- `workflows:rename` (ADR-0036) réécrit aussi `glossary.xml`.

## Conséquences

- Le vocabulaire des pages converge ; il suppose qu'un humain valide les définitions, que l'outil
  propose sans les imposer.

## Dépendances

ADR-0015.
