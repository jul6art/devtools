# ADR-0055 — L'heure se lit par l'horloge du core-bundle : une règle PHPStan et une règle Rector

- **Statut** : Accepted — 2026-11-25 (décidé par jul6art : le chantier horloge, « on va faire tout », lancé le
  2026-11-25) · Livré le 2026-11-25 (v3.10.0)
- **Décideurs** : jul6art
- **Specs** : § 4.8 (la gate)
- **Dépend de** : ADR-0054 (la garde de taille, première règle de qualité de code de DevTools)

## Contexte

Le 2026-11-24, le relevé des projets perso comptait 579 lectures directes de l'heure dans le code — `new
\DateTimeImmutable()`, `time()`, `date('Y')`, `sleep()` — et 827 dates construites dans les tests. Une lecture
directe lit l'horloge du système : un test ne peut pas l'arrêter, et son résultat dépend de l'heure à laquelle il
tourne (une échéance qui tombe à minuit, un délai qu'on attend par un `sleep()`).

`jul6art/core-bundle` 3.14 apporte `Util\Clock`, qui lit l'horloge globale de Symfony — celle du service `clock`,
de Messenger, de Lexik et de tout service typé `Psr\Clock\ClockInterface` —, et `Test\ClockTestTrait`, qui la
fige. Il reste à faire passer les 579 lectures par elle, puis à empêcher qu'il en revienne.

Comme pour la garde de taille (ADR-0054), la règle ne doit pas être recopiée dans chaque application : elle a
besoin d'un paquet de développement que les applications chargent déjà dans PHPStan. DevTools est celui-là. La
question qu'ouvrait l'ADR-0054 — une règle de ce genre a-t-elle sa place ici, ou faut-il un paquet de règles à
part ? — reçoit la même réponse : ici, tant qu'il n'y en a que deux.

## Décision

### 1. Une règle à part, qui ne s'active que si le projet l'inclut

`resources/phpstan/clock.neon`, distinct de `extension.neon` et de `class-length.neon`. Rien n'est activé par
l'installation du paquet.

### 2. DevTools décide, PHPStan et Rector appliquent

`TimeReading` décide, sans PHPStan, ce qu'est une lecture de l'heure et ce qu'il faut en dire. `TimeReadingRule`
ne fait que lui passer l'appel, les noms que PHPStan a résolus et les chaînes constantes qu'il connaît.
`UseClockRector` lui demande quelle chaîne de date est relative : la règle et l'outil de remplacement sont
d'accord sur ce qu'est une lecture, par construction.

### 3. Ce qui est une lecture

- `new \DateTime()` et `new \DateTimeImmutable()` sans chaîne, avec `'now'` ou une date relative (`'today'`,
  `'-14 days'`, `'-'.$interval`, `sprintf('-%d days', $n)`) ; une valeur convertie
  (`new \DateTimeImmutable($row['date'])`) n'en est pas une ;
- `time()`, `date()` et `gmdate()` sans horodatage, `idate()`, `getdate()` et `localtime()` sans horodatage,
  `mktime()` et `gmmktime()` à qui il manque un champ, `strtotime()` et `date_create()` sur une date relative ;
- `sleep()`, `usleep()`, `time_nanosleep()`, `time_sleep_until()`, qu'une horloge figée ne saute pas ;
- `createFromFormat()` avec un format littéral qui omet des champs sans `!` ni `|` : PHP les remplit avec
  l'heure courante ;
- les classes de Symfony Clock (`now()`, `Clock`, `NativeClock`, `DatePoint`), que seul `Util\Clock` connaît.

Un test construit ses dates comme il veut : seules ses attentes sont signalées, `advanceClock()` les remplace.
Les durées (`microtime()`, `hrtime()`) ne sont pas des lectures de l'heure.

### 4. Ce qui se règle

`devtoolsClock.clock` (la classe d'horloge que les messages nomment, `Jul6Art\CoreBundle\Util\Clock` par défaut),
`devtoolsClock.allowed` (les classes et traits qui lisent l'heure eux-mêmes, l'horloge et le trait de test par
défaut), `devtoolsClock.tests` (motifs `fnmatch()` des fichiers de test, `*/tests/*` et `*/Tests/*`).

### 5. Un message stable

Le message nomme l'appel, jamais la ligne ni la valeur : une lecture qu'un projet décide de garder se fige dans
une baseline ou par un `@phpstan-ignore devtools.clock` motivé, comme pour la garde de taille.

### 6. Rector remplace ce qui se remplace un pour un

`UseClockRector` réécrit `new \DateTimeImmutable(…)`, `time()`, `date($format)`, `strtotime('…')` relatif,
`date_create_immutable()`, `sleep()`, `usleep()` et `now()` de Symfony vers l'horloge, avec le même résultat sous
l'horloge native. Il laisse `new \DateTime()` (l'horloge perso ne rend que des dates immuables), `gmdate()`,
`idate()`, `mktime()` et les formats incomplets : la règle les signale, on les traite à la main. Il se lance sur
le code, pas sur les tests.

## Critères d'acceptation

- [x] Chaque forme de lecture du § 3 produit une erreur à sa ligne, identifiant `devtools.clock`
- [x] Une valeur convertie, une date complète, un horodatage, un format complet et une durée ne produisent rien
- [x] Dans un fichier de test, seules les attentes produisent une erreur
- [x] L'horloge et le trait de test sont autorisés ; `allowed` en ajoute
- [x] `resources/phpstan/clock.neon` s'inclut seul et enregistre la règle ; `clock`, `allowed` et `tests` se règlent
- [x] `UseClockRector` remplace les lectures du § 6 et laisse le reste ; la classe d'horloge se règle
- [x] README : section « PHPStan — the time read directly »

## Conséquences

- Les applications perso refusent une lecture directe de l'heure au moment où on l'écrit, dès le niveau 0 de
  PHPStan.
- DevTools porte une seconde règle de qualité de code. La troisième pose pour de bon la question d'un paquet de
  règles à part.

## Dépendances

ADR-0054 ; `jul6art/core-bundle` 3.14 (`Util\Clock`, `Test\ClockTestTrait`), que la règle nomme sans en
dépendre.
