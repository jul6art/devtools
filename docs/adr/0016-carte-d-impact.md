# ADR-0016 — Carte d'impact : `workflows:impact`

- **Statut** : Proposed — 2026-09-16
- **Décideurs** : jul6art
- **Specs** : § 4.8 (carte d'impact), § 5 (socle des modules ultérieurs), § 9 (phase 1)

## Contexte

« Qu'est-ce que ce changement touche ? » est la question que ni Claude ni l'humain ne savaient
trancher (§ 1.3). Le MVP écrit la réponse dans `graph/files-to-workflows.xml` ; il faut maintenant
**la servir** : à Claude Code avant qu'il modifie un fichier (ADR-0019), à la gate pour choisir les
scénarios à rejouer (ADR-0023), à l'humain en revue de pull request.

## Décision

### Commande

```
devtools workflows:impact <fichier>...            un ou plusieurs chemins
devtools workflows:impact --commit=<ref>          fichiers d'un commit
devtools workflows:impact --range=<a>..<b>        fichiers d'une plage (revue de PR)
devtools workflows:impact --changed               working tree : modifiés, indexés, non suivis
        [--format=text|json|xml] [--depth=0|1]
```

### Calcul (`Impact\ImpactResolver`)

1. Fichiers en entrée normalisés par `ProjectRoot` (ADR-0004) ; un chemin hors racine est refusé.
2. Lecture de `graph/files-to-workflows.xml` (**jamais** de re-scan) :
   - `relation="file"` → impact **direct** ;
   - `relation="test"` → impact **par test** (le test couvre ce workflow).
3. `--depth=1` (défaut) ajoute les workflows qui ont un workflow direct dans leur `dependsOn`
   (un listener global modifié impacte toutes les routes, § 11) ; `--depth=0` s'en abstient.
4. Un fichier absent de l'index → **« non couvert »** : listé à part, jamais ignoré. Un fichier
   source nouveau est le cas typique ; la sortie le dit et suggère `workflows:inspect`.
5. Sortie triée par identifiant : `id`, `type`, `relation` (`direct|test|dependency`), fichiers
   déclencheurs, chemin de la page, statut de fraîcheur (lu dans `index.xml`).

Le format `json` existe ici parce que la sortie est destinée à des outils (hooks Claude Code, CI) ;
il est décrit par un exemple dans le README, et figé par un snapshot de test.

### Avertissement de fraîcheur

Si `index.xml` est plus ancien que le dernier commit touchant un fichier source, la sortie commence
par un avertissement : l'impact est calculé sur une carte **potentiellement périmée**.

## Budget d'exécution

Lecture de deux fichiers XML ; **aucun parsing de code, aucun hash**. `--commit`, `--range`,
`--changed` : **un** processus git. Cible : < 200 ms sur un projet de 300 workflows — c'est ce qui
permet de l'appeler à chaque modification de fichier depuis un hook (ADR-0019).

## Hors périmètre

Impact au niveau de la méthode ou de la ligne ; impact transitif au-delà d'un saut ; re-scan
automatique quand la carte est périmée.

## Critères d'acceptation

- [ ] Sur `symfony-minimal` inspecté : un service partagé → ses deux routes (direct) ; un listener
      `kernel.request` → toutes les routes (dependency) ; un test → le workflow qu'il couvre (test)
- [ ] Fichier inconnu → section « non couvert », code de sortie 0
- [ ] `--commit`, `--range`, `--changed` dans un dépôt git temporaire : un test chacun, un seul
      processus git (compteur)
- [ ] Chemin hors racine refusé
- [ ] Sorties `text`, `json`, `xml` figées en snapshot
- [ ] Carte périmée → avertissement en tête
- [ ] < 200 ms mesurés sur le projet généré de 300 routes (ADR-0015)
- [ ] README : section « Impact d'un changement »

## Conséquences

- La carte devient une dépendance de travail quotidien : sa fraîcheur (ADR-0017) cesse d'être un
  confort.

## Dépendances

ADR-0015 (MVP livré).
