# Sicherheit

Übersicht der Sicherheitsmaßnahmen der RadiSSO OIDC-Implementierung.

---

## Inhaltsverzeichnis

1. [Schlüsselverwaltung](#schlüsselverwaltung)
2. [Token-Sicherheit](#token-sicherheit)
3. [PKCE](#pkce)
4. [CSRF-Schutz](#csrf-schutz)
5. [Token Rotation & Replay-Schutz](#token-rotation--replay-schutz)
6. [CORS-Konfiguration](#cors-konfiguration)
7. [Dateiberechtigungen](#dateiberechtigungen)
8. [Checkliste für Produktivbetrieb](#checkliste-für-produktivbetrieb)

---

## Schlüsselverwaltung

### RSA-Schlüsselpaar (JWT-Signierung)

| Datei | Pfad | Beschreibung |
|-------|------|-------------|
| Private Key | `/var/certs/radisso.key` | RSA-2048, signiert JWT-Tokens (RS256) |
| Public Key | `radisso.pub` (Webroot) | Veröffentlicht über JWKS-Endpoint |

- **Algorithmus:** RS256 (RSA SHA-256)
- **Key ID (kid):** Erste 16 Zeichen des SHA-256-Hash des RSA-Modulus
- Der Public Key wird über den JWKS-Endpoint (`/oidc/jwks`) mit **1 Tag Cache** bereitgestellt

### Encryption Key (symmetrisch)

| Eigenschaft | Wert |
|-------------|------|
| **Pfad** | `/var/certs/oidc_encryption.key` |
| **Generierung** | `base64_encode(random_bytes(32))` — 256-Bit Entropie |
| **Verwendung** | Verschlüsselung von Auth Codes, Access Tokens, Refresh Tokens |
| **Berechtigungen** | `0600` (rw-------) — automatisch gesetzt |
| **Auto-Generierung** | Wird erstellt, falls nicht vorhanden |

> Der Encryption Key wird von `league/oauth2-server` intern für die symmetrische Verschlüsselung der Token-Payloads verwendet. Geht dieser Key verloren, werden alle ausgestellten Tokens ungültig.

---

## Token-Sicherheit

### Token Lifetimes

| Token | TTL | Beschreibung |
|-------|-----|-------------|
| Authorization Code | 10 Minuten | Einmalig verwendbar |
| Access Token | 1 Stunde | Bearer Token |
| Refresh Token | 30 Tage | Rotiert bei jeder Nutzung |
| Device Code | 15 Minuten | Verfällt bei Ablauf |
| ID Token | 1 Stunde | JWT, nicht verlängerbar |

### JWT-Struktur (ID Token)

```
Header:  { typ: "JWT", alg: "RS256", kid: "<key-id>" }
Payload: { iss, sub, aud, exp, iat, nonce, auth_time, ... }
Signatur: OpenSSL SHA-256 mit RSA Private Key
```

### Access Token Format

Access Tokens sind von `league/oauth2-server` generierte, verschlüsselte Strings — **keine JWTs**. Validierung erfolgt über:
- **Introspection-Endpoint:** Clients können Token-Gültigkeit prüfen
- **Resource Server:** Entschlüsselung mit dem Encryption Key

---

## PKCE

**Proof Key for Code Exchange** schützt den Authorization Code Flow vor Interception-Angriffen.

| Eigenschaft | Wert |
|-------------|------|
| **Methode** | `S256` (SHA-256) |
| **Erzwingung** | Durch `league/oauth2-server` automatisch |
| **Discovery** | `code_challenge_methods_supported: ["S256"]` |

### Ablauf

1. Client generiert `code_verifier` (zufälliger String, 43-128 Zeichen)
2. Client berechnet `code_challenge = BASE64URL(SHA256(code_verifier))`
3. `code_challenge` wird im Authorization Request gesendet
4. `code_verifier` wird beim Token-Austausch gesendet
5. Server verifiziert: `SHA256(code_verifier) === stored_challenge`

> PKCE ist **dringend empfohlen** für alle Clients, auch für Confidential Clients.

---

## CSRF-Schutz

### Consent-Formular

| Element | Implementierung |
|---------|----------------|
| **Generierung** | `bin2hex(random_bytes(32))` — 64-Zeichen-Hex |
| **Speicherung** | `$_SESSION['oidc_csrf']` |
| **Validierung** | `hash_equals()` (Constant-Time-Vergleich) |
| **Verbrauch** | Token wird nach erfolgreicher Validierung gelöscht |

### Device-Code-Formular

| Element | Implementierung |
|---------|----------------|
| **Generierung** | `bin2hex(random_bytes(32))` — 64-Zeichen-Hex |
| **Speicherung** | `$_SESSION['oidc_device_csrf']` |
| **Validierung** | `hash_equals()` (Constant-Time-Vergleich) |
| **Verbrauch** | Token wird nach erfolgreicher Validierung gelöscht |

### OAuth `state`-Parameter

Zusätzlich wird der OAuth `state`-Parameter als CSRF-Schutz auf Client-Seite empfohlen:
- Client generiert zufälligen `state`
- Server gibt `state` unverändert im Callback zurück
- Client vergleicht `state` mit gespeichertem Wert

---

## Token Rotation & Replay-Schutz

### Refresh Token Rotation

Bei jeder Nutzung eines Refresh Tokens:

1. Altes Refresh Token wird **sofort ungültig**
2. Neues Refresh Token wird ausgestellt (neue 30-Tage-TTL)
3. Neues Access Token wird ausgestellt

### Replay Detection

Wird ein **bereits verwendetes** Refresh Token erneut eingesetzt:

```
Altes (verbrauchtes) Token → Replay erkannt!
→ Alle Tokens der Token-Familie werden widerrufen
→ Angreifer UND legitimer Nutzer müssen sich neu authentifizieren
```

Dies schützt gegen die Situation, dass ein Angreifer ein Refresh Token abfängt und parallel nutzt.

### Nonce-Schutz

Der `nonce`-Parameter aus dem Authorization Request wird:
- In `$_SESSION['oidc_nonce']` gespeichert
- In das ID Token eingebettet
- Vom Client nach Erhalt des ID Tokens verifiziert

---

## CORS-Konfiguration

| Endpoint | `Access-Control-Allow-Origin` | Methoden |
|----------|-------------------------------|----------|
| Discovery | `*` | GET |
| JWKS | `*` | GET |
| Token | `*` | POST, OPTIONS |
| UserInfo | `*` | GET, POST, OPTIONS |
| Revoke | `*` | POST, OPTIONS |
| Introspect | `*` | POST, OPTIONS |
| Device Authorize | `*` | POST, OPTIONS |

**OPTIONS-Preflight:**
- Gibt `204 No Content` zurück
- Erlaubt Header: `Authorization`, `Content-Type`

> Für erhöhte Sicherheit kann `Access-Control-Allow-Origin` auf spezifische Domains eingeschränkt werden. Die aktuelle `*`-Konfiguration ist notwendig für Clients die von beliebigen Domains aus zugreifen.

---

## Dateiberechtigungen

| Datei | Empfohlene Rechte | Beschreibung |
|-------|------------------|-------------|
| `/var/certs/radisso.key` | `0600` | RSA Private Key – nur Webserver-User |
| `/var/certs/oidc_encryption.key` | `0600` | Encryption Key – auto-generiert mit korrekten Rechten |
| `radisso.pub` | `0644` | RSA Public Key – öffentlich lesbar |

### Empfehlung

```bash
# Berechtigungen prüfen und setzen
chown www-data:www-data /var/certs/radisso.key /var/certs/oidc_encryption.key
chmod 600 /var/certs/radisso.key /var/certs/oidc_encryption.key
chmod 644 radisso.pub
```

---

## Checkliste für Produktivbetrieb

### Zwingend

- [ ] RSA Private Key existiert unter `/var/certs/radisso.key` mit Berechtigung `0600`
- [ ] Encryption Key wird automatisch generiert (oder manuell mit `base64_encode(random_bytes(32))`)
- [ ] HTTPS auf beiden Domains (`radisso.de` und `api.radisso.de`)
- [ ] Redirect URIs verwenden ausschließlich `https://`
- [ ] Client Secrets sind ausreichend lang und sicher gespeichert
- [ ] `oidc.admin`-Berechtigung nur an autorisierte Administratoren vergeben

### Empfohlen

- [ ] Rate-Limiting auf Token- und Introspection-Endpoints
- [ ] Monitoring für fehlgeschlagene Token-Requests
- [ ] Regelmäßige Key-Rotation (RSA-Schlüssel)
- [ ] CORS-Origins einschränken, falls alle Clients bekannt sind
- [ ] Content-Security-Policy und X-Frame-Options auf Consent-/Device-Seiten
- [ ] Logging von Token-Revocations und Replay-Detection-Events

### Client-seitig

- [ ] PKCE (S256) für alle Authorization Code Flows verwenden
- [ ] `state`-Parameter für CSRF-Schutz
- [ ] `nonce`-Parameter für Replay-Schutz
- [ ] Refresh Tokens **sofort** nach Erhalt speichern (Rotation!)
- [ ] Access Tokens nicht im `localStorage` (XSS-Risiko)
- [ ] ID Token-Signatur, Issuer, Audience und Expiry validieren
