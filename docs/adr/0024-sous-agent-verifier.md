# ADR-0024 — Sous-agent `verifier`

- **Statut** : Proposed — 2026-09-16
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

## Budget d'exécution

Un sous-agent par passage de gate concerné ; le dossier ne contient que les workflows impactés.

## Hors périmètre

Vérificateur hors Claude Code (API) ; vérification des commandes et handlers asynchrones ; plusieurs
vérificateurs en parallèle.

## Critères d'acceptation

- [ ] `verify:prepare` sur un changement de template : dossier conforme, aucune trace de la
      conversation (contenu comparé en snapshot)
- [ ] `verification.xsd` ; verdicts enregistrés : valide, sélecteur absent du DOM, workflow inconnu,
      XML invalide → `inconclusive`
- [ ] Définition d'agent sans outil d'écriture (test sur le fichier installé)
- [ ] Gate : étape `verification` rouge sur `rejected`
- [ ] Session réelle vérifiée une fois : le vérificateur signale un select natif volontairement
      introduit dans `symfony-minimal`
- [ ] README : section « Vérificateur »

## Conséquences

- Le vérificateur coûte un sous-agent par tâche touchant l'interface : c'est le prix d'un second
  regard qui n'a pas écrit le code.

## Dépendances

ADR-0019, ADR-0022.
