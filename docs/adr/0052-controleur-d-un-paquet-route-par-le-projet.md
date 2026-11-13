# ADR-0052 — Le contrôleur d'un paquet, routé par le projet, est un workflow du projet

- **Statut** : Accepted — 2026-11-13 (décidé par jul6art : « adapte devtools pour qu'il le prenne ») · Livré le
  2026-11-13 (v3.6.0)
- **Décideurs** : jul6art
- **Specs** : § 4.2 (adaptateurs), § 4.3 étape 4 (les fichiers de `vendor/` sont des paquets)
- **Dépend de** : ADR-0003 (modèle), ADR-0007 (adaptateur Symfony)
- **Amende** : ADR-0007, table « Correspondance avec les types » (ligne `routes`)

## Contexte

L'ADR-0007 écarte toute route dont le contrôleur n'est pas un fichier du projet : « les routes de `vendor/`
(profiler, bundles) sont exclues ». C'était juste tant qu'un bundle ne servait que ses propres routes.

Depuis l'audit de 2026-11 (T20), les applications délèguent des écrans entiers à leurs bundles et les routent
elles-mêmes, dans leurs fichiers :

```yaml
# config/routes/role_permission.yaml
admin_role_permission_index:
    path: /admin/role-permissions
    controller: Jul6Art\AdminBundle\Controller\RolePermissionController::index
```

Chaque déplacement fait disparaître la page du workflow : la bascule de langue (core-bundle, lot 4), le jeton
Mercure (push-bundle, lot 1), puis la grille des permissions (admin-bundle, lot 2). Mesuré sur devingame le
2026-11-13 : cinq routes que le projet déclare n'ont plus de page, et le guide d'administration, qui est tiré de ces
pages, a perdu sa section « Les permissions ». L'écran existe toujours ; c'est le projet qui le route, le protège
et le traduit, et c'est son gabarit qui l'affiche.

## Décision

### 1. La règle

Une route dont le contrôleur appartient à un paquet est un workflow du projet **quand le projet la déclare
lui-même** : son nom est une clé d'un fichier de routes du projet (`config/routes.yaml`, `config/routes/**`, au
premier niveau ou sous `when@<env>:`), et le bloc de cette clé nomme ce contrôleur (`controller:` ou
`_controller:`, entre guillemets ou non, barres obliques inverses simples ou doublées).

Les autres routes restent dehors, comme avant :

- une route qu'un bundle déclare et que le projet importe (`resource: '@AdminBundle/…'`) : son nom n'est la clé
  d'aucun fichier du projet ;
- les routes du profiler : leur contrôleur est un service, pas une classe d'un paquet ;
- une clé du projet dont le bloc nomme un autre contrôleur que celui de la console : ce n'est pas la même route.

Une route traduite est nommée `<clé>.<locale>` par Symfony : elle est retrouvée par sa clé, sans la locale.

### 2. Le modèle ne change pas

Aucun chemin de `vendor/` n'entre dans le modèle (ADR-0003) :

- **le point d'entrée** est déclaré dans le fichier de routes du projet (`declaredIn`) ;
- **le paquet du contrôleur** est une dépendance du workflow (`PackageRef`, version de `composer.lock`),
  donnée par l'adaptateur. `EntryPointCandidate` gagne `packages` pour cela ;
- **l'attribut `controller`** (`Classe::méthode`) dit ce qui répond, puisque aucun fichier du projet ne le dit.

Une route que les traductions déclinent forme un seul workflow, comme les routes d'une même méthode du projet. La
clé de groupe est le fichier de routes et le contrôleur. Deux redirections d'un même fichier vers
`RedirectController` forment donc un workflow, et ses satellites sont les autres redirections.

### 3. Ce que la page ne dit pas

- **Ni navigation, ni machine à états, ni décisions** : elles se lisent dans le PHP du contrôleur, qui n'est pas
  un fichier du projet. Un fichier de routes n'a pas de méthode à parcourir.
- **La sécurité** se limite à `access_control` : un contrôleur de bundle se garde souvent par sa configuration
  (`admin.role_permissions.read`), que DevTools ne lit pas.
- **Sans console**, le repli statique ne les voit pas : il ne lit que les `#[Route]` des classes du projet.

## Conséquences

- Les applications qui routent un contrôleur de bundle retrouvent la page de ces routes au prochain
  `workflows:inspect`. Mesuré avant livraison sur devingame : `workflows:check` annonce cinq routes non
  documentées (`route.admin.locale.switch`, `route.admin.mercure.token`, `route.admin.role.permission.index`,
  `.save` et `route.site.account.two.factor.en`), et rien d'autre.
- Monter le paquet de ce contrôleur déclenche la dérive de ces workflows (`workflows:accept '<paquet>'`), comme
  pour toute dépendance.
- Tests : `RoutedPackageControllerTest`, sur un projet temporaire. Il couvre les routes déclarées, un import, le
  profiler, une clé dont le contrôleur diffère, `when@prod`, une route traduite et deux redirections. Deux
  mutations le font échouer : retirer la recherche du fichier, ou retirer la comparaison du contrôleur.
