# RadiSSO OIDC PHP Client

PHP-Wrapper für die RadiSSO OpenID Connect API.

## Installation

```bash
composer require radisso/oidc-client
```

Oder manuell: `src/`-Ordner kopieren und PSR-4-Autoloading konfigurieren.

## Quick Start – Authorization Code (PKCE)

```php
<?php
require 'vendor/autoload.php';

use Radisso\OIDCClient\RadissoOIDC;

$oidc = new RadissoOIDC(
    clientId:     'DEINE_CLIENT_ID',
    clientSecret: 'DEIN_CLIENT_SECRET',
    redirectUri:  'https://example.com/callback'
);

// 1. Login starten
session_start();
$pkce  = RadissoOIDC::generatePKCE();
$state = RadissoOIDC::generateState();
$nonce = RadissoOIDC::generateNonce();

$_SESSION['pkce_verifier'] = $pkce['verifier'];
$_SESSION['oauth_state']   = $state;

$url = $oidc->getAuthorizationUrl(
    scopes:        ['openid', 'profile', 'email'],
    state:         $state,
    nonce:         $nonce,
    codeChallenge: $pkce['challenge']
);

header("Location: $url");
exit;
```

```php
// 2. Callback verarbeiten
$oidc = new RadissoOIDC('CLIENT_ID', 'CLIENT_SECRET', 'https://example.com/callback');

if (!hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
    die('State mismatch');
}

$tokens = $oidc->exchangeCode($_GET['code'], $_SESSION['pkce_verifier']);

// Access Token + Refresh Token speichern
$_SESSION['access_token']  = $tokens['access_token'];
$_SESSION['refresh_token'] = $tokens['refresh_token'] ?? null;

// UserInfo abrufen
$user = $oidc->getUserInfo($tokens['access_token']);
echo "Hallo {$user['name']}!";
// $user['addressid'] — Adress-ID (immer enthalten)
// $user['sub']       — Benutzer-UUID
```

## Client Credentials (Server-to-Server)

```php
$oidc = new RadissoOIDC('CLIENT_ID', 'CLIENT_SECRET');

$tokens = $oidc->clientCredentials(['radisso:roles']);
$accessToken = $tokens['access_token'];
```

## Refresh Token

```php
// WICHTIG: Neues Refresh Token sofort speichern! (Rotation)
$newTokens = $oidc->refreshToken($currentRefreshToken);
$_SESSION['access_token']  = $newTokens['access_token'];
$_SESSION['refresh_token'] = $newTokens['refresh_token'];
```

## Device Code Flow

```php
$oidc = new RadissoOIDC('CLIENT_ID', 'CLIENT_SECRET');

$device = $oidc->requestDeviceCode(['openid', 'profile']);
echo "Öffne {$device['verification_uri']} und gib ein: {$device['user_code']}\n";

// Blockierend warten (mit Polling)
$tokens = $oidc->waitForDeviceToken($device['device_code'], $device['interval']);
echo "Eingeloggt! Token: {$tokens['access_token']}\n";
```

## Token Revocation (Logout)

```php
$oidc->revokeToken($refreshToken, 'refresh_token');
```

## Token Introspection

```php
$info = $oidc->introspect($accessToken);
if ($info['active']) {
    echo "Token gültig für User: {$info['sub']}";
}
```

## S2S API (Server-to-Server)

Alle S2S-Methoden erfordern `clientId` + `clientSecret` und müssen im RadiSSO-Admin für den Client freigeschaltet sein.

```php
$oidc = new RadissoOIDC('CLIENT_ID', 'CLIENT_SECRET');

// User suchen
$result = $oidc->usersSearch(['email' => 'max@example.de']);
$result = $oidc->usersSearch(['lastname' => 'Muster', 'city' => 'Berlin']);

// User suchen – nur aus bestimmten OrgUnits (UUIDs)
$result = $oidc->usersSearch(['lastname' => 'Muster'], ['ou-uuid-1', 'ou-uuid-2']);

// Alle User paginiert abrufen
$page = $oidc->usersList(limit: 500, offset: 0);
while ($page['has_more']) {
    $page = $oidc->usersList(500, $page['offset'] + $page['limit']);
}

// Alle User einer bestimmten OrgUnit abrufen
$ouPage = $oidc->usersList(500, 0, ['ou-uuid-1']);

// Einzelnen User abrufen
$result = $oidc->usersGet(['uuid' => '550e8400-...']);
$result = $oidc->usersGet(['addressid' => 12345]);
$result = $oidc->usersGet(['email' => 'max@example.de']);

// User nur zurückgeben wenn er Mitglied einer bestimmten OrgUnit ist
$result = $oidc->usersGet(['email' => 'max@example.de'], ['ou-uuid-1']);

// User prüfen (existiert + Geburtsdatum-Match)
$result = $oidc->usersCheck('max@example.de', '1990-01-15');
// match_level: 0=nicht gefunden, 1=Mail existiert, 2=Mail+Geburtsdatum stimmen

// Neuen User anlegen
$result = $oidc->usersCreate('neu@example.de', [
    'firstname' => 'Max', 'lastname' => 'Mustermann', 'city' => 'Berlin'
]);

// One-Time-Login-Hash erzeugen
$result = $oidc->usersCreateOTL(['email' => 'max@example.de']);
$loginUrl = "https://radisso.de/sso/?hash={$result['hash']}";

// Login zurücksetzen (Passwort vergessen + 2FA deaktiviert + OTL)
$result = $oidc->usersResetLogin(['email' => 'max@example.de']);

// Zertifikatstypen / Personenzertifikate
$types   = $oidc->certificatesTypes();
$persons = $oidc->certificatesPersons();

// Client-Panel-Daten
$panel = $oidc->clientsPanel();

// Redirect-URIs setzen (Multidomain)
$result = $oidc->setRedirectUris([
    'https://www.example.de/oidc',
    'https://dev.example.de/oidc',
]);

// Verfügbare OrgUnits des Clients abrufen
$orgunits = $oidc->listOrgUnits();
// $orgunits['orgunits'][]['uuid'], ['name'], ['shortname'], ['parent_uuid'], ['type'], ['type_uuid']

// Mitgliedsformular-Lookups
$lookups = $oidc->memberformData('DRG');

// Incremental Sync: Geänderte User seit einem Zeitpunkt
$changes = $oidc->usersChanged('2026-03-01T00:00:00Z');
// $changes['changes'][], $changes['next_since'], $changes['has_more'], $changes['count']

// Mit vollem User-Objekt (wie Webhook-Payload):
$full = $oidc->usersChanged('2026-03-01T00:00:00Z', 500, true);
// $full['changes'][]['user'] — volles Profil (außer bei deleted)
```

## Fehlerbehandlung

```php
use Radisso\OIDCClient\RadissoOIDCException;

try {
    $tokens = $oidc->exchangeCode($code, $verifier);
} catch (RadissoOIDCException $e) {
    echo "Fehler: {$e->getMessage()} (Code: {$e->getErrorCode()})";
}
```

## Konfiguration

| Parameter | Default | Beschreibung |
|-----------|---------|-------------|
| `$clientId` | — | Client-ID (UUID) |
| `$clientSecret` | `null` | Secret (null = Public Client) |
| `$redirectUri` | `''` | Callback-URL |
| `$issuer` | `https://api.radisso.de/oidc` | Issuer-URL |
