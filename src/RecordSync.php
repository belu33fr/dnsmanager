<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Gère la modification bidirectionnelle des enregistrements DNS.
 *
 * ÉTAT ACTUEL (étape 7 du plan) : la mécanique de verrouillage/détection
 * (étapes 5+6) est validée, et les vrais appels API sont maintenant branchés
 * pour le provider OVH (createRecord/updateRecord/deleteRecord). Le
 * marquage "échec de synchronisation" (sync_status = 'push_failed') est en
 * place, mais le mécanisme de retry automatique à la prochaine synchro
 * n'est pas encore implémenté (étape 8).
 *
 * Périmètre actuel : OVH uniquement, comptes avec la modification
 * bidirectionnelle explicitement activée (Account::hasBidirectionalSync()).
 */
class RecordSync
{
    /**
     * Champs dont un changement doit déclencher un push vers le provider.
     * Les autres champs (commentaire, entité, etc.) sont des métadonnées
     * internes GLPI sans équivalent DNS.
     */
    private const TRIGGER_FIELDS = ['name', 'domainrecordtypes_id', 'ttl', 'data'];

    /** Clé utilisée dans $item->input pour faire transiter l'état PRE_* → POST_* */
    private const LOCK_MARKER = '_dnsmanage_lock_domain';

    // =========================================================
    // Hooks PRE_ITEM_* (avant écriture — peuvent bloquer l'action)
    // =========================================================

    public static function preAdd(\DomainRecord $item): void
    {
        if (!is_array($item->input)) return; // déjà annulé par un autre hook

        $domainsId = (int)($item->input['domains_id'] ?? 0);
        if (!$domainsId) return;

        if (!self::shouldHandle($domainsId)) return;

        if (self::acquireOrCancel($item, $domainsId, __('cet ajout', 'dnsmanager'))) {
            $item->input[self::LOCK_MARKER] = $domainsId;
        }
    }

    public static function preUpdate(\DomainRecord $item): void
    {
        if (!is_array($item->input)) return;

        $domainsId = (int)($item->fields['domains_id'] ?? 0);
        if (!$domainsId) return;

        if (!self::shouldHandle($domainsId)) return;

        // Ne réagir que si un champ surveillé change réellement
        if (!self::hasTriggerFieldChange($item)) return;

        if (!self::acquireOrCancel($item, $domainsId, __('cette modification', 'dnsmanager'))) {
            return;
        }

        // Le verrou vient d'être acquis (donc toute synchro concurrente vient de finir) :
        // vérifier que ce record précis n'a pas été touché entre-temps.
        if (self::wasRecordTouchedMeanwhile($item)) {
            DomainLock::release($domainsId);
            $item->input = false;
            \Session::addMessageAfterRedirect(
                __('Une synchronisation a modifié cet enregistrement entre-temps. Veuillez vérifier son état actuel avant de réessayer.', 'dnsmanager'),
                true,
                ERROR
            );
            return;
        }

        $item->input[self::LOCK_MARKER] = $domainsId;
    }

    public static function preDelete(\DomainRecord $item): void
    {
        self::preRemoval($item, __('cette suppression', 'dnsmanager'));
    }

    public static function prePurge(\DomainRecord $item): void
    {
        self::preRemoval($item, __('cette suppression définitive', 'dnsmanager'));
    }

    public static function preRestore(\DomainRecord $item): void
    {
        self::preRemoval($item, __('cette restauration', 'dnsmanager'));
    }

    private static function preRemoval(\DomainRecord $item, string $actionLabel): void
    {
        if (!is_array($item->input)) return;

        $domainsId = (int)($item->fields['domains_id'] ?? 0);
        if (!$domainsId) return;

        if (!self::shouldHandle($domainsId)) return;

        if (self::acquireOrCancel($item, $domainsId, $actionLabel)) {
            $item->input[self::LOCK_MARKER] = $domainsId;
        }
    }

    // =========================================================
    // Hooks ITEM_* (post — écriture confirmée en base GLPI)
    // Pousse réellement l'action vers le provider (OVH uniquement, cf.
    // Account::hasBidirectionalSync()). Toute erreur est journalisée et
    // marque le mapping en "push_failed" pour retry à la prochaine
    // synchronisation (mécanisme de retry : étape 8, pas encore implémenté).
    // =========================================================

    public static function postAdd(\DomainRecord $item): void
    {
        self::handlePost($item, 'ADD');
    }

    public static function postUpdate(\DomainRecord $item): void
    {
        self::handlePost($item, 'UPDATE');
    }

    public static function postDelete(\DomainRecord $item): void
    {
        self::handlePost($item, 'DELETE');
    }

    public static function postPurge(\DomainRecord $item): void
    {
        self::handlePost($item, 'PURGE');
    }

    public static function postRestore(\DomainRecord $item): void
    {
        self::handlePost($item, 'RESTORE');
    }

    private static function handlePost(\DomainRecord $item, string $action): void
    {
        $domainsId = is_array($item->input) ? ($item->input[self::LOCK_MARKER] ?? null) : null;
        if ($domainsId === null) return; // le PRE_* correspondant n'a pas posé de verrou : rien à faire ici

        $domainsId = (int)$domainsId;

        try {
            self::pushToProvider($item, $action, $domainsId);
        } finally {
            // Toujours libérer le verrou, même en cas d'échec du push
            DomainLock::release($domainsId);
        }
    }

    /**
     * Effectue réellement l'appel API vers le provider (création,
     * modification ou suppression de l'enregistrement DNS).
     */
    private static function pushToProvider(\DomainRecord $item, string $action, int $domainsId): void
    {
        global $DB;

        $accountId = self::resolveAccountForDomain($domainsId);
        if ($accountId === null) return;

        try {
            $provider = Account::getProvider($accountId);
        } catch (\Exception $e) {
            error_log('DNSManage RecordSync : impossible de charger le provider du compte #' . $accountId . ' - ' . $e->getMessage());
            return;
        }

        $domainMapping = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['domains_id' => $domainsId, 'accounts_id' => $accountId],
        ])->current();
        if (!$domainMapping) return; // ne devrait pas arriver, shouldHandle() l'a déjà vérifié en amont
        $zoneRef = $domainMapping['provider_ref'];

        $recordId = (int)($item->fields['id'] ?? 0);

        // Rafraîchir les champs depuis la base pour être sûr d'avoir les valeurs
        // définitives après écriture GLPI (sauf PURGE : le record n'existe déjà plus).
        if ($action !== 'PURGE' && $recordId > 0) {
            $item->getFromDB($recordId);
        }

        $data = [
            'name'   => $item->fields['name'] ?? '',
            'type'   => self::resolveTypeName((int)($item->fields['domainrecordtypes_id'] ?? 0)),
            'ttl'    => (int)($item->fields['ttl'] ?? 0),
            'target' => $item->fields['data'] ?? '',
        ];

        try {
            switch ($action) {
                case 'ADD':
                    $providerRef = $provider->createRecord($zoneRef, $data);
                    if ($providerRef === '') {
                        throw new \RuntimeException('createRecord() a retourné une référence vide');
                    }
                    $DB->insert('glpi_plugin_dnsmanager_records', [
                        'accounts_id'      => $accountId,
                        'domainrecords_id' => $recordId,
                        'provider_ref'     => $providerRef,
                        'is_editable'      => 1,
                        'last_sync_at'     => date('Y-m-d H:i:s'),
                        'sync_status'      => 'ok',
                    ]);
                    break;

                case 'UPDATE':
                    $mapping = self::getRecordMapping($recordId, $accountId);
                    if (!$mapping) {
                        error_log("DNSManage RecordSync : aucun mapping trouvé pour DomainRecord #$recordId, impossible de pousser la modification vers le provider.");
                        return;
                    }
                    $provider->updateRecord($zoneRef, $mapping['provider_ref'], $data);
                    $DB->update('glpi_plugin_dnsmanager_records',
                        ['last_sync_at' => date('Y-m-d H:i:s'), 'sync_status' => 'ok'],
                        ['id' => $mapping['id']]
                    );
                    break;

                case 'DELETE':
                case 'PURGE':
                    $mapping = self::getRecordMapping($recordId, $accountId);
                    if (!$mapping) {
                        error_log("DNSManage RecordSync : aucun mapping trouvé pour DomainRecord #$recordId, rien à supprimer côté provider (jamais synchronisé ou déjà purgé du mapping).");
                        return;
                    }
                    try {
                        $provider->deleteRecord($zoneRef, $mapping['provider_ref']);
                    } catch (\Exception $e) {
                        // Tolérer "déjà supprimé" côté provider (ex: DELETE puis PURGE juste après)
                        $msg = strtolower($e->getMessage());
                        $alreadyGone = str_contains($msg, '404') || str_contains($msg, 'not found') || str_contains($msg, "n'existe pas");
                        if (!$alreadyGone) throw $e;
                    }
                    if ($action === 'PURGE') {
                        $DB->delete('glpi_plugin_dnsmanager_records', ['id' => $mapping['id']]);
                    } else {
                        $DB->update('glpi_plugin_dnsmanager_records',
                            ['last_sync_at' => date('Y-m-d H:i:s'), 'sync_status' => 'ok'],
                            ['id' => $mapping['id']]
                        );
                    }
                    break;

                case 'RESTORE':
                    $mapping = self::getRecordMapping($recordId, $accountId);

                    // Si un mapping existe et que la dernière suppression avait échoué côté
                    // provider (sync_status = push_failed), l'enregistrement existe probablement
                    // encore chez OVH : ne pas recréer (éviter un doublon), juste re-marquer OK.
                    if ($mapping && $mapping['sync_status'] === 'push_failed') {
                        $DB->update('glpi_plugin_dnsmanager_records',
                            ['last_sync_at' => date('Y-m-d H:i:s'), 'sync_status' => 'ok'],
                            ['id' => $mapping['id']]
                        );
                        error_log("DNSManage RecordSync : restauration de DomainRecord #$recordId — la suppression précédente avait échoué côté provider, probablement encore présent chez OVH, pas de recréation (évite un doublon).");
                        break;
                    }

                    // Sinon (mapping absent, ou suppression précédente réussie) : recréer
                    $providerRef = $provider->createRecord($zoneRef, $data);
                    if ($providerRef === '') {
                        throw new \RuntimeException('createRecord() a retourné une référence vide lors de la restauration');
                    }
                    if ($mapping) {
                        $DB->update('glpi_plugin_dnsmanager_records', [
                            'provider_ref' => $providerRef,
                            'last_sync_at' => date('Y-m-d H:i:s'),
                            'sync_status'  => 'ok',
                        ], ['id' => $mapping['id']]);
                    } else {
                        $DB->insert('glpi_plugin_dnsmanager_records', [
                            'accounts_id'      => $accountId,
                            'domainrecords_id' => $recordId,
                            'provider_ref'     => $providerRef,
                            'is_editable'      => 1,
                            'last_sync_at'     => date('Y-m-d H:i:s'),
                            'sync_status'      => 'ok',
                        ]);
                    }
                    break;
            }

            error_log(sprintf(
                'DNSManage [bidirectionnel] %s DomainRecord #%d (domaine #%d) poussé vers %s avec succès.',
                $action, $recordId, $domainsId, $provider::getLabel()
            ));
        } catch (\Exception $e) {
            error_log(sprintf(
                'DNSManage [bidirectionnel] ÉCHEC push %s DomainRecord #%d (domaine #%d) : %s',
                $action, $recordId, $domainsId, $e->getMessage()
            ));

            // Message visible pour l'utilisateur : l'action GLPI a réussi, mais la
            // répercussion chez le provider a échoué (droits API insuffisants,
            // panne provider, etc.) — sans ce message, l'échec passe totalement
            // inaperçu côté utilisateur (seulement visible dans les logs serveur).
            \Session::addMessageAfterRedirect(
                sprintf(
                    __('Attention : l\'enregistrement DNS a bien été %s dans GLPI, mais la répercussion chez le provider a échoué (%s). Vérifiez les droits API du compte, la synchronisation retentera automatiquement au prochain passage.', 'dnsmanager'),
                    match ($action) {
                        'ADD'     => __('ajouté', 'dnsmanager'),
                        'UPDATE'  => __('modifié', 'dnsmanager'),
                        'DELETE'  => __('supprimé', 'dnsmanager'),
                        'PURGE'   => __('purgé', 'dnsmanager'),
                        'RESTORE' => __('restauré', 'dnsmanager'),
                        default   => $action,
                    },
                    $e->getMessage()
                ),
                true,
                WARNING
            );

            // Marquer l'échec pour retry à la prochaine synchro (étape 8, pas encore implémentée)
            if (in_array($action, ['UPDATE', 'DELETE', 'PURGE'], true)) {
                $mapping = self::getRecordMapping($recordId, $accountId);
                if ($mapping) {
                    $DB->update('glpi_plugin_dnsmanager_records', ['sync_status' => 'push_failed'], ['id' => $mapping['id']]);
                }
            }
        }
    }

    private static function getRecordMapping(int $glpiRecordId, int $accountId): ?array
    {
        global $DB;
        $mapping = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_records',
            'WHERE' => ['domainrecords_id' => $glpiRecordId, 'accounts_id' => $accountId],
        ])->current();
        return $mapping ?: null;
    }

    private static function resolveTypeName(int $typeId): string
    {
        global $DB;
        $row = $DB->request(['FROM' => 'glpi_domainrecordtypes', 'WHERE' => ['id' => $typeId]])->current();
        return $row ? strtoupper($row['name']) : '';
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Détermine si ce domaine relève de notre logique de modification
     * bidirectionnelle : pas une écriture de synchro (anti-boucle), domaine
     * géré par DNSManage, compte avec la fonctionnalité activée (OVH only).
     */
    private static function shouldHandle(int $domainsId): bool
    {
        if (DomainLock::isSyncInProgress($domainsId)) return false;

        $accountId = self::resolveAccountForDomain($domainsId);
        if ($accountId === null) return false;

        return Account::hasBidirectionalSync($accountId);
    }

    /**
     * Tente d'acquérir le verrou (en attendant si nécessaire, jusqu'au
     * timeout configuré). Annule l'action GLPI (input = false) et affiche
     * un message clair à l'utilisateur en cas d'échec.
     */
    private static function acquireOrCancel(\DomainRecord $item, int $domainsId, string $actionLabel): bool
    {
        $userId = (int)(\Session::getLoginUserID() ?: 0);

        if (DomainLock::waitAndAcquire($domainsId, 'user', $userId)) {
            return true;
        }

        $item->input = false;
        \Session::addMessageAfterRedirect(
            sprintf(
                __('Une synchronisation est en cours sur ce domaine, impossible d\'effectuer %s pour le moment. Veuillez réessayer dans quelques instants.', 'dnsmanager'),
                $actionLabel
            ),
            true,
            ERROR
        );
        return false;
    }

    /**
     * Vérifie si au moins un des champs "déclencheurs" change réellement
     * par rapport à la valeur actuellement en base.
     */
    private static function hasTriggerFieldChange(\DomainRecord $item): bool
    {
        foreach (self::TRIGGER_FIELDS as $field) {
            if (!array_key_exists($field, $item->input)) continue;
            $old = $item->fields[$field] ?? null;
            $new = $item->input[$field];
            if ((string)$old !== (string)$new) return true;
        }
        return false;
    }

    /**
     * Compare le date_mod actuellement en base à celui chargé dans $item->fields
     * (au moment où GLPI a construit le formulaire) : s'ils diffèrent, le record
     * a été modifié entre-temps (typiquement par la synchro qu'on vient d'attendre).
     */
    private static function wasRecordTouchedMeanwhile(\DomainRecord $item): bool
    {
        global $DB;

        $id = (int)($item->fields['id'] ?? 0);
        if (!$id) return false;

        $current = $DB->request(['FROM' => 'glpi_domainrecords', 'WHERE' => ['id' => $id]])->current();
        if (!$current) return true; // disparu entre-temps : à considérer comme "touché"

        return ($current['date_mod'] ?? null) !== ($item->fields['date_mod'] ?? null);
    }

    private static function resolveAccountForDomain(int $domainsId): ?int
    {
        global $DB;
        $mapping = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['domains_id' => $domainsId],
        ])->current();

        return $mapping ? (int)$mapping['accounts_id'] : null;
    }
}
