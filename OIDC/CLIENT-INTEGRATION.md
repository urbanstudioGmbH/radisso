# RadiSSO OIDC — Integrationshandbuch für externe Clients

Dieses Dokument richtet sich an Entwickler, die externe Anwendungen mit RadiSSO per
OpenID Connect verbinden. Es deckt alle verfügbaren Flows, die S2S-API, Webhooks und
die offiziellen SDKs ab.

**Basis-URLs**

| Umgebung | Frontend (User-facing) | API |
|---|---|---|
| Produktion | `https://radisso.de/oidc/` | `https://api.radisso.de/oidc/` |
| Entwicklung | `https://dev.radisso.de/oidc/` | `https://dev.api.radisso.de/oidc/` |

---

## Inhaltsverzeichnis

1. [Client-Registrierung](#1-client-registrierung)
2. [Authorization Code Flow (PKCE)](#2-authorization-code-flow-pkce)
3. [Device Code Flow](#3-device-code-flow)
4. [Client Credentials / S2S](#4-client-credentials--s2s)
5. [Refresh Token](#5-refresh-token)
6. [Token Management](#6-token-management)
7. [Scopes & Claims](#7-scopes--claims)
8. [S2S API Methoden](#8-s2s-api-methoden)
9. [Webhooks](#9-webhooks)
10. [Discovery Document](#10-discovery-document)
11. [SDK Referenz](#11-sdk-referenz)
12. [Fehlerbehandlung](#12-fehlerbehandlung)
13. [Sicherheitshinweise](#13-sicherheitshinweise)

---

## 1. Client-Registrierung

> Neu hier? Siehe [ONBOARDING.md](ONBOARDING.md) für die vollständige Schritt-für-Schritt-Anleitung zur Beantragung und Einrichtung Ihres Clients.

Nach der Registrierung erhalten Sie:

| Wert | Beispiel | Verwendung |
|---|---|---|
| `client_id` | `550e8400-e29b-41d4-a716-446655440000` | Alle Requests |
| `client_secret` | `secret_abc123…` | Server-side only, niemals im Browser |

**Wichtig:** Das `client_secret` darf ausschließlich in serverseitigen Anwendungen
verwendet werden. Für Browser-Apps und native Apps immer `PKCE` ohne Secret nutzen
(Public Client).

**Redirect-URIs** können nachträglich per S2S-API (`setRedirectUris`) aktualisiert
werden — für Multidomain-Deployments nützlich.

---

## 2. Authorization Code Flow (PKCE)

Standard-Flow für Web-Anwendungen und native Apps. PKCE (`code_challenge_method=S256`)
wird **dringend empfohlen** und ist für Public Clients Pflicht.

### Flow-Übersicht

```
Client App          RadiSSO Frontend         RadiSSO API
    |                      |                      |
    |-- GET /authorize ---->|                      |
    |   + code_challenge    |                      |
    |                  [Login + Consent]           |
    |<-- redirect ?code=… --|                      |
    |                      |                      |
    |-- POST /token ----------------------->|      |
    |   + code + code_verifier             |      |
    |<-- access_token + id_token + refresh -|      |
    |                      |                      |
    |-- GET /userinfo ---------------------->|     |
    |<-- { sub, name, email, … } -----------|     |
```

### Schritt 1: Authorization Request

```
GET https://radisso.de/oidc/authorize/
  ?response_type=code
  &client_id=YOUR_CLIENT_ID
  &redirect_uri=https://example.com/callback
  &scope=openid profile email
  &state=RANDOM_STATE
  &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
  &code_challenge_method=S256
  &nonce=RANDOM_NONCE
```

| Parameter | Pflicht | Beschreibung |
|---|---|---|
| `response_type` | ✅ | Muss `code` sein |
| `client_id` | ✅ | Client-UUID |
| `redirect_uri` | ✅ | Muss registriert und exakt übereinstimmen |
| `scope` | ✅ | Space-getrennt, mind. `openid` |
| `state` | Empfohlen | CSRF-Schutz — im Callback prüfen |
| `code_challenge` | Empfohlen | SHA-256 des PKCE-Verifiers, base64url-encoded |
| `code_challenge_method` | Empfohlen | Muss `S256` sein |
| `nonce` | Optional | Wird in den ID Token übernommen |

**Erfolg:** Redirect zu `redirect_uri?code=AUTH_CODE&state=RANDOM_STATE`

### Schritt 2: Token Exchange

```http
POST https://api.radisso.de/oidc/token/
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&code=AUTH_CODE
&redirect_uri=https://example.com/callback
&client_id=YOUR_CLIENT_ID
&client_secret=YOUR_CLIENT_SECRET
&code_verifier=PKCE_VERIFIER
```

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

### Schritt 3: UserInfo abrufen

```http
GET https://api.radisso.de/oidc/userinfo/
Authorization: Bearer ACCESS_TOKEN
```

**Response** (abhängig von Scopes):
```json
{
  "sub": "bb87ac52-a522-4f3e-8985-7f2ec512961b",
  "addressid": 12345,
  "name": "Dr. Max Mustermann",
  "given_name": "Max",
  "family_name": "Mustermann",
  "email": "max@example.de",
  "email_verified": true
}
```

> `sub` ist die Benutzer-UUID, `addressid` ist die numerische Adress-ID im
> RadiSSO-System. **Beide sind immer enthalten** — kein eigener Scope nötig.

### SDK-Beispiele

#### Node.js
```javascript
const { RadissoOIDC } = require('radisso-oidc');
const oidc = new RadissoOIDC({
  clientId: 'YOUR_CLIENT_ID',
  clientSecret: 'YOUR_CLIENT_SECRET',
  redirectUri: 'https://example.com/callback',
});

// Login starten
const pkce  = RadissoOIDC.generatePKCE();
const state = RadissoOIDC.generateState();
const nonce = RadissoOIDC.generateNonce();
req.session.pkceVerifier = pkce.verifier;
req.session.oauthState   = state;
const url = oidc.getAuthorizationUrl({ scopes: ['openid', 'profile', 'email'], state, nonce, codeChallenge: pkce.challenge });
res.redirect(url);

// Callback
if (req.query.state !== req.session.oauthState) throw new Error('State mismatch');
const tokens = await oidc.exchangeCode(req.query.code, req.session.pkceVerifier);
const user   = await oidc.getUserInfo(tokens.access_token);
```

#### PHP
```php
use Radisso\OIDCClient\RadissoOIDC;
$oidc = new RadissoOIDC('YOUR_CLIENT_ID', 'YOUR_CLIENT_SECRET', 'https://example.com/callback');

// Login starten
$pkce = RadissoOIDC::generatePKCE();
$state = RadissoOIDC::generateState();
$_SESSION['pkce_verifier'] = $pkce['verifier'];
$_SESSION['oauth_state']   = $state;
header('Location: ' . $oidc->getAuthorizationUrl(['openid', 'profile', 'email'], $state, RadissoOIDC::generateNonce(), $pkce['challenge']));

// Callback
if (!hash_equals($_SESSION['oauth_state'], $_GET['state'])) die('State mismatch');
$tokens = $oidc->exchangeCode($_GET['code'], $_SESSION['pkce_verifier']);
$user   = $oidc->getUserInfo($tokens['access_token']);
```

#### Python
```python
from radisso_oidc import RadissoOIDC
oidc = RadissoOIDC(client_id='YOUR_CLIENT_ID', client_secret='YOUR_CLIENT_SECRET', redirect_uri='https://example.com/callback')

# Login starten
pkce = RadissoOIDC.generate_pkce()
state = RadissoOIDC.generate_state()
session['pkce_verifier'] = pkce['verifier']
session['oauth_state'] = state
return redirect(oidc.get_authorization_url(scopes=['openid','profile','email'], state=state, nonce=RadissoOIDC.generate_nonce(), code_challenge=pkce['challenge']))

# Callback
tokens = oidc.exchange_code(request.args['code'], code_verifier=session['pkce_verifier'])
user = oidc.get_userinfo(tokens['access_token'])
```

---

## 3. Device Code Flow

Für Geräte ohne Browser (Smart-TVs, CLI-Tools, IoT). Das Gerät zeigt einen
kurzen Code an — der Benutzer gibt ihn auf einem anderen Gerät unter
`https://radisso.de/oidc/device/` (oder direkt via `verification_uri_complete`) ein.

### Schritt 1: Device Authorization Request

```http
POST https://api.radisso.de/oidc/device-authorize/
Content-Type: application/x-www-form-urlencoded

client_id=YOUR_CLIENT_ID
&client_secret=YOUR_CLIENT_SECRET
&scope=openid profile
```

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

### Schritt 2: Polling

```http
POST https://api.radisso.de/oidc/token/
Content-Type: application/x-www-form-urlencoded

grant_type=urn:ietf:params:oauth:grant-type:device_code
&device_code=def50200abc...
&client_id=YOUR_CLIENT_ID
&client_secret=YOUR_CLIENT_SECRET
```

| Response | HTTP | Bedeutung |
|---|---|---|
| Token Response | 200 | Autorisiert ✅ |
| `{"error":"authorization_pending"}` | 400 | Noch nicht autorisiert — weiter warten |
| `{"error":"slow_down"}` | 400 | Zu schnell gepollt — `interval` um 5 s erhöhen |
| `{"error":"expired_token"}` | 400 | Device Code abgelaufen — Flow neu starten |

### SDK-Beispiele

```javascript
// Node.js
const device = await oidc.requestDeviceCode(['openid', 'profile']);
console.log(`Öffne ${device.verification_uri} und gib ein: ${device.user_code}`);
const tokens = await oidc.waitForDeviceToken(device.device_code, device.interval);
```

```php
// PHP
$device = $oidc->requestDeviceCode(['openid', 'profile']);
echo "Gib ein: {$device['user_code']} unter {$device['verification_uri']}\n";
$tokens = $oidc->waitForDeviceToken($device['device_code'], $device['interval']);
```

```python
# Python
device = oidc.request_device_code(scopes=['openid', 'profile'])
print(f"Gib ein: {device['user_code']} unter {device['verification_uri']}")
tokens = oidc.wait_for_device_token(device['device_code'], device['interval'])
```

---

## 4. Client Credentials / S2S

Für Server-zu-Server-Kommunikation ohne Benutzer-Kontext. Wird für alle S2S-API-Methoden benötigt.

```http
POST https://api.radisso.de/oidc/token/
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
&client_id=YOUR_CLIENT_ID
&client_secret=YOUR_CLIENT_SECRET
&scope=openid
```

> S2S-Methoden benötigen kein explizites Token aus diesem Endpoint — sie authentifizieren
> sich direkt mit `client_id` + `client_secret` im Request-Body oder als Basic-Auth-Header.

---

## 5. Refresh Token

Access Tokens laufen nach 1 Stunde ab. Mit dem Refresh Token kann ohne erneuten Login
ein neues Access Token angefordert werden.

> **Wichtig: Refresh Token Rotation.** Jede Verwendung eines Refresh Tokens erzeugt
> ein neues Refresh Token. Das alte wird sofort invalidiert. **Das neue Refresh Token
> muss sofort persistiert werden.** Replay Detection ist aktiv —
> wenn ein bereits benutztes Refresh Token erneut gesendet wird, wird die gesamte
> Token-Familie invalidiert.

```http
POST https://api.radisso.de/oidc/token/
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&refresh_token=def50200...
&client_id=YOUR_CLIENT_ID
&client_secret=YOUR_CLIENT_SECRET
```

**Response:** Neues `access_token` + rotiertes `refresh_token`.

```javascript
// Node.js — immer sofort speichern
const newTokens = await oidc.refreshToken(currentRefreshToken);
session.accessToken  = newTokens.access_token;
session.refreshToken = newTokens.refresh_token; // ← SOFORT persistieren
```

---

## 6. Token Management

### Token Revocation (Logout)

```http
POST https://api.radisso.de/oidc/revoke/
Content-Type: application/x-www-form-urlencoded

token=REFRESH_TOKEN_OR_ACCESS_TOKEN
&token_type_hint=refresh_token
&client_id=YOUR_CLIENT_ID
&client_secret=YOUR_CLIENT_SECRET
```

HTTP 200 wird immer geliefert (auch wenn Token nicht gefunden — per RFC 7009).

### Token Introspection

```http
POST https://api.radisso.de/oidc/introspect/
Content-Type: application/x-www-form-urlencoded

token=ACCESS_TOKEN
&client_id=YOUR_CLIENT_ID
&client_secret=YOUR_CLIENT_SECRET
```

**Response (aktiv):**
```json
{
  "active": true,
  "scope": "openid profile email",
  "client_id": "550e8400-...",
  "token_type": "access_token",
  "exp": 1709823600,
  "sub": "bb87ac52-..."
}
```

**Response (inaktiv):** `{"active": false}`

---

## 7. Scopes & Claims

### Verfügbare Scopes

| Scope | Claims | Beschreibung |
|---|---|---|
| `openid` | `sub`, `addressid` | **Pflicht.** Aktiviert OIDC. `addressid` ist immer enthalten. |
| `profile` | `name`, `given_name`, `family_name`, `nickname` | Name und Anrede |
| `email` | `email`, `email_verified` | E-Mail-Adresse |
| `phone` | `phone_number` | Telefonnummer |
| `address` | `address` (Objekt) | Postanschrift |
| `offline_access` | — | Aktiviert Refresh Token |
| `radisso:roles` | `roles` | Benutzerrollen |
| `radisso:participatingevents` | `participatingevents` | Veranstaltungs-Teilnahmen |
| `radisso:participatingconrad` | `participatingconrad` | ConRad-Teilnahmen |
| `radisso:memberships` | `memberships` (deprecated), `orgunits` | Mitgliedschaften & OrganisationUnits |

> Custom Scopes müssen pro Client im Admin-Panel freigeschaltet werden.

### `orgunits`-Struktur (Scope: `radisso:memberships`)

> Der Claim `memberships` ist **deprecated** und wird in einer zukünftigen Version entfernt.
> Bitte stattdessen den Claim `orgunits` verwenden, der im selben Scope enthalten ist.

Das `orgunits`-Array enthält die Haupt-OrgUnits (z.B. eine Fachgesellschaft) mit
einem `children`-Array für Sub-Units (z.B. Arbeitsgemeinschaften, Sektionen).

| Feld | Typ | Beschreibung |
|---|---|---|
| `orgunit_uuid` | `string` | UUID der OrgUnit |
| `name` | `string` | Vollständiger Name |
| `shortname` | `string` | Kürzel |
| `orgunit_active` | `boolean` | OrgUnit selbst ist aktiv |
| `orgunit_public` | `boolean` | OrgUnit ist öffentlich sichtbar |
| `orgunit_main` | `boolean` | Haupt-OrgUnit (true für Root-Entries) |
| `parent` | `string\|null` | Shortname der übergeordneten OrgUnit |
| `parent_uuid` | `string\|null` | UUID der übergeordneten OrgUnit |
| `type` | `string\|null` | Typ (z.B. "Arbeitsgemeinschaft") |
| `type_uuid` | `string\|null` | UUID des Typs |
| `in` | `string\|null` | Beitrittsdatum `YYYY-MM-DD` |
| `out` | `string\|null` | Austrittsdatum `YYYY-MM-DD` (null = aktiv) |
| `active` | `boolean` | Mitgliedschaft aktiv (`false` = ehemaliges Mitglied) |
| `position` | `string\|null` | Funktion/Position (z.B. "Vorsitzender") |
| `position_uuid` | `string\|null` | UUID der Position |
| `position_in` | `string\|null` | Amtsantritt der Position |
| `position_out` | `string\|null` | Amtsende der Position |
| `children` | `array` | Sub-Units (gleiche Struktur, ohne eigenes `children`) |

> `active: false` bedeutet: Der Benutzer **war** Mitglied, ist es aber nicht mehr.
> Clients werden darüber per Webhook informiert (siehe Abschnitt 9).

**Beispiel:**
```json
"orgunits": [
  {
    "orgunit_uuid": "a1b2c3d4-0000-0000-0000-000000000001",
    "name": "Deutsche Röntgengesellschaft",
    "shortname": "DRG",
    "orgunit_active": true,
    "orgunit_public": true,
    "orgunit_main": true,
    "parent": null,
    "parent_uuid": null,
    "type": null,
    "type_uuid": null,
    "in": "2018-01-01",
    "out": null,
    "active": true,
    "position": null,
    "position_uuid": null,
    "position_in": null,
    "position_out": null,
    "children": [
      {
        "orgunit_uuid": "a1b2c3d4-0000-0000-0000-000000000002",
        "name": "Physik und Technik (APT)",
        "shortname": "APT",
        "orgunit_active": true,
        "orgunit_public": true,
        "orgunit_main": false,
        "parent": "DRG",
        "parent_uuid": "a1b2c3d4-0000-0000-0000-000000000001",
        "type": "Arbeitsgemeinschaft",
        "type_uuid": "t1t2t3t4-0000-0000-0000-000000000001",
        "in": "2013-07-10",
        "out": null,
        "active": true,
        "position": "Mitglied",
        "position_uuid": "p1p2p3p4-0000-0000-0000-000000000001",
        "position_in": "2013-07-10",
        "position_out": null,
        "children": []
      }
    ]
  }
]
```

---

## 8. S2S API Methoden

Alle S2S-Methoden werden an `https://api.radisso.de/oidc/s2s/` aufgerufen.
Authentifizierung direkt via `client_id` + `client_secret` (kein separater Token-Request nötig).

> Jede Methode muss im Admin-Panel pro Client explizit freigeschaltet werden.

### Endpoint

```
POST https://api.radisso.de/oidc/s2s/
Content-Type: application/json

{
  "client_id": "YOUR_CLIENT_ID",
  "client_secret": "YOUR_CLIENT_SECRET",
  "method": "METHOD_NAME",
  ...Parameter
}
```

### Verfügbare Methoden

| Methode | Beschreibung |
|---|---|
| `users.search` | Benutzer nach Kriterien suchen |
| `users.list` | Alle Benutzer paginiert abrufen |
| `users.get` | Einzelnen Benutzer abrufen |
| `users.check` | Benutzer prüfen (E-Mail + Geburtsdatum) |
| `users.create` | Neuen Benutzer anlegen |
| `users.createOTL` | One-Time-Login-Hash erzeugen |
| `users.resetLogin` | Login zurücksetzen (Passwort + 2FA) |
| `certificates.types` | Verfügbare Zertifikatstypen |
| `certificates.persons` | Personenzertifikate des Clients |
| `clients.panel` | Client-Panel-Daten |
| `clients.setRedirectUris` | Redirect-URIs aktualisieren |
| `orgunits.list` | Verfügbare OrganisationUnits des Clients |
| `memberform.data` | Mitgliedsformular-Lookup-Daten |

### `users.search`

Suche nach Benutzern anhand von Kriterien.

```json
{
  "method": "users.search",
  "lastname": "Mustermann",
  "firstname": "Max",
  "email": "max@example.de",
  "city": "Berlin",
  "orgunits": ["ou-uuid-1", "ou-uuid-2"]
}
```

`orgunits` (optional): Schränkt die Suche auf Mitglieder dieser OrgUnits (UUIDs) ein.
Der Client kann nur OrgUnits filtern, die ihm zugeordnet sind.

**Response:**
```json
{
  "users": [ { … Benutzer-Objekt … } ],
  "count": 1
}
```

### `users.list`

Alle Benutzer paginiert abrufen.

**Query-Parameter (GET) oder JSON-Body:**

| Parameter | Typ | Default | Beschreibung |
|---|---|---|---|
| `limit` | `integer` | 100 | Max. 1000 |
| `offset` | `integer` | 0 | Startposition |
| `orgunits` | `string[]` | — | UUID-Filter auf OrgUnit-Mitglieder |

**Response:**
```json
{
  "users": [ … ],
  "count": 500,
  "total": 1234,
  "limit": 500,
  "offset": 0,
  "has_more": true
}
```

### `users.get`

Einen Benutzer abrufen — via UUID, Adress-ID oder E-Mail.

```json
{ "method": "users.get", "uuid": "550e8400-…" }
{ "method": "users.get", "addressid": 12345 }
{ "method": "users.get", "email": "max@example.de" }
```

`orgunits` (optional): Gibt `null` zurück, wenn der Benutzer kein Mitglied der
angegebenen OrgUnits ist — nützlich zur Zugangsprüfung.

**Response:**
```json
{
  "user": {
    "uuid": "550e8400-…",
    "addressid": 12345,
    "mail": "max@example.de",
    "firstname": "Max",
    "lastname": "Mustermann",
    "birthdate": "1990-01-15",
    "orgunits": [ … ]
  }
}
```

### `users.check`

Prüft ob ein Benutzer existiert und ob das Geburtsdatum übereinstimmt.
Gibt kein Benutzer-Objekt zurück (Datenschutz).

```json
{ "method": "users.check", "email": "max@example.de", "birthdate": "1990-01-15" }
```

**Response:**
```json
{ "match_level": 2 }
```

| `match_level` | Bedeutung |
|---|---|
| `0` | Benutzer nicht gefunden |
| `1` | E-Mail existiert, Geburtsdatum stimmt nicht |
| `2` | E-Mail + Geburtsdatum stimmen — Identität bestätigt |

### `users.create`

Legt einen neuen Benutzer an.

```json
{
  "method": "users.create",
  "email": "neu@example.de",
  "firstname": "Max",
  "lastname": "Mustermann",
  "city": "Berlin",
  "birthdate": "1990-01-15"
}
```

**Response:** `{ "user": { … }, "created": true }` oder bei Duplikat `"created": false`.

### `users.createOTL`

Erzeugt einen One-Time-Login-Hash für direkten Zugang ohne Passwort (Single Sign-On).

```json
{ "method": "users.createOTL", "email": "max@example.de" }
```

**Response:** `{ "hash": "abc123…", "expires_at": "2026-03-15T20:00:00+01:00" }`

Login-URL: `https://radisso.de/sso/?hash=HASH`

### `users.resetLogin`

Setzt Login-Zugang zurück: Passwort-Reset-Mail, 2FA deaktiviert, OTL erzeugt.

```json
{ "method": "users.resetLogin", "email": "max@example.de" }
```

### `orgunits.list`

Gibt alle OrganisationUnits zurück, die für diesen Client zugänglich sind.
Hat der Client eine OrgUnit-Einschränkung, werden nur die zugeordneten OrgUnits geliefert;
andernfalls alle aktiven OrgUnits des Systems.

```json
{ "method": "orgunits.list" }
```

**Response:**
```json
{
  "orgunits": [
    {
      "uuid": "a1b2c3d4-…",
      "name": "Deutsche Röntgengesellschaft",
      "shortname": "DRG",
      "active": true,
      "public": true,
      "main": true,
      "parent_uuid": null,
      "parent_shortname": null,
      "type": null,
      "type_uuid": null,
      "email": "info@drg.de"
    }
  ],
  "count": 42
}
```

> Die UUIDs aus `orgunits.list` können als `orgunits`-Filter in `users.search`,
> `users.list` und `users.get` verwendet werden.

### `clients.setRedirectUris`

Aktualisiert die erlaubten Redirect-URIs für den Client (Multidomain-Setups).

```json
{
  "method": "clients.setRedirectUris",
  "redirect_uris": [
    "https://www.example.de/oidc/",
    "https://beta.example.de/oidc/"
  ]
}
```

---

## 9. Webhooks

Webhooks informieren den Client in Echtzeit über Benutzeränderungen, ohne dass Polling
notwendig ist.

### Konfiguration (Admin-Panel)

- **Webhook URL** — HTTPS-Endpoint (muss HTTP 2xx antworten)
- **Webhook Secret** — für HMAC-Signatur-Verifikation
- **Events** — `user.created`, `user.updated`, `user.deleted` (leer = alle)
- **OrgUnit-Filter** — Webhooks nur für Mitglieder der zugeordneten OrgUnits

### Events

| Event | Payload enthält |
|---|---|
| `user.created` | Volles Benutzer-Objekt incl. `orgunits` |
| `user.updated` | Volles Benutzer-Objekt + `changed_fields[]` |
| `user.deleted` | Nur `user_uuid` + `addressid` |

**Wichtig bei OrgUnit-Filter:** `user.updated` wird auch gesendet, wenn eine Mitgliedschaft
*weggefallen* ist (`active: false`). Damit kann der Client den Benutzer aus seiner
lokalen Gruppe entfernen. `user.deleted` wird **immer** gesendet, unabhängig vom Filter.

### Payload

```json
{
  "event": "user.updated",
  "timestamp": "2026-03-15T18:00:00+01:00",
  "data": {
    "user_uuid": "550e8400-…",
    "addressid": 12345,
    "changed_fields": ["mail", "firstname"],
    "user": { … volles Benutzer-Objekt … }
  }
}
```

### Signatur-Verifikation

Jeder Webhook trägt den Header `X-Radisso-Signature: sha256=HMAC`.

```php
// PHP
$payload  = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $payload, 'WEBHOOK_SECRET');
if (!hash_equals($expected, $_SERVER['HTTP_X_RADISSO_SIGNATURE'] ?? '')) {
    http_response_code(401); exit;
}
$data = json_decode($payload, true);
```

```python
# Python
import hmac, hashlib
payload  = request.get_data()
expected = 'sha256=' + hmac.new(b'WEBHOOK_SECRET', payload, hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, request.headers.get('X-Radisso-Signature', '')):
    return '', 401
```

```javascript
// Node.js
const crypto = require('crypto');
const expected = 'sha256=' + crypto.createHmac('sha256', 'WEBHOOK_SECRET').update(rawBody).digest('hex');
if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(req.headers['x-radisso-signature'])))
    return res.status(401).end();
```

### Retry-Verhalten

| Eigenschaft | Wert |
|---|---|
| Max. Versuche | 5 |
| Intervall | Minütlich (Cron) |
| Timeout pro Request | 10 Sekunden |
| Erfolg | HTTP 2xx |
| Status nach 5 Fehlern | `failed` |

---

## 10. Discovery Document

```
GET https://api.radisso.de/oidc/.well-known/openid-configuration/
```

Das Discovery Document gibt alle Endpunkte und Capabilities zurück.
Zusätzlich zu den Standard-OIDC-Feldern enthält es:

```json
{
  "s2s_endpoint": "https://api.radisso.de/oidc/s2s/",
  "s2s_methods_supported": [
    "users.search", "users.list", "users.get", "users.check",
    "users.create", "users.createOTL", "users.resetLogin",
    "certificates.types", "certificates.persons",
    "clients.panel", "clients.setRedirectUris",
    "orgunits.list", "memberform.data"
  ],
  "claims_supported": [
    "sub", "iss", "aud", "exp", "iat", "nonce", "auth_time",
    "addressid", "name", "given_name", "family_name", "nickname",
    "email", "email_verified", "phone_number", "address",
    "roles", "participatingevents", "participatingconrad",
    "memberships", "orgunits"
  ]
}
```

---

## 11. SDK Referenz

### Installation

| SDK | Manager | Paket |
|---|---|---|
| **Node.js** (18+) | npm | `npm install radisso-oidc` |
| **PHP** | Composer | `composer require radisso/oidc-client` |
| **Python** | pip | `pip install radisso-oidc` |

### Methoden-Übersicht

| Funktion | Node.js | PHP | Python |
|---|---|---|---|
| Auth-URL erzeugen | `getAuthorizationUrl()` | `getAuthorizationUrl()` | `get_authorization_url()` |
| Code tauschen | `exchangeCode()` | `exchangeCode()` | `exchange_code()` |
| UserInfo | `getUserInfo()` | `getUserInfo()` | `get_userinfo()` |
| Client Credentials | `clientCredentials()` | `clientCredentials()` | `client_credentials()` |
| Refresh | `refreshToken()` | `refreshToken()` | `refresh_token()` |
| Revoke | `revokeToken()` | `revokeToken()` | `revoke_token()` |
| Introspect | `introspect()` | `introspect()` | `introspect()` |
| Device Code | `requestDeviceCode()` | `requestDeviceCode()` | `request_device_code()` |
| Device Token warten | `waitForDeviceToken()` | `waitForDeviceToken()` | `wait_for_device_token()` |
| Users suchen | `usersSearch()` | `usersSearch()` | `users_search()` |
| Users auflisten | `usersList()` | `usersList()` | `users_list()` |
| User abrufen | `usersGet()` | `usersGet()` | `users_get()` |
| User prüfen | `usersCheck()` | `usersCheck()` | `users_check()` |
| User anlegen | `usersCreate()` | `usersCreate()` | `users_create()` |
| OTL erzeugen | `usersCreateOTL()` | `usersCreateOTL()` | `users_create_otl()` |
| Login reset | `usersResetLogin()` | `usersResetLogin()` | `users_reset_login()` |
| OrgUnits | `listOrgUnits()` | `listOrgUnits()` | `list_org_units()` |
| Redirect-URIs | `setRedirectUris()` | `setRedirectUris()` | `set_redirect_uris()` |
| Zertifikatstypen | `certificatesTypes()` | `certificatesTypes()` | `certificates_types()` |
| Personenzertifikate | `certificatesPersons()` | `certificatesPersons()` | `certificates_persons()` |
| Memberform | `memberformData()` | `memberformData()` | `memberform_data()` |
| Incremental Sync | `usersChanged()` | `usersChanged()` | `users_changed()` |

### Konfiguration (alle SDKs)

| Parameter | Beschreibung | Default |
|---|---|---|
| `clientId` | Client-UUID (Pflicht) | — |
| `clientSecret` | Client-Secret (null = Public Client) | `null` |
| `redirectUri` | Callback-URL | `''` |
| `issuer` | Issuer-URL | `https://api.radisso.de/oidc/` |

### TypeScript

Das Node.js-SDK enthält vollständige TypeScript-Definitionen (`index.d.ts`).

```typescript
import { RadissoOIDC, RadissoOIDCError } from 'radisso-oidc';

const oidc = new RadissoOIDC({ clientId: '…', clientSecret: '…' });
const orgunits = await oidc.listOrgUnits();
```

---

## 12. Fehlerbehandlung

### OAuth 2.0 Fehler-Format

```json
{
  "error": "invalid_grant",
  "error_description": "Authorization code has expired",
  "hint": "Please re-request the authorization code"
}
```

### Häufige Error-Codes

| Code | HTTP | Bedeutung |
|---|---|---|
| `invalid_request` | 400 | Fehlende oder ungültige Parameter |
| `invalid_client` | 401 | Client-Authentifizierung fehlgeschlagen |
| `invalid_grant` | 400 | Code/Token ungültig oder abgelaufen |
| `unauthorized_client` | 400 | Client darf diesen Grant Type nicht nutzen |
| `invalid_scope` | 400 | Scope nicht erlaubt oder nicht freigeschaltet |
| `access_denied` | 401 | Benutzer hat Consent abgelehnt |
| `authorization_pending` | 400 | Device Flow: Benutzer noch nicht autorisiert |
| `slow_down` | 400 | Device Flow: Polling zu schnell |
| `expired_token` | 400 | Device Flow: Device Code abgelaufen |

### SDK-Fehlerklassen

```javascript
// Node.js
const { RadissoOIDCError } = require('radisso-oidc');
try {
    const tokens = await oidc.exchangeCode(code, verifier);
} catch (e) {
    if (e instanceof RadissoOIDCError) console.error(e.message, e.errorCode);
}
```

```php
// PHP
use Radisso\OIDCClient\RadissoOIDCException;
try {
    $tokens = $oidc->exchangeCode($code, $verifier);
} catch (RadissoOIDCException $e) {
    echo $e->getMessage() . ' (' . $e->getErrorCode() . ')';
}
```

```python
# Python
from radisso_oidc import RadissoOIDCError
try:
    tokens = oidc.exchange_code(code, verifier)
except RadissoOIDCError as e:
    print(f"{e} ({e.error_code})")
```

---

## 13. Sicherheitshinweise

- **PKCE immer verwenden** für alle flows, bei denen der Authorization Code durch den Browser fließt.
- **`state`-Parameter immer validieren** im Callback (CSRF-Schutz).
- **`nonce` im ID Token prüfen** wenn gesendet (Replay-Protection).
- **`client_secret` niemals im Frontend** exponieren — ausschließlich serverseitig.
- **Redirect-URIs exact-match** — keine Pattern-Matching-Ausnahmen.
- **Refresh Token sofort persistieren** nach jeder Verwendung (Rotation aktiv).
- **Webhook-Signatur immer verifizieren** vor Verarbeitungsbeginn (HMAC-SHA256).
- **ID Token immer verifizieren**: Signatur (RS256), `iss`, `aud`, `exp`, `nonce`.
  Public Key via JWKS: `GET /oidc/jwks/`.
- **Keine eigenen Schlüssel nötig**: Alle Token-Signaturen erfolgen serverseitig.
  Sie benötigen nur `client_id` + `client_secret` (und optional `webhook_secret`).
  Den Public Key für Token-Verifikation laden Sie automatisch vom JWKS-Endpoint.
  Details: siehe [ONBOARDING.md — Kryptografische Schlüssel](ONBOARDING.md#kryptografische-schlüssel--wer-braucht-was).
- **Orgunits-Filter im S2S** schränkt automatisch auf erlaubte UUIDs ein —
  kein zusätzlicher Zugriffsschutz nötig.
