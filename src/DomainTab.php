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

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Domain)) return '';
        if (!Session::haveRight('plugin_dnsmanager_sync', UPDATE)) return '';
        if (!self::getAccountForDomain((int)$item->getID())) return '';
        return self::createTabEntry(__('DNS Sync', 'dnsmanager'));
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!($item instanceof Domain)) return false;

        $domainId  = (int)$item->getID();
        $accountId = self::getAccountForDomain($domainId);

        if (!$accountId) {
            echo "<div class='alert alert-warning m-3'>";
            echo __("Ce domaine n'est associé à aucun compte DNS Hébergeurs.", 'dnsmanager');
            echo "</div>";
            return true;
        }

        global $DB;
        $mapping = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['domains_id' => $domainId],
        ])->current();

        $account = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_accounts',
            'WHERE' => ['id' => $accountId],
        ])->current();

        $webdir   = Plugin::getWebDir('dnsmanager');
        $syncUrl  = $webdir . '/ajax/sync.php';
        $lastSync = $mapping['last_sync_at'] ?? __('Jamais', 'dnsmanager');
        $ref      = htmlspecialchars($mapping['provider_ref'] ?? '');
        $accName  = htmlspecialchars($account['name'] ?? '');

        echo "<div class='container-fluid mt-3'>";
        echo "<div class='row'><div class='col-12 col-lg-6'>";
        echo "<div class='card'>";
        echo "<div class='card-header'><h3>" . __('Synchronisation DNS', 'dnsmanager') . "</h3></div>";
        echo "<div class='card-body'>";
        echo "<dl class='row'>";
        echo "<dt class='col-sm-5'>" . __('Compte DNS', 'dnsmanager') . "</dt>";
        echo "<dd class='col-sm-7'><a href='{$webdir}/front/account.form.php?id={$accountId}'>{$accName}</a></dd>";
        echo "<dt class='col-sm-5'>" . __('Référence provider', 'dnsmanager') . "</dt>";
        echo "<dd class='col-sm-7'>{$ref}</dd>";
        echo "<dt class='col-sm-5'>" . __('Dernière sync', 'dnsmanager') . "</dt>";
        echo "<dd class='col-sm-7'>{$lastSync}</dd>";
        echo "</dl>";
        echo "<div id='dns-domain-sync-result' class='mb-3'></div>";
        echo "<button type='button' class='btn btn-primary' id='dns-domain-sync-btn'
            data-account-id='{$accountId}'
            data-domain-ref='{$ref}'
            data-sync-url='{$syncUrl}'>";
        echo "<i class='ti ti-refresh me-1'></i>" . __('Synchroniser ce domaine', 'dnsmanager');
        echo "</button>";
        echo "</div></div></div></div></div>";
        echo "<script src='{$webdir}/public/domain.sync.js'></script>";

        return true;
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
