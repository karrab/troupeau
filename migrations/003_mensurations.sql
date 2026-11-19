-- =====================================================================
--  Migration : mensurations (PMB + L1…L6 à la mise bas, W0…Wn des chevreaux)
--  À importer sur une base existante (base « troupeau » sélectionnée),
--  après 001_indices.sql. Peut être importée plusieurs fois sans erreur.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS parametres_app (
  cle     VARCHAR(50) PRIMARY KEY,
  valeur  VARCHAR(255) NOT NULL,
  libelle VARCHAR(150) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mensurations (
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

INSERT IGNORE INTO parametres_app (cle, valeur, libelle) VALUES
 ('mesure_l1','','Libellé de la mesure L1'),('mesure_l2','','Libellé de la mesure L2'),('mesure_l3','','Libellé de la mesure L3'),
 ('mesure_l4','','Libellé de la mesure L4'),('mesure_l5','','Libellé de la mesure L5'),('mesure_l6','','Libellé de la mesure L6'),
 ('mesure_unite','cm','Unité des mesures L1 à L6');
