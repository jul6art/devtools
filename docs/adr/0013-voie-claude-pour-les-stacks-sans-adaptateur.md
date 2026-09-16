# ADR-0013 — Voie Claude pour les stacks sans adaptateur natif

- **Statut** : Accepted — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 2 (P1 « indépendant du langage »), § 4.3 étapes 3 et 4, § 4.6.2 étape 4, § 7.1 (`node-express`, `angular-minimal`, `monorepo`), § 11 (stacks exotiques)

## Contexte

P1 exige l'indépendance du langage : l'équipe a aussi de l'Angular. Sans adaptateur natif, « Claude,
guidé par `knowledge/<stack>.md`, parcourt les dossiers sources et produit la même structure de
sortie, validée par schéma » (§ 4.3 étape 3). C'est ce lot qui rend le MVP multi-langage ; tout le
reste du pipeline (fraîcheur, rendu, suivi, rédaction) est réutilisé tel quel.

## Décision

### Tâche `discovery`

- `AdapterResolver` (ADR-0009) choisit `ClaudeDrivenAdapter` quand `stack.xml` indique
  `adapter="claude"`.
- **Premier scan** : brief `.devtools/pending/discovery.<stack>.brief.xml` — racine et dossiers
  sources de la stack, exclusions, chemin du `knowledge` (ADR-0012), types de workflows
  configurés, règles d'identifiants (ADR-0003), le XSD du modèle.
- **Brouillon** : `discovery.<stack>.draft.xml`, un `InspectionResult` (ADR-0003) : workflows avec
  point d'entrée, fichiers impliqués, `dependsOn`, navigation.
- **`workflows:apply`** valide : XSD ; **chaque `FileRef` existe sur disque** et reste dans la racine
  de la stack ; identifiants conformes et sans collision ; `source=claude`,
  `confidence=medium` imposés quoi que dise le brouillon. Accepté → `.devtools/discovery/<stack>.xml`
  (versionné) **devient l'entrée de l'adaptateur** aux scans suivants, puis le pipeline continue
  normalement (fraîcheur, briefs `page`).

### Scans suivants — découverte limitée (§ 4.6.2 étape 4)

`ClaudeDrivenAdapter` relit `discovery/<stack>.xml` sans Claude. Les fichiers sources **non
référencés** par aucun workflow et **nouveaux** depuis la dernière découverte (git, sinon
comparaison avec la liste enregistrée) → brief `discovery` **limité à ces fichiers** ; le brouillon ne
peut qu'ajouter des workflows ou rattacher ces fichiers à des workflows existants. Un fichier listé
qui disparaît suit la fraîcheur normale (ADR-0010).

### Monorepo

Une tâche `discovery` par stack sans adaptateur ; une stack Symfony du même monorepo passe par
l'ADR-0007. Les identifiants restent uniques sur tout le projet (collision = erreur, ADR-0003).

### Honnêteté

Le menu affiche la confiance par workflow (`medium` pour la voie Claude) ; le rapport précise le
nombre de fichiers sources non couverts par stack.

## Budget d'exécution

- Une découverte complète **une fois** par stack ; ensuite une découverte limitée **seulement s'il
  existe des fichiers nouveaux non couverts**, zéro sinon.
- Aucun hash ni parsing de langage non PHP : les fichiers sont fournis par Claude, vérifiés par
  existence, puis suivis par `sha256`.

## Hors périmètre

Adaptateurs natifs Laravel, Angular, Node (phase 3 des specs) ; résolution de dépendances statique
pour d'autres langages ; relier les appels HTTP d'un front aux routes d'un back (multi-projets,
§ 4.8).

## Critères d'acceptation

Avec des brouillons enregistrés :

- [x] `node-express` : `stack.xml` → brief `discovery` et brief `knowledge` (ADR-0012) ; brouillons
      appliqués → `.devtools/` complet, conforme au snapshot, confiance `medium` au menu
- [x] `angular-minimal` : routes et composants documentés de la même façon
- [x] Brouillon avec un fichier inexistant, un chemin hors racine, un identifiant invalide, une
      collision : quatre refus nommés, rien d'écrit
- [x] Brouillon qui déclare `confidence=high` : ramené à `medium`
- [x] Ajout d'un fichier route dans `node-express` (dépôt git temporaire) → brief `discovery`
      limité à ce fichier ; modification d'un fichier déjà couvert → aucun brief `discovery`,
      fraîcheur normale
- [x] `monorepo` (Symfony + Angular) : un seul `.devtools/`, deux stacks, identifiants uniques
- [x] Session réelle vérifiée une fois à la main sur `angular-minimal` avec Claude Code
- [x] README : section « Projets non Symfony »

## Conséquences

- `.devtools/discovery/` s'ajoute à l'arborescence de l'ADR-0004 (versionné) : c'est la mémoire de
  ce que Claude a trouvé, et le point de départ d'un futur adaptateur natif (§ 4.3 étape 3).
- La qualité de la cartographie d'une stack non native dépend de Claude ; le menu le dit.

## Dépendances

ADR-0010, ADR-0011, ADR-0012.
