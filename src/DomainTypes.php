<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Types de domaine GLPI gérés par le plugin. Les domaines des fournisseurs Internet
 * (OVH, Gandi) sont de type « Internet » ; ceux d'un serveur Windows / Active Directory
 * ont leur propre type (configurable, « DNS AD » par défaut). Deux domaines de même nom
 * peuvent ainsi coexister (DNS externe et DNS interne d'un même nom) sans se confondre.
 */
final class DomainTypes
{
    public const INTERNET = 'Internet';

    public static function nameForProvider(string $providerType): string
    {
        return $providerType === 'windowsdns' ? PluginConfig::getAdDomainTypeName() : self::INTERNET;
    }

    /**
     * Identifiant du type attendu pour ce fournisseur : le type choisi dans la configuration du
     * plugin ; à défaut, le type par défaut (« Internet » / « DNS AD »), créé s'il n'existe pas.
     */
    public static function idForProvider(string $providerType): int
    {
        $configured = self::configuredId($providerType);
        $id = ($configured > 0 && self::exists($configured))
            ? $configured
            : self::idByName(self::nameForProvider($providerType), true);

        // Garde-fou : les types Internet et DNS AD doivent être distincts, sinon un domaine externe et un
        // domaine interne de même nom se confondraient.
        $other = $providerType === 'windowsdns' ? 'ovh' : 'windowsdns';
        $otherId = self::effectiveId($other, self::configuredId($other));
        if ($otherId > 0 && $otherId === $id) {
            throw new \RuntimeException('Les types de domaine Internet et DNS AD sont identiques dans la configuration du plugin : corrigez-les avant d\'importer ou de synchroniser.');
        }
        return $id;
    }

    private static function configuredId(string $providerType): int
    {
        return $providerType === 'windowsdns' ? PluginConfig::getAdDomainTypeId() : PluginConfig::getInternetDomainTypeId();
    }

    /**
     * Identifiant EFFECTIF du type d'un fournisseur pour un réglage donné : le type choisi s'il
     * existe, sinon le type par défaut (« Internet » / « DNS AD ») s'il existe déjà, sinon 0.
     * Ne crée rien. Sert à comparer ce qui sera réellement utilisé, valeur par défaut comprise.
     */
    public static function effectiveId(string $providerType, int $configured): int
    {
        if ($configured > 0 && self::exists($configured)) {
            return $configured;
        }
        return self::idByName(self::nameForProvider($providerType), false);
    }

    public static function exists(int $id): bool
    {
        global $DB;
        return (bool)$DB->request(['FROM' => 'glpi_domaintypes', 'WHERE' => ['id' => $id]])->current();
    }

    public static function idByName(string $name, bool $create): int
    {
        global $DB;
        $row = $DB->request(['FROM' => 'glpi_domaintypes', 'WHERE' => ['name' => $name]])->current();
        if ($row) {
            return (int)$row['id'];
        }
        if (!$create) {
            return 0;
        }
        $DB->insert('glpi_domaintypes', ['name' => $name, 'comment' => 'Créé par DNSManage']);
        return (int)$DB->insertId();
    }

    /** Identifiants de TOUS les types gérés par le plugin (Internet, DNS AD), sans en créer */
    private static function managedIds(): array
    {
        $ids = [];
        foreach (['ovh', 'windowsdns'] as $provider) {   // 'ovh' : tout fournisseur Internet
            $id = self::effectiveId($provider, self::configuredId($provider));
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /** Identifiants des AUTRES types gérés par le plugin (sans en créer) */
    public static function otherManagedIds(int $ownId): array
    {
        return array_values(array_diff(self::managedIds(), [$ownId]));
    }

    /**
     * Existe-t-il un domaine actif de même nom, d'un type que le plugin ne gère pas (saisi à
     * la main, par exemple) ? Dans ce cas il est considéré comme géré par un tiers : on n'y
     * touche pas et on ne propose pas de l'importer.
     */
    public static function hasForeignDomain(string $name, int $ownId): bool
    {
        global $DB;
        $managed = array_merge([$ownId], self::otherManagedIds($ownId));
        return (bool)$DB->request([
            'FROM'  => 'glpi_domains',
            'WHERE' => ['name' => $name, 'is_deleted' => 0, 'NOT' => ['domaintypes_id' => $managed]],
            'LIMIT' => 1,
        ])->current();
    }
}
