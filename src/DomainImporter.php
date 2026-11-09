<?php

namespace GlpiPlugin\Dnsmanager;

use Domain;
use Entity;

/**
 * Gestion de l'import et synchronisation des domaines
 */
class DomainImporter
{
    private ProviderInterface $provider;
    private array             $account;
    private bool              $interactive;
    private array             $domainWhitelist = [];
    private ?int              $internetTypeId  = null;

    public function __construct(int $accountId, bool $interactive = false)
    {
        $data = Account::getWithCredentials($accountId);
        if (!$data) throw new \InvalidArgumentException("Compte #$accountId introuvable.");

        $this->account     = $data['account'];
        $this->interactive = $interactive;
        $this->provider    = ProviderFactory::create(
            $data['account']['provider_type'],
            $data['credentials'],
            $data['account']['endpoint']
        );

        // Charger la liste blanche
        $whitelist = trim($data['account']['domain_whitelist'] ?? '');
        if ($whitelist !== '') {
            $this->domainWhitelist = array_map('trim', array_filter(explode(',', strtolower($whitelist))));
        }
    }

    /**
     * Vérifie si un domaine est dans la liste blanche (ou si pas de liste blanche)
     */
    private function isInWhitelist(string $domainName): bool
    {
        if (empty($this->domainWhitelist)) return true;
        return in_array(strtolower(trim($domainName)), $this->domainWhitelist, true);
    }

    /**
     * Analyse les domaines provider vs GLPI et retourne un rapport.
     *
     * @return array{
     *   to_sync: array,       // domaines à synchroniser (existants dans GLPI)
     *   to_import: array,     // nouveaux domaines (absents de GLPI)
     *   to_restore: array,    // domaines en corbeille à restaurer
     *   to_delete: array,     // domaines GLPI sans provider (si interactive)
     * }
     */
    public function analyze(): array
    {
        global $DB;

        $providerDomains = $this->provider->listDomains();
        $providerRefs    = array_column($providerDomains, 'ref');

        $result = [
            'to_sync'    => [],
            'to_import'  => [],
            'to_restore' => [],
            'to_delete'  => [],
        ];

        // Domaines déjà mappés dans dnsmanager_domains
        $mapped = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_dnsmanager_domains', 'WHERE' => ['accounts_id' => (int)$this->account['id']]]) as $row) {
            $mapped[$row['provider_ref']] = $row;
        }

        foreach ($providerDomains as $domainData) {
            $ref = $domainData['ref'];

            // Filtrer par liste blanche
            if (!$this->isInWhitelist($domainData['name'])) {
                continue;
            }

            $ownType = $this->getInternetTypeId();

            // Chercher dans GLPI (actif), parmi les domaines du type géré par CE compte :
            // un domaine homonyme d'un autre type géré par le plugin (Internet / DNS AD) est un
            // domaine distinct.
            $glpiDomain = $DB->request([
                'FROM'  => 'glpi_domains',
                'WHERE' => ['name' => $domainData['name'], 'is_deleted' => 0, 'domaintypes_id' => $ownType],
            ])->current();

            // Homonyme d'un type que le plugin ne gère pas (saisi à la main) : géré par un tiers,
            // on n'y touche pas et on ne propose pas de l'importer
            if (!$glpiDomain && DomainTypes::hasForeignDomain($domainData['name'], $ownType)) {
                continue;
            }

            // Chercher dans GLPI (corbeille)
            $glpiDeleted = !$glpiDomain ? $DB->request([
                'FROM'  => 'glpi_domains',
                'WHERE' => ['name' => $domainData['name'], 'is_deleted' => 1, 'domaintypes_id' => $ownType],
            ])->current() : null;

            if ($glpiDomain) {
                $result['to_sync'][] = [
                    'domain_data'   => $domainData,
                    'glpi_domain'   => $glpiDomain,
                    'mapping'       => $mapped[$ref] ?? null,
                ];
            } elseif ($glpiDeleted) {
                $result['to_restore'][] = [
                    'domain_data'  => $domainData,
                    'glpi_domain'  => $glpiDeleted,
                    'mapping'      => $mapped[$ref] ?? null,
                ];
            } else {
                // Nouveau domaine — à importer si interactive
                $result['to_import'][] = [
                    'domain_data' => $domainData,
                    'ref'         => $ref,
                ];
            }
        }

        // Domaines GLPI mappés mais absents du provider → proposer corbeille (mode interactif)
        if ($this->interactive) {
            foreach ($mapped as $ref => $mapping) {
                if (!in_array($ref, $providerRefs, true)) {
                    $glpiDomain = $DB->request([
                        'FROM'  => 'glpi_domains',
                        'WHERE' => ['id' => (int)$mapping['domains_id'], 'is_deleted' => 0],
                    ])->current();

                    if ($glpiDomain) {
                        $result['to_delete'][] = [
                            'glpi_domain' => $glpiDomain,
                            'mapping'     => $mapping,
                            'account'     => $this->account,
                        ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Retourne les entités parentes des entités dont le nom correspond
     * aux intitulés configurés (ex: "Sites", "Financier").
     */
    public static function getImportableEntities(): array
    {
        global $DB;

        $labels   = PluginConfig::getEntityLabels();
        $entities = [];

        if (empty($labels)) return $entities;

        // Logique 1 : parents des entités dont le nom est dans entity_labels
        foreach ($DB->request([
            'FROM'  => 'glpi_entities',
            'WHERE' => ['name' => $labels],
        ]) as $entity) {
            $parentId = (int)$entity['entities_id'];
            if ($parentId < 0) continue;
            $parent = $DB->request([
                'FROM'  => 'glpi_entities',
                'WHERE' => ['id' => $parentId],
            ])->current();
            if ($parent && !isset($entities[$parentId])) {
                $entities[$parentId] = [
                    'id'   => $parentId,
                    'name' => $parent['completename'] ?? $parent['name'],
                ];
            }
        }

        // Logique 2 : enfants directs des entités dont le nom est dans entity_children_labels
        $childrenLabels = PluginConfig::getEntityChildrenLabels();
        if (!empty($childrenLabels)) {
            foreach ($DB->request([
                'FROM'  => 'glpi_entities',
                'WHERE' => ['name' => $childrenLabels],
            ]) as $entity) {
                $entityId = (int)$entity['id'];
                foreach ($DB->request([
                    'FROM'  => 'glpi_entities',
                    'WHERE' => ['entities_id' => $entityId],
                ]) as $child) {
                    $childId = (int)$child['id'];
                    if (!isset($entities[$childId])) {
                        $entities[$childId] = [
                            'id'   => $childId,
                            'name' => $child['completename'] ?? $child['name'],
                        ];
                    }
                }
            }
        }

        // Trier par nom
        usort($entities, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $entities;
    }

    /**
     * Retourne une condition SQL structurelle (parents/entities_id) pour filtrer
     * dynamiquement les entités importables — contrairement à getImportableEntities()
     * qui résout une liste figée d'IDs, cette condition reste valide même pour
     * des entités créées après coup (nouveaux enfants des entités "intermédiaires").
     */
    public static function getImportableEntitiesCondition(): array
    {
        global $DB;

        $labels = PluginConfig::getEntityLabels();
        $or = [];

        // Logique 1 : parents des entités nommées dans entity_labels (liste figée, stable)
        $parentIds = [];
        if (!empty($labels)) {
            foreach ($DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['name' => $labels]]) as $entity) {
                $parentId = (int)$entity['entities_id'];
                if ($parentId >= 0) $parentIds[] = $parentId;
            }
        }
        if (!empty($parentIds)) {
            $or[] = ['id' => array_unique($parentIds)];
        }

        // Logique 2 : enfants directs des entités nommées dans entity_children_labels
        // Filtre structurel sur entities_id (parent) -> inclut automatiquement
        // les entités créées après coup, tant qu'elles ont ce parent.
        $childrenLabels = PluginConfig::getEntityChildrenLabels();
        $intermediateIds = [];
        if (!empty($childrenLabels)) {
            foreach ($DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['name' => $childrenLabels]]) as $entity) {
                $intermediateIds[] = (int)$entity['id'];
            }
        }
        if (!empty($intermediateIds)) {
            $or[] = ['entities_id' => array_unique($intermediateIds)];
        }

        if (empty($or)) return [];

        return ['OR' => $or];
    }

    /**
     * Importe un domaine dans GLPI et déclenche la sync complète.
     */
    public function importDomain(string $providerRef, int $entitiesId): int
    {
        global $DB;

        // Récupérer les infos du domaine depuis le provider
        $domainInfo = $this->provider->getDomainInfo($providerRef);
        $domainName = $providerRef; // le ref OVH = nom de zone = nom de domaine

        // Créer le domaine dans GLPI
        $domain = new Domain();
        $id = $domain->add([
            'name'           => $domainName,
            'entities_id'    => $entitiesId,
            'is_recursive'   => 0,
            'is_active'      => 1,
            'domaintypes_id' => $this->getInternetTypeId(),
            'suppliers_id'   => (int)($this->account['suppliers_id'] ?? 0),
            'date_domaincreation' => $this->parseDate($domainInfo['creation_date']   ?? ''),
            'date_expiration'     => $this->parseDate($domainInfo['expiration_date'] ?? ''),
            'comment'        => 'Importé via DNSManage',
            'is_deleted'     => 0,
        ]);

        if (!$id) throw new \RuntimeException("Impossible de créer le domaine '$domainName'.");

        // Mettre à jour la date de renouvellement (Additional Fields facturation)
        error_log("DNSManage facturation: id=$id expiration=" . ($domainInfo['expiration_date'] ?? 'VIDE'));
        if (!empty($domainInfo['expiration_date'])) {
            $renewalMonths = max(1, (int)($this->account['renewal_months'] ?? 1));
            try {
                $renewalStr = (new \DateTime($domainInfo['expiration_date']))
                    ->modify("-{$renewalMonths} month")
                    ->format('Y-m-d');

                $facTable = 'glpi_plugin_fields_domainfacturations';
                if ($DB->tableExists($facTable)) {
                    $existing = $DB->request(['FROM' => $facTable, 'WHERE' => ['items_id' => (int)$id]])->current();
                    if ($existing) {
                        if (empty(trim($existing['datederenouvellementfield'] ?? ''))) {
                            $DB->update($facTable, ['datederenouvellementfield' => $renewalStr], ['items_id' => (int)$id]);
                        }
                    } else {
                        $DB->insert($facTable, [
                            'items_id'                   => (int)$id,
                            'itemtype'                   => 'Domain',
                            'plugin_fields_containers_id' => 17,
                            'entities_id'                => (int)$entitiesId,
                            'is_recursive'               => 0,
                            'refarticlefield'            => '',
                            'quantitfield'               => 1,
                            'datederenouvellementfield'  => $renewalStr,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                error_log("DNSManage facturation import error: " . $e->getMessage());
            }
        }

        // Renseigner le fournisseur (et le TTL par défaut si disponible) dans Additional Fields
        $afTable = 'glpi_plugin_fields_domaindomaineadministratifcomplements';
        if ($DB->tableExists($afTable) && !empty($this->account['suppliers_id'])) {
            $updateData = ['suppliers_id_fournisseurfieldtwo' => (int)$this->account['suppliers_id']];

            // TTL par défaut de la zone (SOA TTL côté OVH, null si non supporté)
            $ttlDefault = null;
            if ($DB->fieldExists($afTable, 'ttldfautfield')) {
                try {
                    $ttlDefault = $this->provider->getZoneDefaultTtl($providerRef);
                } catch (\Exception) {
                    $ttlDefault = null;
                }
            }
            if ($ttlDefault !== null) {
                $updateData['ttldfautfield'] = $ttlDefault;
            }

            $existing = $DB->request(['FROM' => $afTable, 'WHERE' => ['items_id' => (int)$id]])->current();
            if ($existing) {
                $DB->update($afTable, $updateData, ['items_id' => (int)$id]);
            } else {
                $DB->insert($afTable, array_merge([
                    'items_id'                     => (int)$id,
                    'itemtype'                     => 'Domain',
                    'plugin_fields_containers_id'  => 13,
                    'entities_id'                  => (int)$this->account['entities_id'],
                    'is_recursive'                 => 0,
                    'nepasrenouvelerfield'          => 0,
                    'avertifield'                  => 0,
                ], $updateData));
            }
        }

        // Mettre à jour le bloc Administratif Domaine (Fields)
        $registrarContacts = $this->resolveRegistrarContacts($domainInfo);
        $this->updateAdministratifDomaine((int)$id, $entitiesId, $registrarContacts);

        // Mettre à jour les groupes
        $this->updateGroups((int)$id);

        // Créer ou mettre à jour le mapping
        $existingMapping = $DB->request([
            'FROM'  => 'glpi_plugin_dnsmanager_domains',
            'WHERE' => ['accounts_id' => (int)$this->account['id'], 'provider_ref' => $providerRef],
        ])->current();

        if ($existingMapping) {
            $DB->update('glpi_plugin_dnsmanager_domains',
                ['domains_id' => (int)$id, 'last_sync_at' => date('Y-m-d H:i:s'), 'sync_status' => 'imported'],
                ['id' => $existingMapping['id']]
            );
        } else {
            $DB->insert('glpi_plugin_dnsmanager_domains', [
                'accounts_id'  => (int)$this->account['id'],
                'domains_id'   => (int)$id,
                'provider_ref' => $providerRef,
                'last_sync_at' => date('Y-m-d H:i:s'),
                'sync_status'  => 'imported',
            ]);
        }

        return (int)$id;
    }

    /**
     * Restaure un domaine depuis la corbeille et le synchronise.
     */
    public function restoreDomain(int $glpiDomainId): void
    {
        $domain = new Domain();
        $domain->restore(['id' => $glpiDomainId]);
        // S'assurer que le domaine est actif après restauration
        $domain->update(['id' => $glpiDomainId, 'is_active' => 1]);
    }

    /**
     * Met un domaine en corbeille avec ses enregistrements.
     */
    public function deleteDomain(int $glpiDomainId, string $providerRef): void
    {
        global $DB;

        DomainLock::markSyncInProgress($glpiDomainId);
        try {
            // Mettre les enregistrements DNS en corbeille
            foreach ($DB->request(['FROM' => 'glpi_domainrecords', 'WHERE' => ['domains_id' => $glpiDomainId, 'is_deleted' => 0]]) as $record) {
                $dr = new \DomainRecord();
                $dr->delete(['id' => $record['id']]);
            }

            // Mettre le domaine en corbeille
            $domain = new Domain();
            $domain->delete(['id' => $glpiDomainId]);

            // Supprimer le mapping
            $DB->delete('glpi_plugin_dnsmanager_domains', [
                'accounts_id'  => (int)$this->account['id'],
                'provider_ref' => $providerRef,
            ]);
            $DB->delete('glpi_plugin_dnsmanager_records', [
                'accounts_id' => (int)$this->account['id'],
            ]);
        } finally {
            DomainLock::unmarkSyncInProgress($glpiDomainId);
        }
    }

    /** Type de domaine GLPI géré par ce compte (Internet, ou DNS AD pour un serveur Windows) */
    private function getInternetTypeId(): int
    {
        if ($this->internetTypeId !== null) return $this->internetTypeId;
        return $this->internetTypeId = DomainTypes::idForProvider((string)($this->account['provider_type'] ?? ''));
    }

    private function updateGroups(int $glpiDomainId): void
    {
        global $DB;

        $groupsNormal = (int)($this->account['groups_id']      ?? 0);
        $groupsTech   = (int)($this->account['groups_id_tech'] ?? 0);

        foreach ([1 => $groupsNormal, 2 => $groupsTech] as $type => $groupId) {
            if (!$groupId) continue;
            $existing = $DB->request([
                'FROM'  => 'glpi_groups_items',
                'WHERE' => ['itemtype' => 'Domain', 'items_id' => $glpiDomainId, 'type' => $type],
            ])->current();
            if (!$existing) {
                $DB->insert('glpi_groups_items', [
                    'groups_id' => $groupId,
                    'itemtype'  => 'Domain',
                    'items_id'  => $glpiDomainId,
                    'type'      => $type,
                ]);
            }
        }
    }

    private function parseDate(string $date): string
    {
        try { return (new \DateTime($date))->format('Y-m-d'); }
        catch (\Exception) { return ''; }
    }

    /**
     * Met à jour le bloc "Administratif Domaine" (Fields) pour un domaine
     */
    /**
     * Résout les contacts registrar (nichandle OVH, etc.) vers des IDs
     * GlpiPlugin\Accounts\Account. Crée automatiquement le compte s'il
     * n'existe pas encore (nom = identifiant registrar).
     *
     * @return array<string,int> fieldName => accounts_id résolu
     */
    /** Cache mémoire (le temps d'un import) pour éviter de requêter à chaque contact résolu */
    private ?int $cachedContactStateId = null;
    private ?int $cachedContactTypeId  = null;

    /** Statut "Actif" (glpi_plugin_accounts_accountstates), résolu par nom pour rester portable */
    private function getDefaultAccountStateId(): int
    {
        global $DB;
        if ($this->cachedContactStateId !== null) return $this->cachedContactStateId;

        $row = $DB->request(['FROM' => 'glpi_plugin_accounts_accountstates', 'WHERE' => ['name' => 'Actif']])->current();
        $this->cachedContactStateId = $row ? (int)$row['id'] : 0;
        return $this->cachedContactStateId;
    }

    /** Type "Fournisseur" (glpi_plugin_accounts_accounttypes), résolu par nom pour rester portable */
    private function getDefaultAccountTypeId(): int
    {
        global $DB;
        if ($this->cachedContactTypeId !== null) return $this->cachedContactTypeId;

        $row = $DB->request(['FROM' => 'glpi_plugin_accounts_accounttypes', 'WHERE' => ['name' => PluginConfig::getContactAccountType()]])->current();
        $this->cachedContactTypeId = $row ? (int)$row['id'] : 0;
        return $this->cachedContactTypeId;
    }

    private function resolveRegistrarContacts(array $domainInfo): array
    {
        global $DB;
        $resolved = [];

        if (!$DB->tableExists('glpi_plugin_accounts_accounts')) return $resolved;

        $map = [
            'admin_contact'   => 'plugin_accounts_accounts_id_administrateurfield',
            'tech_contact'    => 'plugin_accounts_accounts_id_technicienfield',
            'billing_contact' => 'plugin_accounts_accounts_id_financierfield',
        ];

        foreach ($map as $infoKey => $fieldName) {
            $identity = trim($domainInfo[$infoKey] ?? '');
            if ($identity === '') continue;
            $login = trim($domainInfo[$infoKey . '_login'] ?? '');

            $account = $DB->request([
                'FROM'  => 'glpi_plugin_accounts_accounts',
                'WHERE' => ['name' => $identity, 'is_deleted' => 0],
            ])->current();

            if ($account) {
                $resolved[$fieldName] = (int)$account['id'];
                continue;
            }

            try {
                $accountClass = '\\GlpiPlugin\\Accounts\\Account';
                if (class_exists($accountClass)) {
                    $newAccount = new $accountClass();
                    $addData = [
                        'name'                             => $identity,
                        'entities_id'                      => (int)($this->account['entities_id'] ?? 0),
                        'comment'                          => 'Créé automatiquement depuis le contact registrar (DNSManage)',
                        'plugin_accounts_accountstates_id'  => $this->getDefaultAccountStateId(),
                        'plugin_accounts_accounttypes_id'   => $this->getDefaultAccountTypeId(),
                        'groups_id'                         => (int)($this->account['contact_groups_id'] ?? 0),
                    ];
                    if ($login !== '') {
                        $addData['login'] = $login;
                    }
                    $newId = $newAccount->add($addData);
                    if ($newId) {
                        $resolved[$fieldName] = (int)$newId;
                        error_log("DNSManage: compte contact '$identity' créé avec succès, id=$newId");
                    } else {
                        error_log("DNSManage: échec silencieux création compte contact '$identity' - add() a retourné une valeur fausse (droits, champ requis manquant ?)");
                    }
                } else {
                    error_log("DNSManage: classe $accountClass introuvable - plugin Accounts absent ou inactif ?");
                }
            } catch (\Exception $e) {
                error_log('DNSManage: échec création compte contact "' . $identity . '" - ' . $e->getMessage());
            }
        }

        return $resolved;
    }

    public function updateAdministratifDomaine(int $glpiDomainId, int $entitiesId, array $registrarContacts = []): void
    {
        global $DB;

        $table = 'glpi_plugin_fields_domainadministratifdomaines';
        if (!$DB->tableExists($table)) return;

        $data     = ['plugin_dnsmanager_accounts_id_comptedhbergeurfield' => (int)$this->account['id']];
        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['items_id' => $glpiDomainId]])->current();

        $lockMap = [
            'plugin_accounts_accounts_id_administrateurfield' => 'administrateurlockfield',
            'plugin_accounts_accounts_id_technicienfield'     => 'technicienlockfield',
            'plugin_accounts_accounts_id_financierfield'      => 'financierlockfield',
            'users_id_proprietairefield'                      => 'proprietairelockfield',
        ];

        foreach ($lockMap as $field => $lockField) {
            // 1. Ne pas écraser si verrouillé
            if ($existing && !empty($existing[$lockField])) continue;

            // 2. Priorité au contact registrar résolu
            if (isset($registrarContacts[$field])) {
                $data[$field] = $registrarContacts[$field];
                continue;
            }

            // 3. Sinon, valeur par défaut du compte provider
            $data[$field] = (int)($this->account[$field] ?? 0);
        }

        if ($existing) {
            $DB->update($table, $data, ['items_id' => $glpiDomainId]);
        } else {
            $DB->insert($table, array_merge($data, [
                'items_id'                    => $glpiDomainId,
                'itemtype'                    => 'Domain',
                'plugin_fields_containers_id' => 35,
                'entities_id'                 => $entitiesId,
                'is_recursive'                => 0,
                'administrateurlockfield'     => 0,
                'technicienlockfield'         => 0,
                'financierlockfield'          => 0,
                'proprietairelockfield'       => 0,
            ]));
        }
    }
}
