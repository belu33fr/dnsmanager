<?php

namespace GlpiPlugin\Dnsmanager;

use CommonDBTM;
use CommonGLPI;
use Html;
use MassiveAction;
use Plugin;
use Session;
use Supplier;

class Account extends CommonDBTM
{
    public static $rightname = 'plugin_dnsmanager_account';
    public $dohistory  = true;
    public $recursive  = true;  // Active la case "Sous-entités"

    /**
     * Indique à GLPI que cet objet peut être récursif
     * même sans sous-entités existantes.
     */
    public function maybeRecursive(): bool
    {
        return true;
    }

    public function isEntityAssign(): bool
    {
        return true;
    }

    public function canRecurs(): bool
    {
        return Session::haveRight(self::$rightname, CREATE);
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Compte DNS Hébergeurs', 'Comptes DNS Hébergeurs', $nb, 'dnsmanager');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_accounts';
    }

    public static function getIcon(): string
    {
        return 'ti ti-world';
    }

    public static function getFormURL($full = true): string
    {
        return Plugin::getWebDir('dnsmanager', $full) . '/front/account.form.php';
    }

    public static function getFormURLWithID($id = 0, $full = true): string
    {
        return self::getFormURL($full) . '?id=' . (int)$id;
    }

    public static function getSearchURL($full = true): string
    {
        return Plugin::getWebDir('dnsmanager', $full) . '/front/account.php';
    }

    // ------------------------------------------------------------------
    // Menu GLPI 11
    // ------------------------------------------------------------------

    public static function getMenuName(): string
    {
        return 'DNS Hébergeurs';
    }

    public static function getMenuContent(): array
    {
        $search = self::getSearchURL(false);
        $form   = self::getFormURL(false);

        return [
            'title'   => self::getMenuName(),
            'page'    => $search,
            'icon'    => self::getIcon(),
            'options' => [
                'account' => [
                    'title' => self::getTypeName(2),
                    'page'  => $search,
                    'links' => [
                        'search' => $search,
                        'add'    => $form,
                    ],
                ],
                'synclog' => [
                    'title' => Synclog::getTypeName(2),
                    'page'  => Plugin::getWebDir('dnsmanager', false) . '/front/synclog.php',
                    'links' => [
                        'search' => Plugin::getWebDir('dnsmanager', false) . '/front/synclog.php',
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Formulaire GLPI 11 (Twig)
    // ------------------------------------------------------------------

    public function showForm($ID, array $options = []): bool
    {
        // initForm() gère correctement création (ID=0) et édition
        $this->initForm($ID, $options);

        $providers = ProviderFactory::getAvailableProviders();
        $allProviderData = [];
        foreach ($providers as $type => $label) {
            $allProviderData[$type] = [
                'endpoints' => ProviderFactory::getEndpoints($type),
                'fields'    => ProviderFactory::getCredentialFields($type),
            ];
        }

        $credentials = [];
        if ($ID > 0) {
            $credentials = Credential::getForAccount($ID);
        }

        // Vérifier si le plugin accounts est disponible
        $accountsClass = class_exists('\GlpiPlugin\Accounts\Account')
            ? '\GlpiPlugin\Accounts\Account'
            : null;

        $twig_vars = [
            'item'            => $this,
            'params'          => $options,
            'providers'       => $providers,
            'allProviderData' => json_encode($allProviderData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT),
            'credentials'     => json_encode($credentials,    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT),
            'currentEndpoint' => addslashes($this->fields['endpoint'] ?? ''),
            'webdir'          => Plugin::getWebDir('dnsmanager'),
            'can_sync'        => Right::canSync(),
            'accounts_class'  => $accountsClass,
        ];

        error_log("DNSManage Twig vars: item.id=" . ($this->fields['id'] ?? 'NULL')
            . " providers=" . count($providers)
            . " fields=" . json_encode(array_keys($this->fields)));

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@dnsmanager/account.form.html.twig',
            $twig_vars
        );

        return true;
    }

    // ------------------------------------------------------------------
    // Options de recherche
    // ------------------------------------------------------------------

    public function rawSearchOptions(): array
    {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Nom', 'dnsmanager'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'massiveaction' => false,
            'datatype'      => 'number',
        ];

        $tab[] = [
            'id'            => 10,
            'table'         => self::getTable(),
            'field'         => 'provider_type',
            'name'          => __('Provider', 'dnsmanager'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 11,
            'table'         => self::getTable(),
            'field'         => 'endpoint',
            'name'          => __('Endpoint', 'dnsmanager'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 12,
            'table'         => self::getTable(),
            'field'         => 'domain_whitelist',
            'name'          => __('Liste blanche', 'dnsmanager'),
            'datatype'      => 'string',
            'massiveaction' => true,
        ];

        $tab[] = [
            'id'            => 13,
            'table'         => self::getTable(),
            'field'         => 'renewal_months',
            'name'          => __('Délai renouvellement (mois)', 'dnsmanager'),
            'datatype'      => 'number',
            'massiveaction' => true,
        ];

        $tab[] = [
            'id'            => 14,
            'table'         => self::getTable(),
            'field'         => 'is_active',
            'name'          => __('Actif', 'dnsmanager'),
            'datatype'      => 'bool',
            'massiveaction' => true,
        ];

        $tab[] = [
            'id'            => 15,
            'table'         => self::getTable(),
            'field'         => 'last_sync_at',
            'name'          => __('Dernière sync', 'dnsmanager'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 16,
            'table'         => self::getTable(),
            'field'         => 'comment',
            'name'          => __('Commentaire', 'dnsmanager'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 17,
            'table'         => 'glpi_suppliers',
            'field'         => 'name',
            'name'          => __('Fournisseur', 'dnsmanager'),
            'datatype'      => 'dropdown',
            'massiveaction' => true,
            'joinparams'    => [
                'jointype'  => 'field',
                'linkfield' => 'suppliers_id',
            ],
        ];

        $tab[] = [
            'id'            => 80,
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => __('Entité'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    // ------------------------------------------------------------------
    // Actions massives
    // ------------------------------------------------------------------

    public function getSpecificMassiveActions($checkitem = null): array
    {
        $actions = parent::getSpecificMassiveActions($checkitem);

        $sep = MassiveAction::CLASS_ACTION_SEPARATOR;
        $actions[self::class . $sep . 'change_supplier']  = __('Modifier le fournisseur', 'dnsmanager');
        $actions[self::class . $sep . 'change_whitelist'] = __('Modifier la liste blanche', 'dnsmanager');
        $actions[self::class . $sep . 'change_renewal']   = __('Modifier le délai de renouvellement', 'dnsmanager');
        $actions[self::class . $sep . 'sync']             = __('Synchroniser maintenant', 'dnsmanager');

        return $actions;
    }

    public static function showMassiveActionsSubForm(MassiveAction $ma): bool
    {
        switch ($ma->getAction()) {
            case 'change_supplier':
                Supplier::dropdown(['name' => 'suppliers_id', 'value' => 0]);
                echo Html::submit(__('Appliquer', 'dnsmanager'), ['name' => 'massiveaction']);
                return true;

            case 'change_whitelist':
                echo Html::input('domain_whitelist', ['placeholder' => 'example.com, example.fr']);
                echo Html::submit(__('Appliquer', 'dnsmanager'), ['name' => 'massiveaction']);
                return true;

            case 'change_renewal':
                echo Html::input('renewal_months', ['type' => 'number', 'min' => 1, 'max' => 12, 'value' => 1, 'style' => 'width:80px']);
                echo Html::submit(__('Appliquer', 'dnsmanager'), ['name' => 'massiveaction']);
                return true;

            case 'sync':
                echo Html::submit(__('Lancer la synchronisation', 'dnsmanager'), ['name' => 'massiveaction']);
                return true;
        }
        return parent::showMassiveActionsSubForm($ma);
    }

    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ): void {
        global $DB;

        // Contournement bug GLPI 11 namespace + MassiveAction::remainings
        // On ne peut pas appeler itemDone() car remainings[$itemtype] est null
        // On accède directement à $ma->results via Reflection sur la propriété results
        $action = $ma->getAction();

        $handledActions = ['change_supplier', 'change_whitelist', 'change_renewal', 'sync'];
        if (!in_array($action, $handledActions, true)) {
            parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
            return;
        }

        $ok = 0;
        $ko = 0;
        $messages = [];

        switch ($action) {
            case 'change_supplier':
                $supplierId = (int)($ma->POST['suppliers_id'] ?? 0);
                foreach ($ids as $id) {
                    try {
                        $DB->update(self::getTable(), ['suppliers_id' => $supplierId, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
                        $ok++;
                    } catch (\Exception $e) { $ko++; $messages[] = $e->getMessage(); }
                }
                break;

            case 'change_whitelist':
                $whitelist = trim($ma->POST['domain_whitelist'] ?? '');
                foreach ($ids as $id) {
                    try {
                        $DB->update(self::getTable(), ['domain_whitelist' => $whitelist, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
                        $ok++;
                    } catch (\Exception $e) { $ko++; $messages[] = $e->getMessage(); }
                }
                break;

            case 'change_renewal':
                $months = max(1, (int)($ma->POST['renewal_months'] ?? 1));
                foreach ($ids as $id) {
                    try {
                        $DB->update(self::getTable(), ['renewal_months' => $months, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
                        $ok++;
                    } catch (\Exception $e) { $ko++; $messages[] = $e->getMessage(); }
                }
                break;

            case 'sync':
                foreach ($ids as $id) {
                    try {
                        // Mode interactif = false pour les actions massives
                        // Les nouveaux domaines sont ignorés et signalés
                        $importer = new Importer($id, false);
                        $result   = $importer->sync();
                        $ok++;

                        // Vérifier s'il y a des domaines à importer
                        $domainImporter = new \GlpiPlugin\Dnsmanager\DomainImporter($id, true);
                        $analysis       = $domainImporter->analyze();
                        if (!empty($analysis['to_import'])) {
                            $names     = implode(', ', array_column(
                                array_column($analysis['to_import'], 'domain_data'),
                                'name'
                            ));
                            $account   = Account::getWithCredentials($id);
                            $importUrl = Plugin::getWebDir('dnsmanager') . '/front/domain.import.php?account_id=' . $id;
                            \Session::addMessageAfterRedirect(
                                sprintf(
                                    __('%d nouveau(x) domaine(s) à importer depuis "%s" : %s — <a href="%s" class="btn btn-sm btn-warning ms-2">Importer maintenant</a>', 'dnsmanager'),
                                    count($analysis['to_import']),
                                    htmlspecialchars($account['account']['name'] ?? '#' . $id),
                                    htmlspecialchars($names),
                                    $importUrl
                                ),
                                false,
                                WARNING,
                                true  // allow HTML
                            );
                        }
                    } catch (\Exception $e) {
                        $ko++;
                        $messages[] = $e->getMessage();
                    }
                }
                break;
        }

        // Mettre à jour les résultats directement sans passer par itemDone()
        try {
            $ref = new \ReflectionProperty($ma, 'results');
            $ref->setAccessible(true);
            $results = $ref->getValue($ma);
            $results['ok']       += $ok;
            $results['ko']       += $ko;
            $results['messages']  = array_merge($results['messages'] ?? [], $messages);
            $ref->setValue($ma, $results);
        } catch (\Throwable) {
            // Fallback si Reflection échoue
        }

        // Vider remainings pour cet itemtype pour éviter le crash
        try {
            $ref2 = new \ReflectionProperty($ma, 'remainings');
            $ref2->setAccessible(true);
            $remainings = $ref2->getValue($ma) ?? [];
            unset($remainings[self::class]);
            $ref2->setValue($ma, $remainings);
        } catch (\Throwable) {}
    }

    // ------------------------------------------------------------------
    // Préparation des données avant sauvegarde
    // ------------------------------------------------------------------

    /**
     * GLPI filtre les champs inconnus — on force provider_type et endpoint
     * qui viennent de <select> HTML et non de dropdowns GLPI natifs.
     */
    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        return $this->cleanInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = parent::prepareInputForUpdate($input);
        return $this->cleanInput($input);
    }

    private function cleanInput(array $input): array
    {
        // S'assurer que ces champs sont bien présents et propres
        foreach (['provider_type', 'endpoint', 'domain_whitelist', 'renewal_months', 'suppliers_id', 'is_active', 'is_recursive'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = $input[$field];
            }
        }
        // Supprimer les champs credentials qui ne doivent pas aller en base
        foreach (array_keys($input) as $key) {
            if (str_starts_with($key, 'cred_')) {
                unset($input[$key]);
            }
        }
        return $input;
    }

    // ------------------------------------------------------------------
    // Helpers CRUD
    // ------------------------------------------------------------------

    public static function getWithCredentials(int $id): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        if (!$row) return null;
        return ['account' => $row, 'credentials' => Credential::getForAccount($id)];
    }

    public static function getActiveAccounts(): array
    {
        global $DB;
        $accounts = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'name ASC']) as $row) {
            $accounts[] = $row;
        }
        return $accounts;
    }

    public static function updateLastSync(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['last_sync_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public static function getProvider(int $id): ProviderInterface
    {
        $data = self::getWithCredentials($id);
        if (!$data) throw new \RuntimeException("Compte #$id introuvable.");
        return ProviderFactory::create(
            $data['account']['provider_type'],
            $data['credentials'],
            $data['account']['endpoint']
        );
    }
}
