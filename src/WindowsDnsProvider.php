<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Serveur DNS Windows (zones intégrées à Active Directory), lu par LDAP. LECTURE SEULE.
 *
 * Les zones et leurs enregistrements sont des objets de l'annuaire (classes dnsZone et
 * dnsNode, attribut binaire dnsRecord) dans trois conteneurs possibles :
 *   - CN=MicrosoftDNS,DC=DomainDnsZones,<domaine>   (partition applicative du domaine)
 *   - CN=MicrosoftDNS,DC=ForestDnsZones,<forêt>     (partition applicative de la forêt)
 *   - CN=MicrosoftDNS,CN=System,<domaine>           (zones "compatibilité Windows 2000")
 * Seules les zones intégrées à l'AD sont visibles par ce moyen.
 *
 * Le décodage binaire est dans WindowsDnsDecoder, les règles de sélection dans WindowsDnsRules.
 * Les domaines GLPI créés ont le type « DNS AD » (voir DomainTypes), distinct de « Internet ».
 */
class WindowsDnsProvider implements ProviderInterface
{
    private string $uri;
    private string $bindDn;
    private string $password;
    private string $baseDn;
    private bool   $verifyCert;

    /** @var \LDAP\Connection|resource|null */
    private $link = null;

    private ?string $rootDn   = null;
    private string  $forestDn = '';
    private string  $adDomain = '';

    /** @var array<string, array{name:string, dn:string, container:string}>|null clé = nom en minuscules */
    private ?array $zones = null;

    private array $lastDiagnostic = [];

    /** Identité effective après la liaison (Who Am I) ; vide si le serveur ne la fournit pas */
    private string $whoami = '';

    /** Résultat de la lecture de chaque conteneur de zones (diagnostic) */
    private array $containerStatus = [];

    public function __construct(array $credentials, string $endpoint = '')
    {
        $this->uri        = trim((string)($credentials['ldap_uri'] ?? ''));
        $this->bindDn     = trim((string)($credentials['bind_dn'] ?? ''));
        $this->password   = (string)($credentials['password'] ?? '');
        $this->baseDn     = trim((string)($credentials['base_dn'] ?? ''));
        $this->verifyCert = strtolower(trim((string)($credentials['verify_cert'] ?? 'oui'))) !== 'non';
    }

    public function __destruct()
    {
        if ($this->link !== null) {
            @ldap_unbind($this->link);
            $this->link = null;
        }
    }

    // ------------------------------------------------------------------
    // Description
    // ------------------------------------------------------------------

    public static function getLabel(): string
    {
        return 'Windows DNS (Active Directory)';
    }

    public static function getEndpoints(): array
    {
        return [];
    }

    public static function getCredentialFields(): array
    {
        return [
            [
                'key'      => 'ldap_uri',
                'label'    => 'Serveur LDAP (URI)',
                'type'     => 'text',
                'required' => true,
                'help'     => 'ldaps://contrôleur.domaine.local:636 (recommandé) ou ldap://contrôleur.domaine.local:389.',
            ],
            [
                'key'      => 'bind_dn',
                'label'    => 'Compte de lecture',
                'type'     => 'text',
                'required' => true,
                'help'     => 'Compte du domaine en LECTURE SEULE, au format utilisateur@domaine.local ou DN complet. Aucun droit d\'écriture n\'est nécessaire.',
            ],
            [
                'key'      => 'password',
                'label'    => 'Mot de passe',
                'type'     => 'password',
                'required' => true,
                'help'     => '',
            ],
            [
                'key'      => 'base_dn',
                'label'    => 'Base LDAP (facultatif)',
                'type'     => 'text',
                'required' => false,
                'help'     => 'Ex. DC=domaine,DC=local. Laissez vide pour la détecter automatiquement.',
            ],
            [
                'key'      => 'verify_cert',
                'label'    => 'Vérifier le certificat (oui/non)',
                'type'     => 'text',
                'required' => false,
                'help'     => 'oui par défaut. Mettre non si LDAPS utilise un certificat d\'autorité interne non reconnu par ce serveur.',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Connexion
    // ------------------------------------------------------------------

    public function testConnection(): bool
    {
        return $this->guard(fn(): bool => $this->doTestConnection());
    }

    private function doTestConnection(): bool
    {
        $tlds = WindowsDnsRules::parseTldList(PluginConfig::getWindowsInternalTlds());

        $this->connect();
        $this->discover();

        $counts = [];
        $ok     = [];
        $ko     = [];
        foreach ($this->loadZones() as $z) {
            $counts[$z['container']] = ($counts[$z['container']] ?? 0) + 1;
            $reason = WindowsDnsRules::zoneRejectReason($z['name'], $tlds);
            if ($reason === null) {
                $ok[] = $z['name'];
            } else {
                $ko[] = $z['name'] . ' (' . $reason . ')';
            }
        }
        sort($ok);
        sort($ko);

        $this->lastDiagnostic = [
            'base'    => $this->rootDn,
            'domain'  => $this->adDomain,
            'who'     => $this->whoami,
            'status'  => $this->containerStatus,
            'counts'  => $counts,
            'ok'      => $ok,
            'ko'      => $ko,
        ];
        return true;
    }

    public function getDiagnosticMessage(): string
    {
        try {
            return $this->buildDiagnosticMessage();
        } catch (\Throwable $e) {
            return 'Diagnostic indisponible (' . get_class($e) . ') : ' . $e->getMessage() . ' [ligne ' . $e->getLine() . ']';
        }
    }

    /**
     * Les erreurs PHP "graves" (TypeError...) ne sont pas des exceptions ordinaires : sans cette
     * conversion, elles produisent une erreur HTTP 500 muette dans l'interface. On les transforme
     * en message lisible, avec leur origine.
     */
    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Erreur interne du fournisseur Windows DNS (' . get_class($e) . ') : ' . $e->getMessage()
                . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']',
                0,
                $e
            );
        }
    }

    private function buildDiagnosticMessage(): string
    {
        if (empty($this->lastDiagnostic)) {
            return '';
        }
        $d = $this->lastDiagnostic;

        $where = [];
        foreach ($d['counts'] as $container => $n) {
            $where[] = $container . ' : ' . $n;
        }
        $lines   = [];
        $lines[] = 'Base : ' . $d['base'] . ' — domaine AD : ' . $d['domain'];
        if ($d['who'] !== '') {
            $lines[] = 'Connecté en tant que : ' . $d['who'];
        }
        foreach ($d['status'] as $label => $st) {
            $extra = '';
            if (isset($st['probe'])) {
                $extra = ' — conteneur ' . ($st['probe']['readable'] ? 'lisible' : 'illisible')
                    . ', entrées visibles (tous types) : ' . ($st['probe']['children'] === null ? 'erreur' : $st['probe']['children']);
            }
            $lines[] = '  ' . $label . ' : ' . ($st['error'] === null
                ? $st['count'] . ' zone(s)' . $extra
                : 'illisible — ' . $st['error'] . (strpos($st['error'], 'code 50') !== false ? ' : le compte n\'a pas le droit de lire cette partition' : ''));
        }
        $allEmpty = $d['status'] !== [];
        foreach ($d['status'] as $st) {
            if ($st['error'] === null && $st['count'] === 0 && isset($st['probe']) && ($st['probe']['children'] ?? 0) === 0) {
                continue;
            }
            $allEmpty = false;
        }
        if ($allEmpty) {
            $lines[] = 'Aucune entrée n\'est visible dans les conteneurs DNS : le compte n\'a très probablement pas le droit de LISTER leur contenu. Voir « Droits du compte de lecture » dans le guide utilisateur (délégation en lecture seule ; ne PAS utiliser DnsAdmins).';
        }
        $lines[] = 'Zones trouvées : ' . (count($d['ok']) + count($d['ko'])) . ($where ? ' (' . implode(', ', $where) . ')' : '');
        $lines[] = 'Zones importables (' . count($d['ok']) . ') : ' . ($d['ok'] ? implode(', ', array_slice($d['ok'], 0, 40)) : 'aucune');
        if ($d['ko']) {
            $lines[] = 'Zones ignorées (' . count($d['ko']) . ') : ' . implode(' ; ', array_slice($d['ko'], 0, 25));
        }
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    public function listDomains(): array
    {
        return $this->guard(fn(): array => $this->doListDomains());
    }

    private function doListDomains(): array
    {
        $tlds = WindowsDnsRules::parseTldList(PluginConfig::getWindowsInternalTlds());
        $out  = [];
        foreach ($this->loadZones() as $z) {
            if (WindowsDnsRules::isImportableZone($z['name'], $tlds)) {
                $out[] = ['ref' => $z['name'], 'name' => $z['name'], 'comment' => ''];
            }
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** Références des zones importables (utilisé pour les statistiques) */
    public function listDomainRefs(): array
    {
        return array_map(static fn(array $d): string => $d['ref'], $this->listDomains());
    }

    public function listRecords(string $zoneRef): array
    {
        return $this->guard(fn(): array => $this->doListRecords($zoneRef));
    }

    private function doListRecords(string $zoneRef): array
    {
        $zone   = $this->findZone($zoneRef);
        $link   = $this->connect();
        $this->discover();
        $skipAd = true;   // enregistrements techniques de l'AD : exclus (uniquement dans la zone du domaine AD)

        $records = [];
        $cookie  = '';
        do {
            $res = @ldap_list($link, $zone['dn'], '(objectClass=dnsNode)', ['name', 'dnsRecord'], 0, 0, 0, LDAP_DEREF_NEVER, [
                ['oid' => LDAP_CONTROL_PAGEDRESULTS, 'value' => ['size' => 500, 'cookie' => $cookie]],
            ]);
            if ($res === false) {
                throw new \RuntimeException('Lecture de la zone "' . $zone['name'] . '" impossible : ' . ldap_error($link));
            }

            for ($entry = ldap_first_entry($link, $res); $entry !== false; $entry = ldap_next_entry($link, $entry)) {
                $names = ldap_get_values($link, $entry, 'name');
                $raws  = ldap_get_values_len($link, $entry, 'dnsRecord');
                if (!is_array($names) || !is_array($raws) || !isset($names[0])) {
                    continue;
                }
                $node = (string)$names[0];

                for ($i = 0; $i < (int)$raws['count']; $i++) {
                    $dec = WindowsDnsDecoder::decode($raws[$i]);
                    if ($dec === null || !WindowsDnsRules::acceptRecord($dec, $node)) {
                        continue;
                    }
                    if ($skipAd && WindowsDnsRules::isAdTechnicalNode($node, $zone['name'], $this->adDomain)) {
                        continue;
                    }
                    $records[] = [
                        'ref'      => WindowsDnsRules::recordRef($zone['name'], $node, $dec['type'], $dec['data']),
                        'name'     => WindowsDnsRules::fqdn($node, $zone['name']),
                        'type'     => $dec['type'],
                        'target'   => $dec['data'],
                        'ttl'      => $dec['ttl'],
                        'priority' => 0,
                    ];
                }
            }

            $errCode = 0;
            $matched = '';
            $errMsg  = '';
            $refs    = [];
            $ctrl    = [];
            @ldap_parse_result($link, $res, $errCode, $matched, $errMsg, $refs, $ctrl);
            $cookie = $ctrl[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '';
        } while ($cookie !== null && $cookie !== '');

        return $records;
    }

    public function getDomainInfo(string $zoneRef): array
    {
        // Un serveur DNS interne n'a ni registrar, ni dates d'expiration, ni contacts
        return [
            'creation_date'   => '',
            'expiration_date' => '',
            'admin_contact'   => '',
            'tech_contact'    => '',
            'billing_contact' => '',
        ];
    }

    /** TTL par défaut de la zone : champ "minimum" du SOA du sommet de zone */
    public function getZoneDefaultTtl(string $zoneRef): ?int
    {
        try {
            $zone = $this->findZone($zoneRef);
            $link = $this->connect();
            $res  = @ldap_read($link, 'DC=@,' . $zone['dn'], '(objectClass=dnsNode)', ['dnsRecord']);
            if ($res === false) {
                return null;
            }
            $entry = ldap_first_entry($link, $res);
            if ($entry === false) {
                return null;
            }
            $raws = ldap_get_values_len($link, $entry, 'dnsRecord');
            if (!is_array($raws)) {
                return null;
            }
            for ($i = 0; $i < (int)$raws['count']; $i++) {
                $dec = WindowsDnsDecoder::decode($raws[$i]);
                if ($dec !== null && $dec['type'] === 'SOA') {
                    $parts = explode(' ', $dec['data']);
                    return (int)end($parts);
                }
            }
        } catch (\Throwable $e) {
            // facultatif : on retombe sur "inconnu"
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Écriture : non prise en charge (lecture seule)
    // ------------------------------------------------------------------

    public function createRecord(string $zoneRef, array $data): string
    {
        throw new \RuntimeException('Serveur DNS Windows : lecture seule, aucune écriture n\'est prise en charge.');
    }

    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool
    {
        throw new \RuntimeException('Serveur DNS Windows : lecture seule, aucune écriture n\'est prise en charge.');
    }

    public function deleteRecord(string $zoneRef, string $recordRef): bool
    {
        throw new \RuntimeException('Serveur DNS Windows : lecture seule, aucune écriture n\'est prise en charge.');
    }

    // ------------------------------------------------------------------
    // LDAP
    // ------------------------------------------------------------------

    /** @return \LDAP\Connection|resource */
    private function connect()
    {
        if ($this->link !== null) {
            return $this->link;
        }
        if (!function_exists('ldap_connect')) {
            throw new \RuntimeException('L\'extension PHP LDAP n\'est pas disponible sur ce serveur.');
        }
        if ($this->uri === '' || $this->bindDn === '') {
            throw new \RuntimeException('Le serveur LDAP et le compte de lecture sont obligatoires.');
        }
        if ($this->password === '') {
            // Active Directory traite une liaison sans mot de passe comme une connexion ANONYME :
            // elle "réussit" mais ne permet pas de lire les zones DNS.
            throw new \RuntimeException('Le mot de passe du compte de lecture est obligatoire (une liaison sans mot de passe est anonyme et ne voit aucune zone).');
        }
        $parts  = parse_url($this->uri);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['ldap', 'ldaps'], true) || $host === '') {
            throw new \RuntimeException('URI LDAP invalide : ' . $this->uri . ' (attendu : ldaps://serveur:636 ou ldap://serveur:389).');
        }
        $port = (int)($parts['port'] ?? ($scheme === 'ldaps' ? 636 : 389));

        // Test réseau préalable : distingue "serveur injoignable" d'un problème de TLS ou de LDAP
        $errNo  = 0;
        $errStr = '';
        $sock   = @fsockopen($host, $port, $errNo, $errStr, 5);
        if ($sock === false) {
            throw new \RuntimeException(sprintf(
                'Le serveur %s est injoignable sur le port %d depuis le serveur GLPI (%s). Vérifiez le nom (résolution DNS), l\'adresse et le pare-feu.',
                $host, $port, $errStr !== '' ? $errStr : 'erreur ' . $errNo
            ));
        }
        fclose($sock);

        // Désactiver la vérification du certificat est une option GLOBALE du client LDAP du
        // processus PHP : on la rétablit juste après la liaison, pour qu'elle ne s'applique pas
        // aux autres connexions LDAP de GLPI (authentification, synchronisation d'utilisateurs).
        // La connexion déjà établie conserve son propre contexte TLS.
        $prevCert    = null;
        $certChanged = false;
        if (!$this->verifyCert && defined('LDAP_OPT_X_TLS_REQUIRE_CERT')) {
            // ldap_get_option() n'accepte PAS null (contrairement à ldap_set_option) : pour lire la
            // valeur globale actuelle, on passe par une connexion d'essai (ldap_connect ne se connecte
            // pas, il reprend simplement les réglages globaux).
            try {
                $probe = @ldap_connect('ldap://localhost');
                if ($probe !== false) {
                    $val = null;
                    if (@ldap_get_option($probe, LDAP_OPT_X_TLS_REQUIRE_CERT, $val)) {
                        $prevCert = $val;
                    }
                }
            } catch (\Throwable $e) {
                $prevCert = null;   // valeur inconnue : on rétablira la valeur par défaut d'OpenLDAP
            }
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
            $certChanged = true;
        }

        try {
            $link = @ldap_connect($this->uri);
            if ($link === false) {
                throw new \RuntimeException('URI LDAP invalide : ' . $this->uri);
            }
            ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, 10);

            if (!@ldap_bind($link, $this->bindDn, $this->password)) {
                $code = ldap_errno($link);
                $hint = '';
                if ($code === -1 && $scheme === 'ldaps' && $this->verifyCert) {
                    $hint = ' Le port répond : le certificat du serveur n\'est probablement pas reconnu par cette machine. Pour tester, mettez « Vérifier le certificat » à non ; pour une solution durable, installez l\'autorité de certification interne sur le serveur GLPI.';
                } elseif ($code === -1) {
                    $hint = ' Le port répond mais la connexion LDAP échoue : vérifiez que le schéma (ldap ou ldaps) correspond au port.';
                } elseif ($code === 49) {
                    $hint = ' Identifiants refusés : vérifiez le compte (utilisateur@domaine.local) et le mot de passe.';
                } elseif ($code === 8 || $code === 13) {
                    $hint = ' Le contrôleur de domaine exige une connexion chiffrée : utilisez ldaps://.';
                }
                throw new \RuntimeException('Connexion LDAP refusée : ' . ldap_error($link) . ' (code ' . $code . ').' . $hint);
            }

            // Identité réellement obtenue (Who Am I) : vide = connexion anonyme
            $who = null;
            if (function_exists('ldap_exop_whoami')) {
                try {
                    $res = @ldap_exop_whoami($link);
                    $who = is_string($res) ? trim($res) : null;
                } catch (\Throwable $e) {
                    $who = null;   // requête non prise en charge : sans importance
                }
            }
            if ($who !== null) {
                $this->whoami = $who;
                if ($who === '') {
                    throw new \RuntimeException('La liaison a réussi mais le serveur la traite comme anonyme : vérifiez le compte et le mot de passe.');
                }
            }
        } finally {
            if ($certChanged) {
                try {
                    ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $prevCert ?? LDAP_OPT_X_TLS_DEMAND);
                } catch (\Throwable $e) {
                    // rien à faire : le réglage global reste celui qu'il était
                }
            }
        }
        return $this->link = $link;
    }

    /** Détermine la base LDAP du domaine et celle de la forêt */
    private function discover(): void
    {
        if ($this->rootDn !== null) {
            return;
        }
        $link   = $this->connect();
        $base   = $this->baseDn;
        $forest = '';

        $res = @ldap_read($link, '', '(objectClass=*)', ['defaultNamingContext', 'rootDomainNamingContext']);
        if ($res !== false) {
            $e = ldap_get_entries($link, $res);
            if (($e['count'] ?? 0) > 0) {
                if ($base === '') {
                    $base = (string)($e[0]['defaultnamingcontext'][0] ?? '');
                }
                $forest = (string)($e[0]['rootdomainnamingcontext'][0] ?? '');
            }
        }
        if ($base === '') {
            throw new \RuntimeException('Base LDAP introuvable : renseignez-la dans le compte (ex. DC=domaine,DC=local).');
        }

        $this->rootDn   = $base;
        $this->forestDn = $forest !== '' ? $forest : $base;
        $this->adDomain = self::dnToDomain($base);
    }

    private static function dnToDomain(string $dn): string
    {
        $parts = [];
        foreach (explode(',', $dn) as $rdn) {
            $rdn = trim($rdn);
            if (stripos($rdn, 'DC=') === 0) {
                $parts[] = substr($rdn, 3);
            }
        }
        return implode('.', $parts);
    }

    /** Conteneurs de zones, par ordre de préférence si une zone existe à plusieurs endroits */
    private function containers(): array
    {
        $this->discover();
        return [
            'DomainDnsZones' => 'CN=MicrosoftDNS,DC=DomainDnsZones,' . $this->rootDn,
            'ForestDnsZones' => 'CN=MicrosoftDNS,DC=ForestDnsZones,' . $this->forestDn,
            'System'         => 'CN=MicrosoftDNS,CN=System,' . $this->rootDn,
        ];
    }

    /** @return array<string, array{name:string, dn:string, container:string}> */
    private function loadZones(): array
    {
        if ($this->zones !== null) {
            return $this->zones;
        }
        $link  = $this->connect();
        $zones = [];
        foreach ($this->containers() as $label => $container) {
            $res = @ldap_list($link, $container, '(objectClass=dnsZone)', ['name']);
            if ($res === false) {
                // Conteneur absent (code 32) ou illisible (code 50 : droits insuffisants) : on le note
                // pour le diagnostic, et on passe au suivant.
                $this->containerStatus[$label] = ['dn' => $container, 'error' => ldap_error($link) . ' (code ' . ldap_errno($link) . ')', 'count' => 0];
                continue;
            }
            $entries = ldap_get_entries($link, $res);
            $this->containerStatus[$label] = ['dn' => $container, 'error' => null, 'count' => (int)$entries['count']];
            if ((int)$entries['count'] === 0) {
                $this->containerStatus[$label]['probe'] = $this->probeContainer($link, $container);
            }
            for ($i = 0; $i < (int)$entries['count']; $i++) {
                $name = $entries[$i]['name'][0] ?? null;
                if ($name === null) {
                    continue;
                }
                $key = strtolower((string)$name);
                if (!isset($zones[$key])) {
                    $zones[$key] = ['name' => (string)$name, 'dn' => (string)$entries[$i]['dn'], 'container' => $label];
                }
            }
        }
        return $this->zones = $zones;
    }

    /**
     * Conteneur sans zone : distingue "illisible" / "lisible mais contenu invisible" / "vide",
     * pour reconnaître un droit de lecture manquant (Active Directory masque alors les objets
     * sans renvoyer d'erreur).
     *
     * @param \LDAP\Connection|resource $link
     */
    private function probeContainer($link, string $dn): array
    {
        $read     = @ldap_read($link, $dn, '(objectClass=*)', ['objectClass']);
        $readable = ($read !== false && ldap_count_entries($link, $read) > 0);
        $list     = @ldap_list($link, $dn, '(objectClass=*)', ['name']);
        return [
            'readable' => $readable,
            'children' => $list === false ? null : (int)ldap_count_entries($link, $list),
        ];
    }

    /** @return array{name:string, dn:string, container:string} */
    private function findZone(string $zoneRef): array
    {
        $zones = $this->loadZones();
        $key   = strtolower($zoneRef);
        if (!isset($zones[$key])) {
            throw new \RuntimeException('Zone "' . $zoneRef . '" introuvable sur ce serveur DNS.');
        }
        return $zones[$key];
    }
}
