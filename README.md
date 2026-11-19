# GesTroupeau – Gestion et suivi des troupeaux

Application PHP 8 / MySQL / Bootstrap 5 pour bovins, ovins et caprins.

## Installation (XAMPP, WAMP, Laragon…)
1. Copier le dossier `troupeau/` dans `htdocs/` (ou `www/`).
2. Importer `database.sql` dans phpMyAdmin (crée la base `troupeau` + données de démonstration).
3. Adapter `config/db.php` (hôte, utilisateur, mot de passe).
4. Vérifier que le dossier `uploads/` est accessible en écriture (photos).
5. Ouvrir `http://localhost/troupeau/`.

## Modules
| Page | Rôle |
|---|---|
| `index.php` | Tableau de bord : effectifs, lait moyen, TB/TP, alertes (cellules, délais d'attente, mises bas, rappels) |
| `animaux.php` | Liste filtrable (espèce, lot, sexe, statut) |
| `animal.php` | **Fiche animal** : identité, généalogie, descendance, poids + GMQ, contrôle laitier, reproduction, santé, mouvements, impression |
| `animal_form.php` | Création / modification / photo / sortie (vente, mort, réforme) |
| `lait.php` | Synthèse mensuelle et classement des laitières |
| `pesees.php` | Séance de pesée groupée par lot |
| `indices.php` | **Indices de productivité** par famille (R, L, C, S, P), par campagne / lactation / contrôle et par animal, lot, espèce, troupeau ; évolution, export CSV |
| `indice_form.php` | Création / modification d'un indice : formule, période, seuils d'interprétation par espèce, test de la formule |
| `parametres.php` | Espèces (durée de gestation), races, lots, **liste des indices et de leurs formules** |
| `actions.php` | Enregistrement / suppression de l'historique |

## Calculs automatiques
- **GMQ** (g/jour) entre deux pesées et depuis la première pesée.
- **Mise bas prévue** = date de saillie/IA + durée de gestation de l'espèce.
- **État reproductif** (vide, à confirmer, gestante) et intervalle moyen entre mises bas.
- **Lactation** : jours en lait, moyenne, pic, cumul estimé (méthode des trapèzes).
- **Délais d'attente** lait/viande en cours, affichés sur la fiche et le tableau de bord.
- **Alerte cellules** au-delà de 300 000/mL (`SEUIL_CELLULES` dans `includes/functions.php`).
- Mouvements d'entrée, de transfert et de sortie tracés automatiquement.

## Indices de productivité
Les 25 indices de la spécification sont **calculés automatiquement** à partir des événements enregistrés (jamais saisis),
**interprétés** et **enregistrés** dans `indices_resultats` (consultables aussi via la vue SQL `v_indices_resultats`).

| Famille | Codes |
|---|---|
| R · Reproduction et fertilité | R01 fertilité, R02 fécondité, R03 mise-bas, R04 prolificité, R05 indice de saillie, R06 retour en chaleur, R07 avortement, R08 gémellité, R09 âge à la 1re mise-bas, R10 IMB |
| L · Production laitière | L01 production journalière, L02 production totale par lactation, L03 durée de lactation, L04 production moyenne/jour, L05 persistance |
| C · Croissance et alimentation | C01 GMQ, C02 poids au sevrage ajusté 90 j, C03 indice de consommation, C04 valorisation des parcours |
| S · Survie et santé | S01 mortalité des chevreaux, S02 taux de sevrage, S03 taux de réforme |
| P · Productivité globale | P01 productivité numérique, P02 productivité pondérale, P03 score global 0–100 |

**Période de calcul** (granularité) : *campagne* (une valeur par animal et par campagne, début de campagne réglable dans Paramètres,
septembre par défaut), *lactation* (mise bas → tarissement) ou *jour* (chaque contrôle laitier).
**Niveaux enregistrés** : animal (ou mâle pour R05), lot (historique des mouvements), espèce, troupeau.

**Paramètres › Indices** permet de modifier chaque indice sans toucher au code :
- **formule** à partir de variables de base (`gestante`, `saillie_diag`, `nb_petits`, `lait_lactation`, `jours_parcours`, `petits_sevres`…)
  et des **codes d'autres indices** de même période (`L04 = L02 / sinon(L03, jours_en_lait)`, P03 utilise R04 et S02) ; les dépendances circulaires sont refusées ;
- fonctions `si`, `sinon`, `min`, `max`, `moyenne`, `moyenne_ponderee`, `note(x, valeur_0, valeur_100)`, `arrondi`… ;
- **interprétation** : sens favorable, texte, seuils colorés **généraux ou par espèce** (prioritaires) ;
- **calcul des groupes** : *ratio* (Σ numérateurs ÷ Σ dénominateurs, pour les taux) ou moyenne / somme / min / max ;
- **Tester la formule** sur une campagne avant d'enregistrer ; l'enregistrement recalcule tout.

Recalcul automatique après chaque saisie (campagne concernée et précédente, car une mise bas confirme les saillies de la campagne d'avant).

Règles de calcul à connaître :
- une saillie n'entre dans R01 qu'une fois son résultat connu (diagnostic, échec ou 60 jours écoulés), et dans R02/R03 qu'une fois arrivée à terme ;
- R09 ne concerne que les animaux nés à la ferme ; C01 les animaux de moins de 24 mois ; P03 les femelles ayant mis bas dans la campagne ;
- les mort-nés sont saisis avec la mise bas ; le poids au sevrage est celui de la fiche, sinon la pesée la plus proche (± 15 j) ;
- les quantités de lait sont traitées comme des kg (1 L ≈ 1,03 kg).

Données ajoutées pour ces indices : date et poids de **sevrage** (fiche animal), **mort-nés** (mise bas), onglet **Alimentation**
(aliment distribué), lots de type *pâturage* pour les jours de parcours.

**Base existante** : sélectionner la base puis importer `migrations/001_indices.sql` (ajoute les colonnes et tables, remplace les définitions d'indices ;
les espèces sont reconnues par leur nom – Bovin/Vache, Ovin/Mouton/Brebis, Caprin/Chèvre – quels que soient leurs identifiants ;
les résultats sont recalculés au premier affichage de la page Indices).

## Sécurité
Requêtes préparées PDO, échappement HTML, formules évaluées par un analyseur dédié (jamais `eval`), jeton CSRF sur tous les formulaires, contrôle du type des photos.
La table `utilisateurs` est prête pour ajouter une connexion avec `password_hash()`.
