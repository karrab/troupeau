-- =====================================================================
--  Migration : profils, permissions détaillées et profil utilisateur
--  À importer après 002_utilisateurs.sql (base « troupeau » sélectionnée).
--  Peut être importée plusieurs fois sans erreur. Les comptes existants
--  reçoivent le profil correspondant à leur ancien rôle.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS profils (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(30) NOT NULL UNIQUE,
  nom         VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  couleur     VARCHAR(20) NOT NULL DEFAULT 'secondary',
  systeme     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Administrateur : tous les droits, non modifiable',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profil_permissions (
  profil_id  INT NOT NULL,
  permission VARCHAR(50) NOT NULL COMMENT 'Code défini dans includes/auth.php (PERMISSIONS)',
  PRIMARY KEY (profil_id, permission),
  CONSTRAINT fk_pp_profil FOREIGN KEY (profil_id) REFERENCES profils(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN profil_id INT NULL AFTER mot_de_passe', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='profil_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN telephone VARCHAR(30) NULL AFTER profil_id', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='telephone');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN fonction VARCHAR(100) NULL AFTER telephone', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='fonction');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN photo VARCHAR(255) NULL AFTER fonction', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='photo');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD COLUMN notes VARCHAR(255) NULL AFTER photo', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='notes');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE utilisateurs ADD CONSTRAINT fk_utilisateur_profil FOREIGN KEY (profil_id) REFERENCES profils(id) ON DELETE SET NULL', 'DO 0')
          FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND CONSTRAINT_NAME='fk_utilisateur_profil');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- L'ancien rôle n'est plus utilisé : valeur par défaut pour les nouveaux comptes
SET @s = (SELECT IF(COUNT(*)=1, 'ALTER TABLE utilisateurs MODIFY role ENUM(''admin'',''eleveur'',''veterinaire'') NULL DEFAULT NULL', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='role');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS journal_connexions (
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

INSERT IGNORE INTO profils (code, nom, description, couleur, systeme) VALUES
 ('admin','Administrateur','Tous les droits, y compris profils et utilisateurs (non modifiable)','danger',1),
 ('eleveur','Éleveur','Gestion complète du troupeau et des saisies','success',0),
 ('technicien','Technicien / salarié','Saisies quotidiennes, sans suppression ni modification des fiches','primary',0),
 ('veterinaire','Vétérinaire','Consultation de tout le troupeau, saisie des actes de santé','info',0),
 ('consultation','Consultation','Lecture seule (conseiller, contrôle laitier, associé)','secondary',0);
INSERT IGNORE INTO profil_permissions (profil_id, permission)
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
INSERT IGNORE INTO profil_permissions (profil_id, permission)
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
INSERT IGNORE INTO profil_permissions (profil_id, permission)
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
INSERT IGNORE INTO profil_permissions (profil_id, permission)
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

-- Comptes existants : profil d'après l'ancien rôle
SET @s = (SELECT IF(COUNT(*)=1, 'UPDATE utilisateurs u JOIN profils p ON p.code=u.role SET u.profil_id=p.id WHERE u.profil_id IS NULL', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateurs' AND COLUMN_NAME='role');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
