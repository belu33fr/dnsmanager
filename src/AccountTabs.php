<?php

namespace GlpiPlugin\Dnsmanager;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;

/**
 * Onglets natifs GLPI de la fiche d'un hébergeur : Extension, Contacts par défaut, Authentification.
 * Chaque onglet est un formulaire à part entière (son propre bouton Enregistrer) : l'enregistrement
 * ne met à jour que les champs de l'onglet (voir front/account.form.php). Ils n'existent que pour un
 * compte déjà créé : à la création, seule la fiche de base est saisie.
 */
class AccountTabs extends CommonGLPI
{
    private const TEMPLATES = [
        1 => 'extension',
        2 => 'contacts',
        3 => 'auth',
    ];

    public static function getTypeName($nb = 0): string
    {
        return __('Hébergeur', 'dnsmanager');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Account) || $item->isNewItem() || !Right::canSync()) {
            return '';
        }
        return [
            1 => __('Extension', 'dnsmanager'),
            2 => __('Contacts par défaut', 'dnsmanager'),
            3 => __('Authentification', 'dnsmanager'),
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof Account) || !Right::canSync() || !isset(self::TEMPLATES[(int)$tabnum])) {
            return false;
        }
        TemplateRenderer::getInstance()->display(
            '@dnsmanager/account.tab.' . self::TEMPLATES[(int)$tabnum] . '.html.twig',
            $item->getTemplateVars()
        );
        return true;
    }
}
