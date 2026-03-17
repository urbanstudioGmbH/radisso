# Grant Types – Flows im Detail

## Übersicht

| Grant Type | Anwendung | Benutzer-Interaktion | Refresh Token |
|-----------|-----------|---------------------|---------------|
| Authorization Code + PKCE | Web-Apps, SPAs, Mobile | ✅ Login + Consent | ✅ |
| Client Credentials | Server-zu-Server (M2M) | ❌ | ❌ |
| Device Code | Smart TV, CLI, IoT | ✅ Auf anderem Gerät | ✅ |
| Refresh Token | Token-Erneuerung | ❌ | ✅ (rotiert) |

---

## 1. Authorization Code + PKCE

Der Standard-Flow für Anwendungen, bei denen ein Benutzer sich anmeldet. PKCE (Proof Key for Code Exchange) schützt vor Authorization Code Interception und ist für **alle Clients empfohlen** (Pflicht für Public Clients).

### Token-Lifetimes

| Token | TTL |
|-------|-----|
| Authorization Code | 10 Minuten |
| Access Token | 1 Stunde |
| Refresh Token | 30 Tage |

### Ablauf

```
┌──────────┐                    ┌──────────┐                   ┌──────────┐
│  Client   │                    │ RadiSSO  │                   │  API     │
│  (App)    │                    │ Frontend │                   │  Server  │
└────┬─────┘                    └────┬─────┘                   └────┬─────┘
     │  1. Generate code_verifier    │                              │
     │     + code_challenge (S256)   │                              │
     │                               │                              │
     │  2. Redirect to /authorize    │                              │
     │──────────────────────────────>│                              │
     │                               │  3. User Login               │
     │                               │  4. Consent Screen           │
     │                               │  5. Redirect + auth code     │
     │<──────────────────────────────│                              │
     │                               │                              │
     │  6. POST /token               │                              │
     │     code + code_verifier      │                              │
     │─────────────────────────────────────────────────────────────>│
     │                               │                              │
     │  7. access_token + refresh_token + id_token                  │
     │<─────────────────────────────────────────────────────────────│
```

### Schritt 1: PKCE vorbereiten

```javascript
// Code Verifier: 43-128 Zeichen, [A-Za-z0-9-._~]
const verifier = generateRandomString(64);

// Code Challenge: SHA-256 Hash, Base64url-encoded
const challenge = base64url(sha256(verifier));
```

### Schritt 2: Autorisierung starten

```
GET https://radisso.de/oidc/authorize
  ?response_type=code
  &client_id=YOUR_CLIENT_ID
  &redirect_uri=https://your-app.de/callback
  &scope=openid profile email
  &state=RANDOM_STATE_VALUE
  &code_challenge=CHALLENGE
  &code_challenge_method=S256
  &nonce=RANDOM_NONCE
```

### Schritt 3–5: Benutzer-Interaktion

RadiSSO übernimmt:
1. Login (falls nicht angemeldet) → Redirect zu `/login/`
2. Consent-Screen mit angefragten Scopes
3. Bei bestehender Zustimmung: Auto-Approve (kein erneuter Consent)
4. Redirect zurück mit Authorization Code

### Schritt 6: Token-Austausch

```bash
curl -X POST https://api.radisso.de/oidc/token \
  -d grant_type=authorization_code \
  -d code=AUTH_CODE \
  -d redirect_uri=https://your-app.de/callback \
  -d client_id=YOUR_CLIENT_ID \
  -d client_secret=YOUR_SECRET \
  -d code_verifier=ORIGINAL_VERIFIER
```

### Schritt 7: Response

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJS...",
  "refresh_token": "def50200abc123...",
  "id_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6..."
}
```

Das `id_token` enthält die Benutzer-Claims (je nach Scopes). Siehe [SCOPES.md](SCOPES.md).

---

## 2. Client Credentials

Für Server-zu-Server-Kommunikation ohne Benutzerkontext. Nur für **Confidential Clients**.

### Token-Lifetimes

| Token | TTL |
|-------|-----|
| Access Token | 1 Stunde |
| Refresh Token | ❌ Nicht verfügbar |

### Ablauf

```
┌──────────┐                   ┌──────────┐
│  Server   │                   │  API     │
│  (S2S)    │                   │  Server  │
└────┬─────┘                   └────┬─────┘
     │  POST /token                  │
     │  grant_type=client_credentials│
     │  client_id + client_secret    │
     │──────────────────────────────>│
     │                               │
     │  access_token                 │
     │<──────────────────────────────│
```

### Request

```bash
curl -X POST https://api.radisso.de/oidc/token \
  -d grant_type=client_credentials \
  -d client_id=YOUR_CLIENT_ID \
  -d client_secret=YOUR_SECRET \
  -d scope=radisso:memberships
```

### Response

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJS..."
}
```

> **Hinweis:** Kein `sub`-Claim im Token, da kein Benutzer involviert ist. UserInfo-Endpoint nicht nutzbar.

---

## 3. Device Code Flow

Für Geräte ohne Browser oder mit eingeschränkter Eingabe (Smart TVs, CLI-Tools, IoT-Geräte). Der Benutzer autorisiert auf einem separaten Gerät.

### Token-Lifetimes

| Token | TTL |
|-------|-----|
| Device Code | 15 Minuten |
| Access Token | 1 Stunde |
| Refresh Token | 30 Tage |
| Polling Interval | 5 Sekunden |

### Ablauf

```
┌──────────┐                   ┌──────────┐         ┌──────────────┐
│  Gerät    │                   │   API    │         │  Browser     │
│  (TV/CLI) │                   │  Server  │         │  (Benutzer)  │
└────┬─────┘                   └────┬─────┘         └──────┬───────┘
     │  1. POST /device-authorize    │                      │
     │──────────────────────────────>│                      │
     │                               │                      │
     │  2. device_code + user_code   │                      │
     │<──────────────────────────────│                      │
     │                               │                      │
     │  3. Zeige user_code an        │                      │
     │  "Gehen Sie zu radisso.de     │                      │
     │   /oidc/device und geben      │                      │
     │   Sie ABCD-EFGH ein"          │                      │
     │                               │    4. Öffne /device  │
     │                               │<─────────────────────│
     │                               │    5. Code eingeben  │
     │                               │<─────────────────────│
     │                               │    6. Autorisiert!   │
     │                               │─────────────────────>│
     │                               │                      │
     │  7. POST /token (Polling)     │                      │
     │──────────────────────────────>│                      │
     │                               │                      │
     │  8. access_token + refresh    │                      │
     │<──────────────────────────────│                      │
```

### Schritt 1: Device Code anfordern

```bash
curl -X POST https://api.radisso.de/oidc/device-authorize \
  -d client_id=YOUR_CLIENT_ID \
  -d client_secret=YOUR_SECRET \
  -d scope=openid profile email
```

### Schritt 2: Response

```json
{
  "device_code": "def50200abc...",
  "user_code": "ABCD-EFGH",
  "verification_uri": "https://radisso.de/oidc/device",
  "verification_uri_complete": "https://radisso.de/oidc/device?user_code=ABCDEFGH",
  "expires_in": 900,
  "interval": 5
}
```

### Schritt 3: User Code anzeigen

**Dem Benutzer anzeigen:**
> Gehen Sie zu **radisso.de/oidc/device** und geben Sie den Code **ABCD-EFGH** ein.

Alternativ: QR-Code mit `verification_uri_complete` anzeigen.

### Schritt 7: Polling

```bash
# Alle 5 Sekunden wiederholen
curl -X POST https://api.radisso.de/oidc/token \
  -d grant_type=urn:ietf:params:oauth:grant-type:device_code \
  -d device_code=DEF50200ABC... \
  -d client_id=YOUR_CLIENT_ID \
  -d client_secret=YOUR_SECRET
```

**Polling Responses:**

| Situation | HTTP | Response |
|-----------|------|----------|
| Warten | 400 | `{"error": "authorization_pending"}` |
| Zu schnell | 400 | `{"error": "slow_down"}` → Interval erhöhen |
| Erfolg | 200 | Token Response |
| Abgelaufen | 400 | `{"error": "expired_token"}` |

---

## 4. Refresh Token

Erneuert ein abgelaufenes Access Token ohne erneute Benutzer-Interaktion.

### Token-Lifetimes

| Token | TTL |
|-------|-----|
| Neues Access Token | 1 Stunde |
| Neues Refresh Token | 30 Tage (rotiert) |

### Rotation & Replay Detection

RadiSSO implementiert **Refresh Token Rotation**: Bei jeder Nutzung wird ein neues Refresh Token ausgestellt und das alte invalidiert. Wird ein bereits genutztes (altes) Refresh Token erneut verwendet, wird dies als Replay-Angriff erkannt und das Token abgelehnt.

### Request

```bash
curl -X POST https://api.radisso.de/oidc/token \
  -d grant_type=refresh_token \
  -d refresh_token=CURRENT_REFRESH_TOKEN \
  -d client_id=YOUR_CLIENT_ID \
  -d client_secret=YOUR_SECRET
```

### Response

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ...(neues Token)...",
  "refresh_token": "def50200...(neues Refresh Token)..."
}
```

> **Wichtig:** Immer das neue Refresh Token speichern! Das alte ist nach Nutzung ungültig.

---

## Consent-Verhalten

- Beim ersten Zugriff eines Clients: Consent-Screen mit allen angefragten Scopes
- Bei erneutem Zugriff mit gleichen oder weniger Scopes: **Auto-Approve** (kein erneuter Consent)
- Bei neuen Scopes: Erneuter Consent für die erweiterten Berechtigungen
- Consent wird pro User + Client + Scopes gespeichert und merged
