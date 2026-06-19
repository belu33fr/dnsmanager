<?php

use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Credential;
use GlpiPlugin\Dnsmanager\Config as DnsConfig;
use GlpiPlugin\Dnsmanager\Synclog;
use GlpiPlugin\Dnsmanager\Right;

function plugin_dnsmanager_install(): bool
{
    global $DB;

    $default_charset   = \DBConnection::getDefaultCharset();
    $default_collation = \DBConnection::getDefaultCollation();

    $migration = new \Migration(PLUGIN_DNSMANAGER_VERSION);

    // Table des comptes provider
    $table = Account::getTable();
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`             VARCHAR(255) NOT NULL DEFAULT '',
                `provider_type`    VARCHAR(100) NOT NULL DEFAULT '',
                `endpoint`         VARCHAR(255) NOT NULL DEFAULT '',
                `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
                `entities_id`      INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive`     TINYINT(1) NOT NULL DEFAULT 0,
                `suppliers_id`     INT UNSIGNED NOT NULL DEFAULT 0,
                `domain_whitelist` TEXT,
                `renewal_months`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `comment`          TEXT,
                `last_sync_at`     DATETIME DEFAULT NULL,
                `date_creation`    DATETIME DEFAULT NULL,
                `date_mod`         DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Table des credentials chiffrés
    $table = Credential::getTable();
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accounts_id` INT UNSIGNED NOT NULL,
                `cred_key`    VARCHAR(100) NOT NULL DEFAULT '',
                `cred_value`  TEXT NOT NULL,
                `date_mod`    DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `accounts_id` (`accounts_id`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Table des domaines mappés
    if (!$DB->tableExists('glpi_plugin_dnsmanager_domains')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_dnsmanager_domains` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accounts_id`  INT UNSIGNED NOT NULL,
                `domains_id`   INT UNSIGNED NOT NULL,
                `provider_ref` VARCHAR(255) NOT NULL DEFAULT '',
                `last_sync_at` DATETIME DEFAULT NULL,
                `sync_status`  VARCHAR(50) NOT NULL DEFAULT 'pending',
                `sync_message` TEXT,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_account_ref` (`accounts_id`, `provider_ref`),
                KEY `domains_id` (`domains_id`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Table des enregistrements DNS mappés
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
                UNIQUE KEY `uniq_account_record` (`accounts_id`, `provider_ref`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Journal de synchronisation
    $table = Synclog::getTable();
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
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
                KEY `started_at` (`started_at`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Configuration (clé de chiffrement)
    $table = DnsConfig::getTable();
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `config_key`   VARCHAR(100) NOT NULL DEFAULT '',
                `config_value` TEXT,
                PRIMARY KEY (`id`),
                UNIQUE KEY `config_key` (`config_key`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
        $DB->insert($table, [
            'config_key'   => 'encryption_key',
            'config_value' => bin2hex(random_bytes(32)),
        ]);
    }

    // Enregistrement de la tâche CRON
    // Initialiser les champs Additional Fields
    plugin_dnsmanager_init_fields();

    // Initialiser les droits par défaut
    Right::addDefaultProfileRights();

    // Initialiser les paramètres par défaut
    $configTable = \GlpiPlugin\Dnsmanager\Config::getTable();
    if ($DB->tableExists($configTable)) {
        $defaults = ['entity_labels' => 'Sites;Financier'];
        foreach ($defaults as $key => $value) {
            $existing = $DB->request(['FROM' => $configTable, 'WHERE' => ['config_key' => $key]])->current();
            if (!$existing) {
                $DB->insert($configTable, ['config_key' => $key, 'config_value' => $value]);
            }
        }
    }

    \CronTask::register(
        Synclog::class,
        'SyncAllAccounts',
        HOUR_TIMESTAMP,
        [
            'comment' => 'Synchronisation automatique DNS Hébergeurs',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    $migration->executeMigration();
    plugin_dnsmanager_migrate();

    return true;
}

function plugin_dnsmanager_uninstall(): bool
{
    global $DB;

    // Vider les champs pointant vers notre plugin
    $afTable = 'glpi_plugin_fields_domainadministratifdomaines';
    if ($DB->tableExists($afTable) && $DB->fieldExists($afTable, 'plugin_dnsmanager_accounts_id_comptedhbergeurfield')) {
        $DB->update($afTable, ['plugin_dnsmanager_accounts_id_comptedhbergeurfield' => 0], [true]);
    }

    $tables = [
        'glpi_plugin_dnsmanager_records',
        'glpi_plugin_dnsmanager_domains',
        Credential::getTable(),
        Synclog::getTable(),
        Account::getTable(),
        \GlpiPlugin\Dnsmanager\Config::getTable(),
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    // Supprimer les droits
    Right::removeProfileRights();

    return true;
}

/**
 * Appelée à chaque activation du plugin — applique les migrations.
 */
function plugin_dnsmanager_activate(): void
{
    plugin_dnsmanager_migrate();
    Right::addDefaultProfileRights();
}

/**
 * Migrations de schéma cumulatives.
 */
function plugin_dnsmanager_migrate(): void
{
    global $DB;

    $accountTable = Account::getTable();
    if (!$DB->tableExists($accountTable)) {
        return;
    }

    $cols = [
        'domain_whitelist' => "ALTER TABLE `$accountTable` ADD COLUMN `domain_whitelist` TEXT AFTER `last_sync_at`",
        'renewal_months'   => "ALTER TABLE `$accountTable` ADD COLUMN `renewal_months` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `domain_whitelist`",
        'suppliers_id'     => "ALTER TABLE `$accountTable` ADD COLUMN `suppliers_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `renewal_months`",
        'is_recursive'     => "ALTER TABLE `$accountTable` ADD COLUMN `is_recursive` TINYINT(1) NOT NULL DEFAULT 0 AFTER `entities_id`",
        'plugin_accounts_accounts_id_administrateurfield' => "ALTER TABLE `$accountTable` ADD COLUMN `plugin_accounts_accounts_id_administrateurfield` INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'plugin_accounts_accounts_id_technicienfield'     => "ALTER TABLE `$accountTable` ADD COLUMN `plugin_accounts_accounts_id_technicienfield` INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'plugin_accounts_accounts_id_financierfield'      => "ALTER TABLE `$accountTable` ADD COLUMN `plugin_accounts_accounts_id_financierfield` INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'users_id_proprietairefield'                      => "ALTER TABLE `$accountTable` ADD COLUMN `users_id_proprietairefield` INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'groups_id'                                       => "ALTER TABLE `$accountTable` ADD COLUMN `groups_id` INT(10) UNSIGNED NOT NULL DEFAULT 0",
        'groups_id_tech'                                  => "ALTER TABLE `$accountTable` ADD COLUMN `groups_id_tech` INT(10) UNSIGNED NOT NULL DEFAULT 0",
    ];

    foreach ($cols as $col => $sql) {
        if (!$DB->fieldExists($accountTable, $col)) {
            $DB->doQuery($sql);
        }
    }
}

/**
 * Hook pour les actions massives
 * Contourne le bug getItemForItemtype() avec namespace GLPI 11
 * @see https://github.com/glpi-project/glpi/issues/8449
 */
function plugin_dnsmanager_MassiveActions(string $type): array
{
    $actions = [];
    if ($type === \GlpiPlugin\Dnsmanager\Account::class) {
        $sep = \MassiveAction::CLASS_ACTION_SEPARATOR;
        $class = \GlpiPlugin\Dnsmanager\Account::class;
        $actions[$class . $sep . 'change_supplier']  = __('Modifier le fournisseur', 'dnsmanager');
        $actions[$class . $sep . 'change_whitelist'] = __('Modifier la liste blanche', 'dnsmanager');
        $actions[$class . $sep . 'change_renewal']   = __('Modifier le délai de renouvellement', 'dnsmanager');
        $actions[$class . $sep . 'sync']             = __('Synchroniser maintenant', 'dnsmanager');
    }
    return $actions;
    // v3.0.1 — ajouter les champs groupes dans les comptes DNS
    if ($DB->tableExists($accountTable)) {
        $groupFields = [
            'groups_id'      => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            'groups_id_tech' => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
        ];
        foreach ($groupFields as $field => $definition) {
            if (!$DB->fieldExists($accountTable, $field)) {
                $DB->doQuery("ALTER TABLE `$accountTable` ADD COLUMN `$field` $definition");
            }
        }
    }

    // v2.7.5 — ajouter les champs administratifs par défaut dans les comptes DNS
    if ($DB->tableExists($accountTable)) {
        $adminFields = [
            'plugin_accounts_accounts_id_administrateurfield' => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            'plugin_accounts_accounts_id_technicienfield'     => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            'plugin_accounts_accounts_id_financierfield'      => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
            'users_id_proprietairefield'                      => 'INT(10) UNSIGNED NOT NULL DEFAULT 0',
        ];
        foreach ($adminFields as $field => $definition) {
            if (!$DB->fieldExists($accountTable, $field)) {
                $DB->doQuery("ALTER TABLE `$accountTable` ADD COLUMN `$field` $definition");
            }
        }
    }

    // v2.5.5 — supprimer cron_enabled et cron_frequency (gérés par GLPI)
    $configTable = \GlpiPlugin\Dnsmanager\Config::getTable();
    if ($DB->tableExists($configTable)) {
        $DB->delete($configTable, ['config_key' => ['cron_enabled', 'cron_frequency']]);
    }

    // v2.3.9 — corriger contrainte unique sur records (provider_ref dupliqué entre zones OVH)
    $recordsTable = 'glpi_plugin_dnsmanager_records';
    if ($DB->tableExists($recordsTable)) {
        $oldIndex = $DB->request([
            'FROM'  => 'information_schema.STATISTICS',
            'WHERE' => ['TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => $recordsTable, 'INDEX_NAME' => 'uniq_account_record'],
        ])->current();
        if ($oldIndex) {
            $DB->doQuery("ALTER TABLE `$recordsTable` DROP INDEX `uniq_account_record`");
            $DB->doQuery("ALTER TABLE `$recordsTable` ADD UNIQUE KEY `uniq_account_domain_record` (`accounts_id`, `domainrecords_id`, `provider_ref`)");
        }
    }

}

function plugin_dnsmanager_post_item_form(array $params): void
{
    $item = $params['item'] ?? null;
    if (!($item instanceof Domain)) return;
    if (!Session::haveRight('plugin_dnsmanager_sync', UPDATE)) return;

    $domainId  = (int)$item->getID();
    $accountId = \GlpiPlugin\Dnsmanager\DomainTab::getAccountForDomain($domainId);
    if (!$accountId) return;

    global $DB;
    $mapping = $DB->request([
        'FROM'  => 'glpi_plugin_dnsmanager_domains',
        'WHERE' => ['domains_id' => $domainId],
    ])->current();

    $webdir  = Plugin::getWebDir('dnsmanager');
    $syncUrl = $webdir . '/ajax/sync.php';
    $ref     = htmlspecialchars($mapping['provider_ref'] ?? '');

    // Récupérer les infos du compte
    $account = $DB->request([
        'FROM'  => 'glpi_plugin_dnsmanager_accounts',
        'WHERE' => ['id' => $accountId],
    ])->current();

    $accName  = htmlspecialchars($account['name'] ?? '');
    $lastSync = $mapping['last_sync_at'] ?? __('Jamais', 'dnsmanager');
    $status   = $mapping['sync_status'] ?? '';

    echo "<div class='card mt-3'>";
    echo "<div class='card-header'>";
    echo "<h3 class='card-title'><i class='ti ti-refresh me-2'></i>" . __('Informations de synchronisation DNS', 'dnsmanager') . "</h3>";
    echo "</div>";
    echo "<div class='card-body'>";

    // Informations
    echo "<div class='row mb-3'>";
    echo "<div class='col-md-4'>";
    echo "<label class='form-label fw-bold'>" . __('Compte DNS hébergeur', 'dnsmanager') . "</label>";
    echo "<div><a href='{$webdir}/front/account.form.php?id={$accountId}'>{$accName}</a></div>";
    echo "</div>";
    echo "<div class='col-md-4'>";
    echo "<label class='form-label fw-bold'>" . __('Référence provider', 'dnsmanager') . "</label>";
    echo "<div>{$ref}</div>";
    echo "</div>";
    echo "<div class='col-md-4'>";
    echo "<label class='form-label fw-bold'>" . __('Dernière synchronisation', 'dnsmanager') . "</label>";
    echo "<div>{$lastSync}</div>";
    echo "</div>";
    echo "</div>";

    // Bouton + résultat
    echo "<div class='d-flex gap-3 align-items-center flex-wrap'>";
    echo "<button type='button' class='btn btn-primary' id='dns-domain-sync-btn'
        data-account-id='{$accountId}'
        data-domain-ref='{$ref}'
        data-sync-url='{$syncUrl}'>";
    echo "<i class='ti ti-refresh me-1'></i>" . __('Synchroniser ce domaine', 'dnsmanager');
    echo "</button>";
    echo "<div id='dns-domain-sync-result'></div>";
    echo "</div>";

    echo "</div>"; // card-body
    echo "</div>"; // card
    echo '<script>
    (function() {
        var btn = document.getElementById("dns-domain-sync-btn");
        if (!btn) return;
        btn.addEventListener("click", function() {
            var result = document.getElementById("dns-domain-sync-result");
            var meta = document.querySelector("meta[property=\'glpi:csrf_token\']");
            var csrf = meta ? meta.content : "";
            btn.disabled = true;
            result.innerHTML = "<span class=\'badge bg-info me-2\'>En cours...</span>";
            fetch(btn.dataset.syncUrl, {
                method: "POST",
                headers: {"Content-Type":"application/x-www-form-urlencoded","X-Glpi-Csrf-Token":csrf,"X-Requested-With":"XMLHttpRequest"},
                body: new URLSearchParams({action:"sync_domain",account_id:btn.dataset.accountId,domain_ref:btn.dataset.domainRef})
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.disabled = false;
                if (d.success) {
                    window.location.reload();
                } else {
                    result.innerHTML = "<span class=\'badge bg-danger me-2\'>" + d.message + "</span>";
                }
            })
            .catch(function() {
                btn.disabled = false;
                result.innerHTML = "<span class=\'badge bg-danger me-2\'>Erreur.</span>";
            });
        });
    })();
    </script>';
}

/**
 * Initialise les blocs et champs Additional Fields nécessaires au plugin
 * Vérifie l'existence avant création, tolère les IDs différents
 */
function plugin_dnsmanager_init_fields(): void
{
    global $DB;

    // Vérifier que le plugin Fields est actif
    if (!\Plugin::isPluginActive('fields')) return;

    // Tables du plugin Fields
    $tContainers = 'glpi_plugin_fields_containers';
    $tFields     = 'glpi_plugin_fields_fields';

    if (!$DB->tableExists($tContainers) || !$DB->tableExists($tFields)) return;

    // Définition des 3 blocs et leurs champs
    $blocks = [
        [
            'name'     => 'Domaine Administratif Complement',
            'itemtype' => 'Domain',
            'type'     => 'dom',
            'table'    => 'glpi_plugin_fields_domaindomaineadministratifcomplements',
            'fields'   => [
                ['label' => 'Ne pas Renouveler', 'type' => 'yesno',             'name' => 'nepasrenouvelerfield', 'ranking' => 1, 'default_value' => '0'],
                ['label' => 'Averti',             'type' => 'yesno',             'name' => 'avertifield',          'ranking' => 2, 'default_value' => '0'],
                ['label' => 'Fournisseur',        'type' => 'dropdown-Supplier', 'name' => 'suppliers_id_fournisseurfieldtwo', 'ranking' => 3, 'default_value' => '0'],
            ],
        ],
        [
            'name'     => 'Facturation',
            'itemtype' => 'Domain',
            'type'     => 'tab',
            'table'    => 'glpi_plugin_fields_domainfacturations',
            'fields'   => [
                ['label' => 'Ref article',          'type' => 'text',   'name' => 'refarticlefield',           'ranking' => 1, 'default_value' => ''],
                ['label' => 'Quantité',             'type' => 'number', 'name' => 'quantitfield',              'ranking' => 2, 'default_value' => '1'],
                ['label' => 'Date de renouvellement', 'type' => 'date', 'name' => 'datederenouvellementfield', 'ranking' => 3, 'default_value' => ''],
            ],
        ],
        [
            'name'     => 'Administratif Domaine',
            'itemtype' => 'Domain',
            'type'     => 'tab',
            'table'    => 'glpi_plugin_fields_domainadministratifdomaines',
            'fields'   => [
                ['label' => 'Proprietaire',        'type' => 'dropdown-User',                           'name' => 'users_id_proprietairefield',                          'ranking' => 1, 'default_value' => '0'],
                ['label' => 'Proprietaire lock',   'type' => 'yesno',                                   'name' => 'proprietairelockfield',                               'ranking' => 2, 'default_value' => '0'],
                ['label' => 'Administrateur',      'type' => 'dropdown-GlpiPlugin\\Accounts\\Account', 'name' => 'plugin_accounts_accounts_id_administrateurfield',   'ranking' => 3, 'default_value' => '0'],
                ['label' => 'Administrateur lock', 'type' => 'yesno',                                   'name' => 'administrateurlockfield',                             'ranking' => 4, 'default_value' => '0'],
                ['label' => 'Technicien',          'type' => 'dropdown-GlpiPlugin\\Accounts\\Account', 'name' => 'plugin_accounts_accounts_id_technicienfield',       'ranking' => 5, 'default_value' => '0'],
                ['label' => 'Technicien lock',     'type' => 'yesno',                                   'name' => 'technicienlockfield',                                 'ranking' => 6, 'default_value' => '0'],
                ['label' => 'Financier',           'type' => 'dropdown-GlpiPlugin\\Accounts\\Account', 'name' => 'plugin_accounts_accounts_id_financierfield',        'ranking' => 7, 'default_value' => '0'],
                ['label' => 'Financier lock',      'type' => 'yesno',                                   'name' => 'financierlockfield',                                  'ranking' => 8, 'default_value' => '0'],
                ['label' => "Compte d'hébergeur", 'type' => 'dropdown-GlpiPlugin\\Dnsmanager\\Account', 'name' => 'plugin_dnsmanager_accounts_id_comptedhbergeurfield', 'ranking' => 9, 'default_value' => '0'],
            ],
        ],
    ];

    foreach ($blocks as $blockDef) {
        // Chercher le bloc par nom ET itemtype
        // Chercher par label (nom lisible) car 'name' est le slug sans espaces
        $container = null;
        foreach ($DB->request(['FROM' => $tContainers, 'WHERE' => ['label' => $blockDef['name']]]) as $row) {
            $itemtypes = json_decode($row['itemtypes'] ?? '[]', true);
            if (in_array($blockDef['itemtype'], (array)$itemtypes)) {
                $container = $row;
                break;
            }
        }

        if (!$container) {
            // Créer le bloc
            $DB->insert($tContainers, [
                'name'        => $blockDef['name'],
                'label'       => $blockDef['name'],
                'itemtypes'   => json_encode([$blockDef['itemtype']]),
                'type'        => $blockDef['type'],
                'subtype'     => null,
                'is_active'   => 1,
                'is_recursive' => 1,
                'entities_id' => 0,
            ]);
            $containerId = (int)$DB->insertId();

            // Créer la table du bloc si elle n'existe pas
            if (!$DB->tableExists($blockDef['table'])) {
                $tableName = $blockDef['table'];
                $DB->doQuery("CREATE TABLE IF NOT EXISTS `$tableName` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `items_id` int(10) unsigned NOT NULL DEFAULT 0,
                    `itemtype` varchar(255) DEFAULT '{$blockDef['itemtype']}',
                    `plugin_fields_containers_id` int(10) unsigned NOT NULL DEFAULT $containerId,
                    `entities_id` int(10) unsigned NOT NULL DEFAULT 0,
                    `is_recursive` tinyint(4) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `entities_id` (`entities_id`),
                    KEY `is_recursive` (`is_recursive`),
                    KEY `itemtype` (`itemtype`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } else {
            $containerId = (int)$container['id'];
        }

        // Vérifier et créer les champs manquants
        foreach ($blockDef['fields'] as $fieldDef) {
            $existing = $DB->request([
                'FROM'  => $tFields,
                'WHERE' => ['name' => $fieldDef['name'], 'plugin_fields_containers_id' => $containerId],
            ])->current();

            if (!$existing) {
                $DB->insert($tFields, [
                    'name'                        => $fieldDef['name'],
                    'label'                       => $fieldDef['label'],
                    'type'                        => $fieldDef['type'],
                    'plugin_fields_containers_id' => $containerId,
                    'ranking'                     => $fieldDef['ranking'],
                    'default_value'               => $fieldDef['default_value'],
                    'mandatory'                   => 0,
                    'is_active'                   => 1,
                    'is_readonly'                 => 0,
                    'multiple'                    => 0,
                ]);

                // Ajouter la colonne dans la table du bloc
                $tableName = $blockDef['table'];
                if ($DB->tableExists($tableName) && !$DB->fieldExists($tableName, $fieldDef['name'])) {
                    $colType = match(true) {
                        str_starts_with($fieldDef['type'], 'dropdown-') => 'int(10) unsigned NOT NULL DEFAULT 0',
                        $fieldDef['type'] === 'yesno'                   => 'int(11) NOT NULL DEFAULT 0',
                        $fieldDef['type'] === 'number'                  => 'varchar(255) DEFAULT NULL',
                        $fieldDef['type'] === 'date'                    => 'varchar(255) DEFAULT NULL',
                        default                                         => 'varchar(255) DEFAULT NULL',
                    };
                    $DB->doQuery("ALTER TABLE `$tableName` ADD COLUMN `{$fieldDef['name']}` $colType");
                }
            }
        }
    }
}

