# OIDC Endpunkte – API-Referenz

## Basis-URLs

| Umgebung | Frontend (User-facing) | API (S2S) |
|----------|----------------------|-----------|
| Production | `https://radisso.de/oidc/` | `https://api.radisso.de/oidc/` |
| Development | `https://dev.radisso.de/oidc/` | `https://dev.api.radisso.de/oidc/` |

---

## Discovery

```
GET /.well-known/openid-configuration/
```

Liefert das OpenID Connect Discovery Document mit allen Endpunkt-URLs, unterstützten Grant Types, Scopes und Algorithmen.

**Response:**
```json
{
  "issuer": "https://api.radisso.de/oidc/",
  "authorization_endpoint": "https://radisso.de/oidc/authorize/",
  "token_endpoint": "https://api.radisso.de/oidc/token/",
  "userinfo_endpoint": "https://api.radisso.de/oidc/userinfo/",
  "jwks_uri": "https://api.radisso.de/oidc/jwks/",
  "device_authorization_endpoint": "https://api.radisso.de/oidc/device-authorize//",
  "revocation_endpoint": "https://api.radisso.de/oidc/revoke/",
  "introspection_endpoint": "https://api.radisso.de/oidc/introspect/",
  "response_types_supported": ["code"],
  "grant_types_supported": [
    "authorization_code",
    "client_credentials",
    "refresh_token",
    "urn:ietf:params:oauth:grant-type:device_code"
  ],
  "subject_types_supported": ["public"],
  "id_token_signing_alg_values_supported": ["RS256"],
  "scopes_supported": ["openid", "profile", "email", "phone", "address", "offline_access", "radisso:roles", "radisso:participatingevents", "radisso:participatingconrad", "radisso:memberships"],
  "token_endpoint_auth_methods_supported": ["client_secret_post", "client_secret_basic"],
  "code_challenge_methods_supported": ["S256"]
}
```

---

## JWKS (JSON Web Key Set)

```
GET /oidc/jwks/
```

Liefert den öffentlichen RSA-Schlüssel zur JWT-Signatur-Verifizierung. Cache-Header: 24 Stunden.

**Response:**
```json
{
  "keys": [{
    "kty": "RSA",
    "alg": "RS256",
    "use": "sig",
    "kid": "ab12cd34ef56gh78",
    "n": "...(base64url-encoded modulus)...",
    "e": "AQAB"
  }]
}
```

---

## Authorization Endpoint

```
GET https://radisso.de/oidc/authorize/
```

Startet den Authorization Code Flow. Leitet den Benutzer zum Login (falls nicht angemeldet) und anschließend zum Consent-Screen.

**Query-Parameter:**

| Parameter | Pflicht | Beschreibung |
|-----------|---------|-------------|
| `response_type` | ✅ | Muss `code` sein |
| `client_id` | ✅ | Die Client-ID (UUID) |
| `redirect_uri` | ✅ | Registrierte Redirect-URI |
| `scope` | ✅ | Space-getrennt, mind. `openid` |
| `state` | Empfohlen | CSRF-Schutz, wird im Redirect zurückgegeben |
| `code_challenge` | Empfohlen | PKCE Challenge (SHA-256 des Verifiers) |
| `code_challenge_method` | Empfohlen | Muss `S256` sein |
| `nonce` | Optional | Wird ins ID Token übernommen |

**Beispiel:**
```
GET https://radisso.de/oidc/authorize/
  ?response_type=code
  &client_id=550e8400-e29b-41d4-a716-446655440000
  &redirect_uri=https://meine-app.de/callback
  &scope=openid profile email
  &state=abc123
  &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
  &code_challenge_method=S256
  &nonce=xyz789
```

**Erfolg:** Redirect zu `redirect_uri?code=...&state=abc123`

**Fehler:** JSON-Response mit `error` und `error_description`

---

## Token Endpoint

```
POST https://api.radisso.de/oidc/token/
Content-Type: application/x-www-form-urlencoded
```

Token-Austausch für alle Grant Types. Client-Authentifizierung via `client_secret_post` oder `client_secret_basic`.

### Authorization Code Exchange

| Parameter | Wert |
|-----------|------|
| `grant_type` | `authorization_code` |
| `code` | Der erhaltene Authorization Code |
| `redirect_uri` | Gleiche URI wie im Authorize-Request |
| `client_id` | Client-ID |
| `client_secret` | Client Secret (Confidential Clients) |
| `code_verifier` | PKCE Verifier (wenn `code_challenge` gesendet wurde) |

**Response:**
```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ...",
  "refresh_token": "def50200...",
  "id_token": "eyJhbGciOiJSUzI1NiJ9..."
}
```

### Client Credentials

| Parameter | Wert |
|-----------|------|
| `grant_type` | `client_credentials` |
| `client_id` | Client-ID |
| `client_secret` | Client Secret |
| `scope` | Gewünschte Scopes |

**Response:** Wie oben, aber ohne `refresh_token` und `id_token`.

### Refresh Token

| Parameter | Wert |
|-----------|------|
| `grant_type` | `refresh_token` |
| `refresh_token` | Das aktuelle Refresh Token |
| `client_id` | Client-ID |
| `client_secret` | Client Secret (Confidential Clients) |

**Response:** Neues `access_token` + rotiertes `refresh_token`. Das alte Refresh Token wird invalidiert (Replay Detection aktiv).

### Device Code

| Parameter | Wert |
|-----------|------|
| `grant_type` | `urn:ietf:params:oauth:grant-type:device_code` |
| `device_code` | Der Device Code |
| `client_id` | Client-ID |
| `client_secret` | Client Secret (Confidential Clients) |

**Polling Responses:**

| Status | HTTP | Body |
|--------|------|------|
| Noch nicht autorisiert | 400 | `{"error": "authorization_pending"}` |
| Zu schnell gepollt | 400 | `{"error": "slow_down"}` |
| Autorisiert | 200 | Token Response (wie oben) |
| Abgelaufen | 400 | `{"error": "expired_token"}` |

---

## Device Authorization Endpoint

```
POST https://api.radisso.de/oidc/device-authorize/
Content-Type: application/x-www-form-urlencoded
```

Initiiert den Device Code Flow.

| Parameter | Wert |
|-----------|------|
| `client_id` | Client-ID |
| `client_secret` | Client Secret (Confidential Clients) |
| `scope` | Gewünschte Scopes |

**Response:**
```json
{
  "device_code": "def50200abc...",
  "user_code": "ABCD-EFGH",
  "verification_uri": "https://radisso.de/oidc/device/",
  "verification_uri_complete": "https://radisso.de/oidc/device/?user_code=ABCDEFGH",
  "expires_in": 900,
  "interval": 5
}
```

---

## UserInfo Endpoint

```
GET https://api.radisso.de/oidc/userinfo/
Authorization: Bearer <access_token>
```

Liefert Benutzer-Claims basierend auf den im Token enthaltenen Scopes. Siehe [SCOPES.md](SCOPES.md) für Details zu den Claims.

**Response (Beispiel):**
```json
{
  "sub": "bb87ac52-a522-4f3e-8985-7f2ec512961b",
  "name": "Dr. Max Mustermann",
  "given_name": "Max",
  "family_name": "Mustermann",
  "email": "max@example.de",
  "email_verified": true
}
```

**Fehler:**
| HTTP | Bedeutung |
|------|-----------|
| 401 | Kein oder ungültiges Access Token |
| 403 | Token revoked oder expired |

---

## Token Revocation

```
POST https://api.radisso.de/oidc/revoke/
Content-Type: application/x-www-form-urlencoded
```

Widerruft ein Access Token oder Refresh Token gemäß [RFC 7009](https://datatracker.ietf.org/doc/html/rfc7009).

| Parameter | Wert |
|-----------|------|
| `token` | Das zu widerrufende Token |
| `token_type_hint` | Optional: `access_token` oder `refresh_token` |
| `client_id` | Client-ID |
| `client_secret` | Client Secret |

**Response:** HTTP 200 (immer, auch wenn Token nicht gefunden)

---

## Token Introspection

```
POST https://api.radisso.de/oidc/introspect/
Content-Type: application/x-www-form-urlencoded
```

Prüft die Gültigkeit eines Tokens gemäß [RFC 7662](https://datatracker.ietf.org/doc/html/rfc7662). Erfordert Client-Authentifizierung.

| Parameter | Wert |
|-----------|------|
| `token` | Das zu prüfende Token |
| `client_id` | Client-ID |
| `client_secret` | Client Secret |

**Response (aktives Token):**
```json
{
  "active": true,
  "scope": "openid profile email",
  "client_id": "550e8400-e29b-41d4-a716-446655440000",
  "token_type": "access_token",
  "exp": 1709823600,
  "iat": 1709820000,
  "sub": "bb87ac52-a522-4f3e-8985-7f2ec512961b"
}
```

**Response (inaktives Token):**
```json
{
  "active": false
}
```

---

## CORS

Alle API-Endpunkte (auf `api.radisso.de`) senden CORS-Header:

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type
```

`OPTIONS`-Preflight-Requests werden automatisch mit HTTP 204 beantwortet.

---

## Fehler-Format

Alle Fehler folgen dem OAuth 2.0 Error Response Format:

```json
{
  "error": "invalid_request",
  "error_description": "Beschreibung des Fehlers",
  "hint": "Optional: Zusätzlicher Hinweis"
}
```

Häufige Error Codes:

| Code | Bedeutung |
|------|-----------|
| `invalid_request` | Fehlende oder ungültige Parameter |
| `invalid_client` | Client-Authentifizierung fehlgeschlagen |
| `invalid_grant` | Code/Token ungültig oder abgelaufen |
| `unauthorized_client` | Client darf diesen Grant Type nicht nutzen |
| `unsupported_grant_type` | Grant Type nicht unterstützt |
| `invalid_scope` | Angefragter Scope nicht erlaubt |
| `authorization_pending` | Device Flow: Benutzer hat noch nicht autorisiert |
| `slow_down` | Device Flow: Polling zu schnell |
| `expired_token` | Device Flow: Device Code abgelaufen |
