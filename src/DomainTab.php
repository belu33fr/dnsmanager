<?php

namespace GlpiPlugin\Dnsmanager;

use CommonGLPI;
use Domain;
use Plugin;
use Session;

/**
 * Ajoute un onglet et un bouton de synchronisation dans la fiche d'un domaine GLPI
 */
class DomainTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('DNS Sync', 'dnsmanager');
    }

    /**
     * Onglet « Journal de synchro DNS » d'un domaine géré par un compte du plugin (lecture seule).
     * Le bouton de synchronisation du domaine reste dans le formulaire principal (hook POST_ITEM_FORM).
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Domain) || (int)$item->getID() <= 0) return '';
        if (!Session::haveRight('plugin_dnsmanager_sync', READ)) return '';
        if (!self::getAccountForDomain((int)$item->getID())) return '';

        global $DB;
        $count = (int)($DB->request([
            'COUNT' => 'cpt',
            'FROM'  => DomainLog::TABLE,
            'WHERE' => ['domains_id' => (int)$item->getID()],
        ])->current()['cpt'] ?? 0);

        return self::createTabEntry(__('Journal synchro', 'dnsmanager'), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof Domain)) return false;
        echo "<div class='container-fluid mt-3'>";
        self::showDomainLog((int)$item->getID());
        echo "</div>";
        return true;
    }

    /** Journal de synchronisation de ce domaine (les derniers passages, selon la profondeur configurée) */
    private static function showDomainLog(int $domainId): void
    {
        $rows = DomainLog::forDomain($domainId, PluginConfig::getDomainLogDepth());
        echo "<div class='row mt-3'><div class='col-12'><div class='card'>";
        echo "<div class='card-header'><h3>" . __('Journal de synchronisation', 'dnsmanager') . "</h3></div>";
        echo "<div class='card-body p-0'>" . DomainLog::render($rows) . "</div>";
        echo "</div></div></div>";
    }

    /**
     * Hook POST_SHOW_TAB — injecte le bouton dans l'onglet principal Domaine
     */
    public static function injectSyncButton(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!($item instanceof Domain)) return;
        if (!Session::haveRight('plugin_dnsmanager_sync', UPDATE)) return;

        $itemtype = $params['options']['itemtype'] ?? '';
        $tabnum   = (int)($params['options']['tabnum'] ?? -1);

        if ($itemtype !== 'Domain' || $tabnum !== 0) return;

        $domainId  = (int)$item->getID();
        $accountId = self::getAccountForDomain($domainId);
        if (!$accountId) return;

        global $DB;
        $mapping = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['domains_id' => $domainId],
        ])->current();

        $webdir  = Plugin::getWebDir('dnsmanager');
        $syncUrl = $webdir . '/ajax/sync.php';
        $ref     = htmlspecialchars($mapping['provider_ref'] ?? '');

        echo "<div class='d-flex gap-2 mt-2 ms-2' id='dns-domain-sync-wrapper'>";
        echo "<div id='dns-domain-sync-result'></div>";
        echo "<button type='button' class='btn btn-sm btn-primary' id='dns-domain-sync-btn'
            data-account-id='{$accountId}'
            data-domain-ref='{$ref}'
            data-sync-url='{$syncUrl}'>";
        echo "<i class='ti ti-refresh me-1'></i>" . __('Sync DNS', 'dnsmanager');
        echo "</button>";
        echo "</div>";
        echo "<script src='{$webdir}/public/domain.sync.js'></script>";
    }

    public static function getAccountForDomain(int $domainId): ?int
    {
        global $DB;
        $mapping = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['domains_id' => $domainId],
        ])->current();
        return $mapping ? (int)$mapping['accounts_id'] : null;
    }
}
