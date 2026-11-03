<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Verrou de la répercussion GLPI -> provider (modification bidirectionnelle).
 *
 * Par conception, AUCUN réglage de l'interface GLPI (fiche compte, configuration
 * du plugin, droits, profils) ne peut activer cette fonction. Elle ne s'active
 * que par une directive PHP côté serveur, hors de portée des utilisateurs :
 *
 *   // /var/glpi/config/local_define.php
 *   define('PLUGIN_DNSMANAGER_PUSH_ACCOUNTS', [1]);   // IDs des comptes DNS Hébergeurs autorisés
 *
 * Directive absente, vide ou invalide = aucune écriture possible, nulle part.
 *
 * Défense en profondeur (trois verrous indépendants) :
 *   1. setup.php n'enregistre les hooks sur DomainRecord que si la directive existe ;
 *   2. RecordSync/Account::hasBidirectionalSync() vérifient le compte concerné ;
 *   3. OvhProvider::rawCall() refuse toute requête non-GET sans la directive.
 */
class PushGuard
{
    public const CONSTANT = 'PLUGIN_DNSMANAGER_PUSH_ACCOUNTS';

    /**
     * @return int[] IDs des comptes explicitement autorisés par la directive serveur
     */
    public static function getAllowedAccountIds(): array
    {
        if (!defined(self::CONSTANT)) {
            return [];
        }
        $value = constant(self::CONSTANT);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $value), static fn(int $id): bool => $id > 0));
    }

    public static function isAnyEnabled(): bool
    {
        return self::getAllowedAccountIds() !== [];
    }

    public static function isEnabledForAccount(int $accountId): bool
    {
        return in_array($accountId, self::getAllowedAccountIds(), true);
    }

    /**
     * @throws \RuntimeException si l'écriture vers un provider n'est pas activée côté serveur
     */
    public static function assertWriteAllowed(): void
    {
        if (!self::isAnyEnabled()) {
            throw new \RuntimeException(
                'Écriture vers le provider désactivée : la modification depuis GLPI n\'est pas activée sur ce serveur.'
            );
        }
    }
}
