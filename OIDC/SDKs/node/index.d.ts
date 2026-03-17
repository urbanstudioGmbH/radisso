export class RadissoOIDCError extends Error {
  errorCode: string;
  constructor(message: string, errorCode?: string);
}

export interface PKCEPair {
  verifier: string;
  challenge: string;
}

export interface TokenSet {
  access_token: string;
  refresh_token?: string;
  id_token?: string;
  token_type: string;
  expires_in: number;
}

export interface DeviceCodeResponse {
  device_code: string;
  user_code: string;
  verification_uri: string;
  verification_uri_complete?: string;
  expires_in: number;
  interval: number;
}

export interface UserInfo {
  sub: string;
  name?: string;
  given_name?: string;
  family_name?: string;
  nickname?: string;
  email?: string;
  email_verified?: boolean;
  phone_number?: string;
  address?: {
    street_address?: string;
    postal_code?: string;
    locality?: string;
    country?: string;
  };
  roles?: string[];
  participatingevents?: any[];
  participatingconrad?: any[];
  memberships?: any[];
}

export interface IntrospectionResult {
  active: boolean;
  sub?: string;
  scope?: string;
  exp?: number;
  client_id?: string;
}

export interface RadissoOIDCConfig {
  clientId: string;
  clientSecret?: string;
  redirectUri?: string;
  issuer?: string;
}

export class RadissoOIDC {
  constructor(config: RadissoOIDCConfig);

  discover(): Promise<Record<string, any>>;

  static generatePKCE(): PKCEPair;
  static generateState(): string;
  static generateNonce(): string;

  getAuthorizationUrl(options: {
    scopes: string[];
    state: string;
    nonce?: string;
    codeChallenge?: string;
  }): string;

  exchangeCode(code: string, codeVerifier?: string): Promise<TokenSet>;
  clientCredentials(scopes?: string[]): Promise<TokenSet>;
  refreshToken(refreshToken: string): Promise<TokenSet>;

  requestDeviceCode(scopes?: string[]): Promise<DeviceCodeResponse>;
  pollDeviceToken(deviceCode: string): Promise<TokenSet | null>;
  waitForDeviceToken(deviceCode: string, interval?: number, maxWait?: number): Promise<TokenSet>;

  getUserInfo(accessToken: string): Promise<UserInfo>;
  introspect(token: string): Promise<IntrospectionResult>;
  revokeToken(token: string, tokenTypeHint?: string): Promise<void>;
  getJWKS(): Promise<Record<string, any>>;

  // S2S-Methoden
  usersSearch(criteria: Record<string, any>, orgunits?: string[]): Promise<{ users: Record<string, any>[]; count: number }>;
  usersList(limit?: number, offset?: number, orgunits?: string[]): Promise<{ users: Record<string, any>[]; total: number; offset: number; limit: number; has_more: boolean }>;
  usersGet(identifier: { uuid?: string; addressid?: number; email?: string }, orgunits?: string[]): Promise<{ user: Record<string, any> }>;
  usersCheck(email: string, birthdate?: string): Promise<{ exists: boolean; match_level: number; user: Record<string, any> | null }>;
  usersCreate(email: string, fields?: Record<string, any>): Promise<{ created: boolean; status: number; user: Record<string, any> }>;
  usersCreateOTL(identifier: { uuid?: string; addressid?: number; email?: string; login?: string }): Promise<{ user_uuid: string; addressid: number; hash: string }>;
  usersResetLogin(identifier: { uuid?: string; addressid?: number; email?: string; login?: string }): Promise<{ user_uuid: string; addressid: number; name: string; mail: string; hash: string }>;
  certificatesTypes(): Promise<{ types: Record<string, any>[] }>;
  certificatesPersons(): Promise<{ persons: Record<string, any>[] }>;
  clientsPanel(): Promise<{ data: Record<string, any> }>;
  setRedirectUris(uris: string[]): Promise<{ status: string; redirect_uris: string[] }>;
  listOrgUnits(): Promise<{ orgunits: Record<string, any>[]; count: number }>;
  memberformData(mandant: string): Promise<{ data: Record<string, any> }>;
  usersChanged(since: string, options?: { limit?: number; includeUser?: boolean }): Promise<{ changes: Array<{ user_uuid: string; addressid: number | null; change_type: 'created' | 'updated' | 'deleted'; changed_at: string; changed_fields: string[] | null; user?: Record<string, any> }>; next_since: string; has_more: boolean; count: number }>;

  static decodeJwtPayload(jwt: string): Record<string, any>;
}
