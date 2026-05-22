<?php
/**
 * DNSManage - Provider OVH
 *
 * Implémentation basée sur le SDK officiel OVH (github.com/ovh/php-ovh)
 * Authentification par signature SHA1 : $1$sha1(AS+CK+METHOD+URL+BODY+TIMESTAMP)
 */

class PluginDnsmanageOvhProvider implements PluginDnsmanageProviderInterface
{
    private const OVH_ENDPOINTS = [
        'ovh-eu'        => 'https://eu.api.ovh.com/1.0',
        'ovh-ca'        => 'https://ca.api.ovh.com/1.0',
        'ovh-us'        => 'https://api.us.ovhcloud.com/1.0',
        'soyoustart-eu' => 'https://eu.api.soyoustart.com/1.0',
        'soyoustart-ca' => 'https://ca.api.soyoustart.com/1.0',
        'kimsufi-eu'    => 'https://eu.api.kimsufi.com/1.0',
        'kimsufi-ca'    => 'https://ca.api.kimsufi.com/1.0',
    ];

    private string $endpoint;
    private string $appKey;
    private string $appSecret;
    private string $consumerKey;
    private ?int   $timeDelta = null;

    public function __construct(array $credentials, string $endpoint = 'ovh-eu')
    {
        $this->appKey      = $credentials['app_key']      ?? '';
        $this->appSecret   = $credentials['app_secret']   ?? '';
        $this->consumerKey = $credentials['consumer_key'] ?? '';
        $this->endpoint    = self::OVH_ENDPOINTS[$endpoint] ?? self::OVH_ENDPOINTS['ovh-eu'];
    }

    // ------------------------------------------------------------------
    // Méta-information
    // ------------------------------------------------------------------

    public static function getLabel(): string
    {
        return 'OVH / OVHcloud';
    }

    public static function getEndpoints(): array
    {
        return [
            'ovh-eu'        => 'OVH Europe (ovh-eu)',
            'ovh-ca'        => 'OVH Canada (ovh-ca)',
            'ovh-us'        => 'OVHcloud US (ovh-us)',
            'soyoustart-eu' => 'So you Start Europe',
            'soyoustart-ca' => 'So you Start Canada',
            'kimsufi-eu'    => 'Kimsufi Europe',
            'kimsufi-ca'    => 'Kimsufi Canada',
        ];
    }

    public static function getCredentialFields(): array
    {
        return [
            [
                'key'      => 'app_key',
                'label'    => 'Application Key (AK)',
                'type'     => 'text',
                'required' => true,
                'help'     => 'Créer sur https://eu.api.ovh.com/createApp',
            ],
            [
                'key'      => 'app_secret',
                'label'    => 'Application Secret (AS)',
                'type'     => 'password',
                'required' => true,
                'help'     => 'Fourni lors de la création de l\'application',
            ],
            [
                'key'      => 'consumer_key',
                'label'    => 'Consumer Key (CK)',
                'type'     => 'password',
                'required' => true,
                'help'     => 'Généré après validation des droits API',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Test de connexion
    // ------------------------------------------------------------------

    public function testConnection(): bool
    {
        $result = $this->get('/me');
        if (!isset($result['nichandle'])) {
            throw new \RuntimeException('Réponse inattendue de l\'API OVH.');
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Import domaines
    // ------------------------------------------------------------------

    public function listDomains(): array
    {
        $zones = $this->get('/domain/zone');

        if (!is_array($zones)) {
            return [];
        }

        $domains = [];
        foreach ($zones as $zone) {
            try {
                $info = $this->get('/domain/zone/' . urlencode((string) $zone));
                $domains[] = [
                    'ref'     => (string) $zone,
                    'name'    => (string) $zone,
                    'comment' => isset($info['lastUpdate'])
                        ? 'Dernière MAJ OVH : ' . $info['lastUpdate']
                        : '',
                ];
            } catch (\Exception) {
                $domains[] = [
                    'ref'     => (string) $zone,
                    'name'    => (string) $zone,
                    'comment' => '',
                ];
            }
        }

        return $domains;
    }

    // ------------------------------------------------------------------
    // Import enregistrements DNS
    // ------------------------------------------------------------------

    public function listRecords(string $zoneRef): array
    {
        $recordIds = $this->get('/domain/zone/' . urlencode($zoneRef) . '/record');

        if (!is_array($recordIds)) {
            return [];
        }

        $records = [];
        foreach ($recordIds as $recordId) {
            try {
                $rec = $this->get(
                    '/domain/zone/' . urlencode($zoneRef) . '/record/' . (int) $recordId
                );
                $records[] = [
                    'ref'      => (string) $recordId,
                    'name'     => $this->normalizeSubdomain((string)($rec['subDomain'] ?? ''), $zoneRef),
                    'type'     => strtoupper((string)($rec['fieldType'] ?? '')),
                    'target'   => (string)($rec['target'] ?? ''),
                    'ttl'      => (int)($rec['ttl'] ?? 0),
                    'priority' => 0,
                ];
            } catch (\Exception) {
                // Enregistrement inaccessible, on continue
            }
        }

        return $records;
    }

    // ------------------------------------------------------------------
    // Modification (Phase 3 — non activé en UI)
    // ------------------------------------------------------------------

    public function createRecord(string $zoneRef, array $data): string
    {
        $result = $this->post(
            '/domain/zone/' . urlencode($zoneRef) . '/record',
            [
                'fieldType' => $data['type'],
                'subDomain' => $data['name'] === $zoneRef ? '' : $data['name'],
                'target'    => $data['target'],
                'ttl'       => $data['ttl'] ?? 0,
            ]
        );
        $this->post('/domain/zone/' . urlencode($zoneRef) . '/refresh', null);
        return (string)($result['id'] ?? '');
    }

    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool
    {
        $this->put(
            '/domain/zone/' . urlencode($zoneRef) . '/record/' . (int) $recordRef,
            [
                'subDomain' => $data['name']   ?? '',
                'target'    => $data['target'] ?? '',
                'ttl'       => $data['ttl']    ?? 0,
            ]
        );
        $this->post('/domain/zone/' . urlencode($zoneRef) . '/refresh', null);
        return true;
    }

    public function deleteRecord(string $zoneRef, string $recordRef): bool
    {
        $this->delete('/domain/zone/' . urlencode($zoneRef) . '/record/' . (int) $recordRef);
        $this->post('/domain/zone/' . urlencode($zoneRef) . '/refresh', null);
        return true;
    }

    // ------------------------------------------------------------------
    // Méthodes HTTP — calquées sur le SDK officiel OVH
    // ------------------------------------------------------------------

    private function get(string $path): mixed
    {
        return $this->rawCall('GET', $path, null);
    }

    private function post(string $path, ?array $content): mixed
    {
        return $this->rawCall('POST', $path, $content);
    }

    private function put(string $path, array $content): mixed
    {
        return $this->rawCall('PUT', $path, $content);
    }

    private function delete(string $path): void
    {
        $this->rawCall('DELETE', $path, null);
    }

    /**
     * Exécute un appel signé vers l'API OVH.
     * Logique de signature identique au SDK officiel php-ovh.
     */
    private function rawCall(string $method, string $path, ?array $content): mixed
    {
        $url = $this->endpoint . $path;

        // Corps de la requête — vide pour GET/DELETE
        if ($content !== null && $method !== 'GET') {
            $body = json_encode($content, JSON_UNESCAPED_SLASHES);
        } else {
            $body = '';
        }

        // Calcul du timestamp avec delta serveur
        $now = time() + $this->getTimeDelta();

        // Signature : $1$sha1(AS+CK+METHOD+URL+BODY+TIMESTAMP)
        // Identique au SDK officiel OVH
        $toSign    = $this->appSecret . '+' . $this->consumerKey . '+' . strtoupper($method)
                   . '+' . $url . '+' . $body . '+' . $now;
        $signature = '$1$' . sha1($toSign);

        // Construction des headers
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'X-Ovh-Application: ' . $this->appKey,
            'X-Ovh-Consumer: '    . $this->consumerKey,
            'X-Ovh-Signature: '   . $signature,
            'X-Ovh-Timestamp: '   . $now,
        ];

        // Appel cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ]);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Erreur cURL : ' . $curlError);
        }

        // Réponse vide (ex: 204 No Content)
        if ($response === '' || $response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? ($data['errorCode'] ?? ('Erreur HTTP ' . $httpCode));
            throw new \RuntimeException('[OVH ' . $httpCode . '] ' . $msg);
        }

        return $data;
    }

    /**
     * Calcule le delta entre l'horloge locale et le serveur OVH.
     * Identique à calculateTimeDelta() dans le SDK officiel.
     */
    private function getTimeDelta(): int
    {
        if ($this->timeDelta === null) {
            try {
                $ch = curl_init($this->endpoint . '/auth/time');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                ]);
                $response        = curl_exec($ch);
                curl_close($ch);
                $serverTimestamp = (int) json_decode($response, true);
                $this->timeDelta = $serverTimestamp > 0 ? $serverTimestamp - time() : 0;
            } catch (\Exception) {
                $this->timeDelta = 0;
            }
        }
        return $this->timeDelta;
    }

    /**
     * Normalise un sous-domaine OVH en nom complet.
     */
    private function normalizeSubdomain(string $sub, string $zone): string
    {
        if ($sub === '' || $sub === '@') {
            return $zone;
        }
        return $sub . '.' . $zone;
    }
}
