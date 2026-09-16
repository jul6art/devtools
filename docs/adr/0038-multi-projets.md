# ADR-0038 — Multi-projets : relier un front et son API

- **Statut** : Proposed — 2026-09-16
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

- [ ] Fixtures `monorepo-link/api` (Symfony) et `monorepo-link/mobile` (Angular) : liens attendus
      dans les deux `links.xml`
- [ ] Route supprimée côté API → appel orphelin dans les deux menus, `check` en échec
- [ ] `impact --linked` sur un contrôleur API → écrans mobiles concernés
- [ ] Aucun chemin absolu dans les fichiers produits
- [ ] README : section « Projets liés »

## Conséquences

- Un changement d'API devient visible du côté mobile avant la publication d'une version des stores.

## Dépendances

ADR-0016, ADR-0032.
