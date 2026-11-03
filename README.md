# GesTroupeau – Gestion et suivi des troupeaux

Application PHP 8 / MySQL / Bootstrap 5 pour bovins, ovins et caprins.

## Installation (XAMPP, WAMP, Laragon…)
1. Copier le dossier `troupeau/` dans `htdocs/` (ou `www/`).
2. Importer `database.sql` dans phpMyAdmin (crée la base `troupeau` + données de démonstration).
3. Adapter `config/db.php` (hôte, utilisateur, mot de passe).
4. Vérifier que le dossier `uploads/` est accessible en écriture (photos).
5. Ouvrir `http://localhost/troupeau/` : vous êtes redirigé vers la page de connexion.

### Comptes de démonstration
| E-mail | Mot de passe | Rôle |
|---|---|---|
| `admin@troupeau.local` | `admin123` | Administrateur |
| `eleveur@troupeau.local` | `eleveur123` | Éleveur |

> Changez ces mots de passe en production. Pour créer un compte :
> `php -r 'echo password_hash("MonMotDePasse", PASSWORD_DEFAULT);'` puis
> `INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES ('Nom', 'email@exemple.com', '<hash>', 'eleveur');`

## Modules
| Page | Rôle |
|---|---|
| `login.php` / `logout.php` | Connexion personnalisée (Bootstrap 5) et déconnexion |
| `index.php` | Tableau de bord : effectifs, lait moyen, TB/TP, alertes (cellules, délais d'attente, mises bas, rappels) |
| `animaux.php` | Liste filtrable (espèce, lot, sexe, statut) |
| `animal.php` | **Fiche animal** : identité, généalogie, descendance, poids + GMQ, contrôle laitier, reproduction, santé, mouvements, impression |
| `animal_form.php` | Création / modification / photo / sortie (vente, mort, réforme) |
| `lait.php` | Synthèse mensuelle et classement des laitières |
| `pesees.php` | Séance de pesée groupée par lot |
| `parametres.php` | Espèces (durée de gestation), races, lots |
| `actions.php` | Enregistrement / suppression de l'historique |

## Calculs automatiques
- **GMQ** (g/jour) entre deux pesées et depuis la première pesée.
- **Mise bas prévue** = date de saillie/IA + durée de gestation de l'espèce.
- **État reproductif** (vide, à confirmer, gestante) et intervalle moyen entre mises bas.
- **Lactation** : jours en lait, moyenne, pic, cumul estimé (méthode des trapèzes).
- **Délais d'attente** lait/viande en cours, affichés sur la fiche et le tableau de bord.
- **Alerte cellules** au-delà de 300 000/mL (`SEUIL_CELLULES` dans `includes/functions.php`).
- Mouvements d'entrée, de transfert et de sortie tracés automatiquement.

## Sécurité
Requêtes préparées PDO, échappement HTML, jeton CSRF sur tous les formulaires, contrôle du type des photos.
**Authentification** : toutes les pages exigent une connexion (`require_login()` appelé automatiquement dans `includes/functions.php`, sauf si la page définit `PAGE_PUBLIQUE`).
Mots de passe `password_hash()` / `password_verify()`, régénération de l'identifiant de session, cookie `HttpOnly` + `SameSite=Lax`,
blocage de 5 min après 5 échecs, expiration après 2 h d'inactivité, redirection vers la page demandée après connexion.
`require_role('admin')` permet de réserver une page à certains rôles.
