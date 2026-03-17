# RadiSSO OIDC Python Client

Python-Wrapper für die RadiSSO OpenID Connect API.

## Installation

```bash
pip install radisso-oidc
```

Oder manuell:
```bash
pip install requests
```

## Quick Start – Authorization Code (PKCE) mit Flask

```python
from flask import Flask, redirect, request, session
from radisso_oidc import RadissoOIDC

app = Flask(__name__)
app.secret_key = "your-secret-key"

oidc = RadissoOIDC(
    client_id="DEINE_CLIENT_ID",
    client_secret="DEIN_CLIENT_SECRET",
    redirect_uri="https://example.com/callback",
)


@app.route("/login")
def login():
    pkce = RadissoOIDC.generate_pkce()
    state = RadissoOIDC.generate_state()
    nonce = RadissoOIDC.generate_nonce()

    session["pkce_verifier"] = pkce["verifier"]
    session["oauth_state"] = state

    url = oidc.get_authorization_url(
        scopes=["openid", "profile", "email"],
        state=state,
        nonce=nonce,
        code_challenge=pkce["challenge"],
    )
    return redirect(url)


@app.route("/callback")
def callback():
    if request.args.get("state") != session.get("oauth_state"):
        return "State mismatch", 403

    tokens = oidc.exchange_code(
        request.args["code"],
        code_verifier=session["pkce_verifier"],
    )

    session["access_token"] = tokens["access_token"]
    session["refresh_token"] = tokens.get("refresh_token")

    user = oidc.get_userinfo(tokens["access_token"])
    # user["addressid"] — Adress-ID (immer enthalten)
    # user["sub"]       — Benutzer-UUID
    return f"Hallo {user['name']}!"
```

## Client Credentials (Server-to-Server)

```python
oidc = RadissoOIDC("CLIENT_ID", "CLIENT_SECRET")

tokens = oidc.client_credentials(scopes=["radisso:roles"])
access_token = tokens["access_token"]
```

## Refresh Token

```python
# WICHTIG: Neues Refresh Token sofort speichern! (Rotation)
new_tokens = oidc.refresh_token(current_refresh_token)
access_token = new_tokens["access_token"]
refresh_token = new_tokens["refresh_token"]  # SOFORT speichern!
```

## Device Code Flow

```python
oidc = RadissoOIDC("CLIENT_ID", "CLIENT_SECRET")

device = oidc.request_device_code(scopes=["openid", "profile"])
print(f"Öffne {device['verification_uri']} und gib ein: {device['user_code']}")

# Blockierend warten
tokens = oidc.wait_for_device_token(device["device_code"], device["interval"])
print(f"Eingeloggt! Token: {tokens['access_token']}")
```

## Token Management

```python
# Introspection
info = oidc.introspect(access_token)
if info["active"]:
    print(f"Token gültig für User: {info['sub']}")

# Revocation
oidc.revoke_token(refresh_token, "refresh_token")
```

## S2S API (Server-to-Server)

Alle S2S-Methoden erfordern `client_id` + `client_secret` und müssen im RadiSSO-Admin für den Client freigeschaltet sein.

```python
oidc = RadissoOIDC("CLIENT_ID", "CLIENT_SECRET")

# User suchen
result = oidc.users_search({"email": "max@example.de"})
result = oidc.users_search({"lastname": "Muster", "city": "Berlin"})

# User suchen – nur aus bestimmten OrgUnits (UUIDs)
result = oidc.users_search({"lastname": "Muster"}, orgunits=["ou-uuid-1", "ou-uuid-2"])

# Alle User paginiert abrufen
page = oidc.users_list(limit=500, offset=0)
while page["has_more"]:
    page = oidc.users_list(500, page["offset"] + page["limit"])

# Alle User einer bestimmten OrgUnit abrufen
ou_page = oidc.users_list(500, 0, orgunits=["ou-uuid-1"])

# Einzelnen User abrufen
result = oidc.users_get(uuid="550e8400-...")
result = oidc.users_get(addressid=12345)
result = oidc.users_get(email="max@example.de")

# User nur zurückgeben wenn er Mitglied einer bestimmten OrgUnit ist
result = oidc.users_get(email="max@example.de", orgunits=["ou-uuid-1"])

# User prüfen
result = oidc.users_check("max@example.de", birthdate="1990-01-15")

# Neuen User anlegen
result = oidc.users_create("neu@example.de", firstname="Max", lastname="Mustermann")

# One-Time-Login-Hash erzeugen
result = oidc.users_create_otl(email="max@example.de")
login_url = f"https://radisso.de/sso/?hash={result['hash']}"

# Login zurücksetzen
result = oidc.users_reset_login(email="max@example.de")

# Zertifikatstypen / Personenzertifikate
types = oidc.certificates_types()
persons = oidc.certificates_persons()

# Client-Panel-Daten
panel = oidc.clients_panel()

# Redirect-URIs setzen (Multidomain)
result = oidc.set_redirect_uris([
    "https://www.example.de/oidc",
    "https://dev.example.de/oidc",
])

# Verfügbare OrgUnits des Clients abrufen
orgunits = oidc.list_org_units()
# orgunits["orgunits"][].uuid, .name, .shortname, .parent_uuid, .type, .type_uuid

# Mitgliedsformular-Lookups
lookups = oidc.memberform_data("DRG")

# Incremental Sync: Geänderte User seit einem Zeitpunkt
changes = oidc.users_changed("2026-03-01T00:00:00Z")
# changes["changes"][], changes["next_since"], changes["has_more"], changes["count"]

# Mit vollem User-Objekt (wie Webhook-Payload):
full = oidc.users_changed("2026-03-01T00:00:00Z", include_user=True)
# full["changes"][].user — volles Profil (außer bei deleted)
```

## Fehlerbehandlung

```python
from radisso_oidc import RadissoOIDCError

try:
    tokens = oidc.exchange_code(code, verifier)
except RadissoOIDCError as e:
    print(f"Fehler: {e} (Code: {e.error_code})")
```
