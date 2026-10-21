# ADR-0024 — Sous-agent `verifier`

- **Statut** : Accepted — 2026-10-20 (proposée le 2026-09-16 ; phase 1 lancée par jul6art)
- **Décideurs** : jul6art
- **Specs** : § 1.3 (biais de l'auteur), § 5.3

## Contexte

Quand Claude vérifie son propre code, il a « le biais de l'auteur » (§ 1.3). Les scénarios couvrent ce
qui a été prévu ; il reste ce qui ne l'a pas été — un écran qui fonctionne mais ne ressemble pas à ce
qu'on attendait. Le § 5.3 propose un vérificateur en **contexte vierge**, sans accès au raisonnement
de l'agent auteur, qui juge sur pièces.

## Décision

### L'agent

`devtools claude:install --agents` installe `.claude/agents/devtools-verifier.md`. Ses consignes :
- ne lit **que** le dossier de vérification qu'on lui désigne, et le code des fichiers qu'il liste ;
- **ne modifie aucun fichier** (outils limités à la lecture dans sa définition) ;
- rend un verdict au format XML (`verification.xsd`) dans le dossier désigné.

### Le dossier de vérification

`devtools verify:prepare --changed` écrit `.devtools/pending/verification.<horodatage>/` :
workflows impactés et leurs pages, scénarios et résultat de la dernière exécution (ADR-0021), captures,
baselines et diffs (ADR-0022), DOM, et règles applicables (ADR-0026, vide avant la phase 2).
**Rien** de la conversation de l'agent auteur, ni de ses messages de commit.

### Verdict

```xml
<verification verdict="approved|rejected|inconclusive">
  <finding workflow="route.order.new" severity="blocking|minor"
           selector="#order_product" category="ui-component">
    Le champ produit est un select natif ; les autres formulaires utilisent select2.
  </finding>
</verification>
```

- `category` appartient à la **liste fermée** des catégories de retour (ADR-0025, provisoire avant).
- `devtools verify:collect` valide le verdict (XSD, sélecteurs présents dans le DOM capturé, workflows
  existants) ; un verdict invalide compte comme `inconclusive`.
- Un `finding` bloquant est une **proposition de retour** : la phase 2 permet de le promouvoir
  (ADR-0025).

### Déclenchement

Systématique via la gate (ADR-0023) pour tout changement touchant un template ou un formulaire (§ 5.3).
Le hook `Stop` demande à Claude de lancer le sous-agent sur le dossier préparé, puis relance
`verify:collect`.

### Précisions à l'acceptation (2026-10-20)

- **L'agent ne peut pas écrire**, et la décision lui demandait d'écrire son verdict : il le **rend comme
  réponse**, et la session qui l'a appelé l'écrit telle quelle dans `verdict.xml` du dossier. Outils de sa
  définition : `Read, Grep, Glob` — ni `Bash`, qui écrirait. ⚠️ Installer une définition d'agent n'est pas
  lancer un sous-agent : c'est le projet qui l'appelle, dans sa session.
- **`claude:install --agents`** copie `resources/claude/agents/devtools-verifier.md` dans `.claude/agents/`
  (adapté par le projet : conservé sauf `--force`) ; `claude:uninstall --agents` le retire s'il n'a pas changé.
- **Le dossier** `.devtools/pending/verification.<horodatage>/` : `manifest.xml` (`verification-brief.xsd`),
  `pages/`, `scenarios/`, `run/` (captures, DOM, diffs des étapes `screenshot` de la dernière exécution),
  `baselines/` — des copies, des seuls routes et composants atteints ; le code est nommé par son chemin.
  Les catégories sont celles de l'ADR-0025 (dix, provisoires), vérifiées par DevTools et non par le schéma, pour
  que l'ADR-0025 puisse les étendre par configuration. Le changement se prend comme pour `workflows:impact`.
- **`verify:collect [dossier]`** (défaut : le plus récent) écrit `outcome.xml` (`verification.xsd`, racine
  `outcome`) : le verdict qui compte et ses raisons. Devient `inconclusive` : pas de verdict, XML invalide,
  workflow absent du dossier, catégorie inconnue, sélecteur absent des DOM capturés ou invalide, sélecteur
  sur un workflow sans capture, `approved` avec un constat bloquant, `rejected` sans. Sortie 0 sur `approved`
  seulement. Les sélecteurs sont cherchés avec l'analyseur HTML5 de PHP 8.4 (`querySelector`).
- **Déclenchement** : l'étape `verification` de la gate (ADR-0023) prépare le dossier une fois par état du
  changement et dit quoi faire ; le hook `Stop` porte ce texte à Claude.

## Budget d'exécution

Un sous-agent par passage de gate concerné ; le dossier ne contient que les workflows impactés.

## Hors périmètre

Vérificateur hors Claude Code (API) ; vérification des commandes et handlers asynchrones ; plusieurs
vérificateurs en parallèle.

## Critères d'acceptation

- [x] `verify:prepare` sur un changement de template : dossier conforme, aucune trace de la
      conversation (contenu comparé en snapshot) *(`tests/Fixtures/snapshots/verification/folder.txt` ; un
      message de commit ne l'atteint pas)*
- [x] `verification.xsd` ; verdicts enregistrés : valide, sélecteur absent du DOM, workflow inconnu,
      XML invalide → `inconclusive`
- [x] Définition d'agent sans outil d'écriture (test sur le fichier installé)
- [x] Gate : étape `verification` rouge sur `rejected`
- [ ] Session réelle vérifiée une fois : le vérificateur signale un select natif volontairement
      introduit dans `symfony-minimal`
- [x] README : section « Vérificateur » *(« The verifier »)*

## Conséquences

- Le vérificateur coûte un sous-agent par tâche touchant l'interface : c'est le prix d'un second
  regard qui n'a pas écrit le code.

## Dépendances

ADR-0019, ADR-0022.
