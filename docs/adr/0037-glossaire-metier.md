# ADR-0037 — Glossaire métier

- **Statut** : Proposed — 2026-09-16
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

- [ ] Collecte sur `symfony-minimal` : termes attendus, seuil de trois workflows respecté
- [ ] Brouillon de définitions appliqué ; définition trop longue refusée
- [ ] Terme `ignored` jamais reproposé
- [ ] Brief `page` enrichi des termes du workflow ; synonyme → avertissement
- [ ] README : section « Glossaire »

## Conséquences

- Le vocabulaire des pages converge ; il suppose qu'un humain valide les définitions, que l'outil
  propose sans les imposer.

## Dépendances

ADR-0015.
