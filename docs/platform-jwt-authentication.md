# Platform JWT Authentication — Contract v1.1 (Issue #9)

External platforms retain their RSA private keys and issue reusable, short-lived JWTs. The provider stores only administrator-registered public keys. Phase one provides key management and the `platform.jwt` middleware alias. Phase two adds the protected [Player Resolve API](player-resolve-api.md), including database idempotency. Sessions, wallets, settlement and Redis JWT replay prevention remain out of scope. The broader Issue #9 acceptance criteria are not all completed.

## Trust and threats

Bearer tokens require HTTPS and protection by the platform. A stolen token remains reusable until expiry or platform/key disablement/revocation; duplicate `jti` values do not cause rejection. Maximum lifetime is 300 seconds, not a five-minute sliding session. A compromised private key requires permanent key revocation and rotation. Tokens contain platform identity only: no player identity is authenticated.

The middleware never authenticates a Laravel Admin or Sanctum user. Existing Admin routes retain `auth:sanctum` → `admin.token` → `admin.active` → `admin.permission`. Registering a public key is sensitive trust-establishment authority: no existing role receives the new permissions automatically, and the RBAC delegation allowlist is unchanged. External Platform APIs cannot register keys.

Unverified `iss` and `kid` select only a locally registered key. No header URL, embedded JWK, certificate URL or remote key discovery is used. Algorithm confusion is blocked by both an exact RS256 header check and a library `Key` pinned to RS256. RSA verification uses `firebase/php-jwt` 7.2.1 ([upstream documentation](https://github.com/googleapis/php-jwt/tree/v7.2.1)); application code does not implement RSA signing/verification. Header extensions, including `crit`, `jku`, `jwk` and `x5u`, are rejected.

Authentication failures return only HTTP 401 with `{"message":"Unauthenticated."}`. Under the approved phase-two update, a disabled platform returns 403 `{"message":"Platform is disabled."}` only after signature/claims/key validation succeeds; invalid credentials still return 401 even for a disabled platform. No JWT, raw claims, key material or library exception is logged/returned by this middleware. Database failures propagate as server failures rather than being mislabeled as bad credentials. Audit records describe successful administrator key changes only; request/authentication-event logging is not added. Resolve now uses authenticated per-platform admission control after JWT verification; see [rate limiting and maintenance](platform-api-operations.md). Verify production HTTPS/ingress abuse controls before public exposure. The middleware bounds compact JWT input to 16 KiB.

## Schema and lifecycle

The original additive migration creates two tables. A second migration, `2026_11_09_000002_add_global_platform_jwt_key_fingerprint_unique`, adds a global unique fingerprint index. The already-executed first migration is unchanged. The new migration verifies every existing fingerprint against normalized RSA SPKI DER and refuses duplicates or mismatches without rewriting/deleting rows; separately reviewed remediation is required before retrying. The database unique index is the final arbiter if a competing writer registers during migration or API validation. Deploy under a maintenance window and keep key registration unavailable until this migration succeeds; do not run the updated registration endpoint against a schema lacking the index. Never remove existing rows or reset the database merely to make migration succeed.

| Table | Columns and constraints |
|---|---|
| `platform_jwt_issuers` | `platform_id` unsigned BIGINT primary key, FK to `platforms.id` with DELETE RESTRICT; `issuer` VARCHAR(100), case-sensitive `utf8mb4_0900_as_cs`, globally unique; timestamps |
| `platform_jwt_keys` | unsigned BIGINT `id` primary key; `platform_id` unsigned BIGINT FK to issuer binding with DELETE RESTRICT; case-sensitive VARCHAR(100) `kid`; TEXT canonical PEM `public_key`; CHAR(64) SHA-256 `fingerprint`; boolean `is_active` default true; DATETIME `valid_from`, `valid_until`, nullable `revoked_at`; timestamps; unique `(platform_id, kid)` and global unique `fingerprint` (`platform_jwt_keys_fingerprint_unique`) |

The first key registration atomically binds one issuer to one platform. Issuer reassignment/rename and key material editing are not provided. Binding is retained after all keys are revoked; an issuer cannot later be claimed by another platform through these APIs. `issuer` and `kid` accept 1–100 ASCII characters: first letter/digit, followed by letters/digits/dot/underscore/hyphen. They preserve case. Standard Laravel trimming remains enabled.

Register a new RSA key pair and new `kid` before retiring the previous key to allow overlap. Each public key may be registered exactly once globally, including within the same platform; changing PEM line endings or wrapping does not create a new key. Disabled/revoked rows retain the unique fingerprint. A disabled, unrevoked existing key may be re-enabled through status management; revoked keys cannot be restored or re-registered through this API. Recovery after revocation requires a genuinely new RSA key pair and registration; exceptional restoration of revoked material is unsupported and needs a separately approved security design. Keys may be scheduled with `valid_from`; validity is `[valid_from, valid_until)` in UTC with no key-time leeway. The RSA PEM must be a SubjectPublicKeyInfo `BEGIN PUBLIC KEY`, 2048–8192 bits; private keys, certificates, EC and weak RSA keys are rejected. The stored public key is normalized; fingerprint is SHA-256 of the public SPKI DER bytes. APIs return metadata/fingerprint, not PEM. Private keys never belong in requests, logs, Git or this database.

Disablement is reversible while the key remains unrevoked. Revocation sets `revoked_at` and disables the key permanently through these APIs. Repeated revocation succeeds without an additional audit event; changing status after revocation returns 409. No key/issuer DELETE or reset endpoint exists. Model/builder/direct SQL access is trusted internal access, not database-enforced immutability. Platform-row locks serialize these API writers; this is not general concurrency verification or synchronization with arbitrary external writers. Targeted two-process HTTP tests now verify same-platform serialization and cross-platform global-index contention, not every configuration/state race. Revocation blocks subsequent requests; it cannot cancel work already authenticated in flight.

## Administrator API

All paths below are prefixed with `/api/v1/admin/platforms/{id}/keys`. Authentication and permission checks precede platform/key lookup, which precedes body validation. IDs use canonical positive unsigned BIGINT decimal strings. A key ID belonging to another platform is a safe 404.

| Method | Suffix | Permission | Success |
|---|---|---|---|
| GET | (none) | `platforms.keys.view` | 200, `data`, `links`, `meta` |
| POST | (none) | `platforms.keys.register` | 201, `data`, resource `Location` |
| PATCH | `/{keyId}/status` | `platforms.keys.manage` | 200, `data` |
| POST | `/{keyId}/revoke` | `platforms.keys.revoke` | 200, `data` |
| GET | `/{keyId}` | `platforms.keys.view` | 200, `data` |

List accepts only `page` (1–10000, default 1) and `per_page` (1–100, default 20), sorted by ID ascending. No request body is accepted for reads. Write queries and unknown fields are rejected. Registration requires `issuer`, `kid`, `public_key`, `valid_from`, `valid_until`; optional Laravel boolean `is_active` defaults true. Dates must be real, fixed-width UTC `YYYY-MM-DDTHH:MM:SSZ` in years 1000–9999, with `valid_until > valid_from`. PATCH accepts only required Laravel boolean `is_active`. Revoke accepts no fields or query parameters (absent/empty JSON body allowed).

Single-resource fields are `id`, `platform_id`, `issuer`, `kid`, `fingerprint`, boolean `is_active`, UTC `valid_from`, `valid_until`, nullable `revoked_at`, `created_at`, `updated_at`. IDs above PHP_INT_MAX serialize as decimal strings. All errors preserve the existing `message` contract, with `errors` for 422. Missing/invalid platform returns 404 `Platform not found.`; missing/invalid/nonnested key returns 404 `Platform key not found.`. Disabled administrators or missing permission return 403. Duplicate issuer/kid, immutable issuer conflicts, and globally duplicated public keys return 422. A fingerprint conflict always returns `{"message":"The public key has already been registered.","errors":{"public_key":["The public key has already been registered."]}}`, with no existing platform/key/issuer ID or status. The catch applies only to the business INSERT and exact MySQL 1062 fingerprint index; audit/unrelated failures propagate. Registration is not idempotent: retries with an existing kid return 422; inspect the registered resource before retrying; a new kid also requires a genuinely new key pair. Unchanged status and repeated revocation are idempotent and do not change timestamps or audit history.

Each actual change synchronously records `platform.key_registered`, `platform.key_enabled`, `platform.key_disabled` or `platform.key_revoked`, target type `platform_key`, key ID, operator and Request IP on the business connection inside the same transaction. Audit failure rolls back the key and any first issuer binding. Registration snapshots contain issuer/kid/fingerprint/status/validity; status snapshots contain only `is_active`; revocation additionally records `revoked_at` null → UTC timestamp. Service and Resource allowlists accept `issuer`, `kid`, `fingerprint`, `valid_from`, `valid_until`, `revoked_at`, and command `permission`/`ticket` only as string/null; existing boolean/status rules remain intact; PEM, JWT and private key fields remain excluded. Existing allowlist limitations for secrets embedded in allowed business strings still apply. Only exact MySQL 1062 conflicts for business issuer/kid/fingerprint INSERT indexes become 422; audit and unrelated SQL failures propagate.

## JWT validation and consumer contract

Required header: `{"alg":"RS256","typ":"JWT","kid":"platform-a-key-001"}`. Only these three header fields are accepted. Required claims: exact registered case-sensitive `iss`, exact `sub = "platform:" + iss`, exact string `aud = "game-provider-api"`, and nonnegative JSON integer `iat`, `nbf`, `exp`. Audience arrays and numeric strings/floats/booleans for dates are rejected. Optional `jti`, when non-null, must be a nonempty string of at most 128 bytes. It is not stored or used for replay rejection. Additional claims do not establish additional identity or privileges.

For every request:

1. Require a well-formed Bearer compact JWT; parse only for trusted local key selection.
2. Query the issuer/kid binding, platform and key afresh. Require an existing platform, active/unrevoked key and current key validity; do not reveal disabled-platform authorization status before verifying credentials.
3. Verify the RS256 signature with the registered RSA public key using the library.
4. Validate all required claims and their types; require `exp > now`, `exp > iat`, `exp - iat <= 300`, `nbf < exp`, `iat <= now + 30`, `nbf <= now + 30`. Expiry at exactly now is 401. The library's temporary time/leeway settings are restored in `finally`; explicit application checks remove expiration leeway and check iat independently even when nbf exists.
5. After signature and required claims succeed, require active platform; otherwise return the approved 403.
6. Set an immutable `App\Auth\PlatformContext` in `$request->attributes->get(PlatformContext::class)` only after success. It contains string `platformId`, `issuer`, `keyId`, `kid` and integer verified `expiresAt`. Resolve rechecks expiry/key state after database waits. Clear any prior attribute before validation. Consumers must derive platform ownership solely from this context and scope all queries accordingly, ignoring request-supplied platform IDs.

Apply `platform.jwt` to external Platform routes, including POST `/api/v1/platform/players/resolve`, only. Do not add it as an alternative to Admin middleware, use it for player identity, or expose a test authentication endpoint in production. Feature tests additionally register a temporary route to isolate middleware checks; the actual Resolve route is protected in routes/api.php.

## Verification

See the Issue #9 entry in [testing.md](testing.md) for exact results and safety limits. No development/production database or existing migration was changed. Existing-schema PHPUnit execution preserves the repository's phpunit.xml and TestCase guards and transactional rollback; the direct Migration-invoking UserModelTest remains excluded. Targeted real dual-connection registration testing is covered; general revocation/permission concurrency and production ingress policy are not verified guarantees. Symfony 7.4.22 security advisories remain a formal pre-production remediation item; this hardening does not upgrade dependencies. See [controlled RBAC operations](platform-key-permission-operations.md) for explicit initialization, grant and revoke procedures.

## Change manifest

Current uncommitted branch, including all original phase-one work, security hardening, phase-two Resolve and final operations hardening.

Modified (13 files):

- `AGENTS.md`
- `README.md`
- `docs/testing.md`
- `src/AGENTS.md`
- `src/app/Http/Resources/Api/V1/Admin/AuditLogResource.php`
- `src/app/Models/Platform.php`
- `src/app/Services/AuditLogService.php`
- `src/bootstrap/app.php`
- `src/composer.json`
- `src/composer.lock`
- `src/config/cache.php`
- `src/routes/api.php`
- `src/routes/console.php`

Added (46 files):

- `docs/platform-api-operations.md`
- `docs/platform-jwt-authentication.md`
- `docs/platform-key-permission-operations.md`
- `docs/player-resolve-api.md`
- `src/app/Auth/PlatformContext.php`
- `src/app/Console/Commands/ManagePlatformKeyPermission.php`
- `src/app/Console/Commands/PrunePlatformIdempotencies.php`
- `src/app/Exceptions/PlatformDisabledException.php`
- `src/app/Http/Controllers/Api/V1/Admin/PlatformKeyController.php`
- `src/app/Http/Controllers/Api/V1/Platform/PlayerResolveController.php`
- `src/app/Http/Middleware/LimitPlatformResolveRequests.php`
- `src/app/Http/Middleware/VerifyPlatformJwt.php`
- `src/app/Http/Requests/Api/V1/Admin/IndexPlatformKeyRequest.php`
- `src/app/Http/Requests/Api/V1/Admin/PlatformKeyRequest.php`
- `src/app/Http/Requests/Api/V1/Admin/StorePlatformKeyRequest.php`
- `src/app/Http/Requests/Api/V1/Admin/UpdatePlatformKeyStatusRequest.php`
- `src/app/Http/Requests/Api/V1/Platform/ResolvePlayerRequest.php`
- `src/app/Http/Resources/Api/V1/Admin/PlatformKeyResource.php`
- `src/app/Http/Resources/Api/V1/Platform/PlayerResource.php`
- `src/app/Models/PlatformApiIdempotency.php`
- `src/app/Models/PlatformJwtIssuer.php`
- `src/app/Models/PlatformJwtKey.php`
- `src/app/Services/IdempotencyPruner.php`
- `src/app/Services/PlatformJwtVerifier.php`
- `src/app/Services/PlatformKeyPermissionService.php`
- `src/app/Services/PlatformResolveRateLimiter.php`
- `src/app/Services/PlayerResolveService.php`
- `src/app/Support/RsaPublicKey.php`
- `src/config/platform_api.php`
- `src/database/migrations/2026_11_09_000001_create_platform_jwt_keys_tables.php`
- `src/database/migrations/2026_11_09_000002_add_global_platform_jwt_key_fingerprint_unique.php`
- `src/database/migrations/2026_11_09_000003_create_platform_api_idempotencies_table.php`
- `src/database/migrations/2026_11_09_000004_create_platform_api_rate_limits_table.php`
- `src/tests/Feature/Api/V1/Admin/PlatformKeyConcurrencyTest.php`
- `src/tests/Feature/Api/V1/Admin/PlatformKeyManagementTest.php`
- `src/tests/Feature/Api/V1/Platform/IdempotencyPruneConcurrencyTest.php`
- `src/tests/Feature/Api/V1/Platform/PlatformResolveRateLimitTest.php`
- `src/tests/Feature/Api/V1/Platform/PlayerResolveConcurrencyTest.php`
- `src/tests/Feature/Api/V1/Platform/PlayerResolveTest.php`
- `src/tests/Feature/Auth/PlatformJwtTest.php`
- `src/tests/Feature/Console/PlatformKeyPermissionCommandTest.php`
- `src/tests/Feature/Console/PrunePlatformIdempotenciesTest.php`
- `src/tests/Support/PlatformJwtFixture.php`
- `src/tests/Support/PlatformKeyRegistrationWorker.php`
- `src/tests/Support/PlayerResolveWorker.php`
- `src/tests/Support/TestingDatabase.php`
