"""
RadiSSO OIDC Python Client

Unterstützt: Authorization Code (PKCE), Client Credentials, Device Code, Refresh Token
"""

from __future__ import annotations

import base64
import hashlib
import secrets
import time
from typing import Any
from urllib.parse import urlencode

import requests


class RadissoOIDCError(Exception):
    """OIDC Fehler mit Error-Code"""

    def __init__(self, message: str, error_code: str = "unknown_error"):
        self.error_code = error_code
        super().__init__(message)


class RadissoOIDC:
    """
    RadiSSO OIDC Client

    Args:
        client_id: Client-ID (UUID)
        client_secret: Client Secret (None für Public Clients)
        redirect_uri: Callback-URL
        issuer: Issuer Base-URL
    """

    def __init__(
        self,
        client_id: str,
        client_secret: str | None = None,
        redirect_uri: str = "",
        issuer: str = "https://api.radisso.de/oidc",
    ):
        self.client_id = client_id
        self.client_secret = client_secret
        self.redirect_uri = redirect_uri
        self.api_url = issuer.rstrip("/")
        self.auth_url = self.api_url.replace("api.", "", 1)
        self._discovery: dict | None = None
        self._session = requests.Session()
        self._session.timeout = 30

    # ─── Discovery ───────────────────────────────────────────────

    def discover(self) -> dict[str, Any]:
        """Discovery-Dokument laden und cachen"""
        if self._discovery is None:
            self._discovery = self._get(
                f"{self.api_url}/.well-known/openid-configuration"
            )
        return self._discovery

    # ─── PKCE & Helpers ──────────────────────────────────────────

    @staticmethod
    def generate_pkce() -> dict[str, str]:
        """
        PKCE Code Verifier + Challenge generieren

        Returns:
            {"verifier": "...", "challenge": "..."}
        """
        verifier = secrets.token_urlsafe(32)
        challenge = (
            base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest())
            .rstrip(b"=")
            .decode()
        )
        return {"verifier": verifier, "challenge": challenge}

    @staticmethod
    def generate_state() -> str:
        """Zufälligen State-Parameter generieren"""
        return secrets.token_hex(16)

    @staticmethod
    def generate_nonce() -> str:
        """Zufälligen Nonce generieren"""
        return secrets.token_hex(16)

    # ─── Authorization Code Flow (PKCE) ─────────────────────────

    def get_authorization_url(
        self,
        scopes: list[str],
        state: str,
        nonce: str = "",
        code_challenge: str = "",
    ) -> str:
        """
        Authorization-URL für den Browser-Redirect erzeugen

        Args:
            scopes: z.B. ["openid", "profile", "email"]
            state: CSRF-Token (zufällig)
            nonce: Replay-Schutz
            code_challenge: PKCE Challenge (S256)
        """
        params: dict[str, str] = {
            "response_type": "code",
            "client_id": self.client_id,
            "redirect_uri": self.redirect_uri,
            "scope": " ".join(scopes),
            "state": state,
        }
        if nonce:
            params["nonce"] = nonce
        if code_challenge:
            params["code_challenge"] = code_challenge
            params["code_challenge_method"] = "S256"

        return f"{self.auth_url}/authorize?{urlencode(params)}"

    def exchange_code(
        self, code: str, code_verifier: str = ""
    ) -> dict[str, Any]:
        """
        Authorization Code gegen Tokens tauschen

        Returns:
            {"access_token": "...", "refresh_token": "...", "id_token": "...", "expires_in": 3600}
        """
        params: dict[str, str] = {
            "grant_type": "authorization_code",
            "code": code,
            "redirect_uri": self.redirect_uri,
            "client_id": self.client_id,
        }
        if self.client_secret:
            params["client_secret"] = self.client_secret
        if code_verifier:
            params["code_verifier"] = code_verifier

        return self._post(f"{self.api_url}/token", params)

    # ─── Client Credentials Flow ────────────────────────────────

    def client_credentials(
        self, scopes: list[str] | None = None
    ) -> dict[str, Any]:
        """
        Access Token via Client Credentials (Server-to-Server)

        Returns:
            {"access_token": "...", "expires_in": 3600}
        """
        params: dict[str, str] = {"grant_type": "client_credentials"}
        if scopes:
            params["scope"] = " ".join(scopes)
        return self._post(f"{self.api_url}/token", params, use_basic_auth=True)

    # ─── Refresh Token ──────────────────────────────────────────

    def refresh_token(self, refresh_token: str) -> dict[str, Any]:
        """
        Access Token mit Refresh Token erneuern

        WICHTIG: Das zurückgegebene refresh_token SOFORT speichern!
        Das alte Token ist danach ungültig (Rotation).

        Returns:
            {"access_token": "...", "refresh_token": "...", "expires_in": 3600}
        """
        params: dict[str, str] = {
            "grant_type": "refresh_token",
            "refresh_token": refresh_token,
            "client_id": self.client_id,
        }
        if self.client_secret:
            params["client_secret"] = self.client_secret
        return self._post(f"{self.api_url}/token", params)

    # ─── Device Code Flow ───────────────────────────────────────

    def request_device_code(
        self, scopes: list[str] | None = None
    ) -> dict[str, Any]:
        """
        Device Code anfordern

        Returns:
            {"device_code": "...", "user_code": "ABCD-1234",
             "verification_uri": "...", "expires_in": 900, "interval": 5}
        """
        if scopes is None:
            scopes = ["openid", "profile"]
        params: dict[str, str] = {
            "client_id": self.client_id,
            "scope": " ".join(scopes),
        }
        if self.client_secret:
            params["client_secret"] = self.client_secret
        return self._post(f"{self.api_url}/device-authorize", params)

    def poll_device_token(self, device_code: str) -> dict[str, Any] | None:
        """
        Device Code Token Polling (einzelner Versuch)

        Returns:
            Token-Set oder None wenn noch pending

        Raises:
            RadissoOIDCError bei expired_token oder anderen Fehlern
        """
        params = {
            "grant_type": "urn:ietf:params:oauth:grant-type:device_code",
            "device_code": device_code,
            "client_id": self.client_id,
        }
        resp = self._session.post(f"{self.api_url}/token", data=params)
        data = resp.json()

        if resp.status_code == 200:
            return data

        error = data.get("error", "unknown_error")
        if error in ("authorization_pending", "slow_down"):
            return None

        raise RadissoOIDCError(
            data.get("error_description", error), error
        )

    def wait_for_device_token(
        self,
        device_code: str,
        interval: int = 5,
        max_wait: int = 900,
    ) -> dict[str, Any]:
        """
        Vollständiges Device Code Polling mit Warten

        Returns:
            Token-Set

        Raises:
            RadissoOIDCError bei Timeout oder Fehler
        """
        start = time.monotonic()
        while time.monotonic() - start < max_wait:
            result = self.poll_device_token(device_code)
            if result is not None:
                return result
            time.sleep(interval)
        raise RadissoOIDCError("Device code expired", "expired_token")

    # ─── Resource Endpoints ─────────────────────────────────────

    def get_userinfo(self, access_token: str) -> dict[str, Any]:
        """
        UserInfo-Endpoint abfragen

        Returns:
            {"sub": "...", "name": "...", "email": "...", ...}
        """
        return self._get(f"{self.api_url}/userinfo", bearer_token=access_token)

    def introspect(self, token: str) -> dict[str, Any]:
        """
        Token-Introspection (prüft ob Token aktiv)

        Returns:
            {"active": true/false, "sub": "...", "scope": "...", ...}
        """
        return self._post(
            f"{self.api_url}/introspect",
            {"token": token},
            use_basic_auth=True,
        )

    def revoke_token(
        self, token: str, token_type_hint: str = "refresh_token"
    ) -> None:
        """Token widerrufen (Logout)"""
        params: dict[str, str] = {
            "token": token,
            "token_type_hint": token_type_hint,
        }
        if self.client_secret:
            params["client_id"] = self.client_id
            params["client_secret"] = self.client_secret
        self._post(f"{self.api_url}/revoke", params)

    def get_jwks(self) -> dict[str, Any]:
        """JWKS (Public Keys) laden"""
        return self._get(f"{self.api_url}/jwks")

    # ─── S2S-Methoden ───────────────────────────────────────────

    def users_search(self, criteria: dict[str, Any], orgunits: list[str] | None = None) -> dict[str, Any]:
        """
        User suchen (S2S)

        Args:
            criteria: z.B. {"addressid": 12345}, {"uuid": "..."}, {"email": "..."},
                      {"lastname": "Muster", "city": "Berlin"}
            orgunits: Optional: Nur User dieser OrgUnits (UUIDs). Subset der Client-OrgUnits.

        Returns:
            {"users": [...], "count": N}
        """
        data = {**criteria}
        if orgunits is not None:
            data["orgunits"] = orgunits
        return self._s2s_post_json("users.search", data)

    def users_list(self, limit: int = 500, offset: int = 0, orgunits: list[str] | None = None) -> dict[str, Any]:
        """
        Alle aktiven User abrufen (S2S, paginiert)

        Args:
            limit: Max. Einträge pro Seite (max. 5000)
            offset: Startposition
            orgunits: Optional: Nur User dieser OrgUnits (UUIDs). Subset der Client-OrgUnits.

        Returns:
            {"users": [...], "total": N, "offset": N, "limit": N, "has_more": bool}
        """
        params: dict[str, Any] = {"limit": limit, "offset": offset}
        if orgunits is not None:
            params["orgunits"] = ",".join(orgunits)
        return self._s2s_get("users.list", params)

    def users_get(self, orgunits: list[str] | None = None, **identifier) -> dict[str, Any]:
        """
        Einzelnen User abrufen (S2S)

        Args:
            uuid, addressid oder email als Keyword-Argument
            orgunits: Optional: OrgUnit-Filter (UUIDs) für das orgunits-Feld im Profil

        Returns:
            {"user": {...}}
        """
        params = {**identifier}
        if orgunits is not None:
            params["orgunits"] = ",".join(orgunits)
        return self._s2s_get("users.get", params)

    def users_check(self, email: str, birthdate: str | None = None) -> dict[str, Any]:
        """
        User prüfen (S2S)

        Args:
            email: E-Mail-Adresse
            birthdate: Optional, Format YYYY-MM-DD

        Returns:
            {"exists": bool, "match_level": 0|1|2, "user": {...} oder None}
        """
        data: dict[str, Any] = {"email": email}
        if birthdate:
            data["birthdate"] = birthdate
        return self._s2s_post_json("users.check", data)

    def users_create(self, email: str, **fields) -> dict[str, Any]:
        """
        Neuen User anlegen oder bestehenden zurückgeben (S2S)

        Args:
            email: Pflicht
            **fields: Optionale Felder (firstname, lastname, salutation, company, etc.)

        Returns:
            {"created": bool, "status": 0-3, "user": {...}}
        """
        return self._s2s_post_json("users.create", {"email": email, **fields})

    def users_create_otl(self, **identifier) -> dict[str, Any]:
        """
        One-Time-Login-Hash erzeugen (S2S)

        Args:
            uuid, addressid, email oder login als Keyword-Argument

        Returns:
            {"user_uuid": "...", "addressid": N, "hash": "..."}
        """
        return self._s2s_post_json("users.createOTL", identifier)

    def users_reset_login(self, **identifier) -> dict[str, Any]:
        """
        Login zurücksetzen (S2S)

        Args:
            email, uuid, addressid oder login als Keyword-Argument

        Returns:
            {"user_uuid": "...", "addressid": N, "name": "...", "mail": "...", "hash": "..."}
        """
        return self._s2s_post_json("users.resetLogin", identifier)

    def certificates_types(self) -> dict[str, Any]:
        """Zertifikatstypen abrufen (S2S)"""
        return self._s2s_get("certificates.types")

    def certificates_persons(self) -> dict[str, Any]:
        """Personenzertifikate abrufen (S2S)"""
        return self._s2s_get("certificates.persons")

    def clients_panel(self) -> dict[str, Any]:
        """Client-Panel-Daten abrufen (S2S)"""
        return self._s2s_get("clients.panel")

    def set_redirect_uris(self, uris: list[str]) -> dict[str, Any]:
        """
        Redirect-URIs für diesen Client setzen (S2S)

        Args:
            uris: Liste von Redirect-URIs (http:// oder https://)

        Returns:
            {"status": "ok", "redirect_uris": [...]}
        """
        return self._s2s_post_json("clients.redirecturis", {"redirect_uris": uris})

    def list_org_units(self) -> dict[str, Any]:
        """
        Zugriffsberechtigte OrganisationUnits abrufen (S2S)

        Gibt alle OrgUnits zurück, auf die dieser Client Zugriff hat.
        Sub-Units zeigen ihren Parent (parent_uuid / parent_shortname).
        Hat der Client keine Einschränkung, werden alle aktiven OrgUnits geliefert.

        Returns:
            {"orgunits": [{"uuid": ..., "name": ..., "shortname": ...,
                           "parent_uuid": ..., "parent_shortname": ...,
                           "type": ..., "type_uuid": ...}], "count": N}
        """
        return self._s2s_get("orgunits.list")

    def memberform_data(self, mandant: str) -> dict[str, Any]:
        """
        Mitgliedsformular-Lookups abrufen (S2S)

        Args:
            mandant: Mandantenkürzel, z.B. 'DRG', 'DEGIR', 'BDNR'

        Returns:
            {"data": {...}}
        """
        return self._s2s_get("memberform.data", {"mandant": mandant})

    # ─── Incremental Sync ───────────────────────────────────────

    def users_changed(
        self, since: str, limit: int = 500, include_user: bool = False
    ) -> dict[str, Any]:
        """
        Geänderte Benutzer seit einem Zeitpunkt abrufen (Incremental Sync)

        Args:
            since: ISO 8601 Zeitstempel
            limit: Max. Ergebnisse (1–1000, Default: 500)
            include_user: Volles User-Objekt pro Eintrag mitliefern

        Returns:
            {"changes": [...], "next_since": "...", "has_more": bool, "count": int}
        """
        params: dict[str, Any] = {"since": since}
        if limit != 500:
            params["limit"] = limit
        if include_user:
            params["include"] = "user"
        url = f"{self.api_url}/users/changed"
        auth = None
        if self.client_secret:
            auth = (self.client_id, self.client_secret)
        resp = self._session.get(url, params=params, auth=auth)
        if resp.status_code >= 400:
            try:
                data = resp.json()
            except Exception:
                data = {}
            raise RadissoOIDCError(
                data.get("error_description", data.get("error", f"HTTP {resp.status_code}")),
                data.get("error", "http_error"),
            )
        return resp.json()

    # ─── JWT Decode (ohne Signaturprüfung) ──────────────────────

    @staticmethod
    def decode_jwt_payload(jwt_token: str) -> dict[str, Any]:
        """
        JWT-Payload decodieren (OHNE Signaturprüfung!)
        Für Signaturprüfung: PyJWT o.ä. verwenden
        """
        import json

        parts = jwt_token.split(".")
        if len(parts) != 3:
            raise RadissoOIDCError("Invalid JWT format", "invalid_token")

        padding = 4 - len(parts[1]) % 4
        payload_b64 = parts[1] + "=" * padding
        try:
            payload = json.loads(base64.urlsafe_b64decode(payload_b64))
        except Exception as e:
            raise RadissoOIDCError(
                f"Invalid JWT payload: {e}", "invalid_token"
            ) from e
        return payload

    # ─── HTTP-Layer ─────────────────────────────────────────────

    def _s2s_get(
        self, method: str, params: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        """GET mit Basic Auth (für S2S-Methoden)"""
        url = f"{self.api_url}/s2s/{method}"
        auth = None
        if self.client_secret:
            auth = (self.client_id, self.client_secret)
        resp = self._session.get(url, params=params, auth=auth)
        if resp.status_code >= 400:
            result = resp.json() if resp.content else {}
            raise RadissoOIDCError(
                result.get("error_description", result.get("error", f"HTTP {resp.status_code}")),
                result.get("error", "http_error"),
            )
        return resp.json() if resp.content else {}

    def _s2s_post_json(
        self, method: str, data: dict[str, Any]
    ) -> dict[str, Any]:
        """POST mit JSON-Body und Basic Auth (für S2S-Methoden)"""
        url = f"{self.api_url}/s2s/{method}"
        auth = None
        if self.client_secret:
            auth = (self.client_id, self.client_secret)
        resp = self._session.post(url, json=data, auth=auth)
        if resp.status_code >= 400:
            result = resp.json() if resp.content else {}
            raise RadissoOIDCError(
                result.get("error_description", result.get("error", f"HTTP {resp.status_code}")),
                result.get("error", "http_error"),
            )
        return resp.json() if resp.content else {}

    def _get(
        self, url: str, bearer_token: str = ""
    ) -> dict[str, Any]:
        headers = {}
        if bearer_token:
            headers["Authorization"] = f"Bearer {bearer_token}"
        resp = self._session.get(url, headers=headers)
        if resp.status_code >= 400:
            data = resp.json() if resp.content else {}
            raise RadissoOIDCError(
                data.get("error_description", data.get("error", f"HTTP {resp.status_code}")),
                data.get("error", "http_error"),
            )
        return resp.json()

    def _post(
        self,
        url: str,
        params: dict[str, str],
        use_basic_auth: bool = False,
    ) -> dict[str, Any]:
        auth = None
        if use_basic_auth and self.client_secret:
            auth = (self.client_id, self.client_secret)
        resp = self._session.post(url, data=params, auth=auth)
        if resp.status_code >= 400:
            data = resp.json() if resp.content else {}
            raise RadissoOIDCError(
                data.get("error_description", data.get("error", f"HTTP {resp.status_code}")),
                data.get("error", "http_error"),
            )
        return resp.json() if resp.content else {}
