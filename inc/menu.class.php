<?php
/**
 * DNSManage - Entrée de menu GLPI 11
 *
 * Dans GLPI 11, pour qu'une entrée apparaisse dans un menu parent (ex: "Outils"),
 * la classe doit :
 *   1. Étendre CommonGLPI
 *   2. Définir $rightname avec un droit existant (ou 'config' pour les admins)
 *   3. Retourner un tableau non-vide depuis getMenuContent()
 *   4. Le hook menu_toadd dans setup.php doit pointer sur cette classe
 */

class PluginDnsmanageMenu extends CommonGLPI
{
    // 'config' est toujours disponible pour les super-admins,
    // ce qui garantit l'apparition du menu même avant de configurer des droits fins.
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return 'DNSManage';
    }

    public static function getMenuName(): string
    {
        return 'DNSManage';
    }

    /**
     * Icône affichée dans le menu GLPI 11 (classe FontAwesome ou ti-*)
     */
    public static function getIcon(): string
    {
        return 'ti ti-world';
    }

    /**
     * getMenuContent() est appelé par GLPI pour construire le sous-menu.
     * Doit retourner un tableau avec au minimum 'title' et 'page'.
     */
    public static function getMenuContent(): array
    {
        $plugin_dir = Plugin::getWebDir('dnsmanager');

        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $plugin_dir . '/front/account.php',
            'icon'    => self::getIcon(),
            'options' => [
                'account' => [
                    'title' => __('Comptes provider', 'dnsmanager'),
                    'page'  => $plugin_dir . '/front/account.php',
                    'links' => [
                        'search' => $plugin_dir . '/front/account.php',
                        'add'    => $plugin_dir . '/front/account.form.php',
                    ],
                ],
                'synclog' => [
                    'title' => __('Journaux de sync', 'dnsmanager'),
                    'page'  => $plugin_dir . '/front/synclog.php',
                    'links' => [
                        'search' => $plugin_dir . '/front/synclog.php',
                    ],
                ],
            ],
        ];

        return $menu;
    }

    // Nécessaire pour que GLPI accepte la classe comme élément de menu
    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }
}
