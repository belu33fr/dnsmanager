<?php
/**
 * DNSManage - Hooks d'installation
 */

/**
 * Installation du plugin : création des tables
 */
function plugin_dnsmanager_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_DNSMANAGE_VERSION);

    // -------------------------------------------------------
    // Table des comptes provider
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_dnsmanager_accounts')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_dnsmanager_accounts` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`          VARCHAR(255) NOT NULL DEFAULT '',
                `provider_type` VARCHAR(100) NOT NULL DEFAULT '',
                `endpoint`      VARCHAR(255) NOT NULL DEFAULT '',
                `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
                `entities_id`   INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive`  TINYINT(1) NOT NULL DEFAULT 0,
                `comment`       TEXT,
                `last_sync_at`  DATETIME DEFAULT NULL,
                `date_creation` DATETIME DEFAULT NULL,
                `date_mod`      DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `provider_type` (`provider_type`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // -------------------------------------------------------
    // Table des credentials (chiffrés)
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_dnsmanager_credentials')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_dnsmanager_credentials` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accounts_id` INT UNSIGNED NOT NULL,
                `cred_key`    VARCHAR(100) NOT NULL DEFAULT '',
                `cred_value`  TEXT NOT NULL,
                `date_mod`    DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `accounts_id` (`accounts_id`),
                CONSTRAINT `fk_dnsmanager_cred_account`
                    FOREIGN KEY (`accounts_id`)
                    REFERENCES `glpi_plugin_dnsmanager_accounts` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // -------------------------------------------------------
    // Table de mapping domaines importés
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_dnsmanager_domains')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_dnsmanager_domains` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accounts_id`   INT UNSIGNED NOT NULL,
                `domains_id`    INT UNSIGNED NOT NULL,
                `provider_ref`  VARCHAR(255) NOT NULL DEFAULT '',
                `last_sync_at`  DATETIME DEFAULT NULL,
                `sync_status`   VARCHAR(50) NOT NULL DEFAULT 'pending',
                `sync_message`  TEXT,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_account_ref` (`accounts_id`, `provider_ref`),
                KEY `domains_id` (`domains_id`),
                CONSTRAINT `fk_dnsmanager_dom_account`
                    FOREIGN KEY (`accounts_id`)
                    REFERENCES `glpi_plugin_dnsmanager_accounts` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // -------------------------------------------------------
    // Table de mapping enregistrements DNS importés
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_dnsmanager_records')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_dnsmanager_records` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accounts_id`      INT UNSIGNED NOT NULL,
                `domainrecords_id` INT UNSIGNED NOT NULL,
                `provider_ref`     VARCHAR(255) NOT NULL DEFAULT '',
                `is_editable`      TINYINT(1) NOT NULL DEFAULT 0,
                `last_sync_at`     DATETIME DEFAULT NULL,
                `sync_status`      VARCHAR(50) NOT NULL DEFAULT 'pending',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_account_record` (`accounts_id`, `provider_ref`),
                KEY `domainrecords_id` (`domainrecords_id`),
                CONSTRAINT `fk_dnsmanager_rec_account`
                    FOREIGN KEY (`accounts_id`)
                    REFERENCES `glpi_plugin_dnsmanager_accounts` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // -------------------------------------------------------
    // Journal de synchronisation
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_dnsmanager_synclogs')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_dnsmanager_synclogs` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accounts_id`     INT UNSIGNED NOT NULL,
                `started_at`      DATETIME DEFAULT NULL,
                `finished_at`     DATETIME DEFAULT NULL,
                `status`          VARCHAR(50) NOT NULL DEFAULT 'pending',
                `domains_added`   INT NOT NULL DEFAULT 0,
                `domains_updated` INT NOT NULL DEFAULT 0,
                `records_added`   INT NOT NULL DEFAULT 0,
                `records_updated` INT NOT NULL DEFAULT 0,
                `error_log`       TEXT,
                PRIMARY KEY (`id`),
                KEY `accounts_id` (`accounts_id`),
                KEY `status` (`status`),
                KEY `started_at` (`started_at`),
                CONSTRAINT `fk_dnsmanager_log_account`
                    FOREIGN KEY (`accounts_id`)
                    REFERENCES `glpi_plugin_dnsmanager_accounts` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // -------------------------------------------------------
    // Configuration globale du plugin
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_dnsmanager_configs')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_dnsmanager_configs` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `config_key`  VARCHAR(100) NOT NULL DEFAULT '',
                `config_value` TEXT,
                PRIMARY KEY (`id`),
                UNIQUE KEY `config_key` (`config_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Valeurs par défaut
        $DB->insert('glpi_plugin_dnsmanager_configs', [
            'config_key'   => 'encryption_key',
            'config_value' => bin2hex(random_bytes(32)),
        ]);
        $DB->insert('glpi_plugin_dnsmanager_configs', [
            'config_key'   => 'cron_enabled',
            'config_value' => '1',
        ]);
        $DB->insert('glpi_plugin_dnsmanager_configs', [
            'config_key'   => 'cron_frequency',
            'config_value' => '3600', // 1 heure
        ]);
    }

    $migration->executeMigration();

    // ------------------------------------------------------------------
    // Déclaration des droits dans glpi_profilerights
    // Sans cela, GLPI ne connaît pas le droit et bloque l'accès.
    // On accorde READ+UPDATE+CREATE+DELETE au profil super-admin (id=4).
    // ------------------------------------------------------------------
    $rightName = 'plugin_dnsmanager_account';

    // Vérifie si le droit existe déjà
    $existingRight = $DB->request([
        'FROM'  => 'glpi_profilerights',
        'WHERE' => [
            'name'       => $rightName,
            'profiles_id'=> 4, // super-admin
        ],
    ])->current();

    if (!$existingRight) {
        $DB->insert('glpi_profilerights', [
            'profiles_id' => 4,
            'name'        => $rightName,
            'rights'      => READ | CREATE | UPDATE | DELETE | PURGE,
        ]);
    }

    // Nettoyage du cache de profils
    if (method_exists('Profile', 'clearCache')) {
        Profile::clearCache();
    }

    return true;
}

/**
 * Désinstallation du plugin : suppression des tables
 */
function plugin_dnsmanager_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_dnsmanager_records',
        'glpi_plugin_dnsmanager_domains',
        'glpi_plugin_dnsmanager_credentials',
        'glpi_plugin_dnsmanager_synclogs',
        'glpi_plugin_dnsmanager_accounts',
        'glpi_plugin_dnsmanager_configs',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    // Suppression de la tâche CRON
    $cron = new CronTask();
    $cron->deleteByCriteria(['itemtype' => 'PluginDnsmanageSynclog']);

    return true;
}
