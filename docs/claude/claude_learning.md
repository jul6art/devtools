## III. LEARNING LOG (APPEND-ONLY — NEVER MODIFY EXISTING ENTRIES)

> Mémoire chronologique des corrections, découvertes et retours humains sur **ce dépôt**.
> Les entrées existantes ne se modifient **jamais** : on ajoute en bas, daté (`YYYY-MM-DD`).
>
> **Chaque entrée est une règle à faire respecter.** C'est le seul document qui liste des erreurs
> déjà commises ici — donc les plus probables. REVIEWER le relit à chaque passe et vérifie
> qu'aucune correction passée n'a été réintroduite.
>
> Format d'une entrée : ce qui s'est passé, la **règle** qui en découle (prescriptive,
> actionnable), et si possible le test ou le garde-fou qui l'empêche de revenir.

---

### 2026-09-16 — Naissance du dépôt

- **Le squelette vient de `symfony-bundle-generator`, puis s'en écarte volontairement.** Layout
  `src/` + `tests/` + `bin/`, bundle dans `src/Bridge/Symfony/`, pas de Flex, alias `devtools`.
  Les cinq écarts sont listés dans `claude_project.md` : **ne jamais « réaligner » un fichier sur le
  gabarit** du générateur sans relire cette liste.

- **Le nom du paquet est celui du dépôt, pas celui des specs.** `docs/specs.md` (brouillon 0.2) dit
  `provencale/devtools` ; le remote git est `jul6art/devtools`, comme tout l'écosystème. **Règle :**
  quand les specs et le dépôt divergent sur un nom, le dépôt fait foi et l'écart se signale au
  décideur — il ne se résout pas en silence dans le code.

- **Symfony déduit l'alias `dev_tools` de `DevToolsBundle`, et refuse `devtools` au boot.**
  `LogicException: Users will expect the alias of the default extension of a bundle to be the
  underscored version of the bundle name ("dev_tools")`. **Règle :** l'alias `devtools` exige la
  surcharge de `DevToolsBundle::getContainerExtension()` **et** de `DevToolsExtension::getAlias()`.
  Garde-fou : `ContainerTest` — vérifié par mutation (surcharge retirée → 2 erreurs).

- **Le jeu `lowest` a trouvé un écart dès le premier commit.** `ApplicationTest::testItListsItsCommands`
  était vert sur symfony/console 8.1 et lisait un affichage **vide** sur 7.4.0 : `phpunit.xml.dist`
  pose `SHELL_VERBOSITY=-1`, que l'`ApplicationTester` de 8.x neutralise et celui de 7.4.0 non.
  **Règle :** un test qui lit la sortie d'un `ApplicationTester` force la verbosité
  (`'--verbose' => true`) ; et tout changement touchant une API Symfony s'exerce sur
  `composer update --prefer-lowest --prefer-stable` avant de se déclarer prêt.

- **`bin/devtools` n'est analysé par PHPStan que parce qu'il est listé explicitement.**
  `$GLOBALS['_composer_autoload_path']` est `mixed` : il a fallu le restreindre par `is_string()`
  plutôt que par `??` seul. **Règle :** un fichier PHP sans extension `.php` (binaire) est ajouté
  **nommément** dans `phpstan.dist.neon`, `rector.php` et `.php-cs-fixer.dist.php`.

- **Le générateur produit encore `dirname(__DIR__)` dans `Tests/bootstrap.php`**, que Rector 2.6.7
  réécrit en `__DIR__.'/../'` (corrigé à la main dans tous les bundles le 2026-09-14). Ici, la forme
  corrigée a été posée d'emblée. **Règle :** ce défaut se corrige dans le générateur
  (`common/overlay/Tests/bootstrap.php`), pas bundle par bundle.

---

### 2026-09-16 — ADR-0003, modèle intermédiaire

- **`WorkflowType` est un objet valeur, pas une `enum`, contrairement au texte de l'ADR-0003.**
  L'ADR veut à la fois « une enum des sept types natifs » et des types déclarés par `config.xml`
  portés par `Workflow::type` : une enum PHP ne peut pas contenir un type que seul un projet connaît.
  Les sept natifs sont des constructeurs nommés (`WorkflowType::routes()`), `WorkflowTypeRegistry`
  porte l'ensemble en vigueur. **Règle :** quand le texte d'une ADR acceptée est contradictoire avec
  lui-même, on suit l'intention, et l'écart est signalé au décideur dans le compte rendu du lot —
  jamais corrigé en silence dans l'ADR.

- **`ext-dom` et `ext-libxml` sont entrés au `require` avec l'ADR-0003**, pas l'ADR-0004 qui les
  nommait : le sérialiseur du modèle valide par XSD. **Règle :** une dépendance entre avec le premier
  lot qui s'en sert, même si une autre ADR l'annonçait.

- **`PHPUnit\Framework\TestCase::result()` est une méthode finale** : un helper de test privé nommé
  `result()` provoque une erreur fatale au chargement de toute la suite, sans pointer le test.
  **Règle :** ne jamais nommer un helper de test `result`, `name`, `status`, `size` ou `groups`.

- **Les gardes de sécurité du modèle sont vérifiées par mutation** (chemin hors racine, DOCTYPE,
  collision d'identifiants : 3, 1 et 2 tests rouges quand la garde est retirée). **Règle :** une garde
  de sécurité ou d'invariant ne compte comme testée qu'après avoir vu son test rouge sans elle.

---

### 2026-09-16 — ADR-0004, dossier `.devtools/` et suivi XML

- **L'ADR-0004 nomme `<source>` deux choses différentes** : l'état git du § 4.6.1 et le producteur du
  workflow (`native:symfony`, `claude`). Le premier garde le nom des specs, le second s'écrit
  `<producer>`. **Règle :** un nom d'élément XML ne porte qu'un sens dans un document.

- **Le fichier de suivi porte aussi `<title>`**, absent du § 4.6.1 : sans lui, `index.xml` et le menu
  devraient relire les pages Markdown pour les titres. **Règle :** tout ce qu'un index ou un menu
  affiche se lit dans un XML, jamais dans une page.

- **Les contraintes d'identité XSD n'acceptent pas de prédicat** (`t:entrypoint[@role='main']` ne
  compile pas) : « exactement une entrée principale » est vérifié à la lecture, dans le code.
  **Règle :** avant d'ajouter une contrainte à un XSD, compiler le schéma — `schemaValidate` sur un
  document quelconque suffit à le révéler ; un schéma invalide fait échouer toutes les lectures.

- **Rector puis PHP-CS-Fixer, dans cet ordre.** Rector ajoute des imports non triés (règles de typage
  des closures) ; lancé après PHP-CS-Fixer, il laisse `cs-check` rouge. **Règle :** pour corriger,
  `composer rector && composer cs`, jamais l'inverse.

- **Une mutation de test doit produire un document invalide pour la bonne raison.** Retirer une des deux
  révisions laissait un historique valide et le test du schéma « échouait » à tort. **Règle :** un test
  qui mute un document vérifie d'abord que la mutation a changé quelque chose, et vise le cas exact
  (historique vide), pas un voisin.

---

### 2026-09-16 — ADR-0005, détection de stack

- **Un monorepo se détecte en lisant la racine et ses sous-dossiers immédiats**, jamais en parcourant
  l'arborescence : c'est la seule lecture de dossier de cette étape. Un sous-dossier ne devient une
  stack que s'il porte un framework, ou si la racine n'en a aucune. **Règle :** un `package.json` sans
  framework à côté d'un projet Symfony (Webpack Encore) est de l'outillage, pas une stack — sinon la
  voie Claude se lancerait sur les assets.

- **Laravel est détecté mais part sur la voie Claude** (`adapter=claude`) : son adaptateur natif est
  l'ADR-0030, hors MVP. **Règle :** `adapter` dit qui trouve les workflows aujourd'hui, pas ce que la
  stack pourrait avoir un jour.

- **Les projets-fixtures sont exclus de PHPUnit, PHPStan et Rector dès le premier** : un
  `tests/Fixtures/projects/*/tests/*Test.php` serait sinon exécuté comme un test de DevTools. **Règle :**
  tout nouvel outil d'analyse ou de test ajouté au dépôt exclut `tests/Fixtures/projects/`.

- **Un mutant tué par une erreur fatale ne prouve rien.** Un script de mutation mal échappé a produit
  du PHP invalide : 20 erreurs, et un résultat qui ressemblait à un succès de la mutation. **Règle :**
  un mutant compte comme tué quand le test **qui le vise** échoue sur une assertion, pas quand la suite
  plante.

---

### 2026-09-16 — ADR-0006, graphe de dépendances PHP

- **Rector a changé le comportement du code, deux fois, avec une suite verte avant lui.**
  `ForRepeatedCountToOwnVariableRector` a sorti `\count($queue)` d'une boucle dont la file grandit
  (le parcours s'arrêtait au premier niveau), puis une règle de code mort a supprimé un `if (0 === $depth)`
  que l'inférence de PHPStan jugeait toujours vrai. Seuls les tests de profondeur l'ont vu. **Règle :**
  après `composer rector`, relancer la suite **et** relire le diff de Rector sur tout fichier touché ;
  écrire les boucles qui mutent leur collection autrement qu'avec un compteur relu à chaque tour
  (parcours niveau par niveau).

- **Le premier saut part de la méthode du point d'entrée, pas du fichier**, plus les membres partagés
  (parent, attributs, propriétés, constructeur), et ne compte que les noms **utilisés** après
  résolution — jamais les `use`. Sans cela, la route liste d'un contrôleur héritait du formulaire de la
  route création. **Règle :** un écart de précision avec le texte d'une ADR se tranche par le critère
  d'acceptation (« ni plus, ni moins »), et se documente dans le README.

- **Une classe vendor se localise par les sections `autoload` de `composer.lock`**, qui les porte pour
  chaque paquet — pas par `vendor/`, souvent absent, ni par l'autoloader, qui exécuterait le projet.

- **Un compteur de test doit compter l'opération, pas l'état.** `parsedFiles()` comptait les entrées du
  cache : désactiver le cache ne faisait échouer aucun test. **Règle :** un test « fait une seule fois »
  s'appuie sur un compteur incrémenté par l'opération elle-même, et se vérifie par mutation du cache.

- **Rector transforme en `::class` les noms de classes des projets-fixtures cités comme données** et
  ajoute des `use App\…` inexistants. **Règle :** `StringClassNameToClassConstantRector` est exclu de
  `tests/Inspection` ; une classe d'un projet analysé s'écrit en chaîne dans nos tests.

- **Pas de `@var` en ligne pour faire taire PHPStan.** Une structure `array{FileRef, int}` dans une file
  n'était pas démontrable ; la réécrire (tableaux indexés, boucle par niveau) l'a rendue typée sans
  annotation.

---

### 2026-09-16 — ADR-0007, adaptateur Symfony

- **Sans Flex, `framework-bundle 7.4.*` installe des composants Symfony 8.1** : ses dépendances
  acceptent `^7.4 || ^8.0`. `symfony-minimal` est donc une application **8.1 cohérente**, et
  `symfony-legacy-yaml` une application **7.4** dont chaque composant `symfony/*` est épinglé à la main.
  **Règle :** un projet-fixture épingle toute la ligne Symfony qu'il prétend représenter, vérifié par
  `composer show | grep ^symfony/`.

- **Symfony 8 a supprimé les loaders XML** (services et routes) : le critère « services XML » impose une
  application 7.4. **Règle :** un format de configuration se vérifie dans `vendor/` de la version visée
  avant d'écrire un fixture qui en dépend.

- **`debug:config` écrit un titre sur la sortie standard avant son JSON** (`Current configuration for
  "…"` souligné de `=`), contrairement aux autres `debug:*`. C'est le **seul** texte toléré avant le JSON ;
  toute autre ligne (déprécation, warning PHP) est une panne citée ligne à ligne.

- **Une sortie polluée déclenche le repli statique, pas l'arrêt du scan** : l'ADR dit « échoue avec les
  lignes fautives ». L'introspection échoue bien, la cause cite les lignes et passe en tête du rapport,
  mais la documentation reste produite en confiance `medium`. **Règle :** une panne de la source de vérité
  dégrade la confiance et se dit ; elle n'empêche pas de documenter ce qui peut l'être.

- **`handles` vaut `null` dans le conteneur pour un handler déclaré par attribut** : le message se lit
  dans le type du premier paramètre de la méthode du handler.

- **PHPUnit ne pose `SHELL_VERBOSITY=-1` que dans `$_SERVER`** : une garde qui réinitialise la verbosité
  de la console lancée n'était exercée par aucun test (mutant survivant). **Règle :** une garde contre une
  variable d'environnement se teste avec `putenv()`, restauré dans un `finally`.

- **Rector a de nouveau transformé en `::class` des noms de classes cherchés dans le code analysé**
  (`AsMessageHandler`, `IsGranted`…), cette fois dans `src/`. **Règle :** `StringClassNameToClassConstantRector`
  est exclu de `src/Inspection` comme de `tests/Inspection` ; ces noms sont des chaînes, avec un commentaire
  qui le dit.

---

### 2026-09-16 — ADR-0008, rendu normé

- **`StringClassNameToClassConstantRector` est exclu de tout `tests/`** : il a converti les noms de classes
  des projets analysés une troisième fois, dans `tests/Rendering`. **Règle :** dans ce dépôt, un nom de
  classe écrit en chaîne dans un test désigne presque toujours du code analysé ; la règle ne s'applique
  pas aux tests.

- **Un `## ` dans un bloc de code n'est pas une section**, et l'ordre des sections se vérifie sur une page
  désordonnée : deux mutants avaient survécu faute de ces deux tests. **Règle :** chaque branche d'un
  validateur de format a son document invalide dédié.

- **Les sections de Claude ne sont conservées que si la page a été rédigée** (le rendu reçoit la page
  existante seulement en mode `ai`). Garder toujours la section « Parcours » ferait survivre un diagramme
  factuel périmé aux changements de fichiers.

- **Aucune date d'exécution dans une page** : l'en-tête porte la date de la dernière révision. **Règle :**
  tout contenu rendu dépend du modèle et de l'historique, jamais de l'horloge.

---

### 2026-09-16 — ADR-0009, `workflows:inspect`

- **`TestCase::run()` est finale, comme `result()`** : la même erreur fatale au chargement, malgré
  l'entrée du journal qui l'annonçait pour `result`. **Règle :** un helper de test ne porte jamais le nom
  d'une méthode publique de `TestCase` — `run`, `result`, `name`, `status`, `size`, `groups`,
  `dependencies`, `count` ; préfixer d'un verbe métier (`runInProject`).

- **`SOURCE_DATE_EPOCH` fixe l'horloge du cœur**, et `GIT_CEILING_DIRECTORIES` isole un projet de test du
  dépôt DevTools qui l'entoure : deux exécutions en processus séparés se comparent alors à l'octet.
  **Règle :** un test de bout en bout qui compare des fichiers générés fixe ces deux variables ; les
  rapports (durée) et la version de l'outil se normalisent, rien d'autre.

- **Le binaire autonome et `bin/console devtools:workflows:inspect` produisent le même `.devtools/`**,
  vérifié en installant DevTools dans une copie du projet de test par un dépôt Composer `path`. C'est la
  preuve que le bridge n'ajoute que l'enregistrement des commandes.

- **Une copie par `rsync --exclude composer.lock` retire aussi les locks des projets de test**, et un
  test a échoué pour une raison sans rapport avec les dépendances. **Règle :** les exclusions d'une
  copie du dépôt s'ancrent à la racine (`--exclude /composer.lock`).

- **Un fichier de configuration n'est pas dessiné dans le parcours factuel** : il déclare le workflow,
  il n'est pas traversé. Il reste listé dans « Composants impliqués », donc suivi par la fraîcheur.

- **Une stack sans adaptateur est un avertissement, pas une erreur**, sauf si aucune stack n'a pu être
  documentée : un monorepo Symfony + Angular documente sa partie Symfony dès maintenant.

---

### 2026-09-17 — ADR-0010, fraîcheur

- **Recopier `HEAD` dans `index.xml` créait une boucle sans fin** : committer la documentation changeait le
  commit, donc l'index et le menu, donc la documentation à committer. **Règle :** un fichier généré ne
  dépend que de ce qu'il décrit ; `index.xml` garde sa date et son commit tant que ses entrées ne changent
  pas.

- **Le hash tranche, git accélère — et les tests doivent pouvoir le prouver.** Deux mutants ont survécu à
  des tests qui semblaient couvrir le cas : le test de rebase amendait un commit **enfant** du commit
  enregistré (toujours présent), et la modification non indexée restait visible par `git status`.
  **Règle :** un test de repli vérifie d'abord la précondition qu'il prétend créer (le commit n'existe
  plus, l'arbre de travail est propre) par une assertion explicite.

- **`init` écrit le `.gitignore` du projet** : après la première inspection, le dépôt est « sale » pour
  git, et c'est exact. `.devtools/` est exclu de `git status` (pathspec `:(exclude).devtools`) ; le
  `.gitignore` appartient au projet et ne l'est pas.

- **Les chemins de `git status` partent de la racine du dépôt**, ceux de DevTools de la racine du projet :
  `git rev-parse --show-prefix` (dans le même processus que `HEAD` et la branche) donne le préfixe à
  retirer ; `git diff --relative` fait ce travail pour la plage de commits.

- **Aucun pilote de couverture sur le poste** (ni pcov ni Xdebug, et PHPUnit 13 refuse phpdbg) : Infection
  est configuré et tourne dans le job CI « Mutation » avec pcov. **Règle :** en l'absence de couverture,
  une garde de fraîcheur ou de suivi se vérifie par mutation manuelle ciblée (`scratchpad/mutate2.py`),
  mutant tué par le test **qui le vise** ; le critère Infection reste non coché tant qu'il n'a pas tourné.

- **zsh ne découpe pas une variable en mots** : `$M fichier …` échoue quand `M` contient une commande avec
  arguments. **Règle :** encapsuler dans une fonction shell.

---

### 2026-09-17 — ADR-0011, rédaction par Claude Code

- **Un brief ne doit pas changer d'une inspection à l'autre.** `amend` dépendait de la décision du passage
  courant : relancer `inspect` avant de rédiger le passait à faux, et la rédaction aurait ajouté une
  seconde ligne d'historique. **Règle :** ce qu'un brief dit dépend de l'état du suivi (mode `no-ai` de la
  dernière révision), jamais de ce que le passage vient de décider ; le test d'idempotence l'a attrapé.

- **Une inspection `--no-ai` fait avancer le suivi sans toucher aux briefs** : un brouillon répondant à un
  brief antérieur doit être refusé par comparaison brief ↔ révision du suivi, en plus de brouillon ↔ brief
  (mutant survivant sans ce test).

- **Les numéros de ligne annoncés par un validateur se vérifient dans le fichier** (`grep -n`), pas au jugé :
  les quatre attentes écrites à la main étaient fausses, le code était juste.

- **Session réelle faite sur `symfony-minimal`** : dix brouillons écrits en suivant `page/1`, tous acceptés,
  puis une inspection sans aucune tâche restante. La rédaction a relevé deux défauts du fixture (transition
  impossible → 500, `save` jamais appelé) : c'est exactement la valeur attendue de « Points d'attention ».
  Résultat consigné dans `tests/Fixtures/demo/`.

---

### 2026-09-17 — ADR-0012, connaissances de stack

- **Le pipeline prenait la première stack pour tous les workflows** (`$stacks->stacks[0]` dans l'écriture
  des briefs) : invisible sur un projet mono-stack, faux sur un monorepo. **Règle :** tout ce qui dépend de
  la stack d'un workflow passe par la table `identifiant → StackProfile` construite pendant l'extraction.

- **Une connaissance embarquée se copie dans le projet à la première utilisation, puis n'est plus jamais
  écrasée** : c'est le fichier de l'équipe. Les fiches Symfony 7 et 8 ne disent que ce qui est vérifiable ;
  la suppression des loaders XML en 8.x a été constatée dans `vendor/`, pas supposée.

- **Un canevas refuse aussi des « Sources » sans lien** : une section non vide sans URL passait la règle
  « non vide ». **Règle :** « sources consultées » veut dire au moins une URL, testé par un cas dédié.

---

### 2026-09-17 — ADR-0013, voie Claude pour les stacks sans adaptateur

- **Une collision à l'intérieur d'un même brouillon de découverte était fusionnée en silence** : deux points
  d'entrée dérivant le même identifiant donnaient un seul workflow. **Règle :** l'applicateur re-dérive les
  identifiants et refuse toute collision par son nom, qu'elle soit interne au brouillon ou avec une autre
  stack.

- **Un fait déclaré par Claude se vérifie, un niveau de confiance ne se croit pas** : existence du fichier,
  appartenance à la racine de la stack, identifiant re-dérivé, `confidence` ramenée à `medium` et
  `source="claude"` quel que soit le brouillon.

- **Les briefs page et discovery attendent la connaissance** : sans fiche de stack, Claude devinerait où se
  déclarent les points d'entrée. L'ordre du skill est donc connaissance → découverte → pages.

- **Session réelle faite sur `angular-minimal`** : fiche `angular-18`, découverte (deux routes, une
  intégration), trois pages, puis une inspection sans tâche restante ; une modification de
  `order.service.ts` a redemandé exactement les trois pages qui le traversent, et aucune découverte.

---

### 2026-09-17 — ADR-0014, adaptateur PHP générique

- **La règle de dérivation des identifiants ne change pas pour un nouvel adaptateur** : ajouter `/` aux
  séparateurs des routes aurait renommé `route.orders-id` (Angular) et `route.get-orders` (Express). Le nom
  du point d'entrée s'adapte au dériveur (`/orders/new.php` → `orders.new`), jamais l'inverse.

- **Un script composer n'est une commande que s'il exécute un fichier PHP du projet** : `php -l`,
  `php -S` passent des fichiers à un outil. Seule l'option `-d` est acceptée avant le fichier.

- **Un binaire sans extension est du PHP par son shebang** : le graphe ne suivait que les `.php`, et
  `bin/cleanup` n'avait aucune dépendance.

- **Un mutant survit quand deux sources donnent le même nom** : le script et le binaire s'appelaient
  tous deux `import`, si bien que le `??=` ne se voyait pas. **Règle :** les données d'un test de
  priorité doivent différer à l'endroit exact que la priorité départage.

---

### 2026-09-17 — ADR-0015, livraison du MVP

- **La matrice de bout en bout a trouvé deux défauts qu'aucun test unitaire ne voyait** : un test supprimé
  restait cité par la page (la fraîcheur ne regardait pas `<tests>`), et une URL `/orders/new.php` citée
  dans un brouillon était refusée comme fichier inconnu. **Règle :** un scénario « un fichier modifié, un
  supprimé, un ajouté » se joue sur chaque fixture, et chaque décision observée se lit avant d'être
  inscrite comme attendue.

- **Symfony Process peut lancer deux fois une commande relative** (`vendor/bin/devtools`, puis son chemin
  résolu) : deux inspections se disputaient le verrou, un échec sur deux. Le verrou était juste. **Règle :**
  un test qui lance un binaire lui donne un chemin absolu, et un échec intermittent se diagnostique par
  les PID (qui détient le verrou), pas en relançant.

- **`putenv()` ne suffit pas pour un sous-processus lancé par Symfony Process** : seules les variables aussi
  présentes dans `$_SERVER` sont transmises. Les dates de commit fixes (hash stables dans les snapshots)
  passent par les deux.

- **Un `markTestSkipped` sur « Composer indisponible » masquait une option inexistante** (`--no-progress`
  sur `composer config`). **Règle :** seul ce qui dépend du réseau peut être sauté ; une erreur de
  commande locale échoue.

- **Le dogfooding a été écrit avec l'horloge réelle**, pas `SOURCE_DATE_EPOCH` : la documentation
  committée d'un dépôt ne porte pas la date des tests.

- **Les brouillons de `symfony-legacy-yaml` sont ceux de `symfony-minimal`** : même application, seule la
  configuration diffère ; les pages ne décrivent que le code.
