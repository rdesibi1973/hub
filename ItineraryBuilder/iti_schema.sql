-- ============================================================
-- SAVANNAH EXPLORERS — Itinerary System
-- Integrazione nel DB hub esistente
-- Prefisso: iti_
-- Lingue: EN, IT, FR, ES, DE
-- Valute: USD, EUR
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. MASTER DATA
-- ============================================================

CREATE TABLE IF NOT EXISTS `iti_destinations` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(20)     NOT NULL COMMENT 'Es: SNP, TRP, NCA',
  `name_en`         VARCHAR(120)    NOT NULL,
  `name_it`         VARCHAR(120)    NOT NULL,
  `name_fr`         VARCHAR(120)    NOT NULL,
  `name_es`         VARCHAR(120)    NOT NULL,
  `name_de`         VARCHAR(120)    NOT NULL,
  `description_en`  TEXT            DEFAULT NULL,
  `description_it`  TEXT            DEFAULT NULL,
  `description_fr`  TEXT            DEFAULT NULL,
  `description_es`  TEXT            DEFAULT NULL,
  `description_de`  TEXT            DEFAULT NULL,
  `region`          VARCHAR(80)     DEFAULT NULL COMMENT 'Es: Northern Circuit, Zanzibar',
  `country`         VARCHAR(60)     NOT NULL DEFAULT 'Tanzania',
  `latitude`        DECIMAL(9,6)    DEFAULT NULL,
  `longitude`       DECIMAL(9,6)    DEFAULT NULL,
  `cover_photo`     VARCHAR(255)    DEFAULT NULL,
  `sort_order`      SMALLINT        NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dest_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_lodges` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `destination_id`  INT UNSIGNED    NOT NULL,
  `name`            VARCHAR(160)    NOT NULL,
  `category`        ENUM('budget','mid','luxury','ultra_luxury') NOT NULL DEFAULT 'mid',
  `lodge_type`      ENUM('lodge','tented_camp','hotel','mobile_camp','house') NOT NULL DEFAULT 'lodge',
  `description_en`  TEXT            DEFAULT NULL,
  `description_it`  TEXT            DEFAULT NULL,
  `description_fr`  TEXT            DEFAULT NULL,
  `description_es`  TEXT            DEFAULT NULL,
  `description_de`  TEXT            DEFAULT NULL,
  `website`         VARCHAR(255)    DEFAULT NULL,
  `photos`          JSON            DEFAULT NULL COMMENT 'Array di URL',
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_lodge_dest` (`destination_id`),
  CONSTRAINT `fk_lodge_dest` FOREIGN KEY (`destination_id`) REFERENCES `iti_destinations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_transfer_routes` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `from_destination`  INT UNSIGNED    NOT NULL,
  `to_destination`    INT UNSIGNED    NOT NULL,
  `duration_min`      SMALLINT        NOT NULL DEFAULT 0,
  `distance_km`       SMALLINT        DEFAULT NULL,
  `road_type`         ENUM('tarmac','gravel','mixed') NOT NULL DEFAULT 'mixed',
  `notes_en`          TEXT            DEFAULT NULL,
  `notes_it`          TEXT            DEFAULT NULL,
  `notes_fr`          TEXT            DEFAULT NULL,
  `notes_es`          TEXT            DEFAULT NULL,
  `notes_de`          TEXT            DEFAULT NULL,
  `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_tr_from` (`from_destination`),
  KEY `fk_tr_to`   (`to_destination`),
  CONSTRAINT `fk_tr_from` FOREIGN KEY (`from_destination`) REFERENCES `iti_destinations` (`id`),
  CONSTRAINT `fk_tr_to`   FOREIGN KEY (`to_destination`)   REFERENCES `iti_destinations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_flight_routes` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `from_airport`    VARCHAR(80)     NOT NULL COMMENT 'Es: Arusha Airport',
  `from_code`       VARCHAR(10)     DEFAULT NULL COMMENT 'IATA se disponibile',
  `to_airport`      VARCHAR(80)     NOT NULL,
  `to_code`         VARCHAR(10)     DEFAULT NULL,
  `operator`        VARCHAR(100)    DEFAULT NULL COMMENT 'Es: Coastal Aviation, Auric Air, Grumeti Air',
  `flight_type`     ENUM('scheduled','charter') NOT NULL DEFAULT 'scheduled',
  `duration_min`    SMALLINT        NOT NULL DEFAULT 0,
  `notes_en`        TEXT            DEFAULT NULL,
  `notes_it`        TEXT            DEFAULT NULL,
  `notes_fr`        TEXT            DEFAULT NULL,
  `notes_es`        TEXT            DEFAULT NULL,
  `notes_de`        TEXT            DEFAULT NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_activities` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `destination_id`  INT UNSIGNED    DEFAULT NULL COMMENT 'NULL = attivita generica valida ovunque',
  `activity_type`   ENUM('game_drive','walking_safari','cultural','boat','balloon','hiking','beach','other') NOT NULL DEFAULT 'other',
  `name_en`         VARCHAR(160)    NOT NULL,
  `name_it`         VARCHAR(160)    NOT NULL,
  `name_fr`         VARCHAR(160)    NOT NULL,
  `name_es`         VARCHAR(160)    NOT NULL,
  `name_de`         VARCHAR(160)    NOT NULL,
  `description_en`  TEXT            DEFAULT NULL,
  `description_it`  TEXT            DEFAULT NULL,
  `description_fr`  TEXT            DEFAULT NULL,
  `description_es`  TEXT            DEFAULT NULL,
  `description_de`  TEXT            DEFAULT NULL,
  `duration_hours`  DECIMAL(4,1)    DEFAULT NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_act_dest` (`destination_id`),
  CONSTRAINT `fk_act_dest` FOREIGN KEY (`destination_id`) REFERENCES `iti_destinations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_terms_conditions` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `version`       VARCHAR(20)     NOT NULL COMMENT 'Es: 2025-v1',
  `brand`         ENUM('savannah_explorers','orangi_collection','both') NOT NULL DEFAULT 'savannah_explorers',
  `content_en`    LONGTEXT        DEFAULT NULL,
  `content_it`    LONGTEXT        DEFAULT NULL,
  `content_fr`    LONGTEXT        DEFAULT NULL,
  `content_es`    LONGTEXT        DEFAULT NULL,
  `content_de`    LONGTEXT        DEFAULT NULL,
  `effective_date` DATE           NOT NULL,
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_standard_inclusions` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `item_type`   ENUM('inclusion','exclusion') NOT NULL DEFAULT 'inclusion',
  `text_en`     VARCHAR(255)    NOT NULL,
  `text_it`     VARCHAR(255)    NOT NULL,
  `text_fr`     VARCHAR(255)    NOT NULL,
  `text_es`     VARCHAR(255)    NOT NULL,
  `text_de`     VARCHAR(255)    NOT NULL,
  `sort_order`  SMALLINT        NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 2. RICHIESTE E PROGRAMMI
-- ============================================================

CREATE TABLE IF NOT EXISTS `iti_requests` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `agent_id`          INT UNSIGNED    DEFAULT NULL COMMENT 'FK alla tabella agenti hub se esiste',
  `agent_name`        VARCHAR(100)    DEFAULT NULL COMMENT 'Fallback se no FK',
  `client_name`       VARCHAR(160)    NOT NULL,
  `client_email`      VARCHAR(160)    DEFAULT NULL,
  `client_phone`      VARCHAR(60)     DEFAULT NULL,
  `client_nationality` VARCHAR(80)    DEFAULT NULL,
  `pax_adults`        TINYINT         NOT NULL DEFAULT 1,
  `pax_children`      TINYINT         NOT NULL DEFAULT 0,
  `arrival_date`      DATE            DEFAULT NULL,
  `departure_date`    DATE            DEFAULT NULL,
  `budget_category`   ENUM('budget','mid','luxury','ultra_luxury') DEFAULT NULL,
  `preferred_language` ENUM('en','it','fr','es','de') NOT NULL DEFAULT 'en',
  `preferred_currency` ENUM('USD','EUR') NOT NULL DEFAULT 'USD',
  `source`            VARCHAR(80)     DEFAULT NULL COMMENT 'Es: website, referral, B2B',
  `notes`             TEXT            DEFAULT NULL,
  `status`            ENUM('open','quoted','confirmed','cancelled') NOT NULL DEFAULT 'open',
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_programs` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_type`        ENUM('sample','personal') NOT NULL DEFAULT 'sample',
  `request_id`          INT UNSIGNED    DEFAULT NULL COMMENT 'NULL se SAMPLE',
  `sample_program_id`   INT UNSIGNED    DEFAULT NULL COMMENT 'ID del SAMPLE di origine se clonato',
  `terms_id`            INT UNSIGNED    DEFAULT NULL,
  `brand`               ENUM('savannah_explorers','orangi_collection') NOT NULL DEFAULT 'savannah_explorers',
  `title_en`            VARCHAR(200)    NOT NULL,
  `title_it`            VARCHAR(200)    NOT NULL,
  `title_fr`            VARCHAR(200)    NOT NULL,
  `title_es`            VARCHAR(200)    NOT NULL,
  `title_de`            VARCHAR(200)    NOT NULL,
  `subtitle_en`         VARCHAR(255)    DEFAULT NULL,
  `subtitle_it`         VARCHAR(255)    DEFAULT NULL,
  `subtitle_fr`         VARCHAR(255)    DEFAULT NULL,
  `subtitle_es`         VARCHAR(255)    DEFAULT NULL,
  `subtitle_de`         VARCHAR(255)    DEFAULT NULL,
  `duration_days`       TINYINT         NOT NULL DEFAULT 1,
  `pax_adults`          TINYINT         NOT NULL DEFAULT 2,
  `pax_children`        TINYINT         NOT NULL DEFAULT 0,
  `status`              ENUM('draft','sent','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  `public_token`        CHAR(36)        DEFAULT NULL COMMENT 'UUID per link cliente pubblico',
  `is_published`        TINYINT(1)      NOT NULL DEFAULT 0,
  `published_at`        DATETIME        DEFAULT NULL,
  `display_language`    ENUM('en','it','fr','es','de') NOT NULL DEFAULT 'en',
  `display_currency`    ENUM('USD','EUR') NOT NULL DEFAULT 'USD',
  `created_by`          VARCHAR(80)     DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_public_token` (`public_token`),
  KEY `fk_prog_request`  (`request_id`),
  KEY `fk_prog_sample`   (`sample_program_id`),
  KEY `fk_prog_terms`    (`terms_id`),
  CONSTRAINT `fk_prog_request`  FOREIGN KEY (`request_id`)        REFERENCES `iti_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prog_sample`   FOREIGN KEY (`sample_program_id`) REFERENCES `iti_programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prog_terms`    FOREIGN KEY (`terms_id`)          REFERENCES `iti_terms_conditions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_program_days` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_id`          INT UNSIGNED    NOT NULL,
  `day_number`          TINYINT         NOT NULL DEFAULT 1,
  `day_title_en`        VARCHAR(200)    DEFAULT NULL,
  `day_title_it`        VARCHAR(200)    DEFAULT NULL,
  `day_title_fr`        VARCHAR(200)    DEFAULT NULL,
  `day_title_es`        VARCHAR(200)    DEFAULT NULL,
  `day_title_de`        VARCHAR(200)    DEFAULT NULL,
  `start_lodge_id`      INT UNSIGNED    DEFAULT NULL,
  `end_lodge_id`        INT UNSIGNED    DEFAULT NULL,
  `transfer_route_id`   INT UNSIGNED    DEFAULT NULL,
  `narrative_en`        TEXT            DEFAULT NULL,
  `narrative_it`        TEXT            DEFAULT NULL,
  `narrative_fr`        TEXT            DEFAULT NULL,
  `narrative_es`        TEXT            DEFAULT NULL,
  `narrative_de`        TEXT            DEFAULT NULL,
  `meal_breakfast`      TINYINT(1)      NOT NULL DEFAULT 1,
  `meal_lunch`          TINYINT(1)      NOT NULL DEFAULT 1,
  `meal_dinner`         TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prog_day` (`program_id`, `day_number`),
  KEY `fk_pd_prog`        (`program_id`),
  KEY `fk_pd_start_lodge` (`start_lodge_id`),
  KEY `fk_pd_end_lodge`   (`end_lodge_id`),
  KEY `fk_pd_transfer`    (`transfer_route_id`),
  CONSTRAINT `fk_pd_prog`        FOREIGN KEY (`program_id`)        REFERENCES `iti_programs` (`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_pd_start_lodge` FOREIGN KEY (`start_lodge_id`)    REFERENCES `iti_lodges` (`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_pd_end_lodge`   FOREIGN KEY (`end_lodge_id`)      REFERENCES `iti_lodges` (`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_pd_transfer`    FOREIGN KEY (`transfer_route_id`) REFERENCES `iti_transfer_routes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_day_activities` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_day_id`    INT UNSIGNED    NOT NULL,
  `activity_id`       INT UNSIGNED    NOT NULL,
  `sort_order`        TINYINT         NOT NULL DEFAULT 0,
  `custom_note_en`    TEXT            DEFAULT NULL,
  `custom_note_it`    TEXT            DEFAULT NULL,
  `custom_note_fr`    TEXT            DEFAULT NULL,
  `custom_note_es`    TEXT            DEFAULT NULL,
  `custom_note_de`    TEXT            DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_da_day`  (`program_day_id`),
  KEY `fk_da_act`  (`activity_id`),
  CONSTRAINT `fk_da_day` FOREIGN KEY (`program_day_id`) REFERENCES `iti_program_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_da_act` FOREIGN KEY (`activity_id`)    REFERENCES `iti_activities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_day_flights` (
  `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_day_id`    INT UNSIGNED    NOT NULL,
  `flight_route_id`   INT UNSIGNED    NOT NULL,
  `departure_time`    TIME            DEFAULT NULL,
  `arrival_time`      TIME            DEFAULT NULL,
  `sort_order`        TINYINT         NOT NULL DEFAULT 0 COMMENT 'Per giorni con piu voli',
  `note_en`           TEXT            DEFAULT NULL,
  `note_it`           TEXT            DEFAULT NULL,
  `note_fr`           TEXT            DEFAULT NULL,
  `note_es`           TEXT            DEFAULT NULL,
  `note_de`           TEXT            DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_df_day`    (`program_day_id`),
  KEY `fk_df_flight` (`flight_route_id`),
  CONSTRAINT `fk_df_day`    FOREIGN KEY (`program_day_id`)  REFERENCES `iti_program_days` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_df_flight` FOREIGN KEY (`flight_route_id`) REFERENCES `iti_flight_routes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 3. PREZZI
-- ============================================================

CREATE TABLE IF NOT EXISTS `iti_program_prices` (
  `id`                    INT UNSIGNED        NOT NULL AUTO_INCREMENT,
  `program_id`            INT UNSIGNED        NOT NULL,
  `price_category`        ENUM('rack','sto','stospec') NOT NULL,
  `price_per_pax_usd`     DECIMAL(10,2)       DEFAULT NULL,
  `price_per_pax_eur`     DECIMAL(10,2)       DEFAULT NULL,
  `single_suppl_usd`      DECIMAL(10,2)       DEFAULT NULL,
  `single_suppl_eur`      DECIMAL(10,2)       DEFAULT NULL,
  `child_price_usd`       DECIMAL(10,2)       DEFAULT NULL COMMENT 'Prezzo bambino (se diverso da adulto)',
  `child_price_eur`       DECIMAL(10,2)       DEFAULT NULL,
  `min_pax`               TINYINT             DEFAULT NULL COMMENT 'Minimo pax per questa tariffa',
  `valid_from`            DATE                DEFAULT NULL,
  `valid_to`              DATE                DEFAULT NULL,
  `notes`                 TEXT                DEFAULT NULL,
  `created_at`            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prog_price_cat` (`program_id`, `price_category`),
  KEY `fk_pp_prog` (`program_id`),
  CONSTRAINT `fk_pp_prog` FOREIGN KEY (`program_id`) REFERENCES `iti_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_price_supplements` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_id`      INT UNSIGNED    NOT NULL,
  `price_category`  ENUM('rack','sto','stospec','all') NOT NULL DEFAULT 'all',
  `name_en`         VARCHAR(160)    NOT NULL,
  `name_it`         VARCHAR(160)    NOT NULL,
  `name_fr`         VARCHAR(160)    NOT NULL,
  `name_es`         VARCHAR(160)    NOT NULL,
  `name_de`         VARCHAR(160)    NOT NULL,
  `amount_usd`      DECIMAL(10,2)   DEFAULT NULL,
  `amount_eur`      DECIMAL(10,2)   DEFAULT NULL,
  `calc_type`       ENUM('fixed','per_pax','percentage') NOT NULL DEFAULT 'per_pax',
  `sort_order`      TINYINT         NOT NULL DEFAULT 0,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ps_prog` (`program_id`),
  CONSTRAINT `fk_ps_prog` FOREIGN KEY (`program_id`) REFERENCES `iti_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_price_discounts` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_id`      INT UNSIGNED    NOT NULL,
  `price_category`  ENUM('rack','sto','stospec','all') NOT NULL DEFAULT 'all',
  `name_en`         VARCHAR(160)    NOT NULL,
  `name_it`         VARCHAR(160)    NOT NULL,
  `name_fr`         VARCHAR(160)    NOT NULL,
  `name_es`         VARCHAR(160)    NOT NULL,
  `name_de`         VARCHAR(160)    NOT NULL,
  `discount_type`   ENUM('early_bird','group','child','honeymoon','repeat','other') NOT NULL DEFAULT 'other',
  `value_usd`       DECIMAL(10,2)   DEFAULT NULL,
  `value_eur`       DECIMAL(10,2)   DEFAULT NULL,
  `value_type`      ENUM('fixed','per_pax','percentage') NOT NULL DEFAULT 'per_pax',
  `conditions_en`   TEXT            DEFAULT NULL,
  `conditions_it`   TEXT            DEFAULT NULL,
  `sort_order`      TINYINT         NOT NULL DEFAULT 0,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pd2_prog` (`program_id`),
  CONSTRAINT `fk_pd2_prog` FOREIGN KEY (`program_id`) REFERENCES `iti_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `iti_program_inclusions` (
  `id`                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `program_id`              INT UNSIGNED    NOT NULL,
  `item_type`               ENUM('inclusion','exclusion') NOT NULL DEFAULT 'inclusion',
  `standard_inclusion_id`   INT UNSIGNED    DEFAULT NULL COMMENT 'Se preso da standard_inclusions',
  `text_en`                 VARCHAR(255)    DEFAULT NULL COMMENT 'Override o testo custom',
  `text_it`                 VARCHAR(255)    DEFAULT NULL,
  `text_fr`                 VARCHAR(255)    DEFAULT NULL,
  `text_es`                 VARCHAR(255)    DEFAULT NULL,
  `text_de`                 VARCHAR(255)    DEFAULT NULL,
  `sort_order`              SMALLINT        NOT NULL DEFAULT 0,
  `created_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pi_prog` (`program_id`),
  KEY `fk_pi_std`  (`standard_inclusion_id`),
  CONSTRAINT `fk_pi_prog` FOREIGN KEY (`program_id`)            REFERENCES `iti_programs` (`id`)            ON DELETE CASCADE,
  CONSTRAINT `fk_pi_std`  FOREIGN KEY (`standard_inclusion_id`) REFERENCES `iti_standard_inclusions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- TABELLE CREATE (16 totali):
--   Master data  : iti_destinations, iti_lodges,
--                  iti_transfer_routes, iti_flight_routes,
--                  iti_activities, iti_terms_conditions,
--                  iti_standard_inclusions
--   Programmi    : iti_requests, iti_programs,
--                  iti_program_days, iti_day_activities,
--                  iti_day_flights
--   Prezzi       : iti_program_prices, iti_price_supplements,
--                  iti_price_discounts, iti_program_inclusions
-- ============================================================
