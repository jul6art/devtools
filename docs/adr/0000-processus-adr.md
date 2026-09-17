# ADR-0000 — Processus ADR et définition d'un lot

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 9, § 11

## Contexte

`docs/specs.md` décrit un outil très large : six modules, quatre phases, une douzaine d'« idées
complémentaires ». Le premier objectif est étroit : un **MVP qui livre toute la problématique P1**
(§ 2) — introspection du code et génération maintenue des workflows — et rien d'autre. Sans un
mécanisme qui borne chaque tranche avant qu'elle soit codée, l'outil qui promet d'empêcher Claude de
dériver dériverait lui-même.

Le format reprend celui des ADR de `cereezer` et `cegeta`, adapté à un outil console sans base de
données ni écran.

## Décision

Chaque lot de développement est une **ADR** dans ce répertoire, au format suivant :

```markdown
# ADR-NNNN — Titre

- **Statut** : Proposed | Accepted | Superseded by ADR-XXXX | Rejected
- **Décideurs** : …
- **Specs** : les § de docs/specs.md couverts

## Contexte        — le problème, les contraintes, les alternatives écartées EN UNE PHRASE chacune
## Décision        — classes, formats de fichiers, commandes, options : du CONCRET codable
## Budget d'exécution — temps, processus lancés, appels à Claude : ce qui ne doit pas croître
                        avec la taille du projet (obligatoire dès qu'un lot ajoute une étape au
                        pipeline d'inspection)
## Hors périmètre  — ce que ce lot ne fait volontairement PAS (aussi important que le périmètre)
## Critères d'acceptation — testables, en checklist
## Conséquences    — ce que la décision coûte et ce qu'elle interdit ensuite
## Dépendances     — les ADR qui doivent être Done avant
```

### Règles du processus

1. **Un lot ne démarre pas sans son ADR `Accepted`.** L'acceptation est un acte humain (jul6art),
   pas un état par défaut. Toutes les ADR naissent `Proposed`.
2. **Une ADR acceptée ne s'édite plus sur le fond.** Changer une décision = une nouvelle ADR qui
   marque l'ancienne `Superseded by`. Cocher un critère d'acceptation et corriger une coquille
   restent permis.
3. **Un lot est Done** quand : ses critères d'acceptation sont cochés un à un, chacun adossé à un
   test ; `composer qa` est vert ; le jeu de dépendances `lowest` a été exercé si le lot touche une
   API Symfony ; la section du README qui dit **comment se servir** de ce que le lot ajoute est
   écrite ; `docs/claude/claude_learning.md` est complété si une erreur a été corrigée — et rien du
   « Hors périmètre » n'a été codé « tant qu'on y était ».
4. **Chaque lot livre ses propres tests et ses propres projets-fixtures.** Il n'y a pas de lot
   « tests » en fin de parcours : l'« armée de tests » du § 7 se construit lot par lot, et
   l'ADR-0015 ne fait que la consolider.
5. **Un écart avec `docs/specs.md` se dit dans le Contexte de l'ADR**, en nommant le § contredit.
   Les specs sont un brouillon « à valider » ; l'ADR acceptée fait foi sur ce qu'on code.
6. **Une dépendance Composer n'entre qu'avec le lot qui s'en sert**, et l'ADR la nomme.

## Hors périmètre

Les corrections de bugs du quotidien et les choix triviaux. Le processus couvre tout ce qui crée une
commande, une option, un format de fichier généré, une dépendance ou une décision d'architecture.

## Critères d'acceptation

- [x] `docs/adr/README.md` liste toutes les ADR, leur étape et leur statut, et reste à jour à chaque
      changement de statut

## Conséquences

- Le coût : écrire l'ADR avant de coder, et la faire accepter.
- Le gain : le périmètre du MVP est écrit, borné et relisible ; `git log docs/adr/` répond à
  « pourquoi c'est comme ça ? ».

## Dépendances

Aucune.
