-- =====================================================================
--  Système d'information – Gestion et suivi des troupeaux
--  MySQL 5.7+ / MariaDB 10.3+   –   encodage utf8mb4
-- =====================================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS troupeau CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE troupeau;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS mouvements, sante, reproductions, controles_laitiers, pesees, animaux, lots, races, especes, utilisateurs;
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

-- =====================================================================
--  Données de démonstration
-- =====================================================================
-- Élevage caprin : chèvres, chevreaux et bouc
INSERT INTO especes (id, nom, gestation_jours) VALUES
 (1,'Caprin',150);

INSERT INTO races (id, espece_id, nom) VALUES
 (1,1,'Alpine'),(2,1,'Saanen'),(3,1,'Poitevine');

INSERT INTO lots (id, nom, type, description) VALUES
 (1,'Chèvrerie','batiment','Chèvres en lactation'),
 (2,'Nurserie','batiment','Chevreaux'),
 (3,'Parc des boucs','lot','Reproducteurs'),
 (4,'Parcours sud','paturage','Parcelle de 3 ha');

INSERT INTO animaux (id, numero_identification, nom, espece_id, race_id, sexe, categorie, robe, date_naissance, poids_naissance, mere_id, pere_id, origine, date_entree, prix_achat, lot_id) VALUES
 (1,'CP-0001','Noisette',1,1,'F','chèvre',  'Chamoisée','2021-02-10',3.4,NULL,NULL,'naissance','2021-02-10',NULL,1),
 (2,'CP-0002','Câline',  1,2,'F','chèvre',  'Blanche',  '2020-03-05',3.8,NULL,NULL,'achat',    '2023-10-01',250, 1),
 (3,'CP-0003','Hercule', 1,1,'M','bouc',    'Chamoisée','2019-01-20',4.0,NULL,NULL,'achat',    '2022-09-15',400, 3),
 (4,'CP-0004','Pipo',    1,1,'M','chevreau','Chamoisée','2026-02-20',3.6,1,   3,   'naissance','2026-02-20',NULL,2),
 (5,'CP-0005','Mimi',    1,1,'F','chevreau','Chamoisée','2026-02-20',3.2,1,   3,   'naissance','2026-02-20',NULL,2);

INSERT INTO pesees (animal_id, date_pesee, poids, note_etat_corporel, methode) VALUES
 (4,'2026-02-20', 3.6,NULL,'balance'),(4,'2026-03-20', 9.5,3.0,'balance'),(4,'2026-04-20',15.0,3.0,'balance'),
 (4,'2026-06-20',22.0,3.0,'balance'),(4,'2026-08-20',28.0,3.0,'balance'),(4,'2026-10-20',33.0,3.5,'balance'),
 (5,'2026-02-20', 3.2,NULL,'balance'),(5,'2026-03-20', 8.8,3.0,'balance'),(5,'2026-04-20',14.0,3.0,'balance'),
 (5,'2026-06-20',20.5,3.0,'balance'),(5,'2026-08-20',26.0,3.0,'balance'),(5,'2026-10-20',30.5,3.0,'balance'),
 (1,'2026-01-15',64,3.5,'balance'),(1,'2026-05-15',58,2.5,'balance'),(1,'2026-09-15',63,3.0,'balance'),
 (2,'2026-03-01',72,3.0,'balance'),(2,'2026-09-01',69,2.75,'balance'),
 (3,'2026-08-25',88,3.5,'balance');

INSERT INTO controles_laitiers (animal_id, date_controle, traite_matin, traite_soir, taux_butyreux, taux_proteique, cellules) VALUES
 (1,'2026-03-15',1.6,1.4,34.5,31.0,450),(1,'2026-04-15',1.9,1.6,33.8,30.5,380),
 (1,'2026-05-15',1.8,1.5,34.2,31.2,420),(1,'2026-06-15',1.7,1.4,35.0,31.8,500),
 (1,'2026-07-15',1.5,1.3,35.6,32.4,560),(1,'2026-08-15',1.3,1.1,36.1,33.0,610),
 (1,'2026-09-15',1.1,1.0,37.0,33.6,700),(1,'2026-10-15',1.0,0.8,37.8,34.2,760),
 (2,'2026-04-15',2.0,1.7,32.0,29.8,520),(2,'2026-05-15',2.2,1.9,31.5,29.5,600),
 (2,'2026-06-15',2.1,1.8,31.8,29.9,680),(2,'2026-07-15',1.9,1.6,32.5,30.4,900),
 (2,'2026-08-15',1.7,1.4,33.0,30.8,1250),(2,'2026-09-15',1.5,1.2,33.6,31.2,1600),
 (2,'2026-10-15',1.2,1.0,34.2,31.8,1900);

INSERT INTO reproductions (animal_id, type_evenement, date_evenement, male_id, semence, resultat, nb_petits, date_mise_bas_prevue, intervenant) VALUES
 (1,'saillie',   '2025-09-23',3,NULL,NULL,NULL,'2026-02-20',NULL),
 (1,'diagnostic','2025-11-10',NULL,NULL,'positif',NULL,NULL,'Dr Vétérinaire'),
 (1,'mise_bas',  '2026-02-20',NULL,NULL,NULL,2,NULL,NULL),
 (1,'saillie',   '2026-09-15',3,NULL,NULL,NULL,'2027-02-12',NULL),
 (2,'mise_bas',  '2026-03-10',NULL,NULL,NULL,1,NULL,NULL),
 (2,'saillie',   '2026-06-20',3,NULL,NULL,NULL,'2026-11-17',NULL),
 (2,'diagnostic','2026-08-01',NULL,NULL,'positif',NULL,NULL,'Dr Vétérinaire');

INSERT INTO sante (animal_id, date_intervention, type, diagnostic, produit, dose, voie, veterinaire, delai_attente_lait, delai_attente_viande, cout, date_rappel) VALUES
 (1,'2026-05-05','vaccination','Prévention entérotoxémie','Vaccin entérotoxémie','2 mL','SC','Dr Vétérinaire',0,0,8,'2026-11-05'),
 (2,'2026-10-28','traitement','Mammite clinique demi-mamelle gauche','Antibiotique intramammaire','1 seringue/j x3','Intramammaire','Dr Vétérinaire',7,4,35,NULL),
 (3,'2026-10-20','vermifuge','Déparasitage','Éprinomectine','1 mL/10 kg','Pour-on',NULL,0,14,6,'2027-04-20'),
 (4,'2026-09-01','vermifuge','Déparasitage','Ivermectine','1 mL','SC',NULL,0,28,3,'2027-03-01'),
 (5,'2026-09-01','vermifuge','Déparasitage','Ivermectine','1 mL','SC',NULL,0,28,3,'2027-03-01');

INSERT INTO mouvements (animal_id, date_mouvement, type, lot_origine, lot_destination, motif, montant) VALUES
 (2,'2023-10-01','entree',NULL,1,'Achat élevage voisin',250),
 (3,'2022-09-15','entree',NULL,3,'Achat reproducteur',400),
 (4,'2026-02-21','transfert',1,2,'Mise en nurserie',NULL),
 (5,'2026-02-21','transfert',1,2,'Mise en nurserie',NULL);

-- Comptes de démonstration (à modifier en production !)
--   admin@troupeau.local   / admin123
--   eleveur@troupeau.local / eleveur123
INSERT INTO utilisateurs (nom, email, mot_de_passe, role) VALUES
 ('Administrateur','admin@troupeau.local','$2y$10$AbML13Tl02V1Bdxbd9Tcy.7TUuYOCpX3dvbG70yqDDewB50MwAwbO','admin'),
 ('Éleveur démo','eleveur@troupeau.local','$2y$10$P1KQUDLl9JK3bHVjd/k/NeHF5H3rUb00.icLMBZ2LgI5gX9GjkXgS','eleveur');
