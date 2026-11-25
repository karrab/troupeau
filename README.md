# GesTroupeau – Gestion et suivi des troupeaux

Application PHP 8 / MySQL / Bootstrap 5 pour bovins, ovins et caprins.

## Installation (XAMPP, WAMP, Laragon…)
1. Copier le dossier `troupeau/` dans `htdocs/` (ou `www/`).
2. Importer `database.sql` dans phpMyAdmin (crée la base `troupeau` + données de démonstration).
3. Adapter `config/db.php` (hôte, utilisateur, mot de passe).
4. Vérifier que le dossier `uploads/` est accessible en écriture (photos).
5. Ouvrir `http://localhost/troupeau/` : à la première visite, la page de connexion propose de **créer le compte administrateur**
   (aucun mot de passe n'est fourni par défaut).

Base déjà installée : sélectionner la base dans phpMyAdmin, puis importer dans l'ordre `migrations/001_indices.sql`,
`migrations/002_utilisateurs.sql`, `migrations/003_mensurations.sql`, `migrations/004_profils.sql` et `migrations/005_souvenir.sql` (chacune peut être réimportée sans risque).

## Modules
| Page | Rôle |
|---|---|
| `index.php` | Tableau de bord : effectifs, lait moyen, TB/TP, alertes (cellules, délais d'attente, mises bas, rappels) |
| `animaux.php` | Liste filtrable (espèce, lot, sexe, statut) |
| `animal.php` | **Fiche animal** : identité, généalogie, descendance, poids + GMQ, contrôle laitier, reproduction, santé, mouvements, impression |
| `animal_form.php` | Création / modification / photo / sortie (vente, mort, réforme) |
| `lait.php` | Synthèse mensuelle et classement des laitières |
| `mensurations.php` | Séance de mensuration d'un lot de chevreaux au stade W0, W1, W2… et tableau de suivi par stade |
| `pesees.php` | Séance de pesée groupée par lot |
| `indices.php` | **Indices de productivité** par famille (R, L, C, S, P), par campagne / lactation / contrôle et par animal, lot, espèce, troupeau ; évolution, export CSV |
| `indice_form.php` | Création / modification d'un indice : formule, période, seuils d'interprétation par espèce, test de la formule |
| `login.php` / `logout.php` | Connexion (création du compte administrateur au premier accès) et déconnexion |
| `mon_compte.php` | **Mon profil** : nom, e-mail, téléphone, fonction, photo, mot de passe, mes droits, mes connexions |
| `utilisateurs.php` / `utilisateur.php` | Comptes : création, fiche, profil, activation, déblocage, mot de passe provisoire, journal des connexions |
| `profils.php` | **Profils et permissions** : matrice des droits par profil, création de profils |
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

## Mensurations
- **À la mise bas (mère)** : PMB (poids à la mise bas) + L1 à L6. Saisie directement avec la mise bas (onglet Reproduction)
  ou plus tard dans l'onglet **Mensurations** de la fiche, rattachée à la mise bas concernée.
- **Chevreaux** : stades **W0, W1, W2, W3, W4…** (sans limite), chacun avec la date, le poids et L1 à L6.
  Saisie sur la fiche (onglet Mensurations, stade suivant proposé) ou par lot sur la page **Mensurations**.
- La signification et l'unité de L1 à L6 se règlent dans **Paramètres › Mensurations**.
- Le poids (PMB ou Wn) est aussi enregistré comme pesée : il compte dans le GMQ, le poids au sevrage et les indices.
  Supprimer la mensuration supprime cette pesée.

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

## Connexion, profils et permissions
Toutes les pages exigent une connexion (identifiant = adresse e-mail). Chaque utilisateur a un **profil** ;
chaque profil est un ensemble de **permissions** cochées dans **Utilisateurs › Profils et permissions**.

**32 permissions**, par module : tableau de bord ; animaux (voir, créer, modifier, supprimer) ; pesées, mensurations,
production laitière, reproduction, santé, alimentation, mouvements (voir, saisir, supprimer pour chacun) ;
indices (voir, calculer, exporter, configurer les formules) ; administration (paramètres, utilisateurs).

| Profil fourni | Droits par défaut (modifiables) |
|---|---|
| Administrateur | Tout, y compris profils et permissions – non modifiable |
| Éleveur | Tout le troupeau : créer/modifier les animaux, saisir et supprimer dans tous les modules, calcul et export des indices |
| Technicien / salarié | Consultation + saisies quotidiennes et création d'animaux, sans suppression ni modification des fiches |
| Vétérinaire | Consultation de tout le troupeau, saisie et suppression des actes de santé |
| Consultation | Lecture seule + export des indices (conseiller, contrôle laitier, associé) |

- On peut créer d'autres profils (ex. Stagiaire), en copiant les droits d'un profil existant.
- Les permissions sont vérifiées **côté serveur** sur chaque page et chaque saisie, et rechargées à chaque page :
  une modification s'applique immédiatement aux personnes connectées.
- Cocher « saisir » ou « supprimer » coche « voir » ; décocher « voir » retire les autres droits du module.
- Gérer les profils est réservé aux administrateurs. La permission « gérer les utilisateurs » ne permet ni de toucher
  un compte administrateur, ni d'attribuer le profil Administrateur, ni de changer son propre profil.
- **Mon profil** : chacun modifie son nom, téléphone, fonction et photo ; changer d'adresse e-mail demande le mot de passe actuel.
  La page affiche aussi ses droits et ses dernières connexions.
- Toutes les tentatives de connexion sont journalisées (date, résultat, adresse IP).
- **Se souvenir de moi** : reste connecté 30 jours sur l'appareil. Jeton aléatoire dont seule l'empreinte SHA-256 est stockée,
  renouvelé à chaque utilisation ; révoqué à la déconnexion, au changement ou à la réinitialisation du mot de passe et à la
  désactivation du compte. Un jeton falsifié révoque tous ceux du compte.
- **Mot de passe oublié** : la page de connexion explique la procédure (réinitialisation par l'administrateur).
- Mots de passe : 8 caractères minimum avec lettre et chiffre, `password_hash()` ; mot de passe provisoire à changer ;
  5 échecs bloquent le compte 15 minutes ; session expirée après 2 h d'inactivité ; au moins un administrateur actif.
- **Mot de passe administrateur perdu** : `php outils/reinitialiser_mot_de_passe.php adresse@exemple.fr` sur le serveur.

## Mensurations
- **À la mise bas (mère)** : PMB (poids à la mise bas) + L1 à L6. Saisie directement avec la mise bas (onglet Reproduction)
  ou plus tard dans l'onglet **Mensurations** de la fiche, rattachée à la mise bas concernée.
- **Chevreaux** : stades **W0, W1, W2, W3, W4…** (sans limite), chacun avec la date, le poids et L1 à L6.
  Saisie sur la fiche (onglet Mensurations, stade suivant proposé) ou par lot sur la page **Mensurations**.
- La signification et l'unité de L1 à L6 se règlent dans **Paramètres › Mensurations**.
- Le poids (PMB ou Wn) est aussi enregistré comme pesée : il compte dans le GMQ, le poids au sevrage et les indices.
  Supprimer la mensuration supprime cette pesée.

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

## Connexion et rôles
Toutes les pages exigent une connexion. Identifiant = adresse e-mail.

| Rôle | Droits |
|---|---|
| Administrateur | Tout, y compris Paramètres, formules des indices et gestion des utilisateurs |
| Éleveur | Création et modification des animaux, toutes les saisies (pesées, lait, reproduction, santé, alimentation, mouvements) |
| Vétérinaire | Consultation de tout le troupeau et des indices ; saisie et suppression des actes de **santé** uniquement |

- Les droits sont vérifiés côté serveur (pages et `actions.php`), pas seulement masqués à l'écran.
- Nouveau compte ou mot de passe réinitialisé : un **mot de passe provisoire** est affiché une seule fois à l'administrateur,
  et l'utilisateur doit en choisir un nouveau à sa connexion suivante.
- Mot de passe : 8 caractères minimum, avec au moins une lettre et un chiffre ; stocké avec `password_hash()`.
- 5 essais erronés bloquent le compte 15 minutes ; la session expire après 2 heures d'inactivité.
- Il reste toujours au moins un administrateur actif (impossible de se désactiver soi-même ou de rétrograder le dernier admin).
- **Mot de passe administrateur perdu** : en ligne de commande sur le serveur,
  `php outils/reinitialiser_mot_de_passe.php adresse@exemple.fr` (dossier inaccessible depuis le navigateur).

## Sécurité
Requêtes préparées PDO, échappement HTML, formules évaluées par un analyseur dédié (jamais `eval`), jeton CSRF sur tous les formulaires, contrôle du type des photos.
Cookies de session `HttpOnly` / `SameSite=Lax`, identifiant de session régénéré à la connexion.
