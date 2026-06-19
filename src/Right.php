<?php

namespace GlpiPlugin\Dnsmanager;

use CommonGLPI;
use DbUtils;
use Html;
use Profile;
use ProfileRight;
use Session;

class Right extends Profile
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return 'DNS Hébergeurs';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === 'Profile' && $item->getField('interface') === 'central') {
            return self::createTabEntry('DNS Sync');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() === 'Profile') {
            $ID   = $item->getID();
            $prof = new self();

            // Initialiser les droits manquants avec 0
            $profileRight = new ProfileRight();
            $dbu = new DbUtils();
            foreach (['plugin_dnsmanager_account', 'plugin_dnsmanager_config', 'plugin_dnsmanager_sync', 'plugin_dnsmanager_cache'] as $right) {
                if ($dbu->countElementsInTable('glpi_profilerights', ['profiles_id' => $ID, 'name' => $right]) === 0) {
                    $profileRight->add(['profiles_id' => $ID, 'name' => $right, 'rights' => 0]);
                }
            }

            $prof->showForm($ID);
        }
        return true;
    }

    public function showForm($profiles_id = 0, $openform = true, $closeform = true)
    {
        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        ob_start();
        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('DNS Hébergeurs', 'dnsmanager'),
        ]);
        $rights_matrix = ob_get_clean();

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@dnsmanager/profile.html.twig',
            [
                'canedit'          => $canedit,
                'profile_form_url' => $profile->getFormURL(),
                'rights_matrix'    => $rights_matrix,
                'profiles_id'      => $profiles_id,
            ]
        );

        return true;
    }

    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => Account::class,
                'label'    => __('Comptes DNS', 'dnsmanager'),
                'field'    => 'plugin_dnsmanager_account',
            ],
            [
                'itemtype' => PluginConfig::class,
                'label'    => __('Paramétrage', 'dnsmanager'),
                'field'    => 'plugin_dnsmanager_config',
                'rights'   => [READ => __('Lire'), UPDATE => __('Modifier')],
            ],
            [
                'itemtype' => Synclog::class,
                'label'    => __('Synchronisation', 'dnsmanager'),
                'field'    => 'plugin_dnsmanager_sync',
                'rights'   => [READ => __('Voir journal'), UPDATE => __('Exécuter')],
            ],
            [
                'itemtype' => PluginConfig::class,
                'label'    => __('Vider le cache', 'dnsmanager'),
                'field'    => 'plugin_dnsmanager_cache',
                'rights'   => [UPDATE => __('Autoriser')],
            ],
        ];
    }

    public static function addDefaultProfileRights(): void
    {
        global $DB;

        $defaults = [
            'Super-Admin' => [
                'plugin_dnsmanager_account' => ALLSTANDARDRIGHT,
                'plugin_dnsmanager_config'  => READ | UPDATE,
                'plugin_dnsmanager_sync'    => READ | UPDATE,
                'plugin_dnsmanager_cache'   => UPDATE,
            ],
            'Admin' => [
                'plugin_dnsmanager_account' => ALLSTANDARDRIGHT,
                'plugin_dnsmanager_config'  => 0,
                'plugin_dnsmanager_sync'    => READ | UPDATE,
                'plugin_dnsmanager_cache'   => 0,
            ],
            'Observer' => [
                'plugin_dnsmanager_account' => READ,
                'plugin_dnsmanager_config'  => 0,
                'plugin_dnsmanager_sync'    => 0,
                'plugin_dnsmanager_cache'   => 0,
            ],
        ];

        $profileRight = new ProfileRight();
        $dbu          = new DbUtils();

        foreach ($DB->request(['FROM' => 'glpi_profiles']) as $profile) {
            $profileId = (int)$profile['id'];
            $rights    = $defaults[$profile['name']] ?? [
                'plugin_dnsmanager_account' => 0,
                'plugin_dnsmanager_config'  => 0,
                'plugin_dnsmanager_sync'    => 0,
                'plugin_dnsmanager_cache'   => 0,
            ];

            foreach ($rights as $rightName => $value) {
                if ($dbu->countElementsInTable('glpi_profilerights', ['profiles_id' => $profileId, 'name' => $rightName]) === 0) {
                    $profileRight->add(['profiles_id' => $profileId, 'name' => $rightName, 'rights' => $value]);
                } else {
                    // Mettre à jour si les droits sont à 0 (installation fraîche)
                    $DB->update('glpi_profilerights',
                        ['rights' => $value],
                        ['profiles_id' => $profileId, 'name' => $rightName, 'rights' => 0]
                    );
                }
            }
        }
    }

    public static function removeProfileRights(): void
    {
        $rights = array_column(self::getAllRights(), 'field');
        ProfileRight::deleteProfileRights($rights);
    }

    public static function initProfile(): void
    {
        global $DB;
        foreach (self::getAllRights() as $data) {
            $dbu = new DbUtils();
            if ($dbu->countElementsInTable('glpi_profilerights', ['name' => $data['field']]) === 0) {
                ProfileRight::addProfileRights([$data['field']]);
            }
        }

        $profileId = $_SESSION['glpiactiveprofile']['id'] ?? 0;
        error_log("DNSManage initProfile: profileId=$profileId user=" . ($_SESSION['glpiname'] ?? 'unknown'));
        if ($profileId) {
            foreach ($DB->request([
                'FROM'  => 'glpi_profilerights',
                'WHERE' => ['profiles_id' => $profileId, 'name' => ['LIKE', '%plugin_dnsmanager%']],
            ]) as $prof) {
                $_SESSION['glpiactiveprofile'][$prof['name']] = $prof['rights'];
                error_log("DNSManage initProfile: set {$prof['name']}={$prof['rights']}");
            }
        }
    }

    // Helpers
    public static function canConfig(): bool { return Session::haveRight('plugin_dnsmanager_config', UPDATE); }
    public static function canSync(): bool   { return Session::haveRight('plugin_dnsmanager_sync',   UPDATE); }
    public static function canCache(): bool  { return Session::haveRight('plugin_dnsmanager_cache',  UPDATE); }
}
