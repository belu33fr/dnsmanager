<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Verrouillage par domaine pour la modification bidirectionnelle des
 * enregistrements DNS. Un seul verrou actif par domaine à la fois,
 * qu'il soit posé par une synchronisation ('sync') ou par une action
 * utilisateur ('user').
 *
 * Table : glpi_plugin_dnsmanager_domainlocks
 * - domains_id UNIQUE : un seul verrou possible par domaine
 * - expires_at : purge automatique des verrous orphelins (crash serveur,
 *   plantage en cours de synchro) sans intervention manuelle
 */
class DomainLock
{
    /**
     * Flag anti-boucle en mémoire (le temps d'une requête PHP) : domaines
     * actuellement en cours de synchronisation PAR CE PROCESSUS. Permet aux
     * hooks PRE_ITEM_xxx et ITEM_xxx de reconnaître une écriture provenant de la
     * synchro elle-même (à ignorer) plutôt qu'une action utilisateur réelle.
     * Volontairement en mémoire (pas en base) : ce flag n'a de sens que pour
     * le processus PHP qui exécute la synchro, pas besoin de le partager.
     */
    private static array $syncInProgress = [];

    public static function markSyncInProgress(int $domainsId): void
    {
        self::$syncInProgress[$domainsId] = true;
    }

    public static function unmarkSyncInProgress(int $domainsId): void
    {
        unset(self::$syncInProgress[$domainsId]);
    }

    public static function isSyncInProgress(int $domainsId): bool
    {
        return !empty(self::$syncInProgress[$domainsId]);
    }

    public static function getTable(): string
    {
        return 'glpi_plugin_dnsmanager_domainlocks';
    }

    /**
     * Tente de poser le verrou immédiatement (sans attente).
     * Purge d'abord tout verrou expiré sur ce domaine avant de tenter.
     *
     * @param int    $domainsId
     * @param string $type      'sync' ou 'user'
     * @param int    $userId    0 pour une synchro/CRON, ID utilisateur sinon
     *
     * @return bool true si le verrou a été posé avec succès
     */
    public static function acquire(int $domainsId, string $type, int $userId = 0): bool
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) return true; // table absente = pas de verrouillage possible, ne pas bloquer

        self::purgeExpiredForDomain($domainsId);

        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['domains_id' => $domainsId]])->current();
        if ($existing) return false; // déjà verrouillé par quelqu'un d'autre

        $timeout = (int)PluginConfig::getDomainLockTimeout();
        $now     = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + max(1, $timeout) * 2); // marge de sécurité x2

        try {
            $DB->insert($table, [
                'domains_id' => $domainsId,
                'lock_type'  => $type,
                'locked_by'  => $userId,
                'locked_at'  => $now,
                'expires_at' => $expires,
            ]);
            return true;
        } catch (\Exception) {
            // Collision de concurrence (deux tentatives simultanées) : considérer comme non acquis
            return false;
        }
    }

    /**
     * Tente de poser le verrou en attendant (polling) si déjà pris,
     * jusqu'au timeout configuré. Usage serveur (CRON, synchro manuelle).
     * NE PAS utiliser côté formulaire utilisateur web (préférer un polling
     * JS côté navigateur pour ne pas garder une requête PHP ouverte).
     *
     * @return bool true si acquis avant timeout, false si abandon
     */
    public static function waitAndAcquire(int $domainsId, string $type, int $userId = 0, ?int $timeoutSeconds = null): bool
    {
        $timeout = $timeoutSeconds ?? (int)PluginConfig::getDomainLockTimeout();
        $deadline = time() + max(1, $timeout);

        do {
            if (self::acquire($domainsId, $type, $userId)) {
                return true;
            }
            usleep(500000); // 0.5s entre chaque tentative
        } while (time() < $deadline);

        return false;
    }

    /**
     * Libère le verrou d'un domaine. Ne fait rien si aucun verrou présent.
     */
    public static function release(int $domainsId): void
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) return;

        $DB->delete($table, ['domains_id' => $domainsId]);
    }

    /**
     * Vérifie si un domaine est actuellement verrouillé.
     * Purge automatiquement le verrou s'il est expiré (orphelin).
     */
    public static function isLocked(int $domainsId): bool
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) return false;

        self::purgeExpiredForDomain($domainsId);

        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['domains_id' => $domainsId]])->current();
        return (bool)$existing;
    }

    /**
     * Retourne les informations du verrou actif sur un domaine, ou null.
     */
    public static function getLockInfo(int $domainsId): ?array
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) return null;

        self::purgeExpiredForDomain($domainsId);

        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['domains_id' => $domainsId]])->current();
        return $existing ?: null;
    }

    /**
     * Purge le verrou d'un domaine précis s'il est expiré.
     */
    private static function purgeExpiredForDomain(int $domainsId): void
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) return;

        $DB->delete($table, [
            'domains_id' => $domainsId,
            'expires_at' => ['<', date('Y-m-d H:i:s')],
        ]);
    }

    /**
     * Purge tous les verrous expirés, tous domaines confondus.
     * Prévu pour être appelé périodiquement depuis une tâche CRON.
     *
     * @return int nombre de verrous purgés
     */
    public static function purgeAllExpired(): int
    {
        global $DB;
        $table = self::getTable();
        if (!$DB->tableExists($table)) return 0;

        $expiredIds = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['expires_at' => ['<', date('Y-m-d H:i:s')]]]) as $row) {
            $expiredIds[] = (int)$row['id'];
        }

        if (empty($expiredIds)) return 0;

        $DB->delete($table, ['id' => $expiredIds]);

        return count($expiredIds);
    }
}
