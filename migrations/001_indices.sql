-- =====================================================================
--  Migration : indices de productivité (spécification R, L, C, S, P)
--  À importer sur une base existante. Remplace les anciennes tables
--  d'indices (les résultats sont recalculés depuis les données brutes).
--  Compatible MySQL 5.7+ / 8 et MariaDB 10.3+.
-- =====================================================================
SET NAMES utf8mb4;

-- Colonnes de sevrage sur la fiche animal (ajoutées si absentes)
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE animaux ADD COLUMN date_sevrage DATE NULL AFTER poids_naissance', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='animaux' AND COLUMN_NAME='date_sevrage');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE animaux ADD COLUMN poids_sevrage DECIMAL(6,2) NULL AFTER date_sevrage', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='animaux' AND COLUMN_NAME='poids_sevrage');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE reproductions ADD COLUMN nb_morts_nes TINYINT NULL AFTER nb_petits', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reproductions' AND COLUMN_NAME='nb_morts_nes');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

DROP VIEW IF EXISTS v_indices_resultats;
DROP TABLE IF EXISTS indices_resultats, indices_interpretations, indices;

-- ---------------------------------------------------------------------
-- Paramètres généraux de l'application
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parametres_app (
  cle     VARCHAR(50) PRIMARY KEY,
  valeur  VARCHAR(255) NOT NULL,
  libelle VARCHAR(150) NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Alimentation (aliment distribué, pour l'indice de consommation)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alimentation (
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

INSERT INTO parametres_app (cle, valeur, libelle) VALUES ('debut_campagne_mois','9','Mois de début de la campagne (1 = année civile)') ON DUPLICATE KEY UPDATE cle=cle;

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
 (25,'P03','Score global de productivité','globale','Note pondérée 0–100 (femelles ayant mis bas) : lait 35 %, prolificité 25 %, survie 20 %, croissance des petits 20 %','si(nb_mises_bas > 0, moyenne_ponderee(note(lait_moyen, 1, 4), 35, note(R04, 1, 2), 25, note(S02, 70, 100), 20, note(gmq_petits, 80, 200), 20))','score 0–100',0,'campagne','animal,lot,espece,troupeau','moyenne','haut','Classement / sélection / réforme',3,'F',250);

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
 (4,3,NULL,1.2,'Faible','danger',1),
 (4,3,1.2,1.6,'Moyenne','warning',2),
 (4,3,1.6,NULL,'Élevée','success',3),
 (4,2,NULL,1.2,'Faible','danger',4),
 (4,2,1.2,1.6,'Moyenne','warning',5),
 (4,2,1.6,NULL,'Élevée','success',6),
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
 (9,3,NULL,15,'Précoce','success',1),
 (9,3,15,20,'Moyen','warning',2),
 (9,3,20,NULL,'Tardif','danger',3),
 (9,2,NULL,15,'Précoce','success',4),
 (9,2,15,20,'Moyen','warning',5),
 (9,2,20,NULL,'Tardif','danger',6),
 (9,1,NULL,26,'Précoce','success',7),
 (9,1,26,32,'Moyen','warning',8),
 (9,1,32,NULL,'Tardif','danger',9),
 (10,NULL,NULL,240,'Très court','warning',1),
 (10,NULL,240,360,'Bon rythme','success',2),
 (10,NULL,360,420,'Allongé','warning',3),
 (10,NULL,420,NULL,'Trop long','danger',4),
 (10,1,NULL,380,'Bon rythme','success',5),
 (10,1,380,420,'Allongé','warning',6),
 (10,1,420,NULL,'Trop long','danger',7),
 (11,3,NULL,2,'Faible','danger',1),
 (11,3,2,3.5,'Moyen','warning',2),
 (11,3,3.5,NULL,'Bon','success',3),
 (11,1,NULL,15,'Faible','danger',4),
 (11,1,15,25,'Moyen','warning',5),
 (11,1,25,NULL,'Bon','success',6),
 (14,3,NULL,2,'Faible','danger',1),
 (14,3,2,3.5,'Moyen','warning',2),
 (14,3,3.5,NULL,'Bon','success',3),
 (14,1,NULL,15,'Faible','danger',4),
 (14,1,15,25,'Moyen','warning',5),
 (14,1,25,NULL,'Bon','success',6),
 (12,3,NULL,500,'Faible','danger',1),
 (12,3,500,800,'Moyen','warning',2),
 (12,3,800,NULL,'Bon','success',3),
 (12,1,NULL,5000,'Faible','danger',4),
 (12,1,5000,8000,'Moyen','warning',5),
 (12,1,8000,NULL,'Bon','success',6),
 (13,3,NULL,210,'Courte','danger',1),
 (13,3,210,270,'Moyenne','warning',2),
 (13,3,270,NULL,'Longue','success',3),
 (13,1,NULL,250,'Courte','danger',4),
 (13,1,250,305,'Moyenne','warning',5),
 (13,1,305,NULL,'Longue','success',6),
 (15,NULL,NULL,40,'Chute rapide','danger',1),
 (15,NULL,40,60,'Moyenne','warning',2),
 (15,NULL,60,NULL,'Régulière','success',3),
 (16,NULL,NULL,100,'Faible','danger',1),
 (16,NULL,100,150,'Moyen','warning',2),
 (16,NULL,150,NULL,'Bon','success',3),
 (16,1,NULL,600,'Faible','danger',4),
 (16,1,600,900,'Moyen','warning',5),
 (16,1,900,NULL,'Bon','success',6),
 (17,3,NULL,12,'Faible','danger',1),
 (17,3,12,15,'Moyen','warning',2),
 (17,3,15,NULL,'Bon','success',3),
 (17,1,NULL,90,'Faible','danger',4),
 (17,1,90,110,'Moyen','warning',5),
 (17,1,110,NULL,'Bon','success',6),
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
 (23,1,NULL,0.8,'Faible','danger',4),
 (23,1,0.8,0.9,'Moyen','warning',5),
 (23,1,0.9,NULL,'Bon','success',6),
 (24,3,NULL,15,'Faible','danger',1),
 (24,3,15,25,'Moyen','warning',2),
 (24,3,25,NULL,'Bon','success',3),
 (25,NULL,NULL,40,'À réformer','danger',1),
 (25,NULL,40,60,'Moyenne','warning',2),
 (25,NULL,60,80,'Bonne','info',3),
 (25,NULL,80,NULL,'Élite – sélection','success',4);
