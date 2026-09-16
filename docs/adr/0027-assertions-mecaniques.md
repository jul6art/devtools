# ADR-0027 — Assertions mécaniques

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 5.1 (étapes 2 et 3 : règle PHPStan, lint Twig, assertion Panther)

## Contexte

Une règle injectée (ADR-0026) est encore une consigne, que Claude peut ignorer. « Dès que possible »,
le § 5.1 la transforme en **assertion mécanique** exécutée par la gate : alors la même erreur ne peut
plus revenir sans faire échouer quelque chose.

## Décision

### Trois familles, une interface

`Assertion\AssertionInterface::check(ChangeSet): AssertionResult` — chaque assertion déclare les
fichiers qu'elle surveille, pour que la gate ne lance que celles que l'impact concerne.

| Famille | Implémentation | Exemple |
|---|---|---|
| **Motif** (toute stack) | expression régulière ou XPath sur les fichiers d'un glob, `must-match` / `must-not-match` | `<select` interdit dans `templates/**/_form.html.twig` hors `data-controller="select2"` |
| **DOM** (après scénario) | sélecteur CSS + condition sur les snapshots DOM de l'ADR-0021 | `#order_product` porte la classe `select2-hidden-accessible` |
| **Outil du projet** | commande externe avec code de sortie : règle PHPStan, lint Twig, ESLint | `vendor/bin/phpstan analyse --error-format=json` filtré sur l'identifiant de la règle |

- Déclaration : `.devtools/assertions/<id>.xml` (`assertion.xsd`), référencée par la règle
  (`<assertion ref>`).
- Une règle PHPStan ou ESLint vit **dans le projet** (son outillage), DevTools ne fait que l'appeler et
  en lire le résultat : il ne génère pas de code d'analyseur.
- `devtools assertions:propose <rule-id>` : tâche IA `assertion`, brouillon validé en **l'exécutant**
  sur l'état actuel — elle doit passer — et sur le commit qui a introduit le retour d'origine, si
  disponible — elle doit échouer. Une assertion qui ne distingue pas les deux est refusée.

### Dans la gate

L'étape `assertions` de l'ADR-0023 exécute les assertions des règles actives concernées par l'impact.
Échec → gate rouge, `stats/@blocked` de la règle incrémenté, sortie qui cite la règle et le fichier.

## Budget d'exécution

Motif : un passage par fichier concerné. DOM : sur les snapshots déjà capturés, aucun navigateur
relancé. Outil du projet : **une** exécution par outil pour toutes les assertions qui l'utilisent.

## Hors périmètre

Génération de règles PHPStan en PHP par DevTools ; assertions sur la base de données ; mutation de
l'assertion au-delà du commit d'origine.

## Critères d'acceptation

- [ ] Une assertion par famille testée sur `symfony-minimal` : passe, puis échoue après
      introduction du défaut
- [ ] `assertions:propose` : brouillon qui ne distingue pas avant/après refusé ; qui distingue accepté
- [ ] Gate : étape `assertions` limitée aux assertions concernées ; `blocked` incrémenté
- [ ] Deux assertions PHPStan → une seule exécution de PHPStan (compteur)
- [ ] README : section « Assertions »

## Conséquences

- La boucle du § 5.1 est fermée : retour → règle → assertion → gate. Une erreur promue jusqu'ici ne
  peut plus revenir silencieusement.

## Dépendances

ADR-0023, ADR-0026.
