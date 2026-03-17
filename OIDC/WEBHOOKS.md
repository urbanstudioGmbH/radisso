# RadiSSO OIDC — Webhooks & Incremental Sync

## Übersicht

RadiSSO bietet zwei Mechanismen, um Client-Systeme über Benutzeränderungen zu informieren:

1. **Webhooks** — Push-Benachrichtigungen in Echtzeit bei User-Änderungen  
2. **Incremental Sync** — Pull-Endpoint für inkrementellen Abgleich seit einem Zeitstempel

Beide Mechanismen erfordern eine gültige Client-Registrierung.

---

## 1. Webhooks

### Aktivierung

Webhooks werden pro Client im Admin-Panel konfiguriert:

- **Webhook URL** — HTTPS-Endpoint, der POST-Requests empfängt  
- **Webhook Secret** — Shared Secret für HMAC-Signatur-Verifikation  
- **Webhooks aktiv** — Ein/Aus-Schalter pro Client
- **Webhook Events** — Welche Events der Client erhält (leer = alle)

### Event-Filterung

Pro Client kann konfiguriert werden, welche Events er empfängt:

| Event | Beschreibung |
|---|---|
| `user.created` | Neuer Benutzer angelegt — Payload enthält vollen Datensatz |
| `user.updated` | Benutzerdaten geändert — Payload enthält vollen Datensatz + `changed_fields` |
| `user.deleted` | Benutzer gelöscht — Payload enthält nur `user_uuid` + `addressid` |

Wenn keine Events ausgewählt sind, erhält der Client **alle** Events (Rückwärtskompatibilität).

### Events

| Event | Auslöser |
|---|---|
| `user.created` | Neuer Benutzer angelegt — Payload enthält vollen Datensatz |
| `user.updated` | Benutzerdaten geändert (nur OIDC-relevante Felder) — Payload enthält vollen Datensatz + `changed_fields` |
| `user.deleted` | Benutzer gelöscht — Payload enthält nur `user_uuid` + `addressid` |

### OIDC-relevante Felder

Nur Änderungen an diesen Feldern lösen einen Webhook aus:

`mail`, `salutation`, `title`, `firstname`, `lastname`, `company`, `department`, `streetnr`, `zip`, `city`, `country`, `phone`, `addressid`, `participatingevents`, `participatingconrad`, `memberships`

### OrgUnit-basierte Webhook-Filterung

Clients können im Admin-Panel OrganisationUnits zugeordnet werden. Das beeinflusst, welche Webhooks ein Client erhält:

| Event | Filterverhalten |
|-------|----------------|
| `user.deleted` | **Immer** an alle Clients — der User existiert nicht mehr, alle müssen es wissen |
| `user.created` / `user.updated` | Nur wenn der User eine **aktive oder früher aktive** Mitgliedschaft in einer der Client-OrgUnits hat |

**Wichtig für `user.updated`:** Clients werden auch informiert, wenn eine Mitgliedschaft gerade *weggefallen* ist (z.B. `active: false`). Damit kann das Client-System erkennen, dass ein User nicht mehr zu seiner Organisation gehört.

Hat ein Client **keine** OrganisationUnit-Einschränkung, erhält er alle Webhooks (Standardverhalten).

Das Benutzer-Objekt im Webhook-Payload (`data.user`) enthält immer das `orgunits`-Array, gefiltert auf die für den Client relevanten OrgUnits (inkl. inaktiver Mitgliedschaften im Fall eines Wegfalls).

### Payload-Format

```http
POST /your-webhook-endpoint HTTP/1.1
Content-Type: application/json
X-Radisso-Event: user.updated
X-Radisso-Signature: sha256=a1b2c3d4e5f6...
X-Radisso-Timestamp: 2026-03-07T18:00:00+01:00
User-Agent: RadiSSO-Webhook/1.0
```

```json
{
    "event": "user.updated",
    "timestamp": "2026-03-07T18:00:00+01:00",
    "data": {
        "user_uuid": "550e8400-e29b-41d4-a716-446655440000",
        "addressid": 12345,
        "changed_fields": ["mail", "firstname"],
        "user": {
            "uuid": "550e8400-e29b-41d4-a716-446655440000",
            "addressid": 12345,
            "mail": "max@example.com",
            "salutation": "Herr",
            "title": null,
            "firstname": "Max",
            "lastname": "Mustermann",
            "birthdate": "1990-01-15",
            "company": "Muster GmbH",
            "department": null,
            "streetnr": "Musterstr. 1",
            "zip": "12345",
            "city": "Musterstadt",
            "country": "DE",
            "phone": "+49 123 456789",
            "participatingevents": null,
            "participatingconrad": null,
            "memberships": null,
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
        }
    }
}
```

**Hinweis bei weggefallenem Mitglied** (`active: false`): Das `orgunits`-Array enthält auch inaktive Mitgliedschaften, damit das Client-System erkennen kann, aus welcher OrgUnit der User ausgetreten ist. Verwende `active: false` als Signal, um ihn z.B. aus einer lokalen Gruppe zu entfernen:

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
        "out": "2026-03-14",
        "active": false,
        "position": null,
        "position_uuid": null,
        "position_in": null,
        "position_out": null,
        "children": []
    }
]
```

Bei `user.created` und `user.updated` enthält `data.user` den **kompletten Benutzerdatensatz** (identisch mit der `users.get`-Antwort der S2S-API). Das erspart dem Client eine zusätzliche API-Abfrage. Über `changed_fields` kann selektiv erkannt werden, welche Felder sich geändert haben.

Bei `user.deleted` wird **kein** `user`-Objekt mitgeliefert — nur `user_uuid` und `addressid`.

### Signatur-Verifikation

Der `X-Radisso-Signature`-Header enthält eine HMAC-SHA256-Signatur des gesamten Request-Body:

```
sha256=HMAC-SHA256(request_body, webhook_secret)
```

#### PHP-Beispiel

```php
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RADISSO_SIGNATURE'] ?? '';
$secret    = 'euer_webhook_secret';

$expected  = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$data = json_decode($payload, true);
// Event verarbeiten...
```

#### Python-Beispiel

```python
import hmac, hashlib, json
from flask import request

secret = b'euer_webhook_secret'
payload = request.get_data()
signature = request.headers.get('X-Radisso-Signature', '')

expected = 'sha256=' + hmac.new(secret, payload, hashlib.sha256).hexdigest()

if not hmac.compare_digest(expected, signature):
    return 'Invalid signature', 401

data = json.loads(payload)
# Event verarbeiten...
```

#### Node.js-Beispiel

```javascript
const crypto = require('crypto');

function verifyWebhook(req, secret) {
    const payload   = JSON.stringify(req.body);
    const signature = req.headers['x-radisso-signature'] || '';
    const expected  = 'sha256=' + crypto.createHmac('sha256', secret).update(payload).digest('hex');
    return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature));
}
```

### Retry-Verhalten

| Eigenschaft | Wert |
|---|---|
| Max. Versuche | 5 |
| Verarbeitungsintervall | Jede Minute (Cron) |
| Timeout pro Request | 10 Sekunden |
| Erfolg | HTTP 2xx |
| Status nach 5 Fehlversuchen | `failed` |

Gesendete Einträge werden nach **7 Tagen** gelöscht, fehlgeschlagene nach **30 Tagen**.

### Antwort

Euer Endpoint muss mit HTTP **2xx** antworten. Der Response-Body wird ignoriert.

---

## 2. Incremental Sync Endpoint

Für Systeme, die nicht auf Push-Webhooks reagieren können oder einen periodischen Abgleich bevorzugen.

### Endpoint

```
GET https://api.radisso.de/oidc/users/changed?since={ISO_8601_TIMESTAMP}
```

### Authentifizierung

Basic Auth (Client-Credentials) oder Bearer Token:

```bash
# Basic Auth
curl -u "CLIENT_ID:CLIENT_SECRET" \
  "https://api.radisso.de/oidc/users/changed?since=2026-03-01T00:00:00Z"

# Bearer Token (vorher per Client-Credentials-Grant erhalten)
curl -H "Authorization: Bearer ACCESS_TOKEN" \
  "https://api.radisso.de/oidc/users/changed?since=2026-03-01T00:00:00Z"
```

### Parameter

| Parameter | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `since` | string | ja | ISO 8601 Zeitstempel — nur Änderungen nach diesem Zeitpunkt |
| `limit` | int | nein | Max. Anzahl Ergebnisse (1–1000, Default: 500) |
| `include` | string | nein | `user` — liefert das volle Benutzer-Objekt pro Eintrag mit (wie im Webhook-Payload). Bei `deleted`-Einträgen wird kein `user`-Objekt geliefert. |

### Response

```json
{
    "changes": [
        {
            "user_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "addressid": 12345,
            "change_type": "updated",
            "changed_at": "2026-03-07T18:00:43+01:00",
            "changed_fields": ["mail", "firstname"]
        },
        {
            "user_uuid": "660f9511-f3ac-52e5-b827-557766551111",
            "addressid": 67890,
            "change_type": "created",
            "changed_at": "2026-03-07T18:05:12+01:00",
            "changed_fields": null
        }
    ],
    "next_since": "2026-03-07T18:05:12+01:00",
    "has_more": false,
    "count": 2
}
```

Mit `include=user` enthält jeder Eintrag (außer `deleted`) zusätzlich ein `user`-Objekt — identisch zum Webhook-Payload:

```json
{
    "changes": [
        {
            "user_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "addressid": 12345,
            "change_type": "updated",
            "changed_at": "2026-03-07T18:00:43+01:00",
            "changed_fields": ["mail", "firstname"],
            "user": {
                "uuid": "550e8400-e29b-41d4-a716-446655440000",
                "addressid": 12345,
                "mail": "max@example.com",
                "firstname": "Max",
                "lastname": "Mustermann",
                "orgunits": [ ... ]
            }
        }
    ],
    "next_since": "2026-03-07T18:00:43+01:00",
    "has_more": false,
    "count": 1
}
```

| Feld | Beschreibung |
|---|---|
| `changes[]` | Array der Änderungen seit `since` |
| `changes[].user_uuid` | UUID des geänderten Benutzers |
| `changes[].addressid` | Adress-ID im RadiSSO-System |
| `changes[].change_type` | `created`, `updated` oder `deleted` |
| `changes[].changed_at` | Zeitpunkt der Änderung (ISO 8601) |
| `changes[].changed_fields` | Geänderte Felder (nur bei `updated`, sonst `null`) |
| `changes[].user` | Volles Benutzer-Objekt (nur bei `include=user`, nicht bei `deleted`) |
| `next_since` | Zeitstempel für den nächsten Abruf — als `since`-Parameter weiterverwenden |
| `has_more` | `true` wenn weitere Ergebnisse folgen (Paginierung nötig) |
| `count` | Anzahl der zurückgegebenen Änderungen |

### Paginierung

Wenn `has_more: true`, rufe den Endpoint erneut mit `since=next_since` auf:

```python
import requests

since = "2026-03-01T00:00:00Z"
all_changes = []

while True:
    resp = requests.get(
        f"https://api.radisso.de/oidc/users/changed?since={since}&limit=500",
        auth=("CLIENT_ID", "CLIENT_SECRET")
    )
    data = resp.json()
    all_changes.extend(data["changes"])
    
    if not data["has_more"]:
        since = data["next_since"]  # für den nächsten Lauf merken
        break
    since = data["next_since"]
```

### Changelog-Aufbewahrung

Einträge im Changelog werden nach **90 Tagen** automatisch gelöscht. Stellt sicher, dass ihr mindestens alle 90 Tage synchronisiert.

---

## 3. Empfohlene Strategie

### Echtzeit-Updates: Webhooks + Sync als Fallback

```
┌─────────────┐  Webhook POST   ┌─────────────┐
│   RadiSSO   │ ──────────────→ │ Euer System │
│             │                  │             │
│  User saved │                  │  Sofort     │
│             │                  │  verarbeiten│
└─────────────┘                  └─────────────┘

                  Falls Webhook fehlschlägt:

┌─────────────┐  GET /changed   ┌─────────────┐
│   RadiSSO   │ ←────────────── │ Euer System │
│             │                  │ (Cron, z.B. │
│  Changelog  │ ──────────────→ │  alle 15min)│
└─────────────┘  JSON Response   └─────────────┘
```

1. Aktiviert **Webhooks** für Echtzeit-Push  
2. Richtet zusätzlich einen **Cron** ein (z.B. alle 15 Minuten), der den Sync-Endpoint abfragt  
3. Der Sync fängt verpasste Webhooks auf (Netzwerkprobleme, Downtime)  

### Nur Sync (ohne Webhooks)

Für einfachere Setups reicht ein regelmäßiger Abruf des Sync-Endpoints:

```bash
# Crontab: alle 5 Minuten
*/5 * * * * /usr/bin/php /path/to/sync-script.php
```

---

## 4. Fehlercodes

| HTTP | Error | Beschreibung |
|---|---|---|
| 400 | `invalid_request` | `since`-Parameter fehlt oder ungültig |
| 401 | `unauthorized` | Keine oder ungültige Authentifizierung |
| 405 | `method_not_allowed` | Nur GET erlaubt |
| 404 | `not_found` | Unbekannter Endpoint |

---

## 5. Datenbank-Schema (Referenz)

### radisso_oidc_user_changelog

| Spalte | Typ | Beschreibung |
|---|---|---|
| id | BIGINT PK | Auto-Increment |
| useruuid | CHAR(36) | UUID des Benutzers |
| changedat | TIMESTAMP | Zeitpunkt (Default: NOW()) |
| changetype | ENUM | `created`, `updated`, `deleted` |
| changedfields | TEXT | JSON-Array der geänderten Felder |

### radisso_oidc_webhook_queue

| Spalte | Typ | Beschreibung |
|---|---|---|
| id | BIGINT PK | Auto-Increment |
| clientid | INT | FK auf radisso_oidc_clients.id |
| event | VARCHAR(50) | z.B. `user.updated` |
| payload | MEDIUMTEXT | JSON-Payload |
| attempts | INT | Bisherige Versuche (Default: 0) |
| status | ENUM | `pending`, `sent`, `failed` |
| createdat | TIMESTAMP | Eintrag erstellt |
| sentat | TIMESTAMP | Erfolgreich gesendet |
| lastattemptat | TIMESTAMP | Letzter Versuch |
