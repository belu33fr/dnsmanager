<?php

use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Credential;
use GlpiPlugin\Dnsmanager\Importer;
use GlpiPlugin\Dnsmanager\ProviderFactory;

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_dnsmanager_account', READ);

header('Content-Type: application/json');

$action    = $_POST['action'] ?? '';
$accountId = (int)($_POST['account_id'] ?? 0);

try {
    switch ($action) {

        case 'sync':
            Session::checkRight('plugin_dnsmanager_sync', UPDATE);
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');
            $importer = new Importer($accountId);
            $result   = $importer->sync();
            echo json_encode(['success' => true] + $result);
            break;

        case 'test':
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');
            Account::getProvider($accountId)->testConnection();
            echo json_encode(['success' => true]);
            break;

        case 'test_form':
            $providerType = trim($_POST['provider_type'] ?? '');
            $endpoint     = trim($_POST['endpoint']      ?? '');
            if (!$providerType) throw new \InvalidArgumentException('Type de provider manquant.');

            $fields      = ProviderFactory::getCredentialFields($providerType);
            $credentials = $accountId ? Credential::getForAccount($accountId) : [];

            foreach ($fields as $field) {
                $val = trim($_POST['cred_' . $field['key']] ?? '');
                if ($val !== '') $credentials[$field['key']] = $val;
            }

            $missing = [];
            foreach ($fields as $field) {
                if ($field['required'] && empty($credentials[$field['key']])) $missing[] = $field['label'];
            }
            if (!empty($missing)) throw new \InvalidArgumentException('Champs manquants : ' . implode(', ', $missing));

            ProviderFactory::create($providerType, $credentials, $endpoint)->testConnection();
            echo json_encode(['success' => true]);
            break;

        case 'sync_domain':
            $accountId = (int)($_POST['account_id'] ?? 0);
            $domainRef = trim($_POST['domain_ref'] ?? '');
            if (!$accountId || !$domainRef) {
                echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
                break;
            }
            $importer = new Importer($accountId, false);
            $result   = $importer->syncDomainByRef($domainRef);
            echo json_encode([
                'success' => true,
                'message' => ($result['records_added'] ?? 0) . ' créé(s), ' . ($result['records_updated'] ?? 0) . ' mis à jour.',
            ]);
            break;

        default:
            throw new \InvalidArgumentException("Action inconnue : $action");
    }
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
