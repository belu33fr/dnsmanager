<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Règles de sélection (pures, sans accès réseau ni base) pour la remontée des zones et
 * des enregistrements d'un serveur DNS Windows. Testées par tools/test_windowsdns_decoder.php.
 */
final class WindowsDnsRules
{
    /** Extensions internes par défaut (modifiables dans la configuration du plugin) */
    public const DEFAULT_INTERNAL_TLDS = 'local;internal;interne;lan;corp;home;intranet;private';

    /**
     * Extensions Internet reconnues d'office, en plus de toute extension à 2 lettres (domaines
     * nationaux) et des extensions IDN (xn--). Liste volontairement non exhaustive : une
     * extension absente se déclare dans la configuration du plugin.
     */
    private const INTERNET_TLDS = [
        'com', 'net', 'org', 'info', 'biz', 'edu', 'gov', 'mil', 'int', 'name', 'pro', 'aero', 'asia',
        'coop', 'jobs', 'mobi', 'museum', 'tel', 'travel', 'cat', 'post',
        'app', 'dev', 'cloud', 'online', 'site', 'shop', 'store', 'tech', 'xyz', 'top', 'club', 'blog',
        'email', 'digital', 'group', 'agency', 'solutions', 'systems', 'services', 'network', 'company',
        'support', 'media', 'world', 'website', 'space', 'life', 'live', 'link', 'page', 'host', 'work',
        'zone', 'tools', 'expert', 'consulting', 'center', 'academy', 'training', 'paris', 'bzh', 'alsace',
    ];

    /** @return string[] liste normalisée (minuscules, sans point initial) */
    public static function parseTldList(string $conf): array
    {
        $out = [];
        foreach (preg_split('/[;,\s]+/', strtolower($conf)) ?: [] as $tld) {
            $tld = ltrim(trim($tld), '.');
            if ($tld !== '') {
                $out[$tld] = $tld;
            }
        }
        return array_values($out);
    }

    /**
     * Pourquoi une zone est écartée, ou null si elle est importable.
     *
     * @param string[] $internalTlds extensions internes configurées
     */
    public static function zoneRejectReason(string $zone, array $internalTlds): ?string
    {
        $zone = strtolower(trim($zone, " \t."));
        if ($zone === '' || strpos($zone, '.') === false) {
            return 'zone technique ou sans extension';
        }
        $labels = explode('.', $zone);
        foreach ($labels as $label) {
            if ($label === '') {
                return 'nom de zone invalide';
            }
            if ($label[0] === '_') {
                return 'zone technique (' . $label . ')';
            }
        }
        $tld = end($labels);
        if ($tld === 'arpa') {
            return 'zone inverse';
        }
        if (in_array($tld, $internalTlds, true)
            || in_array($tld, self::INTERNET_TLDS, true)
            || preg_match('/^[a-z]{2}$/', $tld) === 1
            || preg_match('/^xn--[a-z0-9-]{1,59}$/', $tld) === 1) {
            return null;
        }
        return 'extension non reconnue (.' . $tld . ') : à déclarer dans la configuration du plugin';
    }

    /** @param string[] $internalTlds */
    public static function isImportableZone(string $zone, array $internalTlds): bool
    {
        return self::zoneRejectReason($zone, $internalTlds) === null;
    }

    /**
     * Enregistrements techniques de l'Active Directory lui-même (localisation des contrôleurs
     * de domaine : _msdcs, _sites, _tcp, _udp...). Ne concerne QUE la zone du domaine AD :
     * ailleurs, un enregistrement comme _autodiscover._tcp est un enregistrement ordinaire.
     */
    public static function isAdTechnicalNode(string $node, string $zone, string $adDomain): bool
    {
        if ($adDomain === '' || strtolower(rtrim($zone, '.')) !== strtolower(rtrim($adDomain, '.'))) {
            return false;
        }
        return preg_match('/(^|\.)(_msdcs|_sites|_tcp|_udp|domaindnszones|forestdnszones)(\.|$)/i', $node) === 1;
    }

    /**
     * Un enregistrement décodé doit-il remonter dans GLPI ?
     *  - entrées "glue" (hors zone) : non ;
     *  - SOA : non, sauf demande explicite ;
     *  - enregistrements DYNAMIQUES de type A, AAAA, PTR (postes qui s'inscrivent seuls) : non ;
     *    les autres types (NS, CNAME, MX, TXT, SRV), même avec un horodatage, sont conservés
     *    (ex. délégation de sous-domaine).
     *
     * @param array{type:string, static:bool, rank:int} $decoded
     */
    public static function acceptRecord(array $decoded, string $node, bool $importSoa = false): bool
    {
        if (WindowsDnsDecoder::isGlue($node, (int)$decoded['rank'])) {
            return false;
        }
        if ($decoded['type'] === 'SOA' && !$importSoa) {
            return false;
        }
        if (!$decoded['static'] && in_array($decoded['type'], ['A', 'AAAA', 'PTR'], true)) {
            return false;
        }
        return true;
    }

    /** Nom complet d'un enregistrement : '@' désigne le sommet de la zone */
    public static function fqdn(string $node, string $zone): string
    {
        return $node === '@' ? $zone : $node . '.' . $zone;
    }

    /** Référence stable d'un enregistrement (indépendante de l'ordre des valeurs) */
    public static function recordRef(string $zone, string $node, string $type, string $data): string
    {
        return $zone . '/' . $node . '/' . $type . '/' . substr(sha1($data), 0, 12);
    }
}
