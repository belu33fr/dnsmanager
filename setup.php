<?php
/**
 * DNSManage - Plugin GLPI de gestion DNS multi-provider
 *
 * @author    DNSManage
 * @license   GPL-2.0+
 * @version   1.0.0
 */

define('PLUGIN_DNSMANAGE_VERSION', '1.1.0');
define('PLUGIN_DNSMANAGE_MIN_GLPI', '11.0.0');
define('PLUGIN_DNSMANAGE_MAX_GLPI', '12.0.0');

/**
 * Init du plugin - appelé à chaque chargement de page GLPI
 */
function plugin_init_dnsmanager(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['dnsmanager'] = true;

    // ------------------------------------------------------------------
    // Autoloader — uniquement les fichiers qui existent réellement
    // ------------------------------------------------------------------
    spl_autoload_register(function (string $class): void {
        $map = [
            'PluginDnsmanageAccount'           => 'inc/account.class.php',
            'PluginDnsmanageCredential'        => 'inc/credential.class.php',
            'PluginDnsmanageSynclog'           => 'inc/synclog.class.php',
            'PluginDnsmanageImporter'          => 'inc/importer.class.php',
            'PluginDnsmanageConfig'            => 'inc/config.class.php',
            'PluginDnsmanageMenu'              => 'inc/menu.class.php',
            'PluginDnsmanageProviderInterface' => 'inc/providers/ProviderInterface.php',
            'PluginDnsmanageProviderFactory'   => 'inc/providers/ProviderFactory.php',
            'PluginDnsmanageOvhProvider'       => 'inc/providers/OvhProvider.php',
        ];

        if (isset($map[$class])) {
            $file = Plugin::getPhpDir('dnsmanager') . '/' . $map[$class];
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });

    if (!Session::getLoginUserID()) {
        return;
    }

    // ------------------------------------------------------------------
    // Déclaration du menu dans GLPI 11
    // GLPI 11 utilise getMenuContent() sur la classe déclarée ici.
    // La clé du tableau doit être le nom court du plugin.
    // ------------------------------------------------------------------
    $PLUGIN_HOOKS['menu_toadd']['dnsmanager'] = [
        'tools' => 'PluginDnsmanageMenu',
    ];

    // Tâche CRON
    $PLUGIN_HOOKS['cron']['dnsmanager'] = 'PluginDnsmanageSynclog';
}

/**
 * Informations du plugin
 */
function plugin_version_dnsmanager(): array
{
    return [
        'name'         => 'DNSManage',
        'version'      => PLUGIN_DNSMANAGE_VERSION,
        'author'       => 'DNSManage',
        'license'      => 'GPL v2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DNSMANAGE_MIN_GLPI,
                'max' => PLUGIN_DNSMANAGE_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.1',
                'exts' => ['curl', 'json', 'openssl'],
            ],
        ],
    ];
}

function plugin_dnsmanager_check_prerequisites(): bool
{
    if (!extension_loaded('curl')) {
        echo "Extension PHP curl requise.<br/>";
        return false;
    }
    if (!extension_loaded('openssl')) {
        echo "Extension PHP openssl requise.<br/>";
        return false;
    }
    return true;
}

function plugin_dnsmanager_check_config(): bool
{
    return true;
}
