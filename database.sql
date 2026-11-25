-- =====================================================================
--  Système d'information – Gestion et suivi des troupeaux
--  MySQL 5.7+ / MariaDB 10.3+   –   encodage utf8mb4
-- =====================================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS troupeau CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE troupeau;

SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS v_indices_resultats;
DROP TABLE IF EXISTS jetons_connexion, journal_connexions, profil_permissions, profils, mensurations, indices_resultats, indices_interpretations, indices, alimentation, parametres_app, mouvements, sante, reproductions, controles_laitiers, pesees, animaux, lots, races, especes, utilisateurs;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Référentiels
-- ---------------------------------------------------------------------
CREATE TABLE especes (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nom             VARCHAR(50) NOT NULL UNIQUE,
  gestation_jours SMALLINT NOT NULL DEFAULT 283 COMMENT 'Durée moyenne de gestation, sert au calcul de la mise bas prévue'
) ENGINE=InnoDB;

CREATE TABLE races (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  espece_id INT NOT NULL,
  nom       VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_race (espece_id, nom),
  CONSTRAINT fk_race_espece FOREIGN KEY (espece_id) REFERENCES especes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE lots (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nom         VARCHAR(60) NOT NULL UNIQUE,
  type        ENUM('batiment','paturage','lot') NOT NULL DEFAULT 'lot',
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Fiche animal (table centrale)
-- ---------------------------------------------------------------------
CREATE TABLE animaux (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  numero_identification VARCHAR(30) NOT NULL UNIQUE COMMENT 'Boucle / puce / tatouage',
  nom                   VARCHAR(60) NULL,
  espece_id             INT NOT NULL,
  race_id               INT NULL,
  sexe                  ENUM('M','F') NOT NULL,
  categorie             VARCHAR(30) NULL COMMENT 'veau, génisse, vache, taureau, agneau, brebis…',
  robe                  VARCHAR(60) NULL,
  date_naissance        DATE NULL,
  poids_naissance       DECIMAL(6,2) NULL,
  date_sevrage          DATE NULL,
  poids_sevrage         DECIMAL(6,2) NULL COMMENT 'kg ; si vide, pesée la plus proche de la date de sevrage',
  mere_id               INT NULL,
  pere_id               INT NULL,
  origine               ENUM('naissance','achat') NOT NULL DEFAULT 'naissance',
  date_entree           DATE NULL,
  prix_achat            DECIMAL(10,2) NULL,
  lot_id                INT NULL,
  statut                ENUM('actif','vendu','mort','reforme') NOT NULL DEFAULT 'actif',
  date_sortie           DATE NULL,
  motif_sortie          VARCHAR(255) NULL,
  photo                 VARCHAR(255) NULL,
  observations          TEXT NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_statut (statut),
  CONSTRAINT fk_animal_espece FOREIGN KEY (espece_id) REFERENCES especes(id),
  CONSTRAINT fk_animal_race   FOREIGN KEY (race_id)   REFERENCES races(id)   ON DELETE SET NULL,
  CONSTRAINT fk_animal_lot    FOREIGN KEY (lot_id)    REFERENCES lots(id)    ON DELETE SET NULL,
  CONSTRAINT fk_animal_mere   FOREIGN KEY (mere_id)   REFERENCES animaux(id) ON DELETE SET NULL,
  CONSTRAINT fk_animal_pere   FOREIGN KEY (pere_id)   REFERENCES animaux(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Contrôle de poids (croissance, GMQ, état corporel)
-- ---------------------------------------------------------------------
CREATE TABLE pesees (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  animal_id          INT NOT NULL,
  date_pesee         DATE NOT NULL,
  poids              DECIMAL(7,2) NOT NULL COMMENT 'kg',
  note_etat_corporel DECIMAL(2,1) NULL COMMENT 'NEC de 1 à 5',
  methode            ENUM('balance','ruban','estimation') NOT NULL DEFAULT 'balance',
  observations       VARCHAR(255) NULL,
  KEY idx_pesee (animal_id, date_pesee),
  CONSTRAINT fk_pesee_animal FOREIGN KEY (animal_id) REFERENCES animaux(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Contrôle laitier (quantité + qualité)
-- ---------------------------------------------------------------------
CREATE TABLE controles_laitiers (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  animal_id       INT NOT NULL,
  date_controle   DATE NOT NULL,
  traite_matin    DECIMAL(6,2) NULL COMMENT 'litres',
  traite_soir     DECIMAL(6,2) NULL COMMENT 'litres',
  quantite_totale DECIMAL(6,2) AS (IFNULL(traite_matin,0) + IFNULL(traite_soir,0)) STORED,
  taux_butyreux   DECIMAL(4,1) NULL COMMENT 'TB en g/L',
  taux_proteique  DECIMAL(4,1) NULL COMMENT 'TP en g/L',
  cellules        INT NULL COMMENT 'Comptage cellulaire (milliers/mL)',
  observations    VARCHAR(255) NULL,
  UNIQUE KEY uq_controle (animal_id, date_controle),
  CONSTRAINT fk_lait_animal FOREIGN KEY (animal_id) REFERENCES animaux(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Reproduction (chaleurs, IA, diagnostics, mises bas, tarissement)
-- ---------------------------------------------------------------------
CREATE TABLE reproductions (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  animal_id            INT NOT NULL COMMENT 'Femelle concernée',
  type_evenement       ENUM('chaleur','saillie','insemination','diagnostic','mise_bas','avortement','tarissement') NOT NULL,
  date_evenement       DATE NOT NULL,
  male_id              INT NULL COMMENT 'Mâle du troupeau (saillie)',
  semence              VARCHAR(100) NULL COMMENT 'Taureau / référence paillette (IA)',
  resultat             ENUM('positif','negatif') NULL COMMENT 'Pour les diagnostics',
  nb_petits            TINYINT NULL,
  nb_morts_nes         TINYINT NULL COMMENT 'Petits mort-nés (survie des chevreaux)',
  date_mise_bas_prevue DATE NULL,
  intervenant          VARCHAR(100) NULL,
  observations         VARCHAR(255) NULL,
  KEY idx_repro (animal_id, date_evenement),
  CONSTRAINT fk_repro_animal FOREIGN KEY (animal_id) REFERENCES animaux(id) ON DELETE CASCADE,
  CONSTRAINT fk_repro_male   FOREIGN KEY (male_id)   REFERENCES animaux(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Santé (vaccins, traitements, délais d'attente)
-- ---------------------------------------------------------------------
CREATE TABLE sante (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  animal_id            INT NOT NULL,
  date_intervention    DATE NOT NULL,
  type                 ENUM('vaccination','traitement','vermifuge','visite','chirurgie','autre') NOT NULL,
  diagnostic           VARCHAR(150) NULL,
  produit              VARCHAR(150) NULL,
  dose                 VARCHAR(50) NULL,
  voie                 VARCHAR(50) NULL,
  veterinaire          VARCHAR(100) NULL,
  delai_attente_lait   SMALLINT NOT NULL DEFAULT 0 COMMENT 'jours',
  delai_attente_viande SMALLINT NOT NULL DEFAULT 0 COMMENT 'jours',
  cout                 DECIMAL(10,2) NULL,
  date_rappel          DATE NULL,
  observations         VARCHAR(255) NULL,
  KEY idx_sante (animal_id, date_intervention),
  KEY idx_rappel (date_rappel),
  CONSTRAINT fk_sante_animal FOREIGN KEY (animal_id) REFERENCES animaux(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Mouvements (entrées, sorties, transferts de lot)
-- ---------------------------------------------------------------------
CREATE TABLE mouvements (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  animal_id       INT NOT NULL,
  date_mouvement  DATE NOT NULL,
  type            ENUM('entree','sortie','transfert') NOT NULL,
  lot_origine     INT NULL,
  lot_destination INT NULL,
  motif           VARCHAR(150) NULL,
  montant         DECIMAL(10,2) NULL COMMENT 'Prix achat / vente',
  observations    VARCHAR(255) NULL,
  CONSTRAINT fk_mvt_animal FOREIGN KEY (animal_id) REFERENCES animaux(id) ON DELETE CASCADE,
  CONSTRAINT fk_mvt_lot_o  FOREIGN KEY (lot_origine)     REFERENCES lots(id) ON DELETE SET NULL,
  CONSTRAINT fk_mvt_lot_d  FOREIGN KEY (lot_destination) REFERENCES lots(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Profils (ensembles de permissions modifiables) et utilisateurs.
-- Aucun compte n'est fourni : le premier compte administrateur est créé
-- sur la page de connexion au premier accès.
-- ---------------------------------------------------------------------
CREATE TABLE profils (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30) NOT NULL UNIQUE,
  nom         VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  couleur     VARCHAR(20) NOT NULL DEFAULT 'secondary',
  systeme     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Administrateur : tous les droits, non modifiable',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE profil_permissions (
  profil_id  INT NOT NULL,
  permission VARCHAR(50) NOT NULL COMMENT 'Code défini dans includes/auth.php (PERMISSIONS)',
  PRIMARY KEY (profil_id, permission),
  CONSTRAINT fk_pp_profil FOREIGN KEY (profil_id) REFERENCES profils(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE utilisateurs (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  nom                VARCHAR(100) NOT NULL,
  email              VARCHAR(150) NOT NULL UNIQUE COMMENT 'Identifiant de connexion',
  mot_de_passe       VARCHAR(255) NOT NULL COMMENT 'password_hash()',
  profil_id          INT NULL,
  telephone          VARCHAR(30) NULL,
  fonction           VARCHAR(100) NULL COMMENT 'Poste dans l''exploitation',
  photo              VARCHAR(255) NULL,
  notes              VARCHAR(255) NULL COMMENT 'Note interne (administrateur)',
  actif              TINYINT(1) NOT NULL DEFAULT 1,
  doit_changer_mdp   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mot de passe provisoire à changer',
  echecs             TINYINT NOT NULL DEFAULT 0 COMMENT 'Essais de connexion échoués consécutifs',
  bloque_jusqua      DATETIME NULL,
  derniere_connexion DATETIME NULL,
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_utilisateur_profil FOREIGN KEY (profil_id) REFERENCES profils(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- « Se souvenir de moi » (30 jours)
CREATE TABLE jetons_connexion (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id  INT NOT NULL,
  selecteur       CHAR(24) NOT NULL UNIQUE,
  validateur_hash CHAR(64) NOT NULL COMMENT 'SHA-256 du validateur (jamais stocké en clair)',
  expire_le       DATETIME NOT NULL,
  ip              VARCHAR(45) NULL,
  navigateur      VARCHAR(255) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jeton_utilisateur (utilisateur_id),
  CONSTRAINT fk_jeton_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE journal_connexions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT NULL,
  email          VARCHAR(150) NOT NULL,
  date_connexion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  succes         TINYINT(1) NOT NULL,
  motif          VARCHAR(100) NULL,
  ip             VARCHAR(45) NULL,
  navigateur     VARCHAR(255) NULL,
  KEY idx_journal (utilisateur_id, date_connexion),
  CONSTRAINT fk_journal_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- Paramètres généraux de l'application
-- ---------------------------------------------------------------------
CREATE TABLE parametres_app (
  cle     VARCHAR(50) PRIMARY KEY,
  valeur  VARCHAR(255) NOT NULL,
  libelle VARCHAR(150) NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Alimentation (aliment distribué, pour l'indice de consommation)
-- ---------------------------------------------------------------------
CREATE TABLE alimentation (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  animal_id         INT NOT NULL,
  date_distribution DATE NOT NULL,
  aliment           VARCHAR(100) NOT NULL,
  quantite_kg       DECIMAL(8,2) NOT NULL,
  cout              DECIMAL(10,2) NULL,
  observations      VARCHAR(255) NULL,
  KEY idx_alim (animal_id, date_distribution),
  CONSTRAINT fk_alim_animal FOREIGN KEY (animal_id) REFERENCES animaux(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Mensurations : PMB + L1…L6 à la mise bas, W0…Wn + L1…L6 des chevreaux
-- ---------------------------------------------------------------------
CREATE TABLE mensurations (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  animal_id       INT NOT NULL,
  type            ENUM('mise_bas','croissance') NOT NULL COMMENT 'mise_bas : mère (PMB) ; croissance : chevreau (W0, W1…)',
  stade           SMALLINT NULL COMMENT 'Croissance : 0 = W0, 1 = W1…',
  reproduction_id INT NULL COMMENT 'Mise bas concernée',
  date_mesure     DATE NOT NULL,
  poids           DECIMAL(7,2) NULL COMMENT 'kg : PMB ou poids au stade Wn',
  l1              DECIMAL(6,1) NULL,
  l2              DECIMAL(6,1) NULL,
  l3              DECIMAL(6,1) NULL,
  l4              DECIMAL(6,1) NULL,
  l5              DECIMAL(6,1) NULL,
  l6              DECIMAL(6,1) NULL,
  pesee_id        INT NULL COMMENT 'Pesée créée à partir du poids',
  observations    VARCHAR(255) NULL,
  UNIQUE KEY uq_stade (animal_id, type, stade),
  KEY idx_mens (animal_id, date_mesure),
  CONSTRAINT fk_mens_animal FOREIGN KEY (animal_id) REFERENCES animaux(id) ON DELETE CASCADE,
  CONSTRAINT fk_mens_repro  FOREIGN KEY (reproduction_id) REFERENCES reproductions(id) ON DELETE SET NULL,
  CONSTRAINT fk_mens_pesee  FOREIGN KEY (pesee_id) REFERENCES pesees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Indices de productivité : définition (formule modifiable), seuils
-- d'interprétation (généraux ou par espèce) et résultats enregistrés
-- ---------------------------------------------------------------------
CREATE TABLE indices (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(10)  NOT NULL UNIQUE COMMENT 'R01, L02… utilisable dans les autres formules',
  nom            VARCHAR(100) NOT NULL,
  famille        ENUM('reproduction','lait','croissance','sante','globale') NOT NULL,
  description    VARCHAR(255) NULL COMMENT 'Mode de calcul en clair',
  formule        TEXT NOT NULL,
  unite          VARCHAR(30) NULL,
  decimales      TINYINT NOT NULL DEFAULT 1,
  periode        ENUM('campagne','lactation','jour') NOT NULL DEFAULT 'campagne' COMMENT 'Granularité du calcul',
  niveaux        SET('animal','lot','espece','troupeau') NOT NULL DEFAULT 'animal,lot,espece,troupeau',
  agregation     ENUM('ratio','moyenne','somme','min','max') NOT NULL DEFAULT 'ratio',
  sens           ENUM('haut','bas','cible','neutre') NOT NULL DEFAULT 'haut' COMMENT 'Sens d''une valeur favorable',
  interpretation VARCHAR(255) NULL,
  espece_id      INT NULL COMMENT 'NULL = toutes les espèces',
  sexe           ENUM('M','F') NULL,
  ordre          SMALLINT NOT NULL DEFAULT 0,
  actif          TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_indice_espece FOREIGN KEY (espece_id) REFERENCES especes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE indices_interpretations (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  indice_id INT NOT NULL,
  espece_id INT NULL COMMENT 'NULL = seuil général ; sinon prioritaire pour cette espèce',
  borne_min DECIMAL(16,4) NULL COMMENT 'Incluse',
  borne_max DECIMAL(16,4) NULL COMMENT 'Exclue',
  libelle   VARCHAR(80) NOT NULL,
  couleur   VARCHAR(20) NOT NULL DEFAULT 'secondary',
  ordre     SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_interp_indice FOREIGN KEY (indice_id) REFERENCES indices(id) ON DELETE CASCADE,
  CONSTRAINT fk_interp_espece FOREIGN KEY (espece_id) REFERENCES especes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE indices_resultats (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  indice_id      INT NOT NULL,
  campagne       SMALLINT NOT NULL COMMENT 'Année de début de campagne',
  periode        ENUM('campagne','lactation','jour') NOT NULL,
  periode_cle    VARCHAR(20) NOT NULL COMMENT 'Campagne, L+date de mise bas, ou date du contrôle',
  date_debut     DATE NULL,
  date_fin       DATE NULL,
  niveau         ENUM('animal','lot','espece','troupeau') NOT NULL,
  cible_id       INT NOT NULL DEFAULT 0 COMMENT 'id animal / lot / espèce ; 0 = troupeau',
  valeur         DECIMAL(16,4) NOT NULL,
  nb_animaux     INT NOT NULL DEFAULT 1,
  interpretation VARCHAR(80) NULL,
  couleur        VARCHAR(20) NULL,
  formule        TEXT NOT NULL COMMENT 'Formule utilisée au moment du calcul',
  calcule_le     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_resultat (indice_id, niveau, cible_id, periode_cle),
  KEY idx_campagne (campagne, niveau),
  KEY idx_cible (niveau, cible_id),
  CONSTRAINT fk_res_indice FOREIGN KEY (indice_id) REFERENCES indices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Vue de restitution (tableur, outil de BI, requêtes)
CREATE OR REPLACE VIEW v_indices_resultats AS
SELECT r.campagne, r.periode, r.periode_cle, r.date_debut, r.date_fin, r.niveau, r.cible_id,
       CASE r.niveau WHEN 'animal' THEN CONCAT(a.numero_identification, ' ', IFNULL(a.nom,''))
                     WHEN 'lot' THEN l.nom WHEN 'espece' THEN e.nom ELSE 'Troupeau' END AS cible,
       i.code, i.nom AS indice, i.famille, i.unite, r.valeur, r.interpretation, r.nb_animaux, r.formule, r.calcule_le
FROM indices_resultats r
JOIN indices i ON i.id = r.indice_id
LEFT JOIN animaux a ON r.niveau = 'animal' AND a.id = r.cible_id
LEFT JOIN lots    l ON r.niveau = 'lot'    AND l.id = r.cible_id
LEFT JOIN especes e ON r.niveau = 'espece' AND e.id = r.cible_id;

-- =====================================================================
--  Données de démonstration
-- =====================================================================
INSERT INTO especes (id, nom, gestation_jours) VALUES
 (1,'Bovin',283),(2,'Ovin',150),(3,'Caprin',150);

INSERT INTO races (id, espece_id, nom) VALUES
 (1,1,'Holstein'),(2,1,'Montbéliarde'),(3,1,'Brune des Alpes'),
 (4,2,'Lacaune'),(5,2,'Sicilo-Sarde'),
 (6,3,'Alpine'),(7,3,'Saanen');

INSERT INTO lots (id, nom, type, description) VALUES
 (1,'Étable A','batiment','Vaches en lactation'),
 (2,'Étable B','batiment','Génisses et veaux'),
 (3,'Bergerie','batiment','Ovins'),
 (4,'Pâturage nord','paturage','Parcelle de 6 ha');

INSERT INTO animaux (id, numero_identification, nom, espece_id, race_id, sexe, categorie, robe, date_naissance, poids_naissance, mere_id, pere_id, origine, date_entree, prix_achat, lot_id) VALUES
 (1,'BV-0001','Bella', 1,1,'F','vache',   'Pie noire', '2020-03-12',38,NULL,NULL,'achat',    '2022-06-01',1800,1),
 (2,'BV-0002','Rose',  1,2,'F','vache',   'Pie rouge', '2019-05-02',42,NULL,NULL,'achat',    '2023-09-15',4200,1),
 (3,'BV-0003','Titan', 1,1,'M','taureau', 'Pie noire', '2018-02-10',45,NULL,NULL,'achat',    '2021-01-20',6500,4),
 (4,'BV-0004','Perle', 1,1,'F','veau',    'Pie noire', '2026-02-15',40,1,3,      'naissance','2026-02-15',NULL,2),
 (5,'OV-0001','Blanche',2,4,'F','brebis', 'Blanche',   '2022-01-20',4.5,NULL,NULL,'naissance','2022-01-20',NULL,3),
 (6,'OV-0002','Hercule',2,4,'M','bélier', 'Blanche',   '2021-12-05',5,NULL,NULL,'achat',    '2023-03-01',900,3);

INSERT INTO pesees (animal_id, date_pesee, poids, note_etat_corporel, methode) VALUES
 (4,'2026-02-15', 40,NULL,'balance'),(4,'2026-04-15', 85,3.0,'balance'),(4,'2026-06-15',135,3.0,'balance'),
 (4,'2026-08-15',180,3.0,'balance'),(4,'2026-10-15',222,3.5,'balance'),
 (1,'2026-01-10',620,3.5,'ruban'),(1,'2026-05-10',590,2.5,'ruban'),(1,'2026-09-10',640,3.0,'ruban'),
 (2,'2026-03-01',610,3.0,'ruban'),(2,'2026-09-01',600,2.75,'ruban'),
 (5,'2026-04-01',62,3.0,'balance');

INSERT INTO controles_laitiers (animal_id, date_controle, traite_matin, traite_soir, taux_butyreux, taux_proteique, cellules) VALUES
 (1,'2026-03-01',18.0,16.0,38.5,31.0,120),(1,'2026-04-01',20.0,18.0,37.8,30.5,95),
 (1,'2026-05-01',19.0,17.0,38.2,31.2,110),(1,'2026-06-01',17.5,16.0,39.0,31.8,140),
 (1,'2026-07-01',16.0,15.0,39.6,32.4,160),(1,'2026-08-01',15.0,13.5,40.1,33.0,150),
 (1,'2026-09-01',13.0,12.0,41.0,33.6,180),(1,'2026-10-01',12.0,10.5,41.8,34.2,210),
 (2,'2026-06-01',14.0,13.0,40.0,33.0,180),(2,'2026-07-01',16.0,14.0,39.5,32.8,200),
 (2,'2026-08-01',15.0,14.0,39.8,33.1,260),(2,'2026-09-01',14.0,12.5,40.5,33.5,350),
 (2,'2026-10-01',13.0,12.0,41.0,33.9,520);

INSERT INTO reproductions (animal_id, type_evenement, date_evenement, male_id, semence, resultat, nb_petits, date_mise_bas_prevue, intervenant) VALUES
 (1,'insemination','2025-05-08',NULL,'HO-Taureau 4512',NULL,NULL,'2026-02-15','Inséminateur coop.'),
 (1,'diagnostic',  '2025-06-20',NULL,NULL,'positif',NULL,NULL,'Dr Vétérinaire'),
 (1,'mise_bas',    '2026-02-15',NULL,NULL,NULL,1,NULL,NULL),
 (1,'insemination','2026-05-05',NULL,'HO-Taureau 4512',NULL,NULL,'2027-02-12','Inséminateur coop.'),
 (1,'diagnostic',  '2026-06-20',NULL,NULL,'positif',NULL,NULL,'Dr Vétérinaire'),
 (2,'mise_bas',    '2026-05-10',NULL,NULL,NULL,1,NULL,NULL),
 (5,'saillie',     '2026-07-10',6,NULL,NULL,NULL,'2026-12-07',NULL);

INSERT INTO sante (animal_id, date_intervention, type, diagnostic, produit, dose, voie, veterinaire, delai_attente_lait, delai_attente_viande, cout, date_rappel) VALUES
 (1,'2026-05-05','vaccination','Prévention fièvre aphteuse','Vaccin FA','2 mL','SC','Dr Vétérinaire',0,0,25,'2026-11-05'),
 (2,'2026-10-25','traitement','Mammite clinique quartier AG','Antibiotique intramammaire','1 seringue/j x3','Intramammaire','Dr Vétérinaire',7,4,60,NULL),
 (4,'2026-09-01','vermifuge','Déparasitage','Ivermectine','4 mL','SC',NULL,0,28,12,'2027-03-01');

INSERT INTO mouvements (animal_id, date_mouvement, type, lot_origine, lot_destination, motif, montant) VALUES
 (2,'2023-09-15','entree',NULL,1,'Achat élevage voisin',4200),
 (3,'2021-01-20','entree',NULL,4,'Achat reproducteur',6500),
 (4,'2026-05-15','transfert',1,2,'Sevrage',NULL);

-- ---------------------------------------------------------------------
-- Troupeau caprin de démonstration (indices R, L, C, S, P)
-- ---------------------------------------------------------------------
INSERT INTO lots (id, nom, type, description) VALUES
 (5,'Chèvrerie','batiment','Chèvres laitières'),
 (6,'Parcours sud','paturage','Parcours boisé de 12 ha'),
 (7,'Nurserie','batiment','Chevreaux et chevrettes');

INSERT INTO animaux (id, numero_identification, nom, espece_id, race_id, sexe, categorie, robe, date_naissance, poids_naissance, date_sevrage, poids_sevrage, mere_id, pere_id, origine, date_entree, prix_achat, lot_id, statut, date_sortie, motif_sortie) VALUES
 (11,'CP-0005','Zorro',   3,6,'M','bouc',    'Chamoisée','2021-01-10',3.8,NULL,NULL,NULL,NULL,'achat','2022-08-01',350,5,'actif',NULL,NULL),
 (7, 'CP-0001','Noisette',3,6,'F','chèvre',  'Chamoisée','2022-03-01',3.4,NULL,NULL,NULL,NULL,'achat','2024-06-01',240,5,'actif',NULL,NULL),
 (8, 'CP-0002','Câline',  3,7,'F','chèvre',  'Blanche',  '2022-02-15',3.6,NULL,NULL,NULL,NULL,'achat','2024-06-01',260,5,'actif',NULL,NULL),
 (9, 'CP-0003','Praline', 3,6,'F','chèvre',  'Chamoisée','2025-02-01',3.2,'2025-04-25',13.8,7,11,'naissance','2025-02-01',NULL,5,'actif',NULL,NULL),
 (10,'CP-0004','Vanille', 3,6,'F','chèvre',  'Pie',      '2023-03-01',3.3,NULL,NULL,NULL,NULL,'achat','2024-06-01',220,5,'actif',NULL,NULL),
 (17,'CP-0006','Biscotte',3,7,'F','chèvre',  'Blanche',  '2017-03-01',NULL,NULL,NULL,NULL,NULL,'achat','2019-05-10',180,5,'reforme','2026-07-01','Âge, mamelle déséquilibrée'),
 (12,'CP-0101','Grisette',3,6,'F','chevrette','Chamoisée','2026-02-06',3.5,'2026-05-01',NULL,7,11,'naissance','2026-02-06',NULL,7,'actif',NULL,NULL),
 (13,'CP-0102',NULL,      3,6,'M','chevreau', 'Chamoisée','2026-02-06',2.4,NULL,NULL,7,11,'naissance','2026-02-06',NULL,7,'mort','2026-02-20','Faiblesse néonatale'),
 (14,'CP-0103','Myrtille',3,7,'F','chevrette','Blanche',  '2026-03-03',3.2,'2026-05-10',15.2,8,11,'naissance','2026-03-03',NULL,7,'actif',NULL,NULL),
 (15,'CP-0104','Filou',   3,7,'M','chevreau', 'Blanche',  '2026-03-03',3.6,'2026-05-10',16.8,8,11,'naissance','2026-03-03',NULL,7,'vendu','2026-06-15','Vente chevreau'),
 (16,'CP-0105','Cannelle',3,6,'F','chevrette','Chamoisée','2026-02-25',3.0,'2026-05-20',13.0,9,11,'naissance','2026-02-25',NULL,7,'actif',NULL,NULL),
 (18,'CP-0090',NULL,3,6,'M','chevreau','Chamoisée','2025-02-01',3.5,'2025-04-25',14.6,7,11,'naissance','2025-02-01',NULL,7,'vendu','2025-05-20','Vente chevreau'),
 (19,'CP-0091',NULL,3,7,'F','chevrette','Blanche','2025-03-10',3.3,'2025-05-15',14.9,8,11,'naissance','2025-03-10',NULL,7,'vendu','2025-06-10','Vente chevrette'),
 (20,'CP-0092',NULL,3,7,'M','chevreau','Blanche','2025-03-10',3.6,'2025-05-15',15.8,8,11,'naissance','2025-03-10',NULL,7,'vendu','2025-06-10','Vente chevreau'),
 (21,'CP-0093',NULL,3,6,'M','chevreau','Pie','2025-02-20',3.1,NULL,NULL,10,11,'naissance','2025-02-20',NULL,7,'mort','2025-03-05','Diarrhée néonatale'),
 (22,'CP-0094',NULL,3,7,'F','chevrette','Blanche','2025-03-01',3.0,'2025-05-10',12.4,17,11,'naissance','2025-03-01',NULL,7,'vendu','2025-06-10','Vente chevrette');

INSERT INTO reproductions (animal_id, type_evenement, date_evenement, male_id, semence, resultat, nb_petits, date_mise_bas_prevue, intervenant) VALUES
 (8,'saillie','2024-09-20',11,NULL,NULL,NULL,'2025-02-17',NULL),
 (10,'saillie','2024-09-22',11,NULL,NULL,NULL,'2025-02-19',NULL),
 (17,'saillie','2024-10-01',11,NULL,NULL,NULL,'2025-02-28',NULL),
 (7,'saillie','2024-09-05',11,NULL,NULL,NULL,'2025-02-02',NULL),
 (7,'mise_bas','2025-02-01',NULL,NULL,NULL,2,NULL,NULL),
 (7,'tarissement','2025-11-15',NULL,NULL,NULL,NULL,NULL,NULL),
 (7,'saillie','2025-09-10',11,NULL,NULL,NULL,'2026-02-07',NULL),
 (7,'diagnostic','2025-10-25',NULL,NULL,'positif',NULL,NULL,'Échographie'),
 (7,'mise_bas','2026-02-06',NULL,NULL,NULL,2,NULL,NULL),
 (7,'tarissement','2026-11-01',NULL,NULL,NULL,NULL,NULL,NULL),
 (8,'mise_bas','2025-03-10',NULL,NULL,NULL,2,NULL,NULL),
 (8,'insemination','2025-09-12',NULL,'SA-Bouc 22',NULL,NULL,'2026-02-09','Inséminateur coop.'),
 (8,'chaleur','2025-10-03',NULL,NULL,NULL,NULL,NULL,NULL),
 (8,'saillie','2025-10-04',11,NULL,NULL,NULL,'2026-03-03',NULL),
 (8,'diagnostic','2025-11-20',NULL,NULL,'positif',NULL,NULL,'Échographie'),
 (8,'mise_bas','2026-03-03',NULL,NULL,NULL,3,NULL,NULL),
 (9,'saillie','2025-09-25',11,NULL,NULL,NULL,'2026-02-22',NULL),
 (9,'diagnostic','2025-11-05',NULL,NULL,'positif',NULL,NULL,'Échographie'),
 (9,'mise_bas','2026-02-25',NULL,NULL,NULL,1,NULL,NULL),
 (9,'tarissement','2026-10-15',NULL,NULL,NULL,NULL,NULL,NULL),
 (10,'mise_bas','2025-02-20',NULL,NULL,NULL,1,NULL,NULL),
 (10,'saillie','2025-09-15',11,NULL,NULL,NULL,'2026-02-12',NULL),
 (10,'diagnostic','2025-11-01',NULL,NULL,'negatif',NULL,NULL,'Échographie'),
 (10,'saillie','2025-11-10',11,NULL,NULL,NULL,'2026-04-09',NULL),
 (10,'diagnostic','2025-12-20',NULL,NULL,'positif',NULL,NULL,'Échographie'),
 (10,'avortement','2026-03-10',NULL,NULL,NULL,NULL,NULL,NULL),
 (17,'mise_bas','2025-03-01',NULL,NULL,NULL,1,NULL,NULL),
 (17,'saillie','2025-09-20',11,NULL,NULL,NULL,'2026-02-17',NULL),
 (17,'diagnostic','2025-11-01',NULL,NULL,'negatif',NULL,NULL,'Échographie');

INSERT INTO pesees (animal_id, date_pesee, poids, note_etat_corporel, methode) VALUES
 (9,'2025-02-01',3.2,NULL,'balance'),(9,'2025-03-01',7.5,NULL,'balance'),(9,'2025-04-25',13.8,NULL,'balance'),
 (12,'2026-02-06',3.5,NULL,'balance'),(12,'2026-03-06',8.0,NULL,'balance'),(12,'2026-04-06',11.8,NULL,'balance'),(12,'2026-05-01',14.5,NULL,'balance'),
 (14,'2026-03-03',3.2,NULL,'balance'),(14,'2026-04-03',8.4,NULL,'balance'),(14,'2026-05-10',15.2,NULL,'balance'),
 (15,'2026-03-03',3.6,NULL,'balance'),(15,'2026-04-03',9.1,NULL,'balance'),(15,'2026-05-10',16.8,NULL,'balance'),
 (16,'2026-02-25',3.0,NULL,'balance'),(16,'2026-04-01',8.2,NULL,'balance'),(16,'2026-05-20',13.0,NULL,'balance'),
 (7,'2026-01-10',62,3.5,'balance'),(7,'2026-06-10',58,2.75,'balance'),(7,'2026-10-10',61,3.0,'balance'),
 (8,'2026-01-10',68,3.5,'balance'),(8,'2026-09-10',66,3.0,'balance');

INSERT INTO controles_laitiers (animal_id, date_controle, traite_matin, traite_soir, taux_butyreux, taux_proteique, cellules) VALUES
 (7,'2025-03-01',3.0,2.5,34.5,30.5,520),(7,'2025-05-01',3.2,2.7,33.8,30.9,610),(7,'2025-07-01',2.6,2.1,35.9,32.0,700),(7,'2025-09-01',1.9,1.6,38.4,33.6,850),
 (7,'2026-03-01',3.2,2.6,34.0,30.2,450),(7,'2026-04-01',3.6,3.0,33.5,30.0,480),(7,'2026-05-01',3.3,2.8,34.2,30.8,520),(7,'2026-06-01',3.0,2.5,35.0,31.5,600),
 (7,'2026-07-01',2.6,2.2,36.1,32.2,640),(7,'2026-08-01',2.2,1.9,37.5,33.0,700),(7,'2026-09-01',1.8,1.5,38.8,34.0,760),(7,'2026-10-01',1.4,1.1,40.2,35.1,800),
 (8,'2026-04-01',3.9,3.3,32.5,29.5,380),(8,'2026-05-01',4.2,3.6,32.0,29.2,350),(8,'2026-06-01',4.0,3.4,32.8,29.8,400),(8,'2026-07-01',3.6,3.1,33.6,30.4,420),
 (8,'2026-08-01',3.2,2.7,34.5,31.0,450),(8,'2026-09-01',2.8,2.4,35.4,31.8,480),(8,'2026-10-01',2.4,2.0,36.6,32.6,520),(8,'2026-11-01',2.0,1.7,37.8,33.4,560),
 (9,'2026-04-01',2.0,1.6,35.0,31.0,300),(9,'2026-05-01',2.2,1.8,34.6,30.8,320),(9,'2026-06-01',2.1,1.7,35.2,31.2,340),
 (9,'2026-07-01',1.9,1.5,36.0,31.9,380),(9,'2026-08-01',1.6,1.3,37.2,32.8,420),(9,'2026-09-01',1.3,1.0,38.5,33.7,470);

INSERT INTO alimentation (animal_id, date_distribution, aliment, quantite_kg, cout) VALUES
 (7,'2026-03-31','Concentré chèvre laitière',24,9.6),(7,'2026-04-30','Concentré chèvre laitière',27,10.8),(7,'2026-05-31','Concentré chèvre laitière',25,10.0),(7,'2026-06-30','Concentré chèvre laitière',24,9.6),
 (8,'2026-04-30','Concentré chèvre laitière',28,11.2),(8,'2026-05-31','Concentré chèvre laitière',30,12.0),(8,'2026-06-30','Concentré chèvre laitière',29,11.6),(8,'2026-07-31','Concentré chèvre laitière',27,10.8),
 (12,'2026-03-06','Lait en poudre',9,22.0),(12,'2026-04-06','Granulé démarrage',8,4.8),(12,'2026-05-01','Granulé démarrage',10,6.0),
 (14,'2026-04-03','Lait en poudre',10,24.0),(14,'2026-05-10','Granulé démarrage',11,6.6);

INSERT INTO sante (animal_id, date_intervention, type, diagnostic, produit, dose, voie, veterinaire, delai_attente_lait, delai_attente_viande, cout, date_rappel) VALUES
 (12,'2026-04-15','vermifuge','Coccidiose (prévention)','Toltrazuril','2 mL','Orale',NULL,0,63,3,NULL),
 (10,'2026-03-11','traitement','Métrite après avortement','Oxytétracycline','5 mL','IM','Dr Vétérinaire',7,28,35,NULL);

INSERT INTO mouvements (animal_id, date_mouvement, type, lot_origine, lot_destination, motif, montant) VALUES
 (11,'2022-08-01','entree',NULL,5,'Achat bouc',350),(7,'2024-06-01','entree',NULL,5,'Achat',240),(8,'2024-06-01','entree',NULL,5,'Achat',260),(10,'2024-06-01','entree',NULL,5,'Achat',220),(17,'2019-05-10','entree',NULL,5,'Achat',180),
 (7,'2026-05-15','transfert',5,6,'Mise au parcours',NULL),(8,'2026-05-15','transfert',5,6,'Mise au parcours',NULL),(9,'2026-05-15','transfert',5,6,'Mise au parcours',NULL),
 (10,'2026-05-15','transfert',5,6,'Mise au parcours',NULL),(11,'2026-05-15','transfert',5,6,'Mise au parcours',NULL),
 (7,'2026-10-15','transfert',6,5,'Rentrée en bâtiment',NULL),(8,'2026-10-15','transfert',6,5,'Rentrée en bâtiment',NULL),(9,'2026-10-15','transfert',6,5,'Rentrée en bâtiment',NULL),
 (10,'2026-10-15','transfert',6,5,'Rentrée en bâtiment',NULL),(11,'2026-10-15','transfert',6,5,'Rentrée en bâtiment',NULL),
 (13,'2026-02-20','sortie',7,NULL,'Mort – faiblesse néonatale',NULL),(18,'2025-05-20','sortie',7,NULL,'Vendu',95),(19,'2025-06-10','sortie',7,NULL,'Vendu',90),(20,'2025-06-10','sortie',7,NULL,'Vendu',95),(21,'2025-03-05','sortie',7,NULL,'Mort – diarrhée néonatale',NULL),(22,'2025-06-10','sortie',7,NULL,'Vendu',85),(15,'2026-06-15','sortie',7,NULL,'Vendu – vente chevreau',120),(17,'2026-07-01','sortie',5,NULL,'Réformé – âge',90);

-- =====================================================================
--  Indices de productivité (spécification – modifiables dans Paramètres)
-- =====================================================================
INSERT INTO parametres_app (cle, valeur, libelle) VALUES ('debut_campagne_mois','9','Mois de début de la campagne (1 = année civile)');

INSERT INTO indices (id, code, nom, famille, description, formule, unite, decimales, periode, niveaux, agregation, sens, interpretation, espece_id, sexe, ordre) VALUES
 (1,'R01','Taux de fertilité','reproduction','(Femelles gestantes ÷ femelles saillies) × 100 – saillies dont le résultat est connu','gestante / saillie_diag * 100','%',1,'campagne','lot,espece,troupeau','ratio','haut','Élevé = bonne aptitude à concevoir',NULL,'F',10),
 (2,'R02','Taux de fécondité','reproduction','(Femelles ayant mis bas ÷ femelles saillies) × 100 – saillies arrivées à terme','mb_suite_saillie / saillie_bilan * 100','%',1,'campagne','lot,espece,troupeau','ratio','haut','Intègre conception + maintien de la gestation',NULL,'F',20),
 (3,'R03','Taux de mise-bas','reproduction','(Nb de mises bas ÷ femelles mises à la reproduction) × 100','nb_mb_issues / saillie_bilan * 100','%',1,'campagne','lot,espece,troupeau','ratio','haut','Réussite globale de la lutte',NULL,'F',30),
 (4,'R04','Prolificité','reproduction','Petits nés ÷ nb de mises bas','nb_petits / nb_mises_bas','petits/portée',2,'campagne','animal,lot,espece,troupeau','ratio','haut','Élevé = portées multiples',NULL,'F',40),
 (5,'R05','Indice de saillie','reproduction','Nb de saillies ÷ nb de gestations obtenues (par femelle ou par mâle)','si(femelle, nb_saillies_bilan / nb_gestations, saillies_male / gestations_male)','ratio',2,'campagne','animal,lot,espece,troupeau','ratio','bas','Proche de 1 = très efficace',NULL,NULL,50),
 (6,'R06','Taux de retour en chaleur','reproduction','(Femelles revenues en chaleur ÷ femelles saillies) × 100','retour_chaleur / saillie * 100','%',1,'campagne','lot,espece,troupeau','ratio','bas','Bas = peu d''échecs de fécondation',NULL,'F',60),
 (7,'R07','Taux d''avortement','reproduction','(Avortements ÷ gestations) × 100','nb_avortements_gest / nb_gestations * 100','%',1,'campagne','lot,espece,troupeau','ratio','bas','Bas = bon état sanitaire / nutrition',NULL,'F',70),
 (8,'R08','Taux de gémellité','reproduction','(Mises bas multiples ÷ total des mises bas) × 100','nb_mb_multiples / nb_mises_bas * 100','%',1,'campagne','lot,espece,troupeau','ratio','haut','Élevé = potentiel de prolificité',NULL,'F',80),
 (9,'R09','Âge à la 1re mise-bas','reproduction','Date de la 1re mise bas − date de naissance','age_1ere_mb_mois','mois',1,'campagne','animal,lot,espece,troupeau','moyenne','bas','Bas = précocité',NULL,'F',90),
 (10,'R10','Intervalle entre mises bas (IMB)','reproduction','Jours entre deux mises bas successives','imb','jours',0,'campagne','animal,lot,espece,troupeau','moyenne','cible','Court (≈ 240–360 j) = bon rythme',NULL,'F',100),
 (11,'L01','Production journalière','lait','Lait matin + lait soir','lait_jour','kg/jour',2,'jour','animal,lot,espece,troupeau','moyenne','haut','Suivi quotidien',NULL,'F',110),
 (12,'L02','Production totale par lactation','lait','Σ des pesées de lait sur le cycle (méthode Fleischmann)','lait_lactation','kg',0,'lactation','animal,lot,espece,troupeau','moyenne','haut','Élevé = bonne productrice',NULL,'F',120),
 (13,'L03','Durée de lactation','lait','Date de tarissement − date de mise bas','duree_lactation','jours',0,'lactation','animal,lot,espece,troupeau','moyenne','haut','Plus longue = plus de lait total',NULL,'F',130),
 (14,'L04','Production moyenne par jour de lactation','lait','Production totale ÷ durée de lactation (jours en lait si non tarie)','L02 / sinon(L03, jours_en_lait)','kg/jour',2,'lactation','animal,lot,espece,troupeau','moyenne','haut','Indicateur de niveau laitier',NULL,'F',140),
 (15,'L05','Persistance de lactation','lait','(Production de fin de cycle ÷ production au pic) × 100','lait_fin / lait_pic * 100','%',0,'lactation','animal,lot,espece,troupeau','moyenne','haut','Élevé = lactation régulière',NULL,'F',150),
 (16,'C01','Gain moyen quotidien (GMQ)','croissance','(Poids final − poids initial) ÷ nb de jours – animaux de moins de 24 mois','si(age_mois < 24, gmq)','g/jour',0,'campagne','animal,lot,espece,troupeau','moyenne','haut','Élevé = bonne croissance',NULL,NULL,160),
 (17,'C02','Poids au sevrage ajusté (90 j)','croissance','Poids ramené à un âge type de 90 jours','poids_naissance + (poids_sevrage - poids_naissance) / age_sevrage_jours * 90','kg',1,'campagne','animal,lot,espece,troupeau','moyenne','haut','Comparaison équitable entre individus',NULL,NULL,170),
 (18,'C03','Indice de consommation (IC)','croissance','Aliment consommé ÷ gain de poids','si(aliment_kg > 0 et gain_poids > 0, aliment_kg / gain_poids)','kg/kg',2,'campagne','animal,lot,espece,troupeau','moyenne','bas','Bas = efficacité alimentaire (animaux rationnés)',NULL,NULL,180),
 (19,'C04','Indice de valorisation des parcours','croissance','Production laitière ÷ jours sur parcours','si(jours_parcours > 0, lait_total / jours_parcours)','kg/jour',2,'campagne','animal,lot,espece,troupeau','moyenne','neutre','Adapté au système extensif',NULL,NULL,190),
 (20,'S01','Taux de mortalité des chevreaux','sante','(Chevreaux morts ÷ chevreaux nés) × 100','petits_morts / petits_nes * 100','%',1,'campagne','animal,lot,espece,troupeau','ratio','bas','Bas = bonne conduite',NULL,'F',200),
 (21,'S02','Taux de sevrage (viabilité)','sante','(Chevreaux sevrés ÷ chevreaux nés) × 100','petits_sevres / petits_nes * 100','%',1,'campagne','animal,lot,espece,troupeau','ratio','haut','Élevé = bonne survie',NULL,'F',210),
 (22,'S03','Taux de réforme','sante','(Animaux réformés ÷ effectif) × 100','reforme / nb_animaux * 100','%',1,'campagne','lot,espece,troupeau','ratio','cible','Renouvellement du troupeau',NULL,NULL,220),
 (23,'P01','Productivité numérique','globale','Nb de chevreaux sevrés par femelle et par an','petits_sevres / reproductrice','chevreaux/femelle/an',2,'campagne','animal,lot,espece,troupeau','ratio','haut','Indice synthétique majeur',NULL,'F',230),
 (24,'P02','Productivité pondérale','globale','kg de chevreaux sevrés par femelle et par an','poids_petits_sevres / reproductrice','kg/femelle/an',1,'campagne','animal,lot,espece,troupeau','ratio','haut','Intègre prolificité + survie + croissance',NULL,'F',240),
 (25,'P03','Score global de productivité','globale','Note pondérée 0–100 (femelles ayant mis bas) : lait 35 %, prolificité 25 %, survie 20 %, croissance des petits 20 %','si(nb_mises_bas > 0, moyenne_ponderee(note(lait_moyen, 1, 4), 35, note(R04, 1, 2), 25, note(S02, 70, 100), 20, note(gmq_petits, 80, 200), 20))','score 0–100',0,'campagne','animal,lot,espece,troupeau','moyenne','haut','Classement / sélection / réforme',(SELECT id FROM especes WHERE nom LIKE 'caprin%' OR nom LIKE 'chevre%' OR nom LIKE 'chèvre%' ORDER BY id LIMIT 1),'F',250);

-- Seuils généraux
INSERT INTO indices_interpretations (indice_id, espece_id, borne_min, borne_max, libelle, couleur, ordre) VALUES
 (1,NULL,NULL,70,'Faible','danger',1),
 (1,NULL,70,85,'Moyen','warning',2),
 (1,NULL,85,NULL,'Bon','success',3),
 (2,NULL,NULL,60,'Faible','danger',1),
 (2,NULL,60,80,'Moyen','warning',2),
 (2,NULL,80,NULL,'Bon','success',3),
 (3,NULL,NULL,70,'Faible','danger',1),
 (3,NULL,70,90,'Moyen','warning',2),
 (3,NULL,90,NULL,'Bon','success',3),
 (5,NULL,NULL,1.5,'Très efficace','success',1),
 (5,NULL,1.5,2,'Moyen','warning',2),
 (5,NULL,2,NULL,'Peu efficace','danger',3),
 (6,NULL,NULL,15,'Bas','success',1),
 (6,NULL,15,30,'À surveiller','warning',2),
 (6,NULL,30,NULL,'Élevé','danger',3),
 (7,NULL,NULL,3,'Bas','success',1),
 (7,NULL,3,5,'À surveiller','warning',2),
 (7,NULL,5,NULL,'Élevé','danger',3),
 (8,NULL,NULL,30,'Faible','warning',1),
 (8,NULL,30,60,'Moyen','info',2),
 (8,NULL,60,NULL,'Élevé','success',3),
 (10,NULL,NULL,240,'Très court','warning',1),
 (10,NULL,240,360,'Bon rythme','success',2),
 (10,NULL,360,420,'Allongé','warning',3),
 (10,NULL,420,NULL,'Trop long','danger',4),
 (15,NULL,NULL,40,'Chute rapide','danger',1),
 (15,NULL,40,60,'Moyenne','warning',2),
 (15,NULL,60,NULL,'Régulière','success',3),
 (16,NULL,NULL,100,'Faible','danger',1),
 (16,NULL,100,150,'Moyen','warning',2),
 (16,NULL,150,NULL,'Bon','success',3),
 (18,NULL,NULL,4,'Efficace','success',1),
 (18,NULL,4,6,'Moyen','warning',2),
 (18,NULL,6,NULL,'Peu efficace','danger',3),
 (20,NULL,NULL,5,'Bonne conduite','success',1),
 (20,NULL,5,10,'À surveiller','warning',2),
 (20,NULL,10,NULL,'Élevé','danger',3),
 (21,NULL,NULL,85,'Faible','danger',1),
 (21,NULL,85,95,'Moyen','warning',2),
 (21,NULL,95,NULL,'Bon','success',3),
 (22,NULL,NULL,15,'Renouvellement faible','warning',1),
 (22,NULL,15,30,'Normal','success',2),
 (22,NULL,30,NULL,'Élevé','danger',3),
 (23,NULL,NULL,1.2,'Faible','danger',1),
 (23,NULL,1.2,1.6,'Moyen','warning',2),
 (23,NULL,1.6,NULL,'Bon','success',3),
 (25,NULL,NULL,40,'À réformer','danger',1),
 (25,NULL,40,60,'Moyenne','warning',2),
 (25,NULL,60,80,'Bonne','info',3),
 (25,NULL,80,NULL,'Élite – sélection','success',4);

-- Seuils propres aux caprins (ignorés si l'espèce n'existe pas dans la base)
INSERT INTO indices_interpretations (indice_id, espece_id, borne_min, borne_max, libelle, couleur, ordre)
SELECT v.i, e.id, v.mn, v.mx, v.lib, v.col, v.o
FROM (SELECT id FROM especes WHERE nom LIKE 'caprin%' OR nom LIKE 'chevre%' OR nom LIKE 'chèvre%' ORDER BY id LIMIT 1) e
CROSS JOIN (
  SELECT 4 i, NULL mn, 1.2 mx, 'Faible' lib, 'danger' col, 1 o
  UNION ALL SELECT 4 i, 1.2 mn, 1.6 mx, 'Moyenne' lib, 'warning' col, 2 o
  UNION ALL SELECT 4 i, 1.6 mn, NULL mx, 'Élevée' lib, 'success' col, 3 o
  UNION ALL SELECT 9 i, NULL mn, 15 mx, 'Précoce' lib, 'success' col, 1 o
  UNION ALL SELECT 9 i, 15 mn, 20 mx, 'Moyen' lib, 'warning' col, 2 o
  UNION ALL SELECT 9 i, 20 mn, NULL mx, 'Tardif' lib, 'danger' col, 3 o
  UNION ALL SELECT 11 i, NULL mn, 2 mx, 'Faible' lib, 'danger' col, 1 o
  UNION ALL SELECT 11 i, 2 mn, 3.5 mx, 'Moyen' lib, 'warning' col, 2 o
  UNION ALL SELECT 11 i, 3.5 mn, NULL mx, 'Bon' lib, 'success' col, 3 o
  UNION ALL SELECT 14 i, NULL mn, 2 mx, 'Faible' lib, 'danger' col, 1 o
  UNION ALL SELECT 14 i, 2 mn, 3.5 mx, 'Moyen' lib, 'warning' col, 2 o
  UNION ALL SELECT 14 i, 3.5 mn, NULL mx, 'Bon' lib, 'success' col, 3 o
  UNION ALL SELECT 12 i, NULL mn, 500 mx, 'Faible' lib, 'danger' col, 1 o
  UNION ALL SELECT 12 i, 500 mn, 800 mx, 'Moyen' lib, 'warning' col, 2 o
  UNION ALL SELECT 12 i, 800 mn, NULL mx, 'Bon' lib, 'success' col, 3 o
  UNION ALL SELECT 13 i, NULL mn, 210 mx, 'Courte' lib, 'danger' col, 1 o
  UNION ALL SELECT 13 i, 210 mn, 270 mx, 'Moyenne' lib, 'warning' col, 2 o
  UNION ALL SELECT 13 i, 270 mn, NULL mx, 'Longue' lib, 'success' col, 3 o
  UNION ALL SELECT 17 i, NULL mn, 12 mx, 'Faible' lib, 'danger' col, 1 o
  UNION ALL SELECT 17 i, 12 mn, 15 mx, 'Moyen' lib, 'warning' col, 2 o
  UNION ALL SELECT 17 i, 15 mn, NULL mx, 'Bon' lib, 'success' col, 3 o
  UNION ALL SELECT 24 i, NULL mn, 15 mx, 'Faible' lib, 'danger' col, 1 o
  UNION ALL SELECT 24 i, 15 mn, 25 mx, 'Moyen' lib, 'warning' col, 2 o
  UNION ALL SELECT 24 i, 25 mn, NULL mx, 'Bon' lib, 'success' col, 3 o
) v;

-- Seuils propres aux ovins (ignorés si l'espèce n'existe pas dans la base)
INSERT INTO indices_interpretations (indice_id, espece_id, borne_min, borne_max, libelle, couleur, ordre)
SELECT v.i, e.id, v.mn, v.mx, v.lib, v.col, v.o
FROM (SELECT id FROM especes WHERE nom LIKE 'ovin%' OR nom LIKE 'mouton%' OR nom LIKE 'brebis%' ORDER BY id LIMIT 1) e
CROSS JOIN (
  SELECT 4 i, NULL mn, 1.2 mx, 'Faible' lib, 'danger' col, 4 o
  UNION ALL SELECT 4 i, 1.2 mn, 1.6 mx, 'Moyenne' lib, 'warning' col, 5 o
  UNION ALL SELECT 4 i, 1.6 mn, NULL mx, 'Élevée' lib, 'success' col, 6 o
  UNION ALL SELECT 9 i, NULL mn, 15 mx, 'Précoce' lib, 'success' col, 4 o
  UNION ALL SELECT 9 i, 15 mn, 20 mx, 'Moyen' lib, 'warning' col, 5 o
  UNION ALL SELECT 9 i, 20 mn, NULL mx, 'Tardif' lib, 'danger' col, 6 o
) v;

-- Seuils propres aux bovins (ignorés si l'espèce n'existe pas dans la base)
INSERT INTO indices_interpretations (indice_id, espece_id, borne_min, borne_max, libelle, couleur, ordre)
SELECT v.i, e.id, v.mn, v.mx, v.lib, v.col, v.o
FROM (SELECT id FROM especes WHERE nom LIKE 'bovin%' OR nom LIKE 'vache%' ORDER BY id LIMIT 1) e
CROSS JOIN (
  SELECT 9 i, NULL mn, 26 mx, 'Précoce' lib, 'success' col, 7 o
  UNION ALL SELECT 9 i, 26 mn, 32 mx, 'Moyen' lib, 'warning' col, 8 o
  UNION ALL SELECT 9 i, 32 mn, NULL mx, 'Tardif' lib, 'danger' col, 9 o
  UNION ALL SELECT 10 i, NULL mn, 380 mx, 'Bon rythme' lib, 'success' col, 5 o
  UNION ALL SELECT 10 i, 380 mn, 420 mx, 'Allongé' lib, 'warning' col, 6 o
  UNION ALL SELECT 10 i, 420 mn, NULL mx, 'Trop long' lib, 'danger' col, 7 o
  UNION ALL SELECT 11 i, NULL mn, 15 mx, 'Faible' lib, 'danger' col, 4 o
  UNION ALL SELECT 11 i, 15 mn, 25 mx, 'Moyen' lib, 'warning' col, 5 o
  UNION ALL SELECT 11 i, 25 mn, NULL mx, 'Bon' lib, 'success' col, 6 o
  UNION ALL SELECT 14 i, NULL mn, 15 mx, 'Faible' lib, 'danger' col, 4 o
  UNION ALL SELECT 14 i, 15 mn, 25 mx, 'Moyen' lib, 'warning' col, 5 o
  UNION ALL SELECT 14 i, 25 mn, NULL mx, 'Bon' lib, 'success' col, 6 o
  UNION ALL SELECT 12 i, NULL mn, 5000 mx, 'Faible' lib, 'danger' col, 4 o
  UNION ALL SELECT 12 i, 5000 mn, 8000 mx, 'Moyen' lib, 'warning' col, 5 o
  UNION ALL SELECT 12 i, 8000 mn, NULL mx, 'Bon' lib, 'success' col, 6 o
  UNION ALL SELECT 13 i, NULL mn, 250 mx, 'Courte' lib, 'danger' col, 4 o
  UNION ALL SELECT 13 i, 250 mn, 305 mx, 'Moyenne' lib, 'warning' col, 5 o
  UNION ALL SELECT 13 i, 305 mn, NULL mx, 'Longue' lib, 'success' col, 6 o
  UNION ALL SELECT 16 i, NULL mn, 600 mx, 'Faible' lib, 'danger' col, 4 o
  UNION ALL SELECT 16 i, 600 mn, 900 mx, 'Moyen' lib, 'warning' col, 5 o
  UNION ALL SELECT 16 i, 900 mn, NULL mx, 'Bon' lib, 'success' col, 6 o
  UNION ALL SELECT 17 i, NULL mn, 90 mx, 'Faible' lib, 'danger' col, 4 o
  UNION ALL SELECT 17 i, 90 mn, 110 mx, 'Moyen' lib, 'warning' col, 5 o
  UNION ALL SELECT 17 i, 110 mn, NULL mx, 'Bon' lib, 'success' col, 6 o
  UNION ALL SELECT 23 i, NULL mn, 0.8 mx, 'Faible' lib, 'danger' col, 4 o
  UNION ALL SELECT 23 i, 0.8 mn, 0.9 mx, 'Moyen' lib, 'warning' col, 5 o
  UNION ALL SELECT 23 i, 0.9 mn, NULL mx, 'Bon' lib, 'success' col, 6 o
) v;

-- Câline : un chevreau mort-né sur la portée de trois
UPDATE reproductions SET nb_morts_nes=1 WHERE animal_id=8 AND date_evenement='2026-03-03';

-- Mensurations de démonstration (libellés L1…L6 à définir dans Paramètres)
INSERT INTO mensurations (animal_id, type, stade, reproduction_id, date_mesure, poids, l1, l2, l3, l4, l5, l6) VALUES
 (7, 'mise_bas',NULL,(SELECT id FROM reproductions WHERE animal_id=7 AND type_evenement='mise_bas' AND date_evenement='2026-02-06'),'2026-02-06',59.0,78.0,74.5,92.0,21.5,17.0,10.5),
 (8, 'mise_bas',NULL,(SELECT id FROM reproductions WHERE animal_id=8 AND type_evenement='mise_bas' AND date_evenement='2026-03-03'),'2026-03-03',64.0,81.0,77.0,96.5,22.0,18.0,11.0),
 (9, 'mise_bas',NULL,(SELECT id FROM reproductions WHERE animal_id=9 AND type_evenement='mise_bas' AND date_evenement='2026-02-25'),'2026-02-25',41.5,69.0,66.0,80.5,18.5,15.0,9.0),
 (12,'croissance',0,NULL,'2026-02-06', 3.5,32.0,30.5,35.0,9.0,7.5,5.0),
 (12,'croissance',1,NULL,'2026-02-13', 4.6,34.5,32.0,38.0,9.6,8.0,5.4),
 (12,'croissance',2,NULL,'2026-02-20', 5.8,36.5,33.5,40.5,10.2,8.4,5.8),
 (12,'croissance',3,NULL,'2026-02-27', 6.9,38.0,35.0,43.0,10.8,8.9,6.1),
 (12,'croissance',4,NULL,'2026-03-06', 8.0,40.0,36.5,45.5,11.3,9.3,6.5),
 (14,'croissance',0,NULL,'2026-03-03', 3.2,31.0,29.5,34.0,8.8,7.2,4.9),
 (14,'croissance',1,NULL,'2026-03-10', 4.4,33.5,31.5,37.0,9.4,7.8,5.3),
 (14,'croissance',2,NULL,'2026-03-17', 5.7,35.5,33.0,40.0,10.0,8.3,5.7),
 (15,'croissance',0,NULL,'2026-03-03', 3.6,32.5,30.5,35.5,9.1,7.6,5.1),
 (15,'croissance',1,NULL,'2026-03-10', 4.9,35.0,32.5,38.5,9.8,8.1,5.5),
 (15,'croissance',2,NULL,'2026-03-17', 6.3,37.0,34.0,41.5,10.4,8.6,5.9),
 (16,'croissance',0,NULL,'2026-02-25', 3.0,30.5,29.0,33.5,8.6,7.0,4.8),
 (16,'croissance',1,NULL,'2026-03-04', 4.0,32.5,30.5,36.0,9.1,7.5,5.1);
-- Le poids des mensurations est aussi une pesée (GMQ, poids au sevrage, indices)
INSERT INTO pesees (animal_id, date_pesee, poids, methode, observations)
SELECT m.animal_id, m.date_mesure, m.poids, 'balance', IF(m.type='mise_bas', 'Poids à la mise bas (PMB)', CONCAT('Mensuration W', m.stade))
FROM mensurations m WHERE m.poids IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM pesees p WHERE p.animal_id=m.animal_id AND p.date_pesee=m.date_mesure);
UPDATE mensurations m JOIN pesees p ON p.animal_id=m.animal_id AND p.date_pesee=m.date_mesure SET m.pesee_id=p.id WHERE m.poids IS NOT NULL;

INSERT INTO parametres_app (cle, valeur, libelle) VALUES
 ('mesure_l1','','Libellé de la mesure L1'),('mesure_l2','','Libellé de la mesure L2'),('mesure_l3','','Libellé de la mesure L3'),
 ('mesure_l4','','Libellé de la mesure L4'),('mesure_l5','','Libellé de la mesure L5'),('mesure_l6','','Libellé de la mesure L6'),
 ('mesure_unite','cm','Unité des mesures L1 à L6');

-- Profils fournis (permissions modifiables dans Utilisateurs › Profils)
INSERT INTO profils (code, nom, description, couleur, systeme) VALUES
 ('admin','Administrateur','Tous les droits, y compris profils et utilisateurs (non modifiable)','danger',1),
 ('eleveur','Éleveur','Gestion complète du troupeau et des saisies','success',0),
 ('technicien','Technicien / salarié','Saisies quotidiennes, sans suppression ni modification des fiches','primary',0),
 ('veterinaire','Vétérinaire','Consultation de tout le troupeau, saisie des actes de santé','info',0),
 ('consultation','Consultation','Lecture seule (conseiller, contrôle laitier, associé)','secondary',0);
INSERT INTO profil_permissions (profil_id, permission)
SELECT p.id, x.permission FROM profils p JOIN (
  SELECT 'tableau_bord.voir' permission
  UNION ALL SELECT 'animaux.voir' permission
  UNION ALL SELECT 'pesees.voir' permission
  UNION ALL SELECT 'mensurations.voir' permission
  UNION ALL SELECT 'lait.voir' permission
  UNION ALL SELECT 'repro.voir' permission
  UNION ALL SELECT 'sante.voir' permission
  UNION ALL SELECT 'alimentation.voir' permission
  UNION ALL SELECT 'mouvements.voir' permission
  UNION ALL SELECT 'indices.voir' permission
  UNION ALL SELECT 'animaux.creer' permission
  UNION ALL SELECT 'animaux.modifier' permission
  UNION ALL SELECT 'pesees.saisir' permission
  UNION ALL SELECT 'pesees.supprimer' permission
  UNION ALL SELECT 'mensurations.saisir' permission
  UNION ALL SELECT 'mensurations.supprimer' permission
  UNION ALL SELECT 'lait.saisir' permission
  UNION ALL SELECT 'lait.supprimer' permission
  UNION ALL SELECT 'repro.saisir' permission
  UNION ALL SELECT 'repro.supprimer' permission
  UNION ALL SELECT 'sante.saisir' permission
  UNION ALL SELECT 'sante.supprimer' permission
  UNION ALL SELECT 'alimentation.saisir' permission
  UNION ALL SELECT 'alimentation.supprimer' permission
  UNION ALL SELECT 'mouvements.saisir' permission
  UNION ALL SELECT 'mouvements.supprimer' permission
  UNION ALL SELECT 'indices.calculer' permission
  UNION ALL SELECT 'indices.exporter' permission
) x WHERE p.code='eleveur';
INSERT INTO profil_permissions (profil_id, permission)
SELECT p.id, x.permission FROM profils p JOIN (
  SELECT 'tableau_bord.voir' permission
  UNION ALL SELECT 'animaux.voir' permission
  UNION ALL SELECT 'pesees.voir' permission
  UNION ALL SELECT 'mensurations.voir' permission
  UNION ALL SELECT 'lait.voir' permission
  UNION ALL SELECT 'repro.voir' permission
  UNION ALL SELECT 'sante.voir' permission
  UNION ALL SELECT 'alimentation.voir' permission
  UNION ALL SELECT 'mouvements.voir' permission
  UNION ALL SELECT 'indices.voir' permission
  UNION ALL SELECT 'animaux.creer' permission
  UNION ALL SELECT 'pesees.saisir' permission
  UNION ALL SELECT 'mensurations.saisir' permission
  UNION ALL SELECT 'lait.saisir' permission
  UNION ALL SELECT 'repro.saisir' permission
  UNION ALL SELECT 'sante.saisir' permission
  UNION ALL SELECT 'alimentation.saisir' permission
  UNION ALL SELECT 'mouvements.saisir' permission
  UNION ALL SELECT 'indices.calculer' permission
) x WHERE p.code='technicien';
INSERT INTO profil_permissions (profil_id, permission)
SELECT p.id, x.permission FROM profils p JOIN (
  SELECT 'tableau_bord.voir' permission
  UNION ALL SELECT 'animaux.voir' permission
  UNION ALL SELECT 'pesees.voir' permission
  UNION ALL SELECT 'mensurations.voir' permission
  UNION ALL SELECT 'lait.voir' permission
  UNION ALL SELECT 'repro.voir' permission
  UNION ALL SELECT 'sante.voir' permission
  UNION ALL SELECT 'alimentation.voir' permission
  UNION ALL SELECT 'mouvements.voir' permission
  UNION ALL SELECT 'indices.voir' permission
  UNION ALL SELECT 'sante.saisir' permission
  UNION ALL SELECT 'sante.supprimer' permission
  UNION ALL SELECT 'indices.exporter' permission
) x WHERE p.code='veterinaire';
INSERT INTO profil_permissions (profil_id, permission)
SELECT p.id, x.permission FROM profils p JOIN (
  SELECT 'tableau_bord.voir' permission
  UNION ALL SELECT 'animaux.voir' permission
  UNION ALL SELECT 'pesees.voir' permission
  UNION ALL SELECT 'mensurations.voir' permission
  UNION ALL SELECT 'lait.voir' permission
  UNION ALL SELECT 'repro.voir' permission
  UNION ALL SELECT 'sante.voir' permission
  UNION ALL SELECT 'alimentation.voir' permission
  UNION ALL SELECT 'mouvements.voir' permission
  UNION ALL SELECT 'indices.voir' permission
  UNION ALL SELECT 'indices.exporter' permission
) x WHERE p.code='consultation';
