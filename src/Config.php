<?php

namespace GlpiPlugin\Dnsmanager;

use CommonDBTM;

class Config extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return 'DNS Hébergeurs Config';
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_dnsmanager_configs';
    }

    // ------------------------------------------------------------------
    // Chiffrement AES-256-CBC
    // ------------------------------------------------------------------

    private static ?string $encryptionKey = null;

    public static function getEncryptionKey(): string
    {
        if (self::$encryptionKey === null) {
            global $DB;
            $row = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['config_key' => 'encryption_key'],
            ])->current();
            self::$encryptionKey = $row['config_value'] ?? '';
        }
        return self::$encryptionKey;
    }

    public static function encrypt(string $value): string
    {
        if ($value === '') return '';
        $key    = hex2bin(self::getEncryptionKey());
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) throw new \RuntimeException('Erreur chiffrement.');
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') return '';
        $key  = hex2bin(self::getEncryptionKey());
        $raw  = base64_decode($encrypted);
        if (strlen($raw) < 16) return '';
        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }
}
