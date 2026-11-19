-- =====================================================================
--  Système d'information – Gestion et suivi des troupeaux
--  MySQL 5.7+ / MariaDB 10.3+   –   encodage utf8mb4
-- =====================================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS troupeau CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE troupeau;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS indices_resultats, indices_interpretations, indices, mouvements, sante, reproductions, controles_laitiers, pesees, animaux, lots, races, especes, utilisateurs;
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
-- Utilisateurs (pour l'authentification – évolution prévue)
-- ---------------------------------------------------------------------
CREATE TABLE utilisateurs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nom          VARCHAR(100) NOT NULL,
  email        VARCHAR(150) NOT NULL UNIQUE,
  mot_de_passe VARCHAR(255) NOT NULL COMMENT 'password_hash()',
  role         ENUM('admin','eleveur','veterinaire') NOT NULL DEFAULT 'eleveur',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Indices paramétrables (formules, interprétations, résultats enregistrés)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS indices (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30)  NOT NULL UNIQUE,
  nom         VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  formule     TEXT NOT NULL COMMENT 'Expression utilisant les variables de base (voir includes/indices.php)',
  unite       VARCHAR(20) NULL,
  decimales   TINYINT NOT NULL DEFAULT 1,
  espece_id   INT NULL COMMENT 'NULL = toutes les espèces',
  sexe        ENUM('M','F') NULL COMMENT 'NULL = les deux sexes',
  niveaux     SET('animal','lot','espece','troupeau') NOT NULL DEFAULT 'animal,lot,espece,troupeau',
  agregation  ENUM('moyenne','somme','min','max','ratio') NOT NULL DEFAULT 'moyenne' COMMENT 'Calcul pour lot / espèce / troupeau',
  ordre       SMALLINT NOT NULL DEFAULT 0,
  actif       TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_indice_espece FOREIGN KEY (espece_id) REFERENCES especes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS indices_interpretations (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  indice_id INT NOT NULL,
  borne_min DECIMAL(16,4) NULL COMMENT 'Inclus ; NULL = pas de minimum',
  borne_max DECIMAL(16,4) NULL COMMENT 'Exclu ; NULL = pas de maximum',
  libelle   VARCHAR(80) NOT NULL,
  couleur   VARCHAR(20) NOT NULL DEFAULT 'secondary',
  ordre     SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_interp_indice FOREIGN KEY (indice_id) REFERENCES indices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS indices_resultats (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  indice_id      INT NOT NULL,
  annee          SMALLINT NOT NULL,
  niveau         ENUM('animal','lot','espece','troupeau') NOT NULL,
  cible_id       INT NOT NULL DEFAULT 0 COMMENT 'id animal / lot / espèce ; 0 pour le troupeau',
  valeur         DECIMAL(16,4) NOT NULL,
  nb_animaux     INT NOT NULL DEFAULT 1,
  interpretation VARCHAR(80) NULL,
  couleur        VARCHAR(20) NULL,
  formule        TEXT NOT NULL COMMENT 'Formule utilisée lors du calcul',
  calcule_le     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_resultat (indice_id, annee, niveau, cible_id),
  KEY idx_cible (niveau, cible_id, annee),
  CONSTRAINT fk_res_indice FOREIGN KEY (indice_id) REFERENCES indices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
 (1,'BV-0001','Bella', 1,1,'F','vache',   'Pie noire', '2020-03-12',38,NULL,NULL,'naissance','2020-03-12',NULL,1),
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
--  Indices fournis par défaut (modifiables dans Paramètres)
-- ---------------------------------------------------------------------
INSERT INTO indices (id, code, nom, description, formule, unite, decimales, espece_id, sexe, niveaux, agregation, ordre) VALUES
 (1, 'LAIT_MOY',  'Production laitière moyenne', 'Moyenne des contrôles laitiers de l''année', 'lait_moyen', 'L/j', 1, 1, 'F', 'animal,lot,espece,troupeau', 'moyenne', 10),
 (2, 'LAIT_TOT',  'Lait produit', 'Cumul estimé entre le premier et le dernier contrôle', 'lait_cumul', 'L', 0, NULL, 'F', 'animal,lot,espece,troupeau', 'somme', 20),
 (3, 'MAT_UTILE', 'Matière utile', 'Matière grasse + matière protéique produites', 'lait_cumul * (tb_moyen + tp_moyen) / 1000', 'kg', 1, NULL, 'F', 'animal,lot,espece,troupeau', 'somme', 30),
 (4, 'TB_TP',     'Rapport TB / TP', 'Indicateur d''équilibre de la ration', 'tb_moyen / tp_moyen', NULL, 2, NULL, 'F', 'animal,lot,espece,troupeau', 'moyenne', 40),
 (5, 'CELLULES',  'Cellules moyennes', 'Santé de la mamelle (×1000 cellules/mL)', 'cellules_moyen', '×1000/mL', 0, NULL, 'F', 'animal,lot,espece,troupeau', 'moyenne', 50),
 (6, 'GMQ',       'Gain moyen quotidien', 'Croissance des jeunes de moins de 24 mois entre la première et la dernière pesée de l''année', 'si(age_mois < 24, gmq)', 'g/j', 0, NULL, NULL, 'animal,lot,espece,troupeau', 'moyenne', 60),
 (7, 'IVV',       'Intervalle vêlage-vêlage', 'Intervalle moyen entre deux mises bas', 'ivv', 'j', 0, 1, 'F', 'animal,lot,espece,troupeau', 'moyenne', 70),
 (8, 'GESTATION', 'Taux de gestation', 'Diagnostics positifs / diagnostics réalisés', 'nb_diag_pos / (nb_diag_pos + nb_diag_neg) * 100', '%', 0, NULL, 'F', 'animal,lot,espece,troupeau', 'ratio', 80),
 (9, 'PROLIF',    'Prolificité', 'Nombre de petits nés par mise bas', 'nb_petits / nb_mises_bas', NULL, 2, 2, 'F', 'animal,lot,espece,troupeau', 'ratio', 90),
 (10,'MORTALITE', 'Taux de mortalité', 'Animaux morts / animaux présents', 'mort / nb_animaux * 100', '%', 1, NULL, NULL, 'lot,espece,troupeau', 'ratio', 100),
 (11,'COUT_SANTE','Coût sanitaire', 'Dépenses de santé de l''année par animal', 'cout_sante', NULL, 2, NULL, NULL, 'animal,lot,espece,troupeau', 'moyenne', 110);

INSERT INTO indices_interpretations (indice_id, borne_min, borne_max, libelle, couleur, ordre) VALUES
 (1, NULL, 15, 'Faible', 'danger', 1), (1, 15, 25, 'Moyenne', 'warning', 2), (1, 25, NULL, 'Bonne', 'success', 3),
 (4, NULL, 1, 'Risque d''acidose', 'danger', 1), (4, 1, 1.5, 'Équilibré', 'success', 2), (4, 1.5, NULL, 'Risque de cétose', 'warning', 3),
 (5, NULL, 200, 'Mamelle saine', 'success', 1), (5, 200, 300, 'À surveiller', 'warning', 2), (5, 300, NULL, 'Mammite subclinique probable', 'danger', 3),
 (6, NULL, 600, 'Croissance faible', 'danger', 1), (6, 600, 900, 'Croissance correcte', 'warning', 2), (6, 900, NULL, 'Bonne croissance', 'success', 3),
 (7, NULL, 380, 'Objectif atteint', 'success', 1), (7, 380, 420, 'Acceptable', 'warning', 2), (7, 420, NULL, 'Trop long', 'danger', 3),
 (8, NULL, 40, 'Mauvaise fertilité', 'danger', 1), (8, 40, 60, 'Fertilité moyenne', 'warning', 2), (8, 60, NULL, 'Bonne fertilité', 'success', 3),
 (9, NULL, 1.2, 'Faible', 'warning', 1), (9, 1.2, 1.6, 'Moyenne', 'info', 2), (9, 1.6, NULL, 'Élevée', 'success', 3),
 (10, NULL, 3, 'Normal', 'success', 1), (10, 3, 6, 'À surveiller', 'warning', 2), (10, 6, NULL, 'Élevé', 'danger', 3);
