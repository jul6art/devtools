# ADR-0038 — Multi-projets : relier un front et son API

- **Statut** : Accepted — 2026-10-21 (proposée le 2026-09-16)
- **Décideurs** : jul6art
- **Specs** : § 4.8 (multi-projets, `--link`, Angular ↔ Symfony)

## Contexte

`cegeta` et `cegeta-mobile`, `cereezer` et `cereezer-mobile` : chaque application mobile Angular ne
connaît de son back que `/api/*`. Modifier une ressource API sans savoir quels écrans mobiles
l'appellent est exactement le « qu'est-ce que ça impacte ? » du § 1.3, à travers deux dépôts.

## Décision

### Déclaration

`.devtools/config.xml` de chaque projet déclare ses voisins :

```xml
<links>
  <project name="cegeta-mobile" path="../cegeta-mobile" role="client"/>
</links>
```

`devtools workflows:inspect ../cegeta ../cegeta-mobile --link` (§ 4.8) inspecte chacun normalement, puis
calcule les liens.

### Appariement

- Côté client : workflows `integrations` (ADR-0032 pour Angular ; voie Claude sinon) avec méthode et
  chemin d'URL, préfixe de base résolu depuis la configuration d'environnement du client
  (`environment.ts`, ou `<base-url>` déclaré).
- Côté serveur : routes (méthode, motif de chemin).
- Correspondance méthode + motif (`/api/items/{id}` ↔ `/api/items/${id}`) → lien `calls` ; appel client
  sans route correspondante → **« appel orphelin »** listé dans les deux menus (un écran mobile appelle
  une route supprimée).
- Résultat : `.devtools/graph/links.xml` dans **chaque** projet (`links.xsd`), avec le nom du projet
  voisin et l'identifiant du workflow distant — jamais un chemin absolu.

### Effets

- Pages : section « Workflows liés » enrichie des workflows distants (lien relatif vers l'autre dépôt,
  valide quand les dépôts sont voisins sur disque, sinon identifiant en texte).
- `workflows:impact` (ADR-0016) : `--linked` ajoute les workflows distants impactés.
- Fraîcheur : un lien dont le workflow distant a disparu est signalé par `workflows:check`.

## Budget d'exécution

Appariement en mémoire après les deux inspections ; aucune inspection supplémentaire.

## Hors périmètre

Découverte automatique des voisins ; GraphQL, gRPC, WebSocket ; plus de deux projets liés entre eux
transitivement.

## Critères d'acceptation

- [x] Fixtures `monorepo-link/api` (Symfony) et `monorepo-link/mobile` (Angular) : liens attendus
      dans les deux `links.xml` (`ProjectLinkTest`)
- [x] Route supprimée côté API → appel orphelin dans les deux menus, `check` en échec
- [x] `impact --linked` sur un contrôleur API → écrans mobiles concernés
- [x] Aucun chemin absolu dans les fichiers produits (hors rapports d'exécution, déjà ignorés de git)
- [x] README : section « Linked projects »

## Mise en œuvre (2026-10-21)

- Paquet `src/Link/` : `LinkedProject` (déclaration), `LinkSide` (routes et appels lus dans le dernier
  `index.xml` et les fichiers de suivi, jamais dans le code), `EndpointMatcher` et `LinkCalculator`
  (appariement), `XmlLinksStore` (`links.xsd`), `ProjectLinker` (le pas `--link`), `RelatedLinks`
  (lignes des pages), `LinkCheck` (causes de `check`), `LinkedImpactResolver` (`impact --linked`).
- `workflows:inspect` accepte plusieurs chemins ; `--link` lie chaque projet nommé **après** les avoir
  tous inspectés — les liens d'un front dépendent des routes de l'API et inversement. Chaque projet
  n'écrit que son propre `links.xml` ; `--link` est refusé avec `--dry-run`.
- Appariement : méthode, puis segments ; l'hôte, la requête et le fragment de l'URL du client tombent ;
  `${…}`, `:id`, `{id}`, `*` sont des paramètres. Le meilleur score gagne (littéral = littéral 2,
  paramètre = paramètre 1, paramètre face à un littéral 0), plusieurs routes à égalité sont toutes liées.
  Un début d'URL illisible (`*`) est remplacé par l'attribut `base-url` d'une déclaration — de l'un ou
  l'autre projet, pour que les deux calculs donnent le même résultat — ou, à défaut, apparié par la fin.
- Un appel est stocké tel que le client l'écrit (`GET https://items.example.com/api/items/:param`) : c'est
  ce que la page du client montre.
- Pages : les lignes d'un projet lié commencent par son nom (`- mobile · …`), après les liens du workflow
  lui-même. Le contexte de rendu lit `links.xml` partout où une page est rendue (inspection, `apply`), si
  bien qu'une réinspection sans `--link` redonne les mêmes octets ; `--link` réécrit en place la seule
  section « Related workflows » des pages dont les liens ont changé, sans révision, et refait le menu
  (`MenuRefresher`, extrait de `DraftApplier`).
- Menu : « Linked: … » dans l'en-tête, les appels orphelins sous « To check ».
- Fraîcheur : chaque inspection compare `links.xml` à ce qu'elle trouve (workflow local disparu) et à
  l'index du voisin quand il est sur disque (workflow distant disparu) ; ces écarts et les appels
  orphelins sont des causes de `workflows:check` et des avertissements d'`inspect` — sauf pendant un run
  `--link`, qui recalcule tout juste après.
- `impact --linked` : `calls` (intégration du client qui appelle une route atteinte), `uses` (écran du
  client qui traverse cette intégration, lu dans la carte du client), `answers` (route du serveur
  qu'appelle une intégration atteinte). Clé `linked` en JSON, éléments `<linked>` en XML, seulement sur
  demande.

## Conséquences

- Un changement d'API devient visible du côté mobile avant la publication d'une version des stores.

## Dépendances

ADR-0016, ADR-0032.
