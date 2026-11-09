<?php
/**
 * Test du décodeur dnsRecord (Active Directory). À lancer sur le serveur :
 *     php tools/test_windowsdns_decoder.php
 * Sortie : une ligne par cas, code de retour 1 en cas d'échec.
 *
 * Les cas "réels" viennent d'un contrôleur de domaine (zones blservices.fr et
 * blconseils.com : A, NS, CNAME, SOA, MX, SRV) ; les cas "synthétiques" (TXT, AAAA, PTR)
 * sont construits d'après la spécification, en attendant des échantillons réels : ils ne
 * prouvent que la cohérence du décodeur.
 */
require __DIR__ . '/../src/WindowsDnsDecoder.php';
require __DIR__ . '/../src/WindowsDnsRules.php';

use GlpiPlugin\Dnsmanager\WindowsDnsDecoder as D;
use GlpiPlugin\Dnsmanager\WindowsDnsRules as R;

function hex(string $h): string { return hex2bin(str_replace(' ', '', $h)); }

function name(string $fqdn): string
{
    $labels = $fqdn === '.' ? [] : explode('.', rtrim($fqdn, '.'));
    $body = '';
    foreach ($labels as $l) { $body .= chr(strlen($l)) . $l; }
    $body .= "\0";
    return chr(strlen($body)) . chr(count($labels)) . $body;
}

function rec(int $type, string $data, int $ttl = 3600, int $ts = 0, int $rank = 240): string
{
    return pack('v', strlen($data)) . pack('v', $type) . chr(5) . chr($rank) . pack('v', 0)
         . pack('V', 1) . pack('N', $ttl) . pack('V', 0) . pack('V', $ts) . $data;
}

$cases = [
    // ---- réels (blservices.fr)
    ['A support',        hex('04 00 01 00 05 F0 00 00 17 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 C0 A8 FC FD'), ['A', 3600, true, 240, '192.168.252.253']],
    ['A bulletinrosaire', hex('04 00 01 00 05 F0 00 00 03 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 B2 AA 7D 59'), ['A', 3600, true, 240, '178.170.125.89']],
    ['A www',            hex('04 00 01 00 05 F0 00 00 1E 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 C0 A8 00 22'), ['A', 3600, true, 240, '192.168.0.34']],
    ['CNAME bureaux',    hex('1A 00 05 00 05 F0 00 00 11 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 18 03 07 62 75 72 65 61 75 78 0A 62 6C 63 6F 6E 73 65 69 6C 73 03 63 6F 6D 00'), ['CNAME', 3600, true, 240, 'bureaux.blconseils.com.']],
    ['A glue spirou',    hex('04 00 01 00 05 80 00 00 13 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 C0 A8 00 01'), ['A', 3600, true, 128, '192.168.0.1']],
    ['SOA @',            hex('4E 00 06 00 05 F0 00 00 12 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 00 00 00 11 00 00 03 84 00 00 02 58 00 01 51 80 00 00 0E 10 19 03 06 73 70 69 72 6F 75 0A 62 6C 63 6F 6E 73 65 69 6C 73 05 6C 6F 63 61 6C 00 1D 03 0A 68 6F 73 74 6D 61 73 74 65 72 0A 62 6C 63 6F 6E 73 65 69 6C 73 05 6C 6F 63 61 6C 00'), ['SOA', 3600, true, 240, 'spirou.blconseils.local. hostmaster.blconseils.local. 17 900 600 86400 3600']],
    ['NS @ 1',           hex('1B 00 02 00 05 F0 00 00 12 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 19 03 06 73 70 69 72 6F 75 0A 62 6C 63 6F 6E 73 65 69 6C 73 05 6C 6F 63 61 6C 00'), ['NS', 3600, true, 240, 'spirou.blconseils.local.']],
    ['NS @ 2',           hex('21 00 02 00 05 F0 00 00 12 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 1F 03 0C 70 74 69 74 2D 6D 61 75 72 69 63 65 0A 62 6C 63 6F 6E 73 65 69 6C 73 05 6C 6F 63 61 6C 00'), ['NS', 3600, true, 240, 'ptit-maurice.blconseils.local.']],
    // ---- réels (blconseils.com)
    ['MX @',             hex('30 00 0F 00 05 F0 00 00 40 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 00 0A 2C 05 0E 62 6C 63 6F 6E 73 65 69 6C 73 2D 63 6F 6D 04 6D 61 69 6C 0A 70 72 6F 74 65 63 74 69 6F 6E 07 6F 75 74 6C 6F 6F 6B 03 63 6F 6D 00'), ['MX', 3600, true, 240, '10 blconseils-com.mail.protection.outlook.com.']],
    ['SRV _autodiscover._tcp', hex('1D 00 21 00 05 F0 00 00 3E 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 00 0A 00 0A 01 BB 15 03 04 6D 61 69 6C 0A 62 6C 63 6F 6E 73 65 69 6C 73 03 63 6F 6D 00'), ['SRV', 3600, true, 240, '10 10 443 mail.blconseils.com.']],
    ['CNAME www',        hex('19 00 05 00 05 F0 00 00 3F 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 17 03 09 63 75 73 74 6F 6D 65 72 73 08 63 6D 6F 6E 73 69 74 65 02 66 72 00'), ['CNAME', 3600, true, 240, 'customers.cmonsite.fr.']],
    ['CNAME ftp (4 labels)', hex('14 00 05 00 05 F0 00 00 0E 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 12 04 03 66 74 70 04 36 30 67 70 03 6F 76 68 03 6E 65 74 00'), ['CNAME', 3600, true, 240, 'ftp.60gp.ovh.net.']],
    // délégation de sous-domaine : rang 0x82 et horodatage non nul (donc "non statique")
    ['NS délégation externe', hex('1B 00 02 00 05 82 00 00 10 00 00 00 00 00 0E 10 00 00 00 00 DA AC 36 00 19 03 06 73 70 69 72 6F 75 0A 62 6C 63 6F 6E 73 65 69 6C 73 05 6C 6F 63 61 6C 00'), ['NS', 3600, false, 130, 'spirou.blconseils.local.']],
    // ---- synthétiques (spécification)
    ['TXT (synth.)',     rec(16, chr(6) . 'v=spf1' . chr(5) . ' -all'), ['TXT', 3600, true, 240, 'v=spf1 -all']],
    ['AAAA (synth.)',    rec(28, inet_pton('2001:db8::1')), ['AAAA', 3600, true, 240, '2001:db8::1']],
    ['PTR (synth.)',     rec(12, name('host.example.com.')), ['PTR', 3600, true, 240, 'host.example.com.']],
    ['dynamique (synth.)', rec(1, inet_pton('10.0.0.5'), 1200, 3700000), ['A', 1200, false, 240, '10.0.0.5']],
    // ---- à ignorer
    ['supprimé (type 0)', rec(0, "\0\0"), null],
    ['tronqué',          substr(hex('04 00 01 00 05 F0 00 00 17 00 00 00 00 00 0E 10 00 00 00 00 00 00 00 00 C0 A8'), 0), null],
    ['type non géré',    rec(99, "\1\2\3\4"), null],
];

$fail = 0;
foreach ($cases as [$label, $raw, $expected]) {
    $r = D::decode($raw);
    $got = $r === null ? null : [$r['type'], $r['ttl'], $r['static'], $r['rank'], $r['data']];
    $ok = ($got === $expected);
    if (!$ok) { $fail++; }
    printf("%-22s %s%s\n", $label, $ok ? 'OK' : 'ECHEC', $ok ? '' : '  attendu=' . json_encode($expected) . ' obtenu=' . json_encode($got));
}

// glue
$g = [
    ['glue : nom avec point final',  D::isGlue('spirou.blconseils.local.', 240), true],
    ['glue : rang NS glue',          D::isGlue('spirou', 128),                   true],
    ['pas glue : enregistrement',    D::isGlue('www', 240),                      false],
];
foreach ($g as [$label, $got, $expected]) {
    $ok = ($got === $expected);
    if (!$ok) { $fail++; }
    printf("%-30s %s\n", $label, $ok ? 'OK' : 'ECHEC');
}

// ---- règles de sélection (WindowsDnsRules)
$internal = R::parseTldList(R::DEFAULT_INTERNAL_TLDS);
$r = [
    ['liste : ponctuation et point initial', R::parseTldList('local;.interne, lan'), ['local', 'interne', 'lan']],
    ['zone blconseils.com',          R::isImportableZone('blconseils.com', $internal),         true],
    ['zone BLConseils.local',        R::isImportableZone('BLConseils.local', $internal),       true],
    ['zone support.it-eco.fr',       R::isImportableZone('support.it-eco.fr', $internal),      true],
    ['zone RootDNSServers',          R::isImportableZone('RootDNSServers', $internal),         false],
    ['zone ..TrustAnchors',          R::isImportableZone('..TrustAnchors', $internal),         false],
    ['zone _msdcs.BLConseils.local', R::isImportableZone('_msdcs.BLConseils.local', $internal), false],
    ['zone inverse arpa',            R::isImportableZone('1.168.192.in-addr.arpa', $internal), false],
    ['extension inconnue',           R::isImportableZone('foo.zzzunknown', $internal),         false],
    ['extension interne déclarée',   R::isImportableZone('foo.corp', ['corp']),                true],
    ['extension interne non déclarée', R::isImportableZone('foo.corp', []),                    false],
    ['AD : _msdcs dans la zone AD',  R::isAdTechnicalNode('_ldap._tcp.dc._msdcs', 'blconseils.local', 'blconseils.local'), true],
    ['AD : _sites dans la zone AD',  R::isAdTechnicalNode('_kerberos._tcp.Default-First-Site-Name._sites', 'BLConseils.local', 'blconseils.local'), true],
    ['AD : _autodiscover ailleurs',  R::isAdTechnicalNode('_autodiscover._tcp', 'blconseils.com', 'blconseils.local'), false],
    ['AD : hôte ordinaire dans la zone AD', R::isAdTechnicalNode('www', 'blconseils.local', 'blconseils.local'), false],
    ['accepte : MX statique',        R::acceptRecord(['type' => 'MX', 'static' => true, 'rank' => 240], '@'),  true],
    ['refuse : SOA',                 R::acceptRecord(['type' => 'SOA', 'static' => true, 'rank' => 240], '@'), false],
    ['refuse : A dynamique',         R::acceptRecord(['type' => 'A', 'static' => false, 'rank' => 240], 'pc1'), false],
    ['accepte : NS dynamique (délégation)', R::acceptRecord(['type' => 'NS', 'static' => false, 'rank' => 130], 'externe'), true],
    ['refuse : glue',                R::acceptRecord(['type' => 'A', 'static' => true, 'rank' => 128], 'spirou'), false],
    ['fqdn sommet de zone',          R::fqdn('@', 'blconseils.com'),  'blconseils.com'],
    ['fqdn hôte',                    R::fqdn('www', 'blconseils.com'), 'www.blconseils.com'],
    ['référence stable',             R::recordRef('z.fr', 'www', 'A', '1.2.3.4') === R::recordRef('z.fr', 'www', 'A', '1.2.3.4'), true],
    ['référence selon la valeur',    R::recordRef('z.fr', 'www', 'A', '1.2.3.4') !== R::recordRef('z.fr', 'www', 'A', '1.2.3.5'), true],
];
foreach ($r as [$label, $got, $expected]) {
    $ok = ($got === $expected);
    if (!$ok) { $fail++; }
    printf("%-38s %s%s\n", $label, $ok ? 'OK' : 'ECHEC', $ok ? '' : '  attendu=' . json_encode($expected) . ' obtenu=' . json_encode($got));
}

echo $fail === 0 ? "\nTous les tests passent.\n" : "\n$fail test(s) en échec.\n";
exit($fail === 0 ? 0 : 1);
