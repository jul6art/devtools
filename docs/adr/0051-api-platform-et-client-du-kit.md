# ADR-0051 — Les opérations d'API Platform, le client du kit, et un lien limité à un dossier

- **Statut** : Accepted — 2026-11-09 (décidé par jul6art pour une migration pilote : « Apprendre le kit à devtools », puis
  « Les deux dans devtools ») · Livré le 2026-11-09 (v3.5.0)
- **Décideurs** : jul6art
- **Specs** : § 4.2 (adaptateurs), § 4.8 (multi-projets)
- **Dépend de** : ADR-0007 (adaptateur Symfony), ADR-0032 (adaptateur Angular), ADR-0038 (multi-projets)

## Contexte

Une plateforme de l'écosystème migre une application Symfony de ses écrans Twig vers un front Angular construit sur le kit
de la maison (`@jul6art/web-kit`). Le critère de sa migration : devtools ne signale **aucun appel orphelin** entre
le front et l'API. Mesuré le 2026-11-09, la preuve serait vide des deux côtés :

- **Côté back**, les opérations d'API Platform n'entrent pas dans la carte. Leur contrôleur est celui d'API Platform
  (`api_platform.symfony.main_controller`), que l'adaptateur Symfony écarte comme un contrôleur du vendor. L'ADR-0007
  les laissait hors périmètre. Cette plateforme a 82 opérations à elle ; aucune n'a de page.
- **Côté front**, l'adaptateur Angular ne voit que les classes qui injectent `HttpClient`. Les écrans du kit passent
  par son `ApiClient` (`collection`, `item`, `create`, `update`, `remove`…) et par `<kit-table path="/api/…">` :
  aucun de leurs appels n'est lu.
- **Le lien**, enfin, se déclare entre deux projets entiers. Le front de `legacy` vit dans le workspace Angular de la
  plateforme, à côté de la console, qui appelle une autre API : relier tout le workspace à `legacy` ferait de chaque
  appel de la console un appel orphelin.

## Décision

### 1. Une opération d'API Platform est une route

L'adaptateur Symfony garde une route dont le `debug:router` porte `_api_resource_class` et dont le contrôleur est celui
d'API Platform (`api_platform.symfony.main_controller`, ou `api_platform.action.placeholder` en 3.x). Le point d'entrée
est de type `api-operation` :

- **son fichier** est celui de la classe ressource (`#[ApiResource]`, entité ou DTO) — une ressource du vendor
  (`Error`, `ValidationException`) reste dehors, comme un contrôleur du vendor aujourd'hui ;
- **ses fichiers structurels** ajoutent le provider et le processor de l'opération, lus dans les attributs de la
  ressource (`new Post(processor: GiveBackProcessor::class)`, ou ceux de `#[ApiResource]` par défaut) et
  `config/packages/api_platform.yaml` ;
- **sa sécurité** est l'expression `security` de l'opération, quand elle en a une ;
- une opération `api_platform.action.not_exposed` n'est pas une route : elle répond 404.

L'opération est retrouvée parmi les attributs par son nom (`name:`), sinon par son `uriTemplate` et sa méthode, sinon
par sa sorte (une collection, un élément) ; une opération déclarée ailleurs (YAML, XML) garde la seule classe
ressource.

**Son identifiant** : `route.api.` suivi du nom d'opération qu'API Platform génère, sans `{._format}` ni accolades —
`_api_/loans/{id}/return_post` donne `route.api.loans.id.return.post`, `_api_/items{._format}_get_collection` donne
`route.api.items.get.collection`. Un nom choisi (`name: 'loan_return'`) donne `route.api.loan.return` : le préfixe
`api` évite la collision avec la route Twig `app_loan_return`, qui garde `route.loan.return`. Aucune page existante
ne change d'identifiant : ces routes n'en avaient pas.

**Sans console**, le repli statique ne les lit pas : le chemin d'une opération sans `uriTemplate` est calculé par
API Platform (le nom de la ressource au pluriel), et le deviner serait inventer un fait. Le rapport le dit.

### 2. Le client du kit est un client HTTP

L'adaptateur Angular lit aussi les classes qui injectent `ApiClient` importé de `@jul6art/web-kit` :

| Méthode du kit | Méthode HTTP |
|---|---|
| `collection`, `item`, `get` | GET |
| `create`, `post` | POST |
| `replace` | PUT |
| `update` | PATCH |
| `remove` | DELETE |

Et le gabarit d'un composant qui contient `<kit-table path="/api/…">` appelle `GET /api/…`. Une telle classe est une
intégration (`integration.<kebab(classe)>`), comme un service qui injecte `HttpClient`, ses `endpoints` réunis
avec les siens ; le rôle de son fichier se devine (un composant reste un composant).

Un appel dont l'URL n'a **aucun segment littéral** — `this.api.item(deal.company)`, l'IRI lue dans une réponse — n'est
pas nommable : il n'est pas un `endpoint`. Sans cette règle, son `*` s'apparierait à toutes les routes GET.

Un `ApiClient` d'une autre provenance n'est pas celui du kit : il ne compte pas.

### 3. Un lien peut se limiter à un dossier du client

La déclaration d'un voisin prend l'attribut `client-dir` : seules les intégrations du client dont le fichier est
dans ce dossier (relatif à la racine du projet client) s'apparient à ce voisin.

```xml
<links>
    <project name="legacy" path="demo/legacy" role="server" client-dir="front/projects/legacy"/>
</links>
```

Comme `base-url`, il se lit sur la déclaration de l'un ou de l'autre projet, pour que les deux calculs donnent le
même résultat.

### 4. Le format optionnel ne sépare pas un appel de sa route

`EndpointMatcher` retire de la route un `.{_format}` final, optionnel dans Symfony : sans cela, le segment
`items.{_format}` est un paramètre, et `GET /api/borrowers` s'apparie aussi à `/api/items.{_format}`.

## Budget d'exécution

Aucun processus console de plus (`debug:router` porte déjà les `defaults`) ; une lecture php-parser par classe
ressource, comme pour un contrôleur.

## Hors périmètre

- Les opérations d'API Platform déclarées en YAML ou en XML au-delà de leur classe ; GraphQL.
- Les ressources du repli statique (§ 1).
- Les autres méthodes des clients HTTP maison qu'aucun projet n'utilise encore.

## Critères d'acceptation

- [x] Une console enregistrée avec des opérations d'API Platform : chaque opération d'une ressource du projet est une
      route `api-operation`, son processor et son provider dans ses fichiers, sa sécurité lue ; les ressources du
      vendor et les opérations non exposées n'en sont pas. *(`ApiPlatformOperationsTest`, fixture
      `symfony-api-platform` et sa console enregistrée.)*
- [x] Les identifiants du § 1, dont un nom d'opération choisi, sans collision avec une route Twig.
- [x] Une fixture Angular sur le kit : `ApiClient` et `<kit-table path>` donnent leurs `endpoints`, une IRI n'en
      donne pas, un `ApiClient` d'ailleurs non plus.
- [x] `client-dir` : deux applications dans un même projet client, une seule reliée au serveur, aucun appel orphelin
      pour l'autre.
- [x] `.{_format}` : `GET /api/borrowers` ne s'apparie plus à `/api/items.{_format}`.
- [x] README : API Platform dans l'adaptateur Symfony, le kit dans l'adaptateur Angular, `client-dir` dans
      « Linked projects ».
- [x] `composer qa` vert, jeu `lowest` exercé ; publié en version mineure.

## Écarts assumés à la livraison

- **Le provider et le processor sont des attributs de l'opération**, pas des fichiers structurels : la classe
  ressource les référence, et le graphe les atteint déjà, avec ce qu'ils appellent (le manager d'un processor).
  En contrepartie, chaque opération d'une ressource atteint les providers et processors de toutes ses sœurs :
  le graphe part d'une classe, pas d'une opération.
- **Un appel dont l'URL n'a aucun segment littéral** n'est écarté que pour le client du kit : celui
  d'`HttpClient` garde sa règle d'ADR-0032, pour ne changer aucune carte existante.
- **Le rôle d'une intégration** devient « composant » quand sa classe est un `@Component`, pour le kit comme pour
  `HttpClient` ; aucun service de fixture n'en change.

## Conséquences

- Toute application API Platform de la maison voit son API dans sa carte : cette plateforme, ses
  démonstrations, et à l'étape 2 de sa migration, les applications de jul6art.
- Un changement de ressource API se voit, par `impact --linked`, dans les écrans du kit qui l'appellent.
