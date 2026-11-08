<?php

namespace GlpiPlugin\Dnsmanager;

use CommonGLPI;
use Domain;
use Session;

/**
 * Menu principal « Gestion DNS » (secteur propre, à côté de Gestion, Outils…).
 * GLPI n'a que deux niveaux (secteur -> entrées) : les entrées sont déclarées ici,
 * en mode « multi-entrées », en un seul endroit. Même principe que Mail Manager.
 *
 * Entrées :
 *  - Hébergeurs : la gestion des comptes DNS Hébergeurs du plugin ;
 *  - Domaines   : l'entrée native de GLPI, déplacée ici depuis le menu Gestion ;
 *  - Journal de synchronisation.
 */
class Menu extends CommonGLPI
{
    public static $rightname = 'plugin_dnsmanager_account';

    /**
     * Secteur GLPI où se trouve le menu. Source unique : setup.php (déclaration du menu)
     * ET les en-têtes de toutes les pages front doivent l'utiliser, sinon GLPI ne
     * retrouve pas le menu et perd les liens de la barre d'en-tête (bouton "Ajouter").
     */
    public const MENU_SECTOR = 'dnsmanager';

    public static function getTypeName($nb = 0): string
    {
        return __('Gestion DNS', 'dnsmanager');
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function getIcon(): string
    {
        return Account::getIcon();
    }

    public static function canView(): bool
    {
        return Session::haveRight(Account::$rightname, READ)
            || Session::haveRight(Domain::$rightname, READ);
    }

    public static function getMenuContent(): array
    {
        $entries = [];

        if (Session::haveRight(Account::$rightname, READ)) {
            $entries['account'] = [
                'title' => __('Hébergeurs', 'dnsmanager'),
                'page'  => Account::getSearchURL(false),
                'icon'  => Account::getIcon(),
                'links' => self::links(Account::getSearchURL(false), Account::getFormURL(false), Account::$rightname),
            ];
        }

        // Domaines natifs de GLPI (libellé et droits : ceux de GLPI). Cette entrée est ensuite
        // remplacée par l'entrée native complète, déplacée depuis Gestion (hook redefine_menus).
        if (Session::haveRight(Domain::$rightname, READ)) {
            $entries['domain'] = [
                'title' => Domain::getTypeName(2),
                'page'  => Domain::getSearchURL(false),
                'icon'  => (string)(Domain::getIcon() ?: 'ti ti-world-www'),
                'links' => self::links(Domain::getSearchURL(false), Domain::getFormURL(false), Domain::$rightname),
            ];
        }

        if (Session::haveRight(Account::$rightname, READ)) {
            $syncUrl = \Plugin::getWebDir('dnsmanager', false) . '/front/synclog.php';
            $entries['synclog'] = [
                'title' => Synclog::getTypeName(2),
                'page'  => $syncUrl,
                'icon'  => 'ti ti-history',
                'links' => ['search' => $syncUrl],
            ];
        }

        if ($entries === []) {
            return [];
        }

        // 'title' et 'icon' définissent le secteur lui-même ; 'is_multi_entries' demande à
        // GLPI de fusionner nos entrées dans le menu (cf. Html::generateMenuSession).
        return [
            'title'            => self::getMenuName(),
            'icon'             => self::getIcon(),
            'default'          => reset($entries)['page'],
            'is_multi_entries' => true,
        ] + $entries;
    }

    private static function links(string $searchUrl, string $formUrl, string $right): array
    {
        $links = ['search' => $searchUrl];
        if (Session::haveRight($right, CREATE)) {
            $links['add'] = $formUrl;
        }
        return $links;
    }
}
