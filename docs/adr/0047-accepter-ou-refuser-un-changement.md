# ADR-0047 — Accepter un changement, ou le refuser : la revue

- **Statut** : Accepted — 2026-09-18
- **Décideurs** : jul6art
- **Specs** : § 4.8, § 9 (phase 1), § 11
- **Dépend de** : ADR-0046 (diff typé), ADR-0017 (la gate qui l'annonce)

## Contexte

L'ADR-0046 dit **ce qui a changé** et l'ADR-0017 **fait échouer la gate**. Il manque ce que l'humain
fait ensuite, et qui n'a aujourd'hui aucune place dans l'outil : décider, fait par fait.

Deux décisions seulement, et elles n'ont rien à voir l'une avec l'autre :

- **le changement est voulu** — la documentation doit suivre : les faits se réécrivent, et la prose que
  ce fait rend fausse est à reprendre ;
- **le changement n'était pas voulu** — c'est le code qui doit revenir, et la documentation ne bouge pas.

Aujourd'hui, ces deux cas se traitent de la même façon : on relance `workflows:inspect`, qui réécrit
tout sans distinguer, et l'humain retrouve ses 127 fichiers modifiés.

⚠️ **Ce que l'outil ne fera pas** : accepter la moitié d'un fichier. Un fait est porté par du code ; le
refuser, c'est ramener ce code en arrière. L'outil ne rejoue pas de patch inverse par morceaux — il
donne la commande exacte et ne l'exécute que si on la lui demande.

## Décision

### 1. Accepter, c'est écrire le suivi — et rien d'autre ne le fait

`devtools workflows:accept <cible>… | --all` :

1. retrouve les workflows qui portent ces faits (le diff les donne, groupés) ;
2. relance l'inspection **bornée à eux** (`--force` sur ces identifiants) : les faits de leurs pages
   sont réécrits, leurs suivis mis à jour ;
3. l'entrée d'historique de chaque page **nomme le fait accepté**, pas le fichier : « décision modifiée :
   `App\Entity\User::email` » plutôt que « files changed: src/Entity/User.php » ;
4. le brief de rédaction porte les **faits** (`<fact>`), en plus des fichiers : Claude sait par où
   commencer au lieu de relire la page entière.

Une cible inconnue est une erreur, pas un silence. `--no-ai` accepte les faits sans demander de
rédaction : la page reste juste, sa prose attendra.

### 2. Refuser, c'est ne rien écrire — et dire quoi défaire

`devtools workflows:reject <cible>… | --all` **ne touche ni à la documentation ni au code**. Il imprime
la commande qui ramène les fichiers portant ces faits à l'état où la page a été écrite :

```
git restore --source=8f3c21a -- src/Entity/User.php
```

⚠️ `--restore` l'exécute, et c'est la seule chose de tout DevTools qui touche au code du projet. Elle
est donc bornée : jamais sans l'option, jamais sur un fichier qui porte d'autres modifications que le
fait refusé — dans ce cas l'outil imprime la commande et laisse la main. Un outil de documentation qui
écrase du travail est un outil qu'on désinstalle.

### 3. `devtools workflows:review` — les deux, fait par fait

Interactif : pour chaque fait, dans l'ordre de l'ADR-0046 (le plus large d'abord), le changement, son
diff de code, et une question à trois réponses :

```
 modifié décision App\Entity\User::email   src/Entity/User.php:250
   - null === Strings::lowerEmail($email)
   + '' === trim($email)
   233 workflows

 [a]ccepter · [r]efuser · [p]asser ?
```

À la fin : une acceptation groupée (une seule inspection, quel que soit le nombre de faits acceptés) et
la liste des commandes de restauration pour les refus. **Rien n'est écrit avant la dernière réponse** :
un ^C au milieu d'une revue laisse le projet exactement comme il était.

Sans terminal (CI, redirection), `review` refuse de deviner : il renvoie la sortie de `workflows:diff`
et un code 1.

### 4. Ce qui reste hors de l'outil

Le patch inverse par morceau (refuser un fait sans toucher au reste du fichier), la revue à plusieurs,
l'historique des décisions de revue. Le suivi porte déjà qui a accepté quoi, par ses révisions ; le
reste appartient à git.

## Budget d'exécution

`review` et `reject` : le coût d'un `workflows:diff` (une inspection en lecture seule). `accept` : ce
coût plus une inspection bornée aux workflows touchés — la fraîcheur ne réécrit rien d'autre.

## Critères d'acceptation

- [x] `accept <cible>` réécrit les pages des workflows portant ce fait, et **eux seuls**
- [x] L'historique de la page nomme le fait accepté
- [x] Le brief porte les faits (`<fact>`), validé par le XSD, et le prompt les cite
- [x] Une cible inconnue est refusée avec la liste des cibles disponibles
- [x] `reject` seul n'écrit rien, ni dans la documentation ni dans le code ; il imprime la commande
- [x] `reject --restore` ramène le fichier et le dit ; il refuse quand le fichier porte d'autres
      modifications que le fait refusé
- [x] `review` non interactif (sans terminal) sort 1 sans rien écrire
- [x] `review` : accepter puis refuser sur un projet de test produit exactement une inspection
- [x] README : section « Accepter ou refuser »

## Conséquences

- Le suivi devient le registre des décisions de revue : ce qu'il porte a été accepté par quelqu'un.
- `workflows:inspect` sans argument reste ce qu'il est — tout régénérer —, mais cesse d'être le seul
  geste disponible après une dérive.

## Dépendances

ADR-0010, ADR-0011 (rédaction), ADR-0017, ADR-0046.
