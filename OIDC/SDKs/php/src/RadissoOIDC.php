<?php

declare(strict_types=1);

namespace Radisso\OIDCClient;

/**
 * RadiSSO OIDC Client
 *
 * Unterstützt: Authorization Code (PKCE), Client Credentials, Device Code, Refresh Token
 */
class RadissoOIDC
{
    private string $clientId;
    private ?string $clientSecret;
    private string $redirectUri;
    private string $authUrl;
    private string $apiUrl;
    private ?array $discovery = null;

    /**
     * @param string      $clientId     Client-ID (UUID)
     * @param string|null $clientSecret Client Secret (null für Public Clients)
     * @param string      $redirectUri  Callback-URL
     * @param string      $issuer       Issuer Base-URL (z.B. https://api.radisso.de/oidc)
     */
    public function __construct(
        string $clientId,
        ?string $clientSecret = null,
        string $redirectUri = '',
        string $issuer = 'https://api.radisso.de/oidc'
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->apiUrl = rtrim($issuer, '/');
        // Auth-URL: radisso.de statt api.radisso.de
        $this->authUrl = str_replace('api.', '', $this->apiUrl);
    }

    // ─── Discovery ───────────────────────────────────────────────

    public function discover(): array
    {
        if ($this->discovery === null) {
            $this->discovery = $this->httpGet(
                $this->apiUrl . '/.well-known/openid-configuration'
            );
        }
        return $this->discovery;
    }

    // ─── Authorization Code Flow (PKCE) ─────────────────────────

    /**
     * PKCE Code Verifier + Challenge generieren
     * @return array{verifier: string, challenge: string}
     */
    public static function generatePKCE(): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(
            base64_encode(hash('sha256', $verifier, true)),
            '+/', '-_'
        ), '=');
        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * Authorization-URL für den Browser-Redirect erzeugen
     *
     * @param string[] $scopes       z.B. ['openid', 'profile', 'email']
     * @param string   $state        CSRF-Token (zufällig)
     * @param string   $nonce        Replay-Schutz
     * @param string   $codeChallenge PKCE Challenge (S256)
     */
    public function getAuthorizationUrl(
        array $scopes,
        string $state,
        string $nonce = '',
        string $codeChallenge = ''
    ): string {
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => implode(' ', $scopes),
            'state'         => $state,
        ];
        if ($nonce !== '') {
            $params['nonce'] = $nonce;
        }
        if ($codeChallenge !== '') {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }
        return $this->authUrl . '/authorize?' . http_build_query($params);
    }

    /**
     * Authorization Code gegen Tokens tauschen
     *
     * @return array{access_token: string, refresh_token?: string, id_token?: string, expires_in: int}
     */
    public function exchangeCode(string $code, string $codeVerifier = ''): array
    {
        $params = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id'    => $this->clientId,
        ];
        if ($this->clientSecret !== null) {
            $params['client_secret'] = $this->clientSecret;
        }
        if ($codeVerifier !== '') {
            $params['code_verifier'] = $codeVerifier;
        }
        return $this->httpPost($this->apiUrl . '/token', $params);
    }

    // ─── Client Credentials Flow ────────────────────────────────

    /**
     * Access Token via Client Credentials (Server-to-Server)
     *
     * @param string[] $scopes
     * @return array{access_token: string, expires_in: int}
     */
    public function clientCredentials(array $scopes = []): array
    {
        $params = ['grant_type' => 'client_credentials'];
        if (!empty($scopes)) {
            $params['scope'] = implode(' ', $scopes);
        }
        return $this->httpPost($this->apiUrl . '/token', $params, true);
    }

    // ─── Refresh Token ──────────────────────────────────────────

    /**
     * Access Token mit Refresh Token erneuern
     *
     * WICHTIG: Das zurückgegebene refresh_token SOFORT speichern!
     * Das alte Token ist danach ungültig (Rotation).
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function refreshToken(string $refreshToken): array
    {
        $params = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
        ];
        if ($this->clientSecret !== null) {
            $params['client_secret'] = $this->clientSecret;
        }
        return $this->httpPost($this->apiUrl . '/token', $params);
    }

    // ─── Device Code Flow ───────────────────────────────────────

    /**
     * Device Code anfordern
     *
     * @param string[] $scopes
     * @return array{device_code: string, user_code: string, verification_uri: string, expires_in: int, interval: int}
     */
    public function requestDeviceCode(array $scopes = ['openid', 'profile']): array
    {
        $params = [
            'client_id' => $this->clientId,
            'scope'     => implode(' ', $scopes),
        ];
        if ($this->clientSecret !== null) {
            $params['client_secret'] = $this->clientSecret;
        }
        return $this->httpPost($this->apiUrl . '/device-authorize', $params);
    }

    /**
     * Device Code Token Polling
     *
     * @return array|null Token-Set oder null wenn noch pending
     * @throws RadissoOIDCException bei expired_token oder anderen Fehlern
     */
    public function pollDeviceToken(string $deviceCode): ?array
    {
        $params = [
            'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
            'client_id'   => $this->clientId,
        ];

        $response = $this->httpPostRaw($this->apiUrl . '/token', $params);
        $data = json_decode($response['body'], true);

        if ($response['status'] === 200) {
            return $data;
        }

        $error = $data['error'] ?? 'unknown_error';
        if ($error === 'authorization_pending') {
            return null;
        }
        if ($error === 'slow_down') {
            return null; // Caller sollte Interval erhöhen
        }

        throw new RadissoOIDCException(
            $data['error_description'] ?? $error,
            $error
        );
    }

    /**
     * Vollständiges Device Code Polling mit Warten
     *
     * @return array Token-Set
     * @throws RadissoOIDCException bei Timeout oder Fehler
     */
    public function waitForDeviceToken(string $deviceCode, int $interval = 5, int $maxWait = 900): array
    {
        $start = time();
        while (time() - $start < $maxWait) {
            $result = $this->pollDeviceToken($deviceCode);
            if ($result !== null) {
                return $result;
            }
            sleep($interval);
        }
        throw new RadissoOIDCException('Device code expired', 'expired_token');
    }

    // ─── Resource Endpoints ─────────────────────────────────────

    /**
     * UserInfo-Endpoint abfragen
     *
     * @return array{sub: string, name?: string, email?: string, ...}
     */
    public function getUserInfo(string $accessToken): array
    {
        return $this->httpGet($this->apiUrl . '/userinfo', $accessToken);
    }

    /**
     * Token-Introspection (prüft ob Token aktiv)
     *
     * @return array{active: bool, sub?: string, scope?: string, exp?: int, ...}
     */
    public function introspect(string $token): array
    {
        return $this->httpPost($this->apiUrl . '/introspect', [
            'token' => $token,
        ], true);
    }

    /**
     * Token widerrufen (Logout)
     */
    public function revokeToken(string $token, string $tokenTypeHint = 'refresh_token'): void
    {
        $params = [
            'token'           => $token,
            'token_type_hint' => $tokenTypeHint,
        ];
        if ($this->clientSecret !== null) {
            $params['client_id'] = $this->clientId;
            $params['client_secret'] = $this->clientSecret;
        }
        $this->httpPost($this->apiUrl . '/revoke', $params);
    }

    /**
     * JWKS (Public Keys) laden
     */
    public function getJWKS(): array
    {
        return $this->httpGet($this->apiUrl . '/jwks');
    }

    // ─── S2S-Methoden ───────────────────────────────────────────

    /**
     * User suchen (S2S)
     *
     * Suche nach addressid, uuid, email (exakt) oder nach Name/Firma/Ort (LIKE-Suche).
     *
     * @param array        $criteria  z.B. ['addressid'=>12345], ['uuid'=>'...'], ['email'=>'...'], ['lastname'=>'Muster','city'=>'Berlin']
     * @param string[]|null $orgunits  Optional: Nur User dieser OrgUnits (UUIDs). Subset der Client-OrgUnits.
     * @return array{users: array, count: int}
     */
    public function usersSearch(array $criteria, ?array $orgunits = null): array
    {
        if ($orgunits !== null) $criteria['orgunits'] = $orgunits;
        return $this->s2sPostJson('users.search', $criteria);
    }

    /**
     * Alle aktiven User abrufen (S2S, paginiert)
     *
     * @param int           $limit    Max. Einträge pro Seite (max. 5000)
     * @param int           $offset   Startposition
     * @param string[]|null $orgunits Optional: Nur User dieser OrgUnits (UUIDs). Subset der Client-OrgUnits.
     * @return array{users: array, total: int, offset: int, limit: int, has_more: bool}
     */
    public function usersList(int $limit = 500, int $offset = 0, ?array $orgunits = null): array
    {
        $params = ['limit' => $limit, 'offset' => $offset];
        if ($orgunits !== null) $params['orgunits'] = implode(',', $orgunits);
        return $this->s2sGet('users.list', $params);
    }

    /**
     * Einzelnen User abrufen (S2S)
     *
     * @param array         $identifier z.B. ['uuid'=>'...'], ['addressid'=>12345] oder ['email'=>'...']
     * @param string[]|null $orgunits   Optional: OrgUnit-Filter (UUIDs) für das orgunits-Feld im Profil
     * @return array{user: object}
     */
    public function usersGet(array $identifier, ?array $orgunits = null): array
    {
        $params = $identifier;
        if ($orgunits !== null) $params['orgunits'] = implode(',', $orgunits);
        return $this->s2sGet('users.get', $params);
    }

    /**
     * User prüfen — existiert er, stimmt das Geburtsdatum? (S2S)
     *
     * @param string      $email
     * @param string|null $birthdate Optional, Format YYYY-MM-DD
     * @return array{exists: bool, match_level: int, user: ?object}
     */
    public function usersCheck(string $email, ?string $birthdate = null): array
    {
        $data = ['email' => $email];
        if ($birthdate !== null) $data['birthdate'] = $birthdate;
        return $this->s2sPostJson('users.check', $data);
    }

    /**
     * Neuen User anlegen oder bestehenden zurückgeben (S2S)
     *
     * @param string $email          Pflicht
     * @param array  $fields         Optionale Felder: firstname, lastname, salutation, company, etc.
     * @return array{created: bool, status: int, user: object}
     */
    public function usersCreate(string $email, array $fields = []): array
    {
        return $this->s2sPostJson('users.create', array_merge(['email' => $email], $fields));
    }

    /**
     * One-Time-Login-Hash erzeugen (S2S)
     *
     * @param array $identifier z.B. ['uuid'=>'...'], ['addressid'=>12345], ['email'=>'...'] oder ['login'=>'...']
     * @return array{user_uuid: string, addressid: int, hash: string}
     */
    public function usersCreateOTL(array $identifier): array
    {
        return $this->s2sPostJson('users.createOTL', $identifier);
    }

    /**
     * Login zurücksetzen — Passwort vergessen + 2FA deaktiviert + OTL-Hash (S2S)
     *
     * @param array $identifier z.B. ['email'=>'...'], ['uuid'=>'...'], ['addressid'=>12345], ['login'=>'...']
     * @return array{user_uuid: string, addressid: int, name: string, mail: string, hash: string}
     */
    public function usersResetLogin(array $identifier): array
    {
        return $this->s2sPostJson('users.resetLogin', $identifier);
    }

    /**
     * Zertifikatstypen abrufen (S2S)
     *
     * @return array{types: array}
     */
    public function certificatesTypes(): array
    {
        return $this->s2sGet('certificates.types');
    }

    /**
     * Personenzertifikate abrufen (S2S)
     *
     * @return array{persons: array}
     */
    public function certificatesPersons(): array
    {
        return $this->s2sGet('certificates.persons');
    }

    /**
     * Client-Panel-Daten abrufen (S2S)
     *
     * @return array{data: mixed}
     */
    public function clientsPanel(): array
    {
        return $this->s2sGet('clients.panel');
    }

    /**
     * Redirect-URIs für diesen Client setzen (S2S)
     *
     * @param string[] $uris Array von Redirect-URIs (http:// oder https://)
     * @return array{status: string, redirect_uris: string[]}
     */
    public function setRedirectUris(array $uris): array
    {
        return $this->s2sPostJson('clients.redirecturis', ['redirect_uris' => $uris]);
    }

    /**
     * Zugriffsberechtigte OrganisationUnits abrufen (S2S)
     *
     * Gibt alle OrgUnits zurück, auf die dieser Client Zugriff hat.
     * Sub-Units zeigen ihren Parent (parent_uuid / parent_shortname).
     * Hat der Client keine Einschränkung, werden alle aktiven OrgUnits geliefert.
     *
     * @return array{orgunits: array, count: int}
     */
    public function listOrgUnits(): array
    {
        return $this->s2sGet('orgunits.list');
    }

    /**
     * Mitgliedsformular-Lookups abrufen (S2S)
     *
     * @param string $mandant Mandantenkürzel, z.B. DRG, DEGIR, BDNR
     * @return array{data: mixed}
     */
    public function memberformData(string $mandant): array
    {
        return $this->s2sGet('memberform.data', ['mandant' => $mandant]);
    }

    // ─── Incremental Sync ──────────────────────────────────────

    /**
     * Geänderte Benutzer seit einem Zeitpunkt abrufen (Incremental Sync)
     *
     * @param string $since       ISO 8601 Zeitstempel
     * @param int    $limit       Max. Ergebnisse (1–1000, Default: 500)
     * @param bool   $includeUser Volles User-Objekt pro Eintrag mitliefern
     * @return array{changes: array, next_since: string, has_more: bool, count: int}
     */
    public function usersChanged(string $since, int $limit = 500, bool $includeUser = false): array
    {
        $params = ['since' => $since];
        if ($limit !== 500) $params['limit'] = $limit;
        if ($includeUser) $params['include'] = 'user';
        $url = $this->apiUrl . '/users/changed?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($this->clientSecret !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RadissoOIDCException('cURL error: ' . $error, 'connection_error');
        }
        curl_close($ch);

        $result = json_decode($body, true) ?? [];
        if ($status >= 400) {
            throw new RadissoOIDCException(
                $result['error_description'] ?? $result['error'] ?? "HTTP $status",
                $result['error'] ?? 'http_error'
            );
        }
        return $result;
    }

    // ─── Hilfsmethoden ──────────────────────────────────────────

    /**
     * Zufälligen State-Parameter generieren
     */
    public static function generateState(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Zufälligen Nonce generieren
     */
    public static function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * JWT-Payload decodieren (OHNE Signaturprüfung!)
     * Für Signaturprüfung: firebase/php-jwt o.ä. verwenden
     */
    public static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RadissoOIDCException('Invalid JWT format', 'invalid_token');
        }
        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')),
            true
        );
        if ($payload === null) {
            throw new RadissoOIDCException('Invalid JWT payload', 'invalid_token');
        }
        return $payload;
    }

    // ─── HTTP-Layer ─────────────────────────────────────────────

    /**
     * GET mit Basic Auth (für S2S-Methoden)
     */
    private function s2sGet(string $method, array $params = []): array
    {
        $url = $this->apiUrl . '/s2s/' . $method;
        if ($params) $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($this->clientSecret !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RadissoOIDCException('cURL error: ' . $error, 'connection_error');
        }
        curl_close($ch);

        $result = json_decode($body, true) ?? [];
        if ($status >= 400) {
            throw new RadissoOIDCException(
                $result['error_description'] ?? $result['error'] ?? 'HTTP ' . $status,
                $result['error'] ?? 'http_error'
            );
        }
        return $result;
    }

    /**
     * POST mit JSON-Body und Basic Auth (für S2S-Methoden)
     */
    private function s2sPostJson(string $method, array $data): array
    {
        $url = $this->apiUrl . '/s2s/' . $method;
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        if ($this->clientSecret !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RadissoOIDCException('cURL error: ' . $error, 'connection_error');
        }
        curl_close($ch);

        $result = json_decode($body, true) ?? [];
        if ($status >= 400) {
            throw new RadissoOIDCException(
                $result['error_description'] ?? $result['error'] ?? 'HTTP ' . $status,
                $result['error'] ?? 'http_error'
            );
        }
        return $result;
    }

    private function httpGet(string $url, string $bearerToken = ''): array
    {
        $response = $this->httpPostRaw($url, null, $bearerToken);
        $data = json_decode($response['body'], true);
        if ($response['status'] >= 400) {
            throw new RadissoOIDCException(
                $data['error_description'] ?? $data['error'] ?? 'HTTP ' . $response['status'],
                $data['error'] ?? 'http_error'
            );
        }
        return $data ?? [];
    }

    private function httpPost(string $url, array $params, bool $useBasicAuth = false): array
    {
        $response = $this->httpPostRaw($url, $params, '', $useBasicAuth);
        $data = json_decode($response['body'], true);
        if ($response['status'] >= 400) {
            throw new RadissoOIDCException(
                $data['error_description'] ?? $data['error'] ?? 'HTTP ' . $response['status'],
                $data['error'] ?? 'http_error'
            );
        }
        return $data ?? [];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function httpPostRaw(
        string $url,
        ?array $params = null,
        string $bearerToken = '',
        bool $useBasicAuth = false
    ): array {
        $ch = curl_init($url);
        $headers = [];

        if ($params !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if ($bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        } elseif ($useBasicAuth && $this->clientSecret !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RadissoOIDCException('cURL error: ' . $error, 'connection_error');
        }

        curl_close($ch);
        return ['status' => $status, 'body' => $body];
    }
}
