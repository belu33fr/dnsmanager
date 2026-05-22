<?php
/**
 * DNSManage - Endpoint AJAX : données dynamiques d'un provider
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
// Utiliser 'config' comme les autres pages du plugin
Session::checkRight('config', READ);

header('Content-Type: application/json');

$providerType = trim($_GET['provider'] ?? '');

if (!$providerType || !PluginDnsmanageProviderFactory::isSupported($providerType)) {
    echo json_encode([
        'endpoints' => (object)[], // objet vide, pas tableau
        'fields'    => [],
    ]);
    exit;
}

echo json_encode([
    'endpoints' => PluginDnsmanageProviderFactory::getEndpoints($providerType),
    'fields'    => PluginDnsmanageProviderFactory::getCredentialFields($providerType),
]);
