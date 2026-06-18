<?php

namespace GlpiPlugin\Dnsmanager;

use CommonDBTM;
use Html;
use Session;

/**
 * Page de configuration du plugin (icône clé anglaise)
 */
class PluginConfig extends CommonDBTM
{
    public static $rightname = Right::RIGHT_CONFIG;

    public static function getTypeName($nb = 0): string
    {
        return __('Configuration DNS Hébergeurs', 'dnsmanager');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_configs';
    }

    public static function getConfig(string $key, $default = null)
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['config_key' => $key],
        ])->current();
        return $row ? $row['config_value'] : $default;
    }

    public static function save(array $input): void
    {
        global $DB;
        $allowed = ['entity_labels'];
        foreach ($allowed as $key) {
            if (!isset($input[$key])) continue;
            $existing = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['config_key' => $key],
            ])->current();
            if ($existing) {
                $DB->update(self::getTable(), ['config_value' => $input[$key]], ['id' => $existing['id']]);
            } else {
                $DB->insert(self::getTable(), ['config_key' => $key, 'config_value' => $input[$key]]);
            }
        }
        \Session::addMessageAfterRedirect(__('Configuration enregistrée.', 'dnsmanager'), true, INFO);
    }

    public static function getEntityLabels(): array
    {
        $val = self::getConfig('entity_labels', 'Sites;Financier');
        return array_filter(array_map('trim', explode(';', $val)));
    }

    public static function showConfigForm(): void
    {
        global $DB;
        $canedit   = Right::canConfig();
        $canCache  = Right::canCache();

        echo "<div class='container-fluid mt-3'>";
        echo "<div class='row'>";
        echo "<div class='col-12 col-lg-6'>";

        // Section configuration
        echo "<div class='card mb-4'>";
        echo "<div class='card-header'><h3>" . __('Paramètres généraux', 'dnsmanager') . "</h3></div>";
        echo "<div class='card-body'>";

        if ($canedit) {
            echo "<form method='post' action='" . \Plugin::getWebDir('dnsmanager') . "/front/config.php'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('update', ['value' => 1]);
        }

        // Champ entity_labels
        $entityLabels = self::getConfig('entity_labels', 'Sites;Financier');
        echo "<div class='mb-3 row'>";
        echo "<label class='col-sm-6 col-form-label'>" . __('Intitulés entités import (séparés par ;)', 'dnsmanager') . "</label>";
        echo "<div class='col-sm-6'>";
        if ($canedit) {
            echo "<input type='text' name='entity_labels' class='form-control' value='" . htmlspecialchars($entityLabels) . "'>";
            echo "<div class='form-text text-muted'>" . __('Ex: Sites;Financier — entités dont le parent sera proposé à l\'import', 'dnsmanager') . "</div>";
        } else {
            echo "<span class='form-control-plaintext'>" . htmlspecialchars($entityLabels) . "</span>";
        }
        echo "</div></div>";

        // Trouver l'ID de la tâche SyncAllAccounts
        $cronTask = $DB->request([
            'FROM'  => 'glpi_crontasks',
            'WHERE' => ['name' => 'SyncAllAccounts'],
        ])->current();
        $cronUrl = '/front/crontask.form.php' . ($cronTask ? '?id=' . (int)$cronTask['id'] : '');

        echo "<div class='alert alert-warning mb-3'>";
        echo "<i class='ti ti-alert-triangle me-2'></i>";
        echo __('La désinstallation du plugin supprime uniquement les données DNS (comptes, mappings, logs). Les blocs de champs personnalisés (Facturation, Administratif Domaine, etc.) et leurs données sont conservés.', 'dnsmanager');
        echo "</div>";

        echo "<div class='alert alert-info'>";
        echo "<i class='ti ti-info-circle me-2'></i>";
        echo __('La fréquence de synchronisation automatique est configurable dans', 'dnsmanager');
        echo " <a href='" . $cronUrl . "'>";
        echo __('Configuration → Actions automatiques → SyncAllAccounts', 'dnsmanager');
        echo "</a>.";
        echo "</div>";

        if ($canedit) {
            echo "<button type='submit' class='btn btn-primary mt-2'>";
            echo "<i class='ti ti-device-floppy me-1'></i>" . __('Enregistrer', 'dnsmanager');
            echo "</button>";
            echo "</form>";
        }

        echo "</div></div>";

        // Section cache
        echo "<div class='card mb-4'>";
        echo "<div class='card-header'><h3>" . __('Cache du plugin', 'dnsmanager') . "</h3></div>";
        echo "<div class='card-body'>";

        global $DB;
        $nbRecords = $DB->request(['FROM' => 'glpi_plugin_dnsmanager_records', 'COUNT' => 'id'])->current()['id'] ?? 0;
        echo "<p>" . sprintf(__('%d enregistrement(s) en cache.', 'dnsmanager'), $nbRecords) . "</p>";

        if ($canCache) {
            echo "<form method='post' action='" . \Plugin::getWebDir('dnsmanager') . "/front/config.php'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('clear_cache', ['value' => 1]);
            echo "<button type='submit' class='btn btn-warning' onclick=\"return confirm('" . __('Vider le cache supprimera tous les mappings. La prochaine synchronisation recréera tout. Continuer?', 'dnsmanager') . "')\">";
            echo "<i class='ti ti-trash me-1'></i>" . __('Vider le cache', 'dnsmanager');
            echo "</button>";
            echo "</form>";
        }

        echo "</div></div>";
        echo "</div></div></div>";
    }
}
