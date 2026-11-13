# ADR-0053 — La commande d'un paquet, au nom du projet, est un workflow du projet

- **Statut** : Accepted — 2026-11-13 (décidé par jul6art : « DevTools 3.7 : commandes de paquet ») · Livré le
  2026-11-13 (v3.7.0)
- **Décideurs** : jul6art
- **Specs** : § 4.2 (adaptateurs), § 4.3 étape 4 (les fichiers de `vendor/` sont des paquets)
- **Dépend de** : ADR-0003 (modèle), ADR-0007 (adaptateur Symfony), ADR-0052 (le contrôleur d'un paquet routé
  par le projet)
- **Amende** : ADR-0007, table « Correspondance avec les types » (ligne `commands`)

## Contexte

L'ADR-0007 ne documente que les commandes dont la classe est un fichier du projet. Le lot 3 de T20 (audit de
2026-11) remonte dans auth-bundle les deux commandes de compte que chaque back-office recopiait :
`app:user:create` et `app:dev:login-link`. L'application les active dans sa configuration
(`auth.account.create_user`, `auth.account.dev_login_link`), sous le même nom qu'avant. Les procédures et les
hooks de déploiement les appellent toujours.

Au premier `workflows:inspect` qui suit, leurs deux pages sont « orphelines » dans chaque application : la commande
existe, c'est la classe qui a changé de dépôt. C'est le cas que l'ADR-0052 a réglé pour les routes.

## Décision

### 1. La règle

Une commande dont la classe appartient à un paquet est un workflow du projet **quand elle porte le nom du projet** :
son nom commence par `app:`. C'est l'espace de noms des commandes de l'application. Un bundle ne le prend que sur
demande du projet, comme auth-bundle sur `auth.account`.

Les autres commandes restent dehors, comme avant :

- les commandes d'un bundle sous son propre nom (`acl:permissions:seed`, `doctrine:…`) ;
- celles du framework (`cache:clear`) ;
- une commande au nom du projet dont aucun paquet du `composer.lock` ne porte la classe.

### 2. Le modèle ne change pas

Comme à l'ADR-0052, aucun chemin de `vendor/` n'entre dans le modèle :

- **le point d'entrée** est déclaré dans `composer.json`, là où le projet requiert ses paquets ;
- **le paquet** est une dépendance du workflow (`PackageRef`, version de `composer.lock`) : le monter fait dériver
  la page ;
- **l'attribut `class`** dit ce qui répond, puisque aucun fichier du projet ne le dit.

`config/bundles.php` a été écarté : c'est du PHP, et l'extraction y lit chaque bundle. La page dériverait à la montée
de n'importe lequel.

### 3. Ce que la page ne dit pas

Ni décisions, ni entités, ni tests du projet sur la classe : ils se lisent dans le PHP de la commande, qui n'est pas
un fichier du projet. Sans console, le repli statique ne voit pas ces commandes : il ne lit que les `#[AsCommand]`
des classes du projet.

## Conséquences

- Les applications qui activent les commandes d'auth-bundle retrouvent leurs pages au prochain `workflows:inspect` :
  `command.app.user.create`, `command.app.dev.login-link`.
- Tests : `PackageCommandTest`, sur un projet temporaire. Il couvre une commande du projet, une commande de paquet au
  nom du projet, une commande de bundle sous son propre nom, une commande du framework, et un verrou qui ne connaît
  pas le paquet. Retirer la règle du nom `app:` le fait échouer.
