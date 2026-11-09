<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Décodeur des enregistrements DNS stockés dans Active Directory : valeurs de l'attribut
 * binaire `dnsRecord` des objets `dnsNode` (structure DNS_RPC_RECORD, [MS-DNSP]).
 *
 * Classe pure (aucun accès réseau ni base) : testable seule, voir tools/test_windowsdns_decoder.php.
 *
 * Structure d'une valeur (24 octets d'en-tête puis les données) :
 *   0  DataLength  2 octets  (little-endian)
 *   2  Type        2 octets  (little-endian) : 1 A, 2 NS, 5 CNAME, 6 SOA, 12 PTR, 15 MX, 16 TXT, 28 AAAA, 33 SRV
 *   4  Version     1 octet   (5)
 *   5  Rank        1 octet   (240 = donnée de zone, 128 = glue de NS, 64 = glue, 8 = racine)
 *   6  Flags       2 octets
 *   8  Serial      4 octets  (little-endian)
 *  12  TTL         4 octets  (BIG-endian)
 *  16  Reserved    4 octets
 *  20  Timestamp   4 octets  (little-endian ; 0 = enregistrement statique, sinon dynamique)
 *  24  Data
 *
 * Un nom est codé : [longueur totale (terminateur compris)] [nombre de labels] puis, pour
 * chaque label, [longueur] [octets], et un 0 final.
 *
 * Validé sur des données réelles : A, NS, CNAME, SOA. MX, TXT, SRV, AAAA et PTR suivent la
 * spécification mais n'ont pas encore été confrontés à des données réelles.
 */
final class WindowsDnsDecoder
{
    public const RANK_ZONE    = 240;
    public const RANK_NS_GLUE = 128;
    public const RANK_GLUE    = 64;

    private const TYPES = [
        1  => 'A',
        2  => 'NS',
        5  => 'CNAME',
        6  => 'SOA',
        12 => 'PTR',
        15 => 'MX',
        16 => 'TXT',
        28 => 'AAAA',
        33 => 'SRV',
    ];

    /**
     * @param string $raw valeur binaire brute (un élément de l'attribut dnsRecord)
     * @return array{type:string, ttl:int, static:bool, rank:int, data:string}|null
     *         null = à ignorer (supprimé, type non géré ou données illisibles)
     */
    public static function decode(string $raw): ?array
    {
        if (strlen($raw) < 24) {
            return null;
        }
        $h = unpack('vlen/vtype/Cversion/Crank/vflags/Vserial', $raw);
        $ttl = unpack('Nttl', substr($raw, 12, 4));
        $ts  = unpack('Vts', substr($raw, 20, 4));

        $len = (int)$h['len'];
        if (strlen($raw) < 24 + $len) {
            return null;
        }
        $type = (int)$h['type'];
        if ($type === 0 || !isset(self::TYPES[$type])) {
            return null;   // 0 = enregistrement supprimé (tombstone)
        }

        $data = self::decodeData($type, substr($raw, 24, $len));
        if ($data === null) {
            return null;
        }

        return [
            'type'   => self::TYPES[$type],
            'ttl'    => (int)$ttl['ttl'],
            'static' => ((int)$ts['ts'] === 0),
            'rank'   => (int)$h['rank'],
            'data'   => $data,
        ];
    }

    /**
     * Un nœud dont le nom se termine par un point est une entrée "glue" hors zone
     * (ex. l'adresse d'un serveur de noms situé dans une autre zone), pas un enregistrement
     * de la zone elle-même ; un rang de glue indique la même chose.
     */
    public static function isGlue(string $nodeName, int $rank): bool
    {
        return substr($nodeName, -1) === '.' || $rank === self::RANK_NS_GLUE || $rank === self::RANK_GLUE;
    }

    private static function decodeData(int $type, string $d): ?string
    {
        switch ($type) {
            case 1:   // A
                return strlen($d) === 4 ? self::ip($d) : null;
            case 28:  // AAAA
                return strlen($d) === 16 ? self::ip($d) : null;
            case 2:   // NS
            case 5:   // CNAME
            case 12:  // PTR
                return self::readName($d, 0);
            case 15:  // MX : préférence (2 octets, big-endian) + nom
                if (strlen($d) < 3) {
                    return null;
                }
                $pref = unpack('npref', substr($d, 0, 2));
                $name = self::readName($d, 2);
                return $name === null ? null : $pref['pref'] . ' ' . $name;
            case 33:  // SRV : priorité, poids, port (2 octets chacun, big-endian) + nom
                if (strlen($d) < 7) {
                    return null;
                }
                $srv  = unpack('nprio/nweight/nport', substr($d, 0, 6));
                $name = self::readName($d, 6);
                return $name === null ? null : $srv['prio'] . ' ' . $srv['weight'] . ' ' . $srv['port'] . ' ' . $name;
            case 16:  // TXT : suite de chaînes [longueur][octets], concaténées
                $out = '';
                $p   = 0;
                $max = strlen($d);
                while ($p < $max) {
                    $l = ord($d[$p]);
                    if ($p + 1 + $l > $max) {
                        return null;
                    }
                    $out .= substr($d, $p + 1, $l);
                    $p += 1 + $l;
                }
                return $out;
            case 6:   // SOA : serial, refresh, retry, expire, minimum (4 octets big-endian) + 2 noms
                if (strlen($d) < 22) {
                    return null;
                }
                $soa   = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nmin', substr($d, 0, 20));
                $next  = 0;
                $mname = self::readName($d, 20, $next);
                if ($mname === null) {
                    return null;
                }
                $rname = self::readName($d, $next);
                if ($rname === null) {
                    return null;
                }
                return $mname . ' ' . $rname . ' ' . $soa['serial'] . ' ' . $soa['refresh'] . ' '
                    . $soa['retry'] . ' ' . $soa['expire'] . ' ' . $soa['min'];
        }
        return null;
    }

    private static function ip(string $packed): ?string
    {
        $ip = @inet_ntop($packed);
        return $ip === false ? null : $ip;
    }

    /**
     * Lit un nom à partir de l'offset donné. $next reçoit l'offset qui suit ce nom.
     */
    private static function readName(string $d, int $offset, ?int &$next = null): ?string
    {
        $max = strlen($d);
        if ($offset + 2 > $max) {
            return null;
        }
        $total  = ord($d[$offset]);
        $count  = ord($d[$offset + 1]);
        $p      = $offset + 2;
        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            if ($p >= $max) {
                return null;
            }
            $l = ord($d[$p]);
            if ($p + 1 + $l > $max) {
                return null;
            }
            $labels[] = substr($d, $p + 1, $l);
            $p += 1 + $l;
        }
        $next = $offset + 2 + $total;
        return $labels === [] ? '.' : implode('.', $labels) . '.';
    }
}
