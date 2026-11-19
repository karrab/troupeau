-- =====================================================================
--  Indices paramétrables : définitions, interprétations, résultats
--  (à importer sur une base existante ; déjà inclus dans database.sql)
-- =====================================================================
SET NAMES utf8mb4;

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

-- ---------------------------------------------------------------------
--  Indices fournis par défaut (modifiables dans Paramètres)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO indices (id, code, nom, description, formule, unite, decimales, espece_id, sexe, niveaux, agregation, ordre) VALUES
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

INSERT IGNORE INTO indices_interpretations (indice_id, borne_min, borne_max, libelle, couleur, ordre) VALUES
 (1, NULL, 15, 'Faible', 'danger', 1), (1, 15, 25, 'Moyenne', 'warning', 2), (1, 25, NULL, 'Bonne', 'success', 3),
 (4, NULL, 1, 'Risque d''acidose', 'danger', 1), (4, 1, 1.5, 'Équilibré', 'success', 2), (4, 1.5, NULL, 'Risque de cétose', 'warning', 3),
 (5, NULL, 200, 'Mamelle saine', 'success', 1), (5, 200, 300, 'À surveiller', 'warning', 2), (5, 300, NULL, 'Mammite subclinique probable', 'danger', 3),
 (6, NULL, 600, 'Croissance faible', 'danger', 1), (6, 600, 900, 'Croissance correcte', 'warning', 2), (6, 900, NULL, 'Bonne croissance', 'success', 3),
 (7, NULL, 380, 'Objectif atteint', 'success', 1), (7, 380, 420, 'Acceptable', 'warning', 2), (7, 420, NULL, 'Trop long', 'danger', 3),
 (8, NULL, 40, 'Mauvaise fertilité', 'danger', 1), (8, 40, 60, 'Fertilité moyenne', 'warning', 2), (8, 60, NULL, 'Bonne fertilité', 'success', 3),
 (9, NULL, 1.2, 'Faible', 'warning', 1), (9, 1.2, 1.6, 'Moyenne', 'info', 2), (9, 1.6, NULL, 'Élevée', 'success', 3),
 (10, NULL, 3, 'Normal', 'success', 1), (10, 3, 6, 'À surveiller', 'warning', 2), (10, 6, NULL, 'Élevé', 'danger', 3);
