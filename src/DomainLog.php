<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Journal de synchronisation PAR DOMAINE : une ligne à chaque passage (tâche automatique, synchro
 * rapide, import...), avec le nombre d'enregistrements créés / modifiés et les erreurs éventuelles.
 * Le nombre de passages conservés par domaine est réglable dans la configuration du plugin
 * (PluginConfig::getDomainLogDepth, 20 par défaut) ; les plus anciens sont supprimés à l'écriture.
 */
final class DomainLog
{
    public const TABLE = 'glpi_plugin_dnsmanager_domainlogs';

    public static function record(
        int $domainsId,
        int $accountsId,
        string $origin,
        string $status,
        int $added,
        int $updated,
        ?string $errors = null
    ): void {
        global $DB;
        $DB->insert(self::TABLE, [
            'domains_id'      => $domainsId,
            'accounts_id'     => $accountsId,
            'run_at'          => date('Y-m-d H:i:s'),
            'origin'          => substr($origin, 0, 20),
            'status'          => substr($status, 0, 20),
            'records_added'   => max(0, $added),
            'records_updated' => max(0, $updated),
            'error_log'       => ($errors === null || $errors === '') ? null : mb_substr($errors, 0, 8000),
        ]);
        self::purge($domainsId);
    }

    /** Supprime les passages au-delà de la profondeur configurée pour ce domaine */
    public static function purge(int $domainsId): void
    {
        global $DB;
        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => self::TABLE,
            'WHERE'  => ['domains_id' => $domainsId],
            'ORDER'  => 'id DESC',
            'START'  => PluginConfig::getDomainLogDepth(),
            'LIMIT'  => 1000,
        ]) as $row) {
            $ids[] = (int)$row['id'];
        }
        if ($ids !== []) {
            $DB->delete(self::TABLE, ['id' => $ids]);
        }
    }

    /** @return array<int, array<string, mixed>> du plus récent au plus ancien */
    public static function forDomain(int $domainsId, int $limit = 100): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['domains_id' => $domainsId], 'ORDER' => 'id DESC', 'LIMIT' => $limit]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function originLabel(string $origin): string
    {
        switch ($origin) {
            case 'cron':   return __('Tâche automatique', 'dnsmanager');
            case 'quick':  return __('Sync rapide', 'dnsmanager');
            case 'import': return __('Import', 'dnsmanager');
            default:       return __('Manuelle', 'dnsmanager');
        }
    }

    public static function statusBadge(string $status): string
    {
        switch ($status) {
            case 'success': return '<span class="badge bg-success">' . __('OK', 'dnsmanager') . '</span>';
            case 'partial': return '<span class="badge bg-warning text-dark">' . __('Partiel', 'dnsmanager') . '</span>';
            case 'failed':  return '<span class="badge bg-danger">' . __('Échec', 'dnsmanager') . '</span>';
            default:        return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
        }
    }

    /** Tableau HTML du journal d'un domaine */
    public static function render(array $rows): string
    {
        if ($rows === []) {
            return "<div class='p-3 text-muted'>" . __('Aucune synchronisation enregistrée pour ce domaine.', 'dnsmanager') . "</div>";
        }
        $out  = "<div class='table-responsive'><table class='table table-sm table-striped mb-0'><thead><tr>";
        foreach ([__('Date', 'dnsmanager'), __('Origine', 'dnsmanager'), __('État', 'dnsmanager'),
                  __('Créés', 'dnsmanager'), __('Modifiés', 'dnsmanager'), __('Erreurs', 'dnsmanager')] as $col) {
            $out .= '<th>' . $col . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr>';
            $out .= '<td>' . \Html::convDateTime($r['run_at']) . '</td>';
            $out .= '<td>' . htmlspecialchars(self::originLabel((string)$r['origin'])) . '</td>';
            $out .= '<td>' . self::statusBadge((string)$r['status']) . '</td>';
            $out .= '<td>' . (int)$r['records_added'] . '</td>';
            $out .= '<td>' . (int)$r['records_updated'] . '</td>';
            $out .= '<td>';
            if (!empty($r['error_log'])) {
                $id   = 'dlog-' . (int)$r['id'];
                $out .= "<button class='btn btn-sm btn-outline-danger' data-bs-toggle='collapse' data-bs-target='#{$id}'>"
                    . "<i class='ti ti-alert-triangle me-1'></i>" . __('Voir', 'dnsmanager') . "</button>"
                    . "<div class='collapse' id='{$id}'><pre class='mt-2 text-danger small'>" . htmlspecialchars((string)$r['error_log']) . "</pre></div>";
            } else {
                $out .= "<span class='text-success'>—</span>";
            }
            $out .= '</td></tr>';
        }
        return $out . '</tbody></table></div>';
    }
}
