-- =====================================================================
--  Migration : « Se souvenir de moi » sur la page de connexion
--  À importer après 004_profils.sql (base « troupeau » sélectionnée).
--  Peut être importée plusieurs fois sans erreur.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS jetons_connexion (
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
