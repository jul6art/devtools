# ADR-0048 — La dérive se voit là où on écrit le code : une règle PHPStan

- **Statut** : Accepted — 2026-09-18
- **Décideurs** : jul6art
- **Specs** : § 4.8, § 9 (phase 1)
- **Dépend de** : ADR-0046 (diff typé), ADR-0017 (la gate)

## Contexte

`workflows:check` fait échouer une gate, et `workflows:review` permet de décider. Les deux se lancent
**après** — au commit, en CI, quand on y pense. Or PHPStan tourne déjà dans le `composer qa` de chaque
projet et, pour beaucoup, dans l'éditeur, ligne par ligne.

⚠️ **Le piège serait d'écrire un second extracteur.** Une règle PHPStan qui recalculerait les routes et
les valeurs décidées à partir de l'AST que PHPStan a sous la main serait plus rapide — et elle
divergerait de l'inspection au premier détail, sans que rien ne le signale. Deux vérités sur ce qui a
changé valent moins qu'une.

## Décision

### 1. PHPStan affiche, DevTools calcule

La règle **n'analyse rien**. Elle s'accroche à `CollectedDataNode` — le nœud que PHPStan visite une fois,
à la fin de l'analyse — et appelle le **pipeline d'inspection en lecture seule**, celui de l'ADR-0046.
Ce qu'elle rend est la liste des faits changés, une erreur par fait, à la ligne où le fait est écrit.

Coût : celui d'un `workflows:diff`, une fois par analyse (5 s mesurées sur cereezer, pour une analyse
PHPStan qui en prend soixante). ⚠️ Sur `CollectedDataNode` et non sur un nœud de fichier : le cache de
résultats de PHPStan ne peut donc pas masquer une dérive apparue depuis la dernière analyse.

### 2. Elle ne parle que si le projet la fait parler

- Pas de `.devtools/` dans le projet : **aucune erreur**, jamais. Un projet qui n'utilise pas DevTools
  ne doit pas voir passer ses messages.
- L'extension se déclare explicitement dans le `phpstan.neon` du projet ; rien n'est activé par
  l'installation du paquet.
- `parameters.devtools.path` quand le projet analysé n'est pas le répertoire courant.

### 3. Ce qu'elle dit

```
 ------ -------------------------------------------------------------------
  Line   src/Entity/User.php
 ------ -------------------------------------------------------------------
  250    modified decision App\Entity\User::email: null !== $email -> '' !== trim($email)
         🪪 devtools.workflowDrift
         💡 87 workflows documented this. Accept it with
            devtools workflows:accept 'App\Entity\User::email', or refuse it.
 ------ -------------------------------------------------------------------
```

Un fait sans fichier — une dépendance, un paquet — est rapporté sans ligne, sur le fichier du point
d'entrée du premier workflow qui le porte.

### 4. Ce qu'elle ne fait pas

Elle ne décide rien, n'écrit rien et n'a pas de baseline à elle : accepter ou refuser reste
l'ADR-0047. Une inspection qui échoue (configuration invalide, collision d'identifiants) rend **une**
erreur qui le dit, jamais un silence.

## Budget d'exécution

Une inspection en lecture seule par exécution de PHPStan. Sur un projet où c'est trop, la règle se
retire d'une ligne de `phpstan.neon` — et `workflows:check` reste là.

## Hors périmètre

L'analyse incrémentale (ne rapporter que les fichiers analysés), la baseline PHPStan pour la dérive,
l'intégration Psalm ou PHP-CS-Fixer.

## Critères d'acceptation

- [x] Un fait changé produit une erreur, à son fichier et à sa ligne, avec l'identifiant
      `devtools.workflowDrift`
- [x] Le conseil nomme la commande d'acceptation avec la cible
- [x] Un projet sans `.devtools/` ne produit aucune erreur
- [x] Un projet sans dérive ne produit aucune erreur
- [x] Une inspection en erreur produit une erreur unique qui la nomme
- [x] `resources/phpstan/extension.neon` s'inclut dans un `phpstan.neon` et enregistre la règle
- [x] README : section « PHPStan »

## Conséquences

- Un développeur voit la dérive pendant qu'il écrit, pas au commit — ce qui est le moment où il peut
  encore décider si elle était voulue.
- DevTools gagne un second bridge spécifique à PHP : comme le bridge Symfony, il ne contient aucune
  logique propre.

## Dépendances

ADR-0046, ADR-0047.
