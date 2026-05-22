<?php
/**
 * DNSManage - Endpoint AJAX : synchronisation et test de connexion
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('config', READ);

header('Content-Type: application/json');

$action    = $_POST['action'] ?? '';
$accountId = (int) ($_POST['account_id'] ?? 0);

try {
    switch ($action) {

        // ----------------------------------------------------------
        // Synchronisation d'un compte existant
        // ----------------------------------------------------------
        case 'sync':
            Session::checkRight('config', UPDATE);
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');

            $importer = new PluginDnsmanageImporter($accountId);
            $result   = $importer->sync();

            echo json_encode([
                'success'         => true,
                'added'           => $result['added'],
                'updated'         => $result['updated'],
                'records_added'   => $result['records_added'],
                'records_updated' => $result['records_updated'],
                'errors'          => $result['errors'],
            ]);
            break;

        // ----------------------------------------------------------
        // Test de connexion pour un compte existant (par ID)
        // ----------------------------------------------------------
        case 'test':
            if (!$accountId) throw new \InvalidArgumentException('ID de compte manquant.');

            $provider = PluginDnsmanageAccount::getProvider($accountId);
            $provider->testConnection();

            echo json_encode(['success' => true]);
            break;

        // ----------------------------------------------------------
        // Test de connexion depuis le formulaire
        // ----------------------------------------------------------
        case 'test_form':
            $providerType = trim($_POST['provider_type'] ?? '');
            $endpoint     = trim($_POST['endpoint'] ?? '');

            if (!$providerType) throw new \InvalidArgumentException('Type de provider manquant.');

            $fields      = PluginDnsmanageProviderFactory::getCredentialFields($providerType);
            $credentials = [];

            // 1. Partir des credentials stockés si un compte existe (base de départ)
            if ($accountId) {
                $credentials = PluginDnsmanageCredential::getForAccount($accountId);
            }

            // 2. Écraser avec les valeurs saisies dans le formulaire (priorité au formulaire)
            foreach ($fields as $field) {
                $val = trim($_POST['cred_' . $field['key']] ?? '');
                if ($val !== '') {
                    $credentials[$field['key']] = $val;
                }
            }

            // 3. Vérifier que les champs obligatoires sont présents
            $missing = [];
            foreach ($fields as $field) {
                if ($field['required'] && empty($credentials[$field['key']])) {
                    $missing[] = $field['label'];
                }
            }
            if (!empty($missing)) {
                throw new \InvalidArgumentException('Champs manquants : ' . implode(', ', $missing));
            }

            $provider = PluginDnsmanageProviderFactory::create($providerType, $credentials, $endpoint);
            $provider->testConnection();

            echo json_encode(['success' => true]);
            break;

        default:
            throw new \InvalidArgumentException("Action inconnue : $action");
    }

} catch (\Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
