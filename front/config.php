<?php

use GlpiPlugin\Dnsmanager\Right;
use GlpiPlugin\Dnsmanager\PluginConfig;

include('../../../inc/includes.php');

Session::checkRight('plugin_dnsmanager_config', READ);

Html::header(
    __('Configuration DNS Hébergeurs', 'dnsmanager'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

if (isset($_POST['update']) && Right::canConfig()) {
    PluginConfig::save($_POST);
    Html::back();
}

if (isset($_POST['clear_cache']) && Right::canCache()) {
    global $DB;
    $DB->delete('glpi_plugin_dnsmanager_records', [true]);
    \Session::addMessageAfterRedirect(__('Cache vidé avec succès.', 'dnsmanager'), true, INFO);
    Html::back();
}

PluginConfig::showConfigForm();

Html::footer();
