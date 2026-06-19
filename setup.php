<?php

/**
 * DNSManage - Plugin GLPI 11 de gestion DNS multi-provider
 */

define('PLUGIN_DNSMANAGER_VERSION', '3.0.6');
define('PLUGIN_DNSMANAGER_MIN_GLPI', '11.0.0');
define('PLUGIN_DNSMANAGER_MAX_GLPI', '12.0.0');

use Glpi\Plugin\Hooks;
use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Synclog;
use GlpiPlugin\Dnsmanager\Right;
use GlpiPlugin\Dnsmanager\PluginConfig;
use GlpiPlugin\Dnsmanager\DomainTab;

function plugin_init_dnsmanager(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['dnsmanager'] = true;

    if (!Session::getLoginUserID()) {
        return;
    }

    // Menu dans "Outils"
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['dnsmanager'] = [
        'tools' => Account::class,
    ];

    // Actions massives via hook (compatible namespace GLPI 11)
    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['dnsmanager'] = 1;
    $PLUGIN_HOOKS['massiveactions']['dnsmanager'] = 'plugin_dnsmanager_MassiveActions';

    // Enregistrer la classe pour que getItemForItemtype() la trouve
    Plugin::registerClass(Account::class, [
        'dropdown_itemtypes' => true,  // Expose Account dans les dropdowns Additional Fields
    ]);

    // Alias pour compatibilité MassiveAction GLPI 11
    // getItemForItemtype() peut chercher avec l'ancien nom sans namespace
    if (!class_exists('PluginDnsmanagerAccount', false)) {
        class_alias(Account::class, 'PluginDnsmanagerAccount');
    }

    // Tâche CRON
    $PLUGIN_HOOKS['cron']['dnsmanager'] = Synclog::class;

    // Charger le JS du formulaire uniquement sur account.form.php
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'dnsmanager/front/account.form.php') !== false) {
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['dnsmanager'][] = 'public/account.form.js';
    }

    // Onglet "DNS Sync" dans les profils
    Plugin::registerClass(Right::class, ['addtabon' => 'Profile']);

    $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['dnsmanager'] = 'plugin_dnsmanager_post_item_form';

    // Initialiser les droits dans la session courante
    Right::initProfile();

    // Page de configuration (icône clé anglaise dans liste des plugins)
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['dnsmanager'] = 'front/config.php';

    // Exposer Account dans le plugin Fields (champ dropdown)
    if (\Plugin::isPluginActive('fields') && Session::haveRight('plugin_dnsmanager_account', READ)) {
        $PLUGIN_HOOKS['plugin_fields']['dnsmanager'] = Account::class;
    }
}

function plugin_version_dnsmanager(): array
{
    return [
        'name'         => 'DNS Hébergeurs',
        'version'      => PLUGIN_DNSMANAGER_VERSION,
        'author'       => 'L. Berthaud, Claude (Anthropic)',
        'license'      => 'GPL v2+',
        'homepage'     => 'https://github.com/belu33fr/dnsmanager',
        'bugtracker'   => 'https://github.com/belu33fr/dnsmanager/issues',
        'readme'       => 'https://github.com/belu33fr/dnsmanager/blob/main/docs/user-guide.md',
        'logo'         => '/plugins/dnsmanager/pics/icon.png',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DNSMANAGER_MIN_GLPI,
                'max' => PLUGIN_DNSMANAGER_MAX_GLPI,
            ],
            'php' => [
                'min'  => '8.1',
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
