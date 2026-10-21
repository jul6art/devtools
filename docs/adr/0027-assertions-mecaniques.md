# ADR-0027 — Assertions mécaniques

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 2 lancée par jul6art) · Livré le 2026-10-20
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

### Précisions à l'acceptation (2026-10-20)

- **Une famille, une classe de vérification** (`PatternCheck`, `DomCheck`, `ToolCheck`), un seul
  `AssertionChecker` plutôt qu'une `AssertionInterface::check(ChangeSet)` par assertion : regrouper les
  assertions d'un même outil pour ne l'appeler qu'une fois demande de les voir toutes ensemble. Chaque assertion
  déclare ce qu'elle surveille (`<watch glob>`) ; `<message>`, une ligne de 200 caractères au plus, dit ce qui
  ne va pas.
- **Motif** : une PCRE délimiteurs compris, sur le texte de chaque fichier surveillé ; `must-not-match` signale
  chaque correspondance (fichier, ligne, la ligne trouvée), `must-match` chaque fichier sans correspondance.
  **Pas de XPath** : un template Twig n'est pas du XML, et l'exemple de cette ADR s'écrit en PCRE (assertion
  négative avant) — hors périmètre jusqu'à un besoin réel.
- **DOM** : un sélecteur CSS (`Dom\HTMLDocument::querySelector`) sur le DOM des captures `screenshot` d'un
  scénario — ou de tous ceux d'un workflow —, une capture nommée au besoin ; `must-exist` : dans au moins une,
  `must-not-exist` : dans aucune. Pas de capture, sélecteur illisible : l'assertion « ne peut pas dire ».
- **Outil** : `phpstan` (`--error-format=json`), `eslint` (`--format json`) ou `exit-code` ; `identifier` filtre
  ce qui compte (identifiant PHPStan, règle ESLint) ; `{files}` vaut les fichiers surveillés du changement ; seuls
  comptent les constats dans ces fichiers (un code de sortie vaut pour tout). Chemins absolus ou relatifs, résolus
  ou non. Délai 600 s ; une sortie illisible ou un outil arrêté : « ne peut pas dire ».
- **`assertions:propose <règle> [--before=<commit>]`** : le brief (`assertion-brief.xsd`, prompt `assertion/v1`)
  donne la règle, ses retours et leur commit, les **commits « avant »** — ceux où les retours ont été signalés et
  que git connaît, ou ceux de `--before` : le défaut a pu ne pas être commité au moment du signalement —, les
  fichiers de la règle, les captures DOM de la dernière exécution pour les workflows des retours, les outils
  installés (`vendor/bin/phpstan`, `node_modules/.bin/eslint`, `bin/console`). Une règle proposée ou active reçoit
  une assertion ; la gate n'exécute que celles des règles actives.
- **Validation par exécution** (`workflows:apply`) : refus d'un brouillon hors XSD, d'une autre règle que celle du
  brief, d'une règle archivée ou déjà dotée d'une assertion, d'un identifiant pris, d'un glob hors racine ou ne
  correspondant à rien, d'une PCRE illisible ; puis **sur le projet tel qu'il est elle doit passer** (« ne peut pas
  dire » : refus) ; un **motif doit échouer à chaque commit « avant »**, lu par git sans toucher l'arbre de travail
  — qui passe à l'un d'eux ne distingue pas le défaut de sa correction : refus. Une assertion DOM ou outil ne
  s'évalue pas à un commit, et un motif sans commit « avant » ne s'y confronte pas : acceptés, **avec un
  avertissement** de `workflows:apply`. Acceptée, l'assertion est écrite dans `.devtools/assertions/<id>.xml` et
  la règle porte `<assertion ref>`.
- **Gate** : une assertion illisible est un échec ; les autres ne s'exécutent que si un fichier du changement est
  surveillé (note « N sur M ») ; une ligne d'échec par endroit — règle, assertion, message, fichier:ligne, ce qui a
  été trouvé —, cinq au plus puis le compte. `blocked` et `last-blocked` de la règle incrémentés une fois par
  assertion en échec et par passage ; « ne peut pas dire » est un avertissement (`--strict` : échec), jamais un
  blocage. Une assertion DOM ne lit que les captures **de ce passage** (`runs/<horodatage de la gate>`), jamais
  celles d'un changement antérieur. `.devtools/stats/` ne fait jamais partie d'un changement.

## Budget d'exécution

Motif : un passage par fichier concerné. DOM : sur les snapshots déjà capturés, aucun navigateur
relancé. Outil du projet : **une** exécution par outil pour toutes les assertions qui l'utilisent.

## Hors périmètre

Génération de règles PHPStan en PHP par DevTools ; assertions sur la base de données ; mutation de
l'assertion au-delà du commit d'origine.

## Critères d'acceptation

- [x] Une assertion par famille testée sur `symfony-minimal` : passe, puis échoue après
      introduction du défaut *(motif sur `templates/order/_form.html.twig` ; DOM sur les captures d'une
      exécution, sans navigateur ; outil : le PHPStan de DevTools, filtré sur `function.notFound`)*
- [x] `assertions:propose` : brouillon qui ne distingue pas avant/après refusé ; qui distingue accepté *(dans un
      dépôt git où le défaut a été commité, signalé, puis corrigé ; et neuf autres refus, `--before`, une
      assertion DOM acceptée avec avertissement)*
- [x] Gate : étape `assertions` limitée aux assertions concernées ; `blocked` incrémenté *(PHPStan non lancé
      quand le changement ne touche que des templates)*
- [x] Deux assertions PHPStan → une seule exécution de PHPStan (compteur)
- [x] README : section « Assertions »

## Conséquences

- La boucle du § 5.1 est fermée : retour → règle → assertion → gate. Une erreur promue jusqu'ici ne
  peut plus revenir silencieusement.

## Dépendances

ADR-0023, ADR-0026.
