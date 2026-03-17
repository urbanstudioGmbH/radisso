# Scopes & Claims

## Scope-Übersicht

### Standard OpenID Connect Scopes

| Scope | Beschreibung | Claims |
|-------|-------------|--------|
| `openid` | **Pflicht.** Aktiviert OpenID Connect (ID Token) | `sub`, `iss`, `aud`, `exp`, `iat` |
| `profile` | Name und Profilinformationen | `name`, `given_name`, `family_name`, `nickname` |
| `email` | E-Mail-Adresse | `email`, `email_verified` |
| `phone` | Telefonnummer | `phone_number` |
| `address` | Postanschrift | `address` (Objekt) |
| `offline_access` | Langzeit-Zugriff via Refresh Token | (kein eigener Claim) |

### RadiSSO Custom Scopes

| Scope | Beschreibung | Claims |
|-------|-------------|--------|
| `radisso:roles` | Benutzerrollen im System | `roles` |
| `radisso:participatingevents` | Teilnahmen an Veranstaltungen | `participatingevents` |
| `radisso:participatingconrad` | ConRad-Teilnahmen | `participatingconrad` |
| `radisso:memberships` | Mitgliedschaften & OrganisationUnits | `memberships` (deprecated), `orgunits` |

> Custom Scopes müssen pro Client explizit freigeschaltet werden (Admin-Panel → Client → Scopes).

---

## Claims-Referenz

### Standard-Claims (immer im ID Token)

| Claim | Typ | Beschreibung |
|-------|-----|-------------|
| `sub` | `string` | Benutzer-UUID (z.B. `bb87ac52-a522-4f3e-8985-7f2ec512961b`) |
| `addressid` | `integer` | Adress-ID im RadiSSO-System (immer enthalten, kein Scope nötig) |
| `iss` | `string` | Issuer URL (`https://api.radisso.de/oidc/`) |
| `aud` | `string` | Client-ID des anfragenden Clients |
| `exp` | `integer` | Ablaufzeit (Unix Timestamp) |
| `iat` | `integer` | Ausstellungszeit (Unix Timestamp) |
| `nonce` | `string` | Nonce aus dem Authorization Request (falls gesendet) |
| `auth_time` | `integer` | Zeitpunkt der Authentifizierung (falls verfügbar) |

### Scope: `profile`

| Claim | Typ | Beispiel | Beschreibung |
|-------|-----|---------|-------------|
| `name` | `string` | `"Dr. Max Mustermann"` | Vollständiger Name (Titel + Vorname + Nachname) |
| `given_name` | `string` | `"Max"` | Vorname |
| `family_name` | `string` | `"Mustermann"` | Nachname |
| `nickname` | `string` | `"Herr"` | Anrede (falls vorhanden) |

### Scope: `email`

| Claim | Typ | Beispiel | Beschreibung |
|-------|-----|---------|-------------|
| `email` | `string` | `"max@example.de"` | E-Mail-Adresse |
| `email_verified` | `boolean` | `true` | Immer `true` in RadiSSO |

### Scope: `phone`

| Claim | Typ | Beispiel | Beschreibung |
|-------|-----|---------|-------------|
| `phone_number` | `string` | `"+49 123 456789"` | Telefonnummer (falls hinterlegt) |

### Scope: `address`

| Claim | Typ | Beschreibung |
|-------|-----|-------------|
| `address` | `object` | Adress-Objekt gemäß OIDC-Standard |
| `address.street_address` | `string` | Straße und Hausnummer |
| `address.postal_code` | `string` | Postleitzahl |
| `address.locality` | `string` | Stadt |
| `address.country` | `string` | Land |

**Beispiel:**
```json
{
  "address": {
    "street_address": "Musterstraße 42",
    "postal_code": "12345",
    "locality": "Berlin",
    "country": "DE"
  }
}
```

### Scope: `radisso:roles`

| Claim | Typ | Beschreibung |
|-------|-----|-------------|
| `roles` | `array` | Liste der Benutzerrollen |

### Scope: `radisso:participatingevents`

| Claim | Typ | Beschreibung |
|-------|-----|-------------|
| `participatingevents` | `array` | Veranstaltungs-Teilnahmen des Benutzers |

### Scope: `radisso:participatingconrad`

| Claim | Typ | Beschreibung |
|-------|-----|-------------|
| `participatingconrad` | `array` | ConRad-Teilnahmen des Benutzers |

### Scope: `radisso:memberships`

| Claim | Typ | Beschreibung |
|-------|-----|-------------|
| `orgunits` | `array` | OrganisationUnit-Mitgliedschaften (strukturiert, gefiltert nach Client-Konfiguration) |
| `memberships` | `array` | Vereinsmitgliedschaften des Benutzers **(deprecated** — bitte `orgunits` verwenden) |

**`orgunits`-Struktur:** Haupt-OrgUnits (z.B. DRG) mit `children`-Array für Sub-Units (z.B. Arbeitsgemeinschaften):

```json
"orgunits": [
  {
    "orgunit_uuid": "abc-...",
    "name": "Deutsche R\u00f6ntgengesellschaft",
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
        "orgunit_uuid": "def-...",
        "name": "Physik und Technik (APT)",
        "shortname": "APT",
        "orgunit_active": true,
        "orgunit_public": true,
        "orgunit_main": false,
        "parent": "DRG",
        "parent_uuid": "abc-...",
        "type": "Arbeitsgemeinschaft",
        "type_uuid": "type-uuid-...",
        "in": "2013-07-10",
        "out": null,
        "active": true,
        "position": "Mitglied",
        "position_uuid": "pos-uuid-...",
        "position_in": "2013-07-10",
        "position_out": null
      }
    ]
  }
]
```

> `active: false` bedeutet: Mitgliedschaft hat bestanden, ist aber nicht mehr aktiv. Clients werden über den Wegfall einer Mitgliedschaft per Webhook informiert.

---

## ID Token vs. UserInfo

Claims werden an **zwei Stellen** geliefert:

| Quelle | Wann | Enthält |
|--------|------|---------|
| **ID Token** | Im Token-Response (`id_token`) | Standard-Claims + Scoped Claims |
| **UserInfo Endpoint** | Auf Anfrage mit Access Token | Scoped Claims (aktuellste Daten) |

**Empfehlung:** Für initiale Authentifizierung das ID Token nutzen, für aktuelle Profildaten den UserInfo-Endpoint abfragen.

---

## Beispiel-Responses

### Token Response mit `openid profile email`

**ID Token (decodiert):**
```json
{
  "iss": "https://api.radisso.de/oidc/",
  "sub": "bb87ac52-a522-4f3e-8985-7f2ec512961b",
  "addressid": 12345,
  "aud": "550e8400-e29b-41d4-a716-446655440000",
  "exp": 1709823600,
  "iat": 1709820000,
  "nonce": "xyz789",
  "name": "Dr. Max Mustermann",
  "given_name": "Max",
  "family_name": "Mustermann",
  "email": "max@example.de",
  "email_verified": true
}
```

### UserInfo Response mit `openid profile email radisso:roles`

```json
{
  "sub": "bb87ac52-a522-4f3e-8985-7f2ec512961b",
  "addressid": 12345,
  "name": "Dr. Max Mustermann",
  "given_name": "Max",
  "family_name": "Mustermann",
  "email": "max@example.de",
  "email_verified": true,
  "roles": ["admin", "editor"]
}
```
