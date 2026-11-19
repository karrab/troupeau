-- =====================================================================
--  Migration : connexion des utilisateurs
--  À importer sur une base existante (base « troupeau » sélectionnée).
--  Peut être importée plusieurs fois sans erreur.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS utilisateurs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nom          VARCHAR(100) NOT NULL,
  email        VARCHAR(150) NOT NULL UNIQUE,
  mot_de_passe VARCHAR(255) NOT NULL,
  role         ENUM('admin','eleveur','veterinaire') NOT NULL DEFAULT 'eleveur',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN actif TINYINT(1) NOT NULL DEFAULT 1 AFTER role', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='actif');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN doit_changer_mdp TINYINT(1) NOT NULL DEFAULT 0 AFTER actif', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='doit_changer_mdp');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN echecs TINYINT NOT NULL DEFAULT 0 AFTER doit_changer_mdp', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='echecs');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN bloque_jusqua DATETIME NULL AFTER echecs', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='bloque_jusqua');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN derniere_connexion DATETIME NULL AFTER bloque_jusqua', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='derniere_connexion');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Aucun compte n'est créé ici : si la table est vide, la page de connexion propose de créer le compte administrateur.
