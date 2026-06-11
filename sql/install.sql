-- Module-owned tables only. We NEVER alter existing PrestaShop tables (docs/06 §1).
-- PREFIX_ and ENGINE_TYPE are substituted by daktela.php at install time.

CREATE TABLE IF NOT EXISTS `PREFIX_daktela_ticket` (
    `id_daktela_ticket` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `daktela_name` VARCHAR(191) NOT NULL,
    `title` VARCHAR(512) NOT NULL DEFAULT '',
    `stage` VARCHAR(64) NOT NULL DEFAULT '',
    `contact_email` VARCHAR(255) NOT NULL DEFAULT '',
    `contact_name` VARCHAR(255) NOT NULL DEFAULT '',
    `id_customer` INT(11) UNSIGNED NULL,
    `waiting` TINYINT(1) NOT NULL DEFAULT 0,
    `wait_seconds` INT(11) NOT NULL DEFAULT 0,
    `order_count` INT(11) NOT NULL DEFAULT 0,
    `total_spend` DECIMAL(20,6) NOT NULL DEFAULT 0,
    `has_open_order` TINYINT(1) NOT NULL DEFAULT 0,
    `latest_inbound` TEXT NULL,
    `classified_hash` CHAR(64) NOT NULL DEFAULT '',
    `created_remote` DATETIME NULL,
    `edited_remote` DATETIME NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_daktela_ticket`),
    UNIQUE KEY `uniq_daktela_name` (`daktela_name`),
    KEY `idx_edited` (`edited_remote`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_daktela_ticket_score` (
    `id_score` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_daktela_ticket` INT(11) UNSIGNED NOT NULL,
    `category` VARCHAR(32) NOT NULL DEFAULT 'other',
    `urgency` VARCHAR(16) NOT NULL DEFAULT 'medium',
    `sentiment` VARCHAR(16) NOT NULL DEFAULT 'neutral',
    `lang` VARCHAR(8) NOT NULL DEFAULT '',
    `summary` TEXT NULL,
    `is_question` TINYINT(1) NOT NULL DEFAULT 1,
    `confidence` DECIMAL(4,3) NOT NULL DEFAULT 0,
    `complexity` VARCHAR(16) NOT NULL DEFAULT 'low',
    `model_used` VARCHAR(64) NOT NULL DEFAULT '',
    `comp_sentiment` INT(11) NOT NULL DEFAULT 0,
    `comp_category` INT(11) NOT NULL DEFAULT 0,
    `comp_urgency` INT(11) NOT NULL DEFAULT 0,
    `comp_value` INT(11) NOT NULL DEFAULT 0,
    `comp_sla` INT(11) NOT NULL DEFAULT 0,
    `ai_score` INT(11) NOT NULL DEFAULT 0,
    `manual_score` INT(11) NULL,
    `manual_score_by` VARCHAR(255) NULL,
    `manual_score_at` DATETIME NULL,
    `effective_score` INT(11) NOT NULL DEFAULT 0,
    `weights_json` TEXT NULL,
    `suggested_draft` TEXT NULL,
    `answer_grounded` TINYINT(1) NOT NULL DEFAULT 0,
    `products_referenced` VARCHAR(255) NOT NULL DEFAULT '',
    `needs_human` TINYINT(1) NOT NULL DEFAULT 1,
    `flagged` TINYINT(1) NOT NULL DEFAULT 0,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_score`),
    UNIQUE KEY `uniq_ticket` (`id_daktela_ticket`),
    KEY `idx_effective` (`effective_score`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_daktela_sync_state` (
    `id_state` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `k` VARCHAR(64) NOT NULL,
    `v` TEXT NULL,
    PRIMARY KEY (`id_state`),
    UNIQUE KEY `uniq_k` (`k`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;
