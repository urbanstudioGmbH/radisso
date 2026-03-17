# RadiSSO OIDC – Onboarding-Anleitung für Externe

Willkommen! Diese Anleitung erklärt Schritt für Schritt, wie Sie Ihre Anwendung an das RadiSSO Single-Sign-On-System anbinden.

---

## Übersicht

RadiSSO ist der zentrale Identitätsdienst. Über OpenID Connect (OIDC) können Sie:

- **Benutzer authentifizieren** (Single Sign-On)
- **Profildaten abrufen** (Name, E-Mail, Telefon, Adresse)
- **Rollen und Mitgliedschaften prüfen** (Berechtigungssteuerung)
- **Server-to-Server-Zugriff** (ohne Benutzerinteraktion)

---

## Kryptografische Schlüssel — Wer braucht was?

| Schlüssel | Wo | Wer verwaltet | Zweck |
|-----------|-----|---------------|-------|
| **RSA Private Key** (`radisso.key`) | RadiSSO-Server | RadiSSO-Team | Signiert ID Tokens und Access Tokens (RS256) |
| **RSA Public Key** (JWKS) | Öffentlich via `/oidc/jwks/` | RadiSSO-Team | Clients verifizieren damit die Token-Signatur |
| **Encryption Key** | RadiSSO-Server | RadiSSO-Team | Verschlüsselt Authorization Codes intern |
| **Client Secret** | Ihr Server | Gemeinsam | Authentifiziert Ihren Client beim Token-Endpoint |
| **Webhook Secret** | Ihr Server | Gemeinsam | HMAC-Signatur-Verifikation eingehender Webhooks |

**Als Client benötigen Sie keine eigenen Schlüssel zu generieren.** Sie erhalten von uns `client_id` + `client_secret` und optional ein `webhook_secret`. Alle kryptografischen Operationen (Token-Signatur, Verschlüsselung) übernimmt RadiSSO.

Zur **Verifizierung von ID Tokens** (empfohlen) laden Sie den öffentlichen Schlüssel automatisch vom JWKS-Endpoint:

```
GET https://api.radisso.de/oidc/jwks/
```

Dieser liefert den RSA Public Key im JWK-Format. Standard-OIDC-Libraries (z.B. `firebase/php-jwt`, `jose` für Node.js, `PyJWT` für Python) laden den JWKS automatisch.

> **Wichtig:** Cachen Sie den JWKS lokal (z.B. 24h) — er ändert sich nur bei Key-Rotation, die wir vorab ankündigen.

---

## 1. Zugangsdaten erhalten

Sie erhalten von uns:

| Daten | Beschreibung |
|-------|-------------|
| **Client-ID** | Eindeutige UUID Ihrer Anwendung |
| **Client Secret** | Geheimes Passwort (nur für Serveranwendungen) |
| **Erlaubte Scopes** | Welche Daten Sie abfragen dürfen |
| **Grant Types** | Welche Authentifizierungsverfahren erlaubt sind |

**Von Ihnen benötigen wir:**
- **Redirect URI(s)**: Die exakten Callback-URLs Ihrer Anwendung (z.B. `https://ihre-app.de/auth/callback`)
- **Anwendungstyp**: Server-Anwendung (Confidential) oder Browser-App (Public)

---

## 2. Endpunkte

| Zweck | URL |
|-------|-----|
| **Discovery** | `https://api.radisso.de/oidc/.well-known/openid-configuration/` |
| **Authorization** | `https://radisso.de/oidc/authorize/` |
| **Token** | `https://api.radisso.de/oidc/token/` |
| **UserInfo** | `https://api.radisso.de/oidc/userinfo/` |
| **JWKS** | `https://api.radisso.de/oidc/jwks/` |
| **Token widerrufen** | `https://api.radisso.de/oidc/revoke/` |
| **Device Login** | `https://api.radisso.de/oidc/device-authorize/` |

> **Tipp:** Nutzen Sie das Discovery-Dokument, um alle Endpunkte automatisch zu ermitteln.

---

## 3. Ablauf: Benutzer-Login (Authorization Code + PKCE)

Dies ist der Standard-Flow für Web-Anwendungen:

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Ihre App   │     │   Browser   │     │  RadiSSO    │
└──────┬──────┘     └──────┬──────┘     └──────┬──────┘
       │                   │                    │
       │  1. Redirect →    │                    │
       │──────────────────>│  → radisso.de      │
       │                   │───────────────────>│
       │                   │  2. Login-Formular  │
       │                   │<───────────────────│
       │                   │  3. Login + Consent │
       │                   │───────────────────>│
       │  4. Callback      │                    │
       │  ?code=ABC123     │                    │
       │<──────────────────│<───────────────────│
       │                   │                    │
       │  5. Token-Austausch (Server-to-Server)  │
       │────────────────────────────────────────>│
       │  6. Access Token + ID Token             │
       │<────────────────────────────────────────│
       │                   │                    │
       │  7. UserInfo (optional)                 │
       │────────────────────────────────────────>│
       │  8. Benutzerdaten                       │
       │<────────────────────────────────────────│
```

### Schritt 1: Login starten

Leiten Sie den Benutzer weiter:

```
https://radisso.de/oidc/authorize/?
  response_type=code
  &client_id=IHRE_CLIENT_ID
  &redirect_uri=https://ihre-app.de/auth/callback
  &scope=openid profile email
  &state=ZUFALLS_STRING
  &code_challenge=PKCE_CHALLENGE
  &code_challenge_method=S256
```

**Wichtige Parameter:**
- `state`: Zufälliger String, den Sie sich merken. Schützt gegen CSRF-Angriffe.
- `code_challenge`: PKCE-Challenge (siehe SDK-Beispiele unten). Schützt den Auth Code.
- `scope`: Welche Daten Sie benötigen (siehe Abschnitt "Verfügbare Scopes")

### Schritt 2-3: Benutzer loggt sich ein

RadiSSO zeigt dem Benutzer ein Login-Formular und (beim ersten Mal) eine Einwilligungsseite mit den angeforderten Berechtigungen.

### Schritt 4: Callback

RadiSSO leitet zurück zu Ihrer Redirect URI:
```
https://ihre-app.de/auth/callback?code=AUTH_CODE&state=ZUFALLS_STRING
```

**Prüfen Sie den `state`-Parameter!** Er muss mit dem gesendeten Wert übereinstimmen.

### Schritt 5-6: Code gegen Token tauschen

```bash
POST https://api.radisso.de/oidc/token/
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&code=AUTH_CODE
&redirect_uri=https://ihre-app.de/auth/callback
&client_id=IHRE_CLIENT_ID
&client_secret=IHR_SECRET
&code_verifier=PKCE_VERIFIER
```

**Antwort:**
```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ...",
  "refresh_token": "def50200...",
  "id_token": "eyJ..."
}
```

### Schritt 7-8: Benutzerdaten abrufen

```bash
GET https://api.radisso.de/oidc/userinfo/
Authorization: Bearer ACCESS_TOKEN
```

**Antwort:**
```json
{
  "sub": "benutzer-uuid",
  "addressid": 12345,
  "name": "Max Mustermann",
  "given_name": "Max",
  "family_name": "Mustermann",
  "email": "max@example.de",
  "email_verified": true
}
```

> **Hinweis:** `addressid` wird **immer** zurückgegeben (kein Scope nötig) und identifiziert den Benutzer im RadiSSO-Adresssystem.

---

## 4. Verfügbare Scopes

| Scope | Daten |
|-------|-------|
| `openid` | **Pflicht.** Benutzer-ID (`sub`) + Adress-ID (`addressid`) |
| `profile` | Name, Vorname, Nachname, Anrede |
| `email` | E-Mail-Adresse |
| `phone` | Telefonnummer |
| `address` | Postanschrift |
| `offline_access` | Refresh Token (für Langzeit-Zugriff) |
| `radisso:roles` | Benutzerrollen im System |
| `radisso:participatingevents` | Veranstaltungs-Teilnahmen |
| `radisso:participatingconrad` | ConRad-Teilnahmen |
| `radisso:memberships` | Mitgliedschaften & OrganisationUnits |

> Sie erhalten nur die Scopes, die für Ihren Client freigeschaltet sind.

---

## 5. Token-Lebensdauer

| Token | Gültigkeit | Hinweis |
|-------|-----------|---------|
| Access Token | 1 Stunde | Für API-Zugriff |
| Refresh Token | 30 Tage | Zum Erneuern des Access Tokens |
| ID Token | 1 Stunde | Einmalig bei Login, nicht erneuerbar |

### Refresh Token erneuern

```bash
POST https://api.radisso.de/oidc/token/
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&refresh_token=IHR_REFRESH_TOKEN
&client_id=IHRE_CLIENT_ID
&client_secret=IHR_SECRET
```

> **Wichtig:** Bei jeder Nutzung erhalten Sie ein **neues** Refresh Token. Das alte wird sofort ungültig. Speichern Sie das neue Token **sofort**!

---

## 6. SDK-Bibliotheken

Wir stellen fertige Wrapper-Bibliotheken bereit:

### PHP
```bash
composer require radisso/oidc-client
```
```php
use Radisso\OIDCClient\RadissoOIDC;

$oidc = new RadissoOIDC(
    clientId: 'IHRE_CLIENT_ID',
    clientSecret: 'IHR_SECRET',
    redirectUri: 'https://ihre-app.de/callback'
);

// Login-URL generieren
$pkce = RadissoOIDC::generatePKCE();
$url = $oidc->getAuthorizationUrl(
    scopes: ['openid', 'profile', 'email'],
    state: RadissoOIDC::generateState(),
    codeChallenge: $pkce['challenge']
);

// Im Callback
$tokens = $oidc->exchangeCode($_GET['code'], $pkce['verifier']);
$user = $oidc->getUserInfo($tokens['access_token']);
```

### Python
```bash
pip install radisso-oidc
```
```python
from radisso_oidc import RadissoOIDC

oidc = RadissoOIDC(
    client_id="IHRE_CLIENT_ID",
    client_secret="IHR_SECRET",
    redirect_uri="https://ihre-app.de/callback"
)

pkce = RadissoOIDC.generate_pkce()
url = oidc.get_authorization_url(
    scopes=["openid", "profile", "email"],
    state=RadissoOIDC.generate_state(),
    code_challenge=pkce["challenge"]
)

# Im Callback
tokens = oidc.exchange_code(code, pkce["verifier"])
user = oidc.get_userinfo(tokens["access_token"])
```

### Node.js
```bash
npm install radisso-oidc
```
```javascript
const { RadissoOIDC } = require('radisso-oidc');

const oidc = new RadissoOIDC({
  clientId: 'IHRE_CLIENT_ID',
  clientSecret: 'IHR_SECRET',
  redirectUri: 'https://ihre-app.de/callback',
});

const pkce = RadissoOIDC.generatePKCE();
const url = oidc.getAuthorizationUrl({
  scopes: ['openid', 'profile', 'email'],
  state: RadissoOIDC.generateState(),
  codeChallenge: pkce.challenge,
});

// Im Callback
const tokens = await oidc.exchangeCode(code, pkce.verifier);
const user = await oidc.getUserInfo(tokens.access_token);
```

---

## 7. Logout

Zum Logout senden Sie den Refresh Token an den Revocation-Endpoint:

```bash
POST https://api.radisso.de/oidc/revoke/
Content-Type: application/x-www-form-urlencoded

token=IHR_REFRESH_TOKEN
&token_type_hint=refresh_token
&client_id=IHRE_CLIENT_ID
&client_secret=IHR_SECRET
```

Danach: Lokale Session löschen.

---

## 8. Sicherheitshinweise

| Thema | Empfehlung |
|-------|-----------|
| **PKCE** | Immer verwenden (schützt Auth Code vor Diebstahl) |
| **State** | Immer senden und im Callback prüfen (CSRF-Schutz) |
| **Refresh Token** | Serverseitig speichern, nicht im Browser |
| **Access Token** | Nicht im localStorage (XSS-Risiko) |
| **HTTPS** | Alle Kommunikation nur über HTTPS |
| **Secret** | Niemals im Frontend-Code oder Git-Repository |

---

## 9. Fehlerbehandlung

| HTTP-Code | `error` | Bedeutung |
|-----------|---------|-----------|
| 400 | `invalid_request` | Fehlender/fehlerhafter Parameter |
| 400 | `invalid_grant` | Auth Code abgelaufen oder ungültig |
| 400 | `invalid_scope` | Scope nicht erlaubt |
| 401 | `invalid_client` | Client-ID oder Secret falsch |
| 400 | `access_denied` | Benutzer hat Einwilligung verweigert |

---

## 10. Checkliste für die Inbetriebnahme

- [ ] Client-ID und Secret erhalten
- [ ] Redirect URI(s) bei uns registriert
- [ ] SDK installiert oder eigene Integration implementiert
- [ ] PKCE implementiert
- [ ] State-Parameter implementiert
- [ ] Token-Refresh implementiert (neues Refresh Token speichern!)
- [ ] Fehlerbehandlung implementiert
- [ ] Logout (Token Revocation) implementiert
- [ ] HTTPS in Produktion

---

## Kontakt & Support

Bei Fragen zur Integration wenden Sie sich an Ihr internes RadiSSO-Team.

**Testumgebung:**
- Authorization: `https://dev.radisso.de/oidc/authorize/`
- API: `https://dev.api.radisso.de/oidc/`
- Discovery: `https://dev.api.radisso.de/oidc/.well-known/openid-configuration/`

---

## Self-Service-Registrierung

Sie können Ihren OIDC-Client auch selbst registrieren, ohne vorherige Absprache. Die Freigabe erfolgt dann durch einen Admin.

### Endpoint

```bash
POST https://api.radisso.de/oidc/onboarding/
Content-Type: application/json

{
  "name": "Meine App",
  "contact_name": "Max Mustermann",
  "contact_company": "Beispiel GmbH",
  "contact_email": "max@example.de",
  "contact_phone": "+49 30 12345",
  "redirect_uris": ["https://meine-app.de/callback"],
  "webhook_url": "https://meine-app.de/radisso-webhook",
  "webhook_secret": "mein-geheimes-webhook-secret-min16"
}
```

### Pflichtfelder

| Feld | Beschreibung |
|------|-------------|
| `name` | App-Name (min. 3 Zeichen, muss einzigartig sein) |
| `contact_email` | Gültige E-Mail der Kontaktperson |

### Optionale Felder

| Feld | Beschreibung |
|------|-------------|
| `contact_name` | Name der Kontaktperson |
| `contact_company` | Firmenname |
| `contact_phone` | Telefonnummer |
| `redirect_uris` | Array von Callback-URLs |
| `webhook_url` | HTTPS-URL für Webhook-Events (muss gültige URL sein) |
| `webhook_secret` | Shared Secret für HMAC-Signatur-Verifikation (mind. 16 Zeichen) |

### Ablauf

1. Sie senden den POST-Request
2. Sie erhalten eine `client_id` zurück (Status: `pending`)
3. Ein Admin wird automatisch per E-Mail benachrichtigt
4. Nach Prüfung erhalten Sie per E-Mail:
   - Bei **Freigabe**: Ihre `client_id` + `client_secret` — damit können Sie sofort starten
   - Bei **Ablehnung**: Eine Benachrichtigung

### Antwort (HTTP 201)

```json
{
  "status": "pending",
  "message": "Your OIDC client registration has been submitted and is awaiting review.",
  "client_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### Fehler

| HTTP | Fehler | Ursache |
|------|--------|--------|
| 400 | `invalid_request` | Name zu kurz oder E-Mail ungültig |
| 409 | `conflict` | App-Name bereits vergeben |

> **Wichtig:** Das Client Secret erhalten Sie **nur einmalig** per E-Mail nach der Freigabe. Bitte speichern Sie es sicher ab.
