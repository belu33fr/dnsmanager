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
        $allowed = ['entity_labels', 'entity_children_labels', 'contact_account_type', 'domain_lock_timeout', 'windowsdns_internal_tlds', 'windowsdns_domain_type'];
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

    public static function getEntityChildrenLabels(): array
    {
        $val = self::getConfig('entity_children_labels', '');
        if (empty(trim($val))) return [];
        return array_filter(array_map('trim', explode(';', $val)));
    }

    /**
     * Nom du type de compte (glpi_plugin_accounts_accounttypes) utilisé pour
     * filtrer les dropdowns Administrateur/Technicien/Financier et pour la
     * création automatique de comptes contact depuis le registrar.
     */
    /** Extensions internes acceptées pour les zones d'un serveur DNS Windows (séparées par ;) */
    public static function getWindowsInternalTlds(): string
    {
        $val = trim((string)self::getConfig('windowsdns_internal_tlds', WindowsDnsRules::DEFAULT_INTERNAL_TLDS));
        return $val !== '' ? $val : WindowsDnsRules::DEFAULT_INTERNAL_TLDS;
    }

    /** Nom du type de domaine GLPI attribué aux zones d'un serveur DNS Windows / Active Directory */
    public static function getAdDomainTypeName(): string
    {
        $val = trim((string)self::getConfig('windowsdns_domain_type', 'DNS AD'));
        return $val !== '' ? $val : 'DNS AD';
    }

    public static function getContactAccountType(): string
    {
        $val = trim(self::getConfig('contact_account_type', 'Fournisseur'));
        return $val !== '' ? $val : 'Fournisseur';
    }

    /**
     * Timeout (en secondes) du verrou par domaine pour la modification
     * bidirectionnelle des enregistrements DNS. Configurable car peut
     * varier selon l'environnement et le provider (lenteur API, charge serveur).
     */
    public static function getDomainLockTimeout(): int
    {
        $val = (int)self::getConfig('domain_lock_timeout', 20);
        return $val > 0 ? $val : 20;
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
        $helpEntityLabels = __('Entités dont les parents sont considérés comme étant le lieu d\'import des domaines. Exemple : GAFA > Sites > Bordeaux, et qu\'on veut GAFA (pas Sites et en dessous) : il faut mettre "Sites" dans ce champ.', 'dnsmanager');
        echo "<div class='mb-3 row'>";
        echo "<label class='col-xxl-4 col-form-label'>";
        echo __('Intitulés entités import (séparés par ;)', 'dnsmanager');
        echo " <i class='ti ti-info-circle text-muted' data-bs-toggle='tooltip' title='" . htmlspecialchars($helpEntityLabels) . "'></i>";
        echo "</label>";
        echo "<div class='col-xxl-8'>";
        if ($canedit) {
            echo "<input type='text' name='entity_labels' class='form-control' value='" . htmlspecialchars($entityLabels) . "'>";
        } else {
            echo "<span class='form-control-plaintext'>" . htmlspecialchars($entityLabels) . "</span>";
        }
        echo "</div></div>";

        // Champ entity_children_labels
        $entityChildrenLabels = self::getConfig('entity_children_labels', '');
        $helpEntityChildrenLabels = __('Entités dont les enfants sont considérés comme étant le lieu d\'import des domaines. Exemple : GAFA > Sites > Bordeaux, et qu\'on veut Bordeaux (pas Sites et au-dessus) : il faut mettre "Sites" dans ce champ. Si "Sites" est aussi marqué dans Intitulés entités import, alors GAFA et Bordeaux seront tous les deux proposés. S\'applique à toute l\'arborescence des entités.', 'dnsmanager');
        echo "<div class='mb-3 row'>";
        echo "<label class='col-xxl-4 col-form-label'>";
        echo __('Intitulés enfants import (séparés par ;)', 'dnsmanager');
        echo " <i class='ti ti-info-circle text-muted' data-bs-toggle='tooltip' title='" . htmlspecialchars($helpEntityChildrenLabels) . "'></i>";
        echo "</label>";
        echo "<div class='col-xxl-8'>";
        if ($canedit) {
            echo "<input type='text' name='entity_children_labels' class='form-control' value='" . htmlspecialchars($entityChildrenLabels) . "'>";
        } else {
            echo "<span class='form-control-plaintext'>" . htmlspecialchars($entityChildrenLabels) . "</span>";
        }
        echo "</div></div>";

        // Champ contact_account_type
        $contactAccountType = self::getConfig('contact_account_type', 'Fournisseur');
        $helpContactAccountType = __('Nom exact du type (Configuration → Comptes → Type de compte). Utilisé pour filtrer les listes Administrateur/Technicien/Financier et pour la création automatique de comptes contact.', 'dnsmanager');
        echo "<div class='mb-3 row'>";
        echo "<label class='col-xxl-4 col-form-label'>";
        echo __('Type de compte pour les contacts registrar', 'dnsmanager');
        echo " <i class='ti ti-info-circle text-muted' data-bs-toggle='tooltip' title='" . htmlspecialchars($helpContactAccountType) . "'></i>";
        echo "</label>";
        echo "<div class='col-xxl-8'>";
        if ($canedit) {
            echo "<input type='text' name='contact_account_type' class='form-control' value='" . htmlspecialchars($contactAccountType) . "'>";
        } else {
            echo "<span class='form-control-plaintext'>" . htmlspecialchars($contactAccountType) . "</span>";
        }
        echo "</div></div>";

        // Serveur DNS Windows : extensions internes acceptées
        $winTlds = self::getWindowsInternalTlds();
        $helpWinTlds = __('Extensions de domaine propres à votre réseau, acceptées en plus des extensions Internet pour les zones d\'un serveur DNS Windows (ex. local;interne;lan). Une zone dont l\'extension n\'est ni Internet ni listée ici est ignorée.', 'dnsmanager');
        echo "<div class='mb-3 row'>";
        echo "<label class='col-xxl-4 col-form-label'>";
        echo __('Extensions internes (serveur DNS Windows, séparées par ;)', 'dnsmanager');
        echo " <i class='ti ti-info-circle text-muted' data-bs-toggle='tooltip' title='" . htmlspecialchars($helpWinTlds) . "'></i>";
        echo "</label>";
        echo "<div class='col-xxl-8'>";
        if ($canedit) {
            echo "<input type='text' name='windowsdns_internal_tlds' class='form-control' value='" . htmlspecialchars($winTlds) . "'>";
        } else {
            echo "<span class='form-control-plaintext'>" . htmlspecialchars($winTlds) . "</span>";
        }
        echo "</div></div>";

        // Serveur DNS Windows : type de domaine GLPI
        $adType = self::getAdDomainTypeName();
        $helpAdType = __('Type de domaine GLPI attribué aux zones d\'un serveur DNS Windows / Active Directory, pour les distinguer des domaines Internet de même nom (créé automatiquement s\'il n\'existe pas).', 'dnsmanager');
        echo "<div class='mb-3 row'>";
        echo "<label class='col-xxl-4 col-form-label'>";
        echo __('Type de domaine des zones Windows / AD', 'dnsmanager');
        echo " <i class='ti ti-info-circle text-muted' data-bs-toggle='tooltip' title='" . htmlspecialchars($helpAdType) . "'></i>";
        echo "</label>";
        echo "<div class='col-xxl-8'>";
        if ($canedit) {
            echo "<input type='text' name='windowsdns_domain_type' class='form-control' value='" . htmlspecialchars($adType) . "'>";
        } else {
            echo "<span class='form-control-plaintext'>" . htmlspecialchars($adType) . "</span>";
        }
        echo "</div></div>";

        // Champ domain_lock_timeout
        $lockTimeout = self::getConfig('domain_lock_timeout', 20);
        $helpLockTimeout = __('Délai maximal d\'attente lors d\'un conflit entre synchronisation et modification manuelle d\'un enregistrement DNS (modification bidirectionnelle). Défaut : 20s.', 'dnsmanager');
        echo "<div class='mb-3 row'>";
        echo "<label class='col-xxl-4 col-form-label'>";
        echo __('Timeout verrou domaine (secondes)', 'dnsmanager');
        echo " <i class='ti ti-info-circle text-muted' data-bs-toggle='tooltip' title='" . htmlspecialchars($helpLockTimeout) . "'></i>";
        echo "</label>";
        echo "<div class='col-xxl-8'>";
        if ($canedit) {
            echo "<input type='number' min='1' name='domain_lock_timeout' class='form-control' style='max-width:150px' value='" . htmlspecialchars($lockTimeout) . "'>";
        } else {
            echo "<span class='form-control-plaintext'>" . htmlspecialchars($lockTimeout) . "</span>";
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

        // Filet de sécurité : initialiser les tooltips Bootstrap si GLPI ne le fait pas déjà
        // automatiquement pour ce contenu (sans effet si déjà initialisés par le core).
        echo "<script>
        document.querySelectorAll('[data-bs-toggle=\"tooltip\"]').forEach(function(el) {
            if (window.bootstrap && !bootstrap.Tooltip.getInstance(el)) {
                new bootstrap.Tooltip(el);
            }
        });
        </script>";
    }
}
