/**
 * RadiSSO OIDC Node.js Client
 *
 * Unterstützt: Authorization Code (PKCE), Client Credentials, Device Code, Refresh Token
 * Keine externen Abhängigkeiten – nutzt native fetch() (Node 18+)
 */

const crypto = require('crypto');

class RadissoOIDCError extends Error {
  constructor(message, errorCode = 'unknown_error') {
    super(message);
    this.name = 'RadissoOIDCError';
    this.errorCode = errorCode;
  }
}

class RadissoOIDC {
  /**
   * @param {Object} config
   * @param {string} config.clientId - Client-ID (UUID)
   * @param {string} [config.clientSecret] - Client Secret (undefined für Public Clients)
   * @param {string} [config.redirectUri] - Callback-URL
   * @param {string} [config.issuer] - Issuer Base-URL
   */
  constructor({
    clientId,
    clientSecret,
    redirectUri = '',
    issuer = 'https://api.radisso.de/oidc',
  }) {
    this.clientId = clientId;
    this.clientSecret = clientSecret;
    this.redirectUri = redirectUri;
    this.apiUrl = issuer.replace(/\/+$/, '');
    this.authUrl = this.apiUrl.replace('api.', '');
    this._discovery = null;
  }

  // ─── Discovery ───────────────────────────────────────────────

  async discover() {
    if (!this._discovery) {
      this._discovery = await this._get(
        `${this.apiUrl}/.well-known/openid-configuration`
      );
    }
    return this._discovery;
  }

  // ─── PKCE & Helpers ──────────────────────────────────────────

  static generatePKCE() {
    const verifier = crypto.randomBytes(32).toString('hex');
    const challenge = crypto
      .createHash('sha256')
      .update(verifier)
      .digest('base64url');
    return { verifier, challenge };
  }

  static generateState() {
    return crypto.randomBytes(16).toString('hex');
  }

  static generateNonce() {
    return crypto.randomBytes(16).toString('hex');
  }

  // ─── Authorization Code Flow (PKCE) ─────────────────────────

  /**
   * Authorization-URL für den Browser-Redirect erzeugen
   * @param {Object} options
   * @param {string[]} options.scopes
   * @param {string} options.state
   * @param {string} [options.nonce]
   * @param {string} [options.codeChallenge]
   * @returns {string}
   */
  getAuthorizationUrl({ scopes, state, nonce, codeChallenge }) {
    const params = new URLSearchParams({
      response_type: 'code',
      client_id: this.clientId,
      redirect_uri: this.redirectUri,
      scope: scopes.join(' '),
      state,
    });
    if (nonce) params.set('nonce', nonce);
    if (codeChallenge) {
      params.set('code_challenge', codeChallenge);
      params.set('code_challenge_method', 'S256');
    }
    return `${this.authUrl}/authorize?${params}`;
  }

  /**
   * Authorization Code gegen Tokens tauschen
   * @param {string} code
   * @param {string} [codeVerifier]
   * @returns {Promise<{access_token: string, refresh_token?: string, id_token?: string, expires_in: number}>}
   */
  async exchangeCode(code, codeVerifier) {
    const params = {
      grant_type: 'authorization_code',
      code,
      redirect_uri: this.redirectUri,
      client_id: this.clientId,
    };
    if (this.clientSecret) params.client_secret = this.clientSecret;
    if (codeVerifier) params.code_verifier = codeVerifier;
    return this._post(`${this.apiUrl}/token`, params);
  }

  // ─── Client Credentials Flow ────────────────────────────────

  /**
   * Access Token via Client Credentials (Server-to-Server)
   * @param {string[]} [scopes]
   * @returns {Promise<{access_token: string, expires_in: number}>}
   */
  async clientCredentials(scopes) {
    const params = { grant_type: 'client_credentials' };
    if (scopes?.length) params.scope = scopes.join(' ');
    return this._post(`${this.apiUrl}/token`, params, true);
  }

  // ─── Refresh Token ──────────────────────────────────────────

  /**
   * Access Token mit Refresh Token erneuern
   * WICHTIG: Das zurückgegebene refresh_token SOFORT speichern! (Rotation)
   * @param {string} refreshToken
   * @returns {Promise<{access_token: string, refresh_token: string, expires_in: number}>}
   */
  async refreshToken(refreshToken) {
    const params = {
      grant_type: 'refresh_token',
      refresh_token: refreshToken,
      client_id: this.clientId,
    };
    if (this.clientSecret) params.client_secret = this.clientSecret;
    return this._post(`${this.apiUrl}/token`, params);
  }

  // ─── Device Code Flow ───────────────────────────────────────

  /**
   * Device Code anfordern
   * @param {string[]} [scopes]
   * @returns {Promise<{device_code: string, user_code: string, verification_uri: string, expires_in: number, interval: number}>}
   */
  async requestDeviceCode(scopes = ['openid', 'profile']) {
    const params = {
      client_id: this.clientId,
      scope: scopes.join(' '),
    };
    if (this.clientSecret) params.client_secret = this.clientSecret;
    return this._post(`${this.apiUrl}/device-authorize`, params);
  }

  /**
   * Device Code Token Polling (einzelner Versuch)
   * @param {string} deviceCode
   * @returns {Promise<Object|null>} Token-Set oder null wenn pending
   */
  async pollDeviceToken(deviceCode) {
    const body = new URLSearchParams({
      grant_type: 'urn:ietf:params:oauth:grant-type:device_code',
      device_code: deviceCode,
      client_id: this.clientId,
    });

    const resp = await fetch(`${this.apiUrl}/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    const data = await resp.json();

    if (resp.ok) return data;

    const error = data.error || 'unknown_error';
    if (error === 'authorization_pending' || error === 'slow_down') {
      return null;
    }
    throw new RadissoOIDCError(
      data.error_description || error,
      error
    );
  }

  /**
   * Vollständiges Device Code Polling mit Warten
   * @param {string} deviceCode
   * @param {number} [interval=5]
   * @param {number} [maxWait=900]
   * @returns {Promise<Object>}
   */
  async waitForDeviceToken(deviceCode, interval = 5, maxWait = 900) {
    const start = Date.now();
    while (Date.now() - start < maxWait * 1000) {
      const result = await this.pollDeviceToken(deviceCode);
      if (result) return result;
      await new Promise((r) => setTimeout(r, interval * 1000));
    }
    throw new RadissoOIDCError('Device code expired', 'expired_token');
  }

  // ─── Resource Endpoints ─────────────────────────────────────

  /**
   * UserInfo-Endpoint abfragen
   * @param {string} accessToken
   * @returns {Promise<{sub: string, name?: string, email?: string}>}
   */
  async getUserInfo(accessToken) {
    return this._get(`${this.apiUrl}/userinfo`, accessToken);
  }

  /**
   * Token-Introspection
   * @param {string} token
   * @returns {Promise<{active: boolean, sub?: string, scope?: string}>}
   */
  async introspect(token) {
    return this._post(`${this.apiUrl}/introspect`, { token }, true);
  }

  /**
   * Token widerrufen (Logout)
   * @param {string} token
   * @param {string} [tokenTypeHint='refresh_token']
   */
  async revokeToken(token, tokenTypeHint = 'refresh_token') {
    const params = { token, token_type_hint: tokenTypeHint };
    if (this.clientSecret) {
      params.client_id = this.clientId;
      params.client_secret = this.clientSecret;
    }
    await this._post(`${this.apiUrl}/revoke`, params);
  }

  /**
   * JWKS (Public Keys) laden
   */
  async getJWKS() {
    return this._get(`${this.apiUrl}/jwks`);
  }

  // ─── S2S-Methoden ──────────────────────────────────────────────

  /**
   * User suchen (S2S)
   * @param {Object} criteria - z.B. {addressid: 12345}, {uuid: '...'}, {email: '...'}, {lastname: 'Muster', city: 'Berlin'}
   * @param {string[]} [orgunits] - Optional: Nur User dieser OrgUnits (UUIDs). Subset der Client-OrgUnits.
   * @returns {Promise<{users: Object[], count: number}>}
   */
  async usersSearch(criteria, orgunits) {
    const data = { ...criteria };
    if (orgunits) data.orgunits = orgunits;
    return this._s2sPostJson('users.search', data);
  }

  /**
   * Alle aktiven User abrufen (S2S, paginiert)
   * @param {number} [limit=500] - Max. Einträge (max. 5000)
   * @param {number} [offset=0] - Startposition
   * @param {string[]} [orgunits] - Optional: Nur User dieser OrgUnits (UUIDs). Subset der Client-OrgUnits.
   * @returns {Promise<{users: Object[], total: number, offset: number, limit: number, has_more: boolean}>}
   */
  async usersList(limit = 500, offset = 0, orgunits) {
    const params = { limit, offset };
    if (orgunits) params.orgunits = orgunits.join(',');
    return this._s2sGet('users.list', params);
  }

  /**
   * Einzelnen User abrufen (S2S)
   * @param {Object} identifier - z.B. {uuid: '...'}, {addressid: 12345}, {email: '...'}
   * @param {string[]} [orgunits] - Optional: OrgUnit-Filter (UUIDs) für das orgunits-Feld im Profil
   * @returns {Promise<{user: Object}>}
   */
  async usersGet(identifier, orgunits) {
    const params = { ...identifier };
    if (orgunits) params.orgunits = orgunits.join(',');
    return this._s2sGet('users.get', params);
  }

  /**
   * User prüfen (S2S)
   * @param {string} email
   * @param {string} [birthdate] - Format YYYY-MM-DD
   * @returns {Promise<{exists: boolean, match_level: number, user: ?Object}>}
   */
  async usersCheck(email, birthdate) {
    const data = { email };
    if (birthdate) data.birthdate = birthdate;
    return this._s2sPostJson('users.check', data);
  }

  /**
   * Neuen User anlegen oder bestehenden zurückgeben (S2S)
   * @param {string} email
   * @param {Object} [fields={}] - Optionale Felder: firstname, lastname, salutation, company, etc.
   * @returns {Promise<{created: boolean, status: number, user: Object}>}
   */
  async usersCreate(email, fields = {}) {
    return this._s2sPostJson('users.create', { email, ...fields });
  }

  /**
   * One-Time-Login-Hash erzeugen (S2S)
   * @param {Object} identifier - z.B. {uuid: '...'}, {addressid: 12345}, {email: '...'}, {login: '...'}
   * @returns {Promise<{user_uuid: string, addressid: number, hash: string}>}
   */
  async usersCreateOTL(identifier) {
    return this._s2sPostJson('users.createOTL', identifier);
  }

  /**
   * Login zurücksetzen (S2S)
   * @param {Object} identifier - z.B. {email: '...'}, {uuid: '...'}, {addressid: 12345}, {login: '...'}
   * @returns {Promise<{user_uuid: string, addressid: number, name: string, mail: string, hash: string}>}
   */
  async usersResetLogin(identifier) {
    return this._s2sPostJson('users.resetLogin', identifier);
  }

  /**
   * Zertifikatstypen abrufen (S2S)
   * @returns {Promise<{types: Object[]}>}
   */
  async certificatesTypes() {
    return this._s2sGet('certificates.types');
  }

  /**
   * Personenzertifikate abrufen (S2S)
   * @returns {Promise<{persons: Object[]}>}
   */
  async certificatesPersons() {
    return this._s2sGet('certificates.persons');
  }

  /**
   * Client-Panel-Daten abrufen (S2S)
   * @returns {Promise<{data: Object}>}
   */
  async clientsPanel() {
    return this._s2sGet('clients.panel');
  }

  /**
   * Redirect-URIs für diesen Client setzen (S2S)
   * @param {string[]} uris - Array von Redirect-URIs
   * @returns {Promise<{status: string, redirect_uris: string[]}>}
   */
  async setRedirectUris(uris) {
    return this._s2sPostJson('clients.redirecturis', { redirect_uris: uris });
  }

  /**
   * Zugriffsberechtigte OrganisationUnits abrufen (S2S)
   *
   * Gibt alle OrgUnits zurück, auf die dieser Client Zugriff hat.
   * Sub-Units zeigen ihren Parent (parent_uuid / parent_shortname).
   * Hat der Client keine Einschränkung, werden alle aktiven OrgUnits geliefert.
   *
   * @returns {Promise<{orgunits: Object[], count: number}>}
   */
  async listOrgUnits() {
    return this._s2sGet('orgunits.list');
  }

  /**
   * Mitgliedsformular-Lookups abrufen (S2S)
   * @param {string} mandant - z.B. 'DRG', 'DEGIR', 'BDNR'
   * @returns {Promise<{data: Object}>}
   */
  async memberformData(mandant) {
    return this._s2sGet('memberform.data', { mandant });
  }

  // ─── Incremental Sync ────────────────────────────────────────

  /**
   * Geänderte Benutzer seit einem Zeitpunkt abrufen (Incremental Sync)
   * @param {string} since - ISO 8601 Zeitstempel
   * @param {Object} [options]
   * @param {number} [options.limit=500] - Max. Ergebnisse (1–1000)
   * @param {boolean} [options.includeUser=false] - Volles User-Objekt mitliefern
   * @returns {Promise<{changes: Object[], next_since: string, has_more: boolean, count: number}>}
   */
  async usersChanged(since, { limit = 500, includeUser = false } = {}) {
    const params = { since };
    if (limit !== 500) params.limit = String(limit);
    if (includeUser) params.include = 'user';
    const query = new URLSearchParams(params).toString();
    const url = `${this.apiUrl}/users/changed?${query}`;
    const headers = {};
    if (this.clientSecret) {
      const credentials = Buffer.from(`${this.clientId}:${this.clientSecret}`).toString('base64');
      headers.Authorization = `Basic ${credentials}`;
    }
    const resp = await fetch(url, { headers });
    const result = await resp.json();
    if (!resp.ok) {
      throw new RadissoOIDCError(
        result.error_description || result.error || `HTTP ${resp.status}`,
        result.error || 'http_error'
      );
    }
    return result;
  }

  // ─── JWT Decode ──────────────────────────────────────────────

  /**
   * JWT-Payload decodieren (OHNE Signaturprüfung!)
   * Für Signaturprüfung: jose o.ä. verwenden
   * @param {string} jwt
   * @returns {Object}
   */
  static decodeJwtPayload(jwt) {
    const parts = jwt.split('.');
    if (parts.length !== 3) {
      throw new RadissoOIDCError('Invalid JWT format', 'invalid_token');
    }
    try {
      return JSON.parse(Buffer.from(parts[1], 'base64url').toString());
    } catch (e) {
      throw new RadissoOIDCError('Invalid JWT payload', 'invalid_token');
    }
  }

  // ─── HTTP-Layer ─────────────────────────────────────────────

  /**
   * GET mit Basic Auth (für S2S-Methoden)
   */
  async _s2sGet(method, params = {}) {
    const query = new URLSearchParams(params).toString();
    const url = `${this.apiUrl}/s2s/${method}${query ? '?' + query : ''}`;
    const headers = {};
    if (this.clientSecret) {
      const credentials = Buffer.from(`${this.clientId}:${this.clientSecret}`).toString('base64');
      headers.Authorization = `Basic ${credentials}`;
    }
    const resp = await fetch(url, { headers });
    const result = await resp.json();
    if (!resp.ok) {
      throw new RadissoOIDCError(
        result.error_description || result.error || `HTTP ${resp.status}`,
        result.error || 'http_error'
      );
    }
    return result;
  }

  /**
   * POST mit JSON-Body und Basic Auth (für S2S-Methoden)
   */
  async _s2sPostJson(method, data) {
    const url = `${this.apiUrl}/s2s/${method}`;
    const headers = { 'Content-Type': 'application/json' };
    if (this.clientSecret) {
      const credentials = Buffer.from(`${this.clientId}:${this.clientSecret}`).toString('base64');
      headers.Authorization = `Basic ${credentials}`;
    }
    const resp = await fetch(url, {
      method: 'POST',
      headers,
      body: JSON.stringify(data),
    });
    const result = await resp.json();
    if (!resp.ok) {
      throw new RadissoOIDCError(
        result.error_description || result.error || `HTTP ${resp.status}`,
        result.error || 'http_error'
      );
    }
    return result;
  }

  async _get(url, bearerToken) {
    const headers = {};
    if (bearerToken) headers.Authorization = `Bearer ${bearerToken}`;

    const resp = await fetch(url, { headers });
    const data = await resp.json();

    if (!resp.ok) {
      throw new RadissoOIDCError(
        data.error_description || data.error || `HTTP ${resp.status}`,
        data.error || 'http_error'
      );
    }
    return data;
  }

  async _post(url, params, useBasicAuth = false) {
    const headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    if (useBasicAuth && this.clientSecret) {
      const credentials = Buffer.from(
        `${this.clientId}:${this.clientSecret}`
      ).toString('base64');
      headers.Authorization = `Basic ${credentials}`;
    }

    const resp = await fetch(url, {
      method: 'POST',
      headers,
      body: new URLSearchParams(params),
    });

    if (resp.status === 204 || !resp.headers.get('content-type')?.includes('json')) {
      if (!resp.ok) {
        throw new RadissoOIDCError(`HTTP ${resp.status}`, 'http_error');
      }
      return {};
    }

    const data = await resp.json();
    if (!resp.ok) {
      throw new RadissoOIDCError(
        data.error_description || data.error || `HTTP ${resp.status}`,
        data.error || 'http_error'
      );
    }
    return data;
  }
}

module.exports = { RadissoOIDC, RadissoOIDCError };
