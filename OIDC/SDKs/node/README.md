# RadiSSO OIDC Node.js Client

Node.js-Wrapper (18+) für die RadiSSO OpenID Connect API. Keine externen Abhängigkeiten — nutzt native `fetch()`.

## Installation

```bash
npm install radisso-oidc
```

## Quick Start – Authorization Code (PKCE) mit Express

```javascript
const express = require('express');
const session = require('express-session');
const { RadissoOIDC } = require('radisso-oidc');

const app = express();
app.use(session({ secret: 'your-secret', resave: false, saveUninitialized: false }));

const oidc = new RadissoOIDC({
  clientId: 'DEINE_CLIENT_ID',
  clientSecret: 'DEIN_CLIENT_SECRET',
  redirectUri: 'https://example.com/callback',
});

app.get('/login', (req, res) => {
  const pkce  = RadissoOIDC.generatePKCE();
  const state = RadissoOIDC.generateState();
  const nonce = RadissoOIDC.generateNonce();

  req.session.pkceVerifier = pkce.verifier;
  req.session.oauthState   = state;

  const url = oidc.getAuthorizationUrl({
    scopes: ['openid', 'profile', 'email'],
    state,
    nonce,
    codeChallenge: pkce.challenge,
  });
  res.redirect(url);
});

app.get('/callback', async (req, res) => {
  if (req.query.state !== req.session.oauthState) {
    return res.status(403).send('State mismatch');
  }

  const tokens = await oidc.exchangeCode(req.query.code, req.session.pkceVerifier);

  req.session.accessToken  = tokens.access_token;
  req.session.refreshToken = tokens.refresh_token;

  const user = await oidc.getUserInfo(tokens.access_token);
  // user.addressid — Adress-ID (immer enthalten)
  // user.sub       — Benutzer-UUID
  res.send(`Hallo ${user.name}!`);
});

app.listen(3000);
```

## Client Credentials (Server-to-Server)

```javascript
const oidc = new RadissoOIDC({
  clientId: 'CLIENT_ID',
  clientSecret: 'CLIENT_SECRET',
});

const tokens = await oidc.clientCredentials(['radisso:roles']);
console.log(tokens.access_token);
```

## Refresh Token

```javascript
// WICHTIG: Neues Refresh Token sofort speichern! (Rotation)
const newTokens = await oidc.refreshToken(currentRefreshToken);
session.accessToken  = newTokens.access_token;
session.refreshToken = newTokens.refresh_token; // SOFORT speichern!
```

## Device Code Flow

```javascript
const device = await oidc.requestDeviceCode(['openid', 'profile']);
console.log(`Öffne ${device.verification_uri} und gib ein: ${device.user_code}`);

// Blockierend warten
const tokens = await oidc.waitForDeviceToken(device.device_code, device.interval);
console.log(`Eingeloggt! Token: ${tokens.access_token}`);
```

## Token Management

```javascript
// Introspection
const info = await oidc.introspect(accessToken);
if (info.active) {
  console.log(`Token gültig für User: ${info.sub}`);
}

// Revocation
await oidc.revokeToken(refreshToken, 'refresh_token');
```

## S2S API (Server-to-Server)

Alle S2S-Methoden erfordern `clientId` + `clientSecret` und müssen im RadiSSO-Admin für den Client freigeschaltet sein.

```javascript
const oidc = new RadissoOIDC({ clientId: 'CLIENT_ID', clientSecret: 'CLIENT_SECRET' });

// User suchen
const result = await oidc.usersSearch({ email: 'max@example.de' });
const result2 = await oidc.usersSearch({ lastname: 'Muster', city: 'Berlin' });

// User suchen – nur aus bestimmten OrgUnits (UUIDs)
const result3 = await oidc.usersSearch({ lastname: 'Muster' }, ['ou-uuid-1', 'ou-uuid-2']);

// Alle User paginiert abrufen
let page = await oidc.usersList(500, 0);
while (page.has_more) {
  page = await oidc.usersList(500, page.offset + page.limit);
}

// Alle User einer bestimmten OrgUnit abrufen
let ouPage = await oidc.usersList(500, 0, ['ou-uuid-1']);

// Einzelnen User abrufen
const { user } = await oidc.usersGet({ uuid: '550e8400-...' });
const { user: u2 } = await oidc.usersGet({ addressid: 12345 });

// User nur zurückgeben wenn er Mitglied einer bestimmten OrgUnit ist
const { user: u3 } = await oidc.usersGet({ email: 'max@example.de' }, ['ou-uuid-1']);

// User prüfen
const check = await oidc.usersCheck('max@example.de', '1990-01-15');

// Neuen User anlegen
const created = await oidc.usersCreate('neu@example.de', {
  firstname: 'Max', lastname: 'Mustermann'
});

// One-Time-Login-Hash erzeugen
const otl = await oidc.usersCreateOTL({ email: 'max@example.de' });
const loginUrl = `https://radisso.de/sso/?hash=${otl.hash}`;

// Login zurücksetzen
const reset = await oidc.usersResetLogin({ email: 'max@example.de' });

// Zertifikatstypen / Personenzertifikate
const types = await oidc.certificatesTypes();
const persons = await oidc.certificatesPersons();

// Client-Panel-Daten
const panel = await oidc.clientsPanel();

// Redirect-URIs setzen (Multidomain)
const uris = await oidc.setRedirectUris([
  'https://www.example.de/oidc',
  'https://dev.example.de/oidc',
]);

// Verfügbare OrgUnits des Clients abrufen
const orgunits = await oidc.listOrgUnits();
// orgunits.orgunits[].uuid, .name, .shortname, .parent_uuid, .type, .type_uuid

// Mitgliedsformular-Lookups
const lookups = await oidc.memberformData('DRG');

// Incremental Sync: Geänderte User seit einem Zeitpunkt
const changes = await oidc.usersChanged('2026-03-01T00:00:00Z');
// changes.changes[], changes.next_since, changes.has_more, changes.count

// Mit vollem User-Objekt (wie Webhook-Payload):
const full = await oidc.usersChanged('2026-03-01T00:00:00Z', { includeUser: true });
// full.changes[].user — volles Profil (außer bei deleted)
```

## Fehlerbehandlung

```javascript
const { RadissoOIDCError } = require('radisso-oidc');

try {
  const tokens = await oidc.exchangeCode(code, verifier);
} catch (e) {
  if (e instanceof RadissoOIDCError) {
    console.error(`Fehler: ${e.message} (Code: ${e.errorCode})`);
  }
}
```

## TypeScript

TypeScript-Definitionen sind enthalten (`index.d.ts`).
