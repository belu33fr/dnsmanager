<?php

use GlpiPlugin\Dnsmanager\Account;
use GlpiPlugin\Dnsmanager\Menu;

include('../../../inc/includes.php');

Session::checkRight('plugin_dnsmanager_account', READ);

Html::header(
    Account::getTypeName(2),
    $_SERVER['PHP_SELF'],
    Menu::MENU_SECTOR,
    'account'
);

Search::show(Account::class);

Html::footer();
