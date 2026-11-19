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
| `indices.php` | **Indices** : résultats enregistrés par année et par animal / lot / espèce / troupeau, interprétation, évolution pluriannuelle, export CSV |
| `indice_form.php` | Création / modification d'un indice : formule, seuils d'interprétation, champ d'application, test de la formule |
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

## Indices paramétrables
Chaque indice est défini dans **Paramètres › Indices** et peut être modifié sans toucher au code :
- **Formule** construite à partir de variables de base calculées pour chaque animal et chaque année
  (`lait_moyen`, `lait_cumul`, `tb_moyen`, `gmq`, `ivv`, `nb_diag_pos`, `nb_petits`, `cout_sante`, `age_mois`, `mort`…,
  liste complète dans le formulaire). Opérateurs `+ - * / % ^`, comparaisons, `et / ou / non`,
  fonctions `min max abs arrondi racine log si sinon`. Exemple : `lait_cumul * (tb_moyen + tp_moyen) / 1000`.
- **Interprétation** : seuils (min inclus, max exclu) avec libellé et couleur, ex. `< 200` Mamelle saine, `200 – 300` À surveiller, `≥ 300` Mammite.
- **Champ d'application** : espèce, sexe, niveaux enregistrés (animal, lot, espèce, troupeau) et mode de calcul des groupes
  (moyenne, somme, min, max, ou *ratio* : formule appliquée aux totaux du groupe pour les taux).
- **Tester la formule** affiche le résultat animal par animal avant d'enregistrer ; l'enregistrement recalcule l'indice sur toutes les années.

Les résultats (valeur, interprétation, formule utilisée, date de calcul) sont stockés dans `indices_resultats`.
Ils sont recalculés automatiquement après chaque saisie (pesée, contrôle laitier, reproduction, santé, mouvement, fiche animal)
pour l'année concernée, ou à la demande depuis la page **Indices**. Une icône ⚠ signale un indice dont la formule a changé depuis le dernier calcul.

**Base existante** : importer `migrations/001_indices.sql` (crée les 3 tables et 11 indices par défaut sans toucher aux données).

## Sécurité
Requêtes préparées PDO, échappement HTML, formules évaluées par un analyseur dédié (jamais `eval`), jeton CSRF sur tous les formulaires, contrôle du type des photos.
La table `utilisateurs` est prête pour ajouter une connexion avec `password_hash()`.
