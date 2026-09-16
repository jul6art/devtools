# ADR-0012 — Connaissances de stack (`knowledge`)

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.3 étape 2, § 8 (stacks couvertes), § 12 question 3

## Contexte

« C'est le point qui rend l'outil indépendant du langage » (§ 4.3 étape 2). Claude rédige mieux une
page Symfony quand il sait ce qu'est un `RequestEvent`, et ne peut chercher les points d'entrée d'une
stack Express qu'en sachant où ils se déclarent. Ces fiches sont génériques par stack et version
majeure, donc partageables.

Le § 8 vise quatre stacks embarquées au lancement. Pour aller vite, le MVP n'en **rédige qu'une à la
main** (Symfony, l'étalon) et obtient les autres par la voie d'acquisition, dont c'est justement le
test.

## Décision

### Canevas fixe (`resources/knowledge/_canvas.md`)

Sections obligatoires, dans l'ordre (§ 4.3 étape 2) :

```
# <Stack> <majeure>
## Cycle d'entrée           — requête / commande / message : comment ils entrent et traversent
## Mécanismes d'extension   — listeners, middlewares, décorateurs, hooks
## Injection de dépendances et conventions de nommage
## Points d'entrée par type — une sous-section par type du § 4.2 : où et comment ils se déclarent
## Tests                    — où ils vivent, comment ils s'exécutent
## Pièges connus
## Sources                  — URL de documentation officielle consultées
```

### Résolution (`Stack\Knowledge\KnowledgeProvider`)

Pour `knowledgeKey` (`symfony-7`, ADR-0005), dans l'ordre :
1. `.devtools/knowledge/<clé>.md` du projet — **toujours prioritaire** (versionné, éditable) ;
2. `resources/knowledge/<clé>.md` embarqué → **copié** dans `.devtools/knowledge/` à la première
   utilisation (le projet committe ce qu'il a utilisé, § 4.3) ;
3. sinon **tâche `knowledge`** (ADR-0002) : brief avec la stack, la version, le canevas, la
   consigne de consulter la documentation officielle (recherche web de Claude Code) ; brouillon validé
   par `KnowledgeDraftValidator` (sections du canevas présentes et dans l'ordre, section Sources non
   vide) et écrit dans `.devtools/knowledge/`.

Tant qu'une connaissance manque, les briefs `page` et `discovery` qui en dépendent **ne sont pas
émis** : on ne rédige pas sans savoir comment la stack fonctionne.

### Connaissances embarquées du MVP

`symfony-7.md` et `symfony-8.md`, rédigées et relues dans ce lot, conformes au canevas.

## Budget d'exécution

Une tâche `knowledge` **par stack et version majeure inconnue**, une fois dans la vie du projet ; zéro
ensuite.

## Hors périmètre

Remontée automatique d'une connaissance générée dans le cœur (§ 12 question 3 : **non** — remontée
par pull request manuelle) ; connaissances Laravel, Angular, Express embarquées (produites par
acquisition sur les fixtures dans l'ADR-0013, promues plus tard) ; mise à jour d'une connaissance
existante lors d'un changement de version mineure.

## Critères d'acceptation

- [x] `symfony-7.md` et `symfony-8.md` conformes au canevas (test de conformité sur tout
      `resources/knowledge/`)
- [x] Priorité projet > embarqué, et copie à la première utilisation : un test chacun
- [x] Stack inconnue → brief `knowledge` valide ; brouillon enregistré conforme → fichier écrit ;
      section manquante ou Sources vide → refus nommé
- [x] Aucun brief `page` émis pour une stack sans connaissance ; émis au scan suivant une fois la
      connaissance écrite
- [x] Le chemin du `knowledge` apparaît dans les briefs `page` et dans l'en-tête de `workflows.md`

## Conséquences

- Une connaissance fausse fausse toutes les rédactions de la stack ; elle se corrige une fois, dans
  `.devtools/knowledge/`, et la correction profite à toute l'équipe.

## Dépendances

ADR-0005, ADR-0011.
