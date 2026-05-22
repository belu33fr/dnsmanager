<?php
/**
 * DNSManage - Interface commune des providers DNS
 */

interface PluginDnsmanageProviderInterface
{
    // ------------------------------------------------------------------
    // Authentification
    // ------------------------------------------------------------------

    /**
     * Initialise le provider avec les credentials déchiffrés.
     *
     * @param array<string,string> $credentials  Paires clé/valeur déchiffrées
     * @param string               $endpoint     Endpoint spécifique au provider (ex. "ovh-eu")
     */
    public function __construct(array $credentials, string $endpoint = '');

    /**
     * Teste la connexion à l'API.
     * Doit lever une \RuntimeException en cas d'échec avec le message d'erreur.
     */
    public function testConnection(): bool;

    // ------------------------------------------------------------------
    // Méta-information (pour générer dynamiquement le formulaire UI)
    // ------------------------------------------------------------------

    /**
     * Retourne le libellé affiché dans la liste de providers.
     */
    public static function getLabel(): string;

    /**
     * Retourne les endpoints disponibles pour ce provider.
     * Format : ['ovh-eu' => 'Europe (OVH EU)', 'ovh-ca' => 'Canada (OVH CA)']
     * Retourner un tableau vide si l'endpoint est libre (URL saisie par l'utilisateur).
     *
     * @return array<string,string>
     */
    public static function getEndpoints(): array;

    /**
     * Retourne la définition des champs de credentials attendus.
     *
     * Format :
     * [
     *   ['key' => 'app_key',      'label' => 'Application Key', 'type' => 'text',     'required' => true],
     *   ['key' => 'app_secret',   'label' => 'Application Secret', 'type' => 'password', 'required' => true],
     *   ['key' => 'consumer_key', 'label' => 'Consumer Key',    'type' => 'password', 'required' => true],
     * ]
     *
     * Types supportés : 'text', 'password', 'url'
     *
     * @return array<int, array<string,mixed>>
     */
    public static function getCredentialFields(): array;

    // ------------------------------------------------------------------
    // Import (lecture)
    // ------------------------------------------------------------------

    /**
     * Liste tous les domaines/zones du compte.
     *
     * Retourne un tableau de :
     * [
     *   'ref'     => string,  // identifiant unique chez le provider (ex. "example.com")
     *   'name'    => string,  // FQDN affiché
     *   'comment' => string,  // description optionnelle
     * ]
     *
     * @return array<int, array<string,string>>
     */
    public function listDomains(): array;

    /**
     * Liste tous les enregistrements DNS d'une zone.
     *
     * Retourne un tableau de :
     * [
     *   'ref'     => string,  // identifiant unique chez le provider (ex. "12345678")
     *   'name'    => string,  // sous-domaine ou "@" pour la racine
     *   'type'    => string,  // A, AAAA, CNAME, MX, TXT, NS, SRV, ...
     *   'target'  => string,  // valeur/destination
     *   'ttl'     => int,     // TTL en secondes
     *   'priority'=> int,     // priorité (MX, SRV) — 0 si non applicable
     * ]
     *
     * @param  string $zoneRef  Référence de la zone (valeur du champ 'ref' de listDomains)
     * @return array<int, array<string,mixed>>
     */
    public function listRecords(string $zoneRef): array;

    // ------------------------------------------------------------------
    // Modification (Phase 3 — déclarés mais non exposés en UI pour l'instant)
    // ------------------------------------------------------------------

    /**
     * Crée un nouvel enregistrement dans une zone.
     *
     * @param  string              $zoneRef  Référence de la zone
     * @param  array<string,mixed> $data     Données de l'enregistrement
     * @return string                        Référence du nouvel enregistrement chez le provider
     */
    public function createRecord(string $zoneRef, array $data): string;

    /**
     * Met à jour un enregistrement existant.
     *
     * @param  string              $zoneRef   Référence de la zone
     * @param  string              $recordRef Référence de l'enregistrement
     * @param  array<string,mixed> $data      Nouvelles données
     */
    public function updateRecord(string $zoneRef, string $recordRef, array $data): bool;

    /**
     * Supprime un enregistrement.
     *
     * @param  string $zoneRef   Référence de la zone
     * @param  string $recordRef Référence de l'enregistrement
     */
    public function deleteRecord(string $zoneRef, string $recordRef): bool;
}
