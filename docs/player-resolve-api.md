# Platform Player Resolve API — Issue #9, phase two

`POST /api/v1/platform/players/resolve` identifies or creates a platform-owned player for later integration. It authenticates the platform, not the individual player. It does not log players in, create Game Sessions, issue Sanctum tokens, or read/write wallets, balances or transactions.

## Request and response contract

Required headers:

```text
Authorization: Bearer <platform-issued-RS256-JWT>
Content-Type: application/json
Accept: application/json
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

Example JSON object:

```json
{"external_user_id":"Player001","name":"Player One"}
```

Only `external_user_id` and optional `name` are accepted. Query parameters are rejected. `platform_id`, status, balance, IDs, permissions, tokens, timestamps and all other body fields return 422. The platform ID comes solely from verified `PlatformContext`; no Admin Sanctum guard authenticates this route.

- `external_user_id`: required string, 1–100 characters, case-sensitive under the existing `utf8mb4_0900_as_cs` users column/index. Leading/trailing ASCII or Unicode separator whitespace is rejected, not trimmed. The application does not lowercase or Unicode-normalize identities.
- `name`: optional string or null, maximum 100 characters. Empty string and surrounding whitespace are preserved. Omitted name creates null. The existing Laravel TrimStrings/ConvertEmptyStringsToNull middleware is bypassed only for this exact POST path, leaving Admin query/write contracts unchanged.
- Idempotency-Key: required UUID header. UUID casing is normalized to lowercase; surrounding whitespace, comma-combined values and body/query substitutes are rejected.
- The body must be valid JSON with an object at the top level. Invalid JSON, other body types/content types and invalid fields return 422. JSON field order and JSON escaping/formatting do not change request identity.

Responses below assume rate admission succeeds; authenticated validation failures and replays also consume the per-platform budget. Default admission is 120 requests per 60 seconds; see [rate limiting and maintenance](platform-api-operations.md) for configuration, shared atomic storage and fail-closed behavior.

A new player returns 201; a new logical resolve of an existing active player returns 200. The success body is exactly:

```json
{"data":{"id":1234,"external_user_id":"Player001","name":"Player One","status":1}}
```

IDs within PHP_INT_MAX are JSON integers; larger IDs are decimal strings. Clients should account for JavaScript numeric precision limits. No platform/key details, login timestamp, wallet fields, password, token or internal idempotency state is returned. The Platform Resource allowlist is separate from the Admin PlayerResource.

Creation explicitly uses status 1 and null last_login_at. Existing players are never saved/renamed/reactivated or given a new last_login_at/updated_at by Resolve; an incoming name is ignored for existing player data but remains part of the idempotency request hash. Only status 1 is accepted as active; disabled or unsupported nonactive statuses return 403 unchanged. Different platforms own independent `(platform_id, external_user_id)` identities. `Player001` and `player001` are distinct within the same platform.

## Authentication and error precedence

Every request, including a replay, runs `platform.jwt` to verify the locally registered RSA signature, header/required claims, strict expiration, iat/nbf bounds and current public-key state. Same JWT reuse within its maximum 300-second lifetime remains allowed; JWT jti does not deduplicate requests. A fresh valid JWT/key for the same platform may retry an earlier operation: credentials are not part of the request hash.

The user-approved phase-two contract supersedes the earlier disabled-platform 401 behavior: a disabled platform returns 403 only after signature, claims, time and key validation succeed. An invalid/expired JWT or unusable key still returns 401, even when the selected platform is disabled. `PlatformContext` is populated only after success and now includes verified expiresAt for checks after database waits.

Order is authentication/platform authorization → per-platform rate admission → Form Request validation → locked transactional credential/platform reread → idempotency conflict/replay handling → locked player active check → commit. A different-content key conflict is 409 before looking up a requested player. A same-content replay must still pass the current player's status check before the saved response can be returned. JWT/key expiry is checked again after obtaining the platform lock and after player-lock waits, before saving or returning a result; failure rolls back tentative inserts. Platform/key locks prevent compatible state writers from changing those rows before commit. Verification does not claim to cancel requests after their final authorization/commit decision.

| HTTP | Response |
|---|---|
| 201 | Newly created player, or replay of its original 201/body |
| 200 | Existing active player, or replay of its original 200/body |
| 401 | `{"message":"Unauthenticated."}` for missing/invalid/expired JWT or unusable key |
| 403 | `{"message":"Platform is disabled."}` after valid credentials, or `{"message":"Player is disabled."}` |
| 409 | `{"message":"The Idempotency-Key was already used with different request content."}` |
| 409 | Safe invariant/race errors: incomplete idempotency record, unavailable stored result or disappearing player |
| 422 | Laravel `message` and `errors` validation structure; missing/invalid header uses `errors.idempotency_key` |
| 429 | `{"message":"Too many requests."}` with Retry-After; no player/idempotency writes |
| 503 | `{"message":"Platform rate limit service unavailable."}` when admission storage/configuration fails; no player/idempotency writes |
| 500 | Unexpected SQL/persistence failures propagate; they must not become business success or unrelated unique-key errors |

Authentication/validation/403/409/failed transactions are not cached as successful outcomes. If a player is disabled after successful resolution, retry returns 403 without overwriting the saved success snapshot; if re-enabled within retention, the original status/body can be replayed. Snapshot name can differ from a later externally changed name: replay preserves the earlier response and does not undo the external change.

## Persistent idempotency schema

New additive migration `2026_11_09_000003_create_platform_api_idempotencies_table` creates only `platform_api_idempotencies`. The executed users/JWT migrations remain unchanged.

| Column | Type/purpose |
|---|---|
| id | unsigned BIGINT primary key |
| platform_id | unsigned BIGINT, FK to platforms.id, DELETE RESTRICT |
| endpoint | case-sensitive VARCHAR(80), fixed `v1.platform.players.resolve` for this POST API |
| idempotency_key | case-sensitive CHAR(36), canonical lowercase UUID |
| request_hash | CHAR(64), SHA-256 of canonical validated request fields |
| state | VARCHAR(16), processing or completed |
| player_id | nullable unsigned BIGINT, FK to users.id, DELETE RESTRICT |
| response_status | nullable unsigned SMALLINT, only application-written 200/201 |
| response_body | nullable TEXT containing exact serialized JSON bytes |
| expires_at | UTC DATETIME |
| created_at / updated_at | timestamps |

Unique index `platform_api_idempotencies_scope_unique` covers `(platform_id, endpoint, idempotency_key)`; `(state, expires_at)` is indexed for expiry inspection. Request body and JWT are not stored wholesale. Saved response contains only the four player Resource fields. The API namespace is versioned; future endpoints/contract versions must choose their own endpoint identity rather than accidentally sharing this cache.

Hash canonicalization uses a fixed-order object: external_user_id first, and name only when present, serialized with JSON_THROW_ON_ERROR before SHA-256. Strings retain their exact validated Unicode content/case/whitespace. Omitted name, explicit null and empty string are distinct request contents and conflict when a live key is reused with a different one. JSON object field order, escaped versus literal Unicode and header UUID casing are not differences. Unknown fields and invalid types are rejected before hashing.

## Transaction and concurrency protocol

All business/idempotency operations use the User connection and one transaction, with at most three retries for recognized database transaction deadlocks. Errors other than the explicitly recognized player unique conflict propagate.

1. Lock the verified platform row exclusively, then the verified key row shared. Reread active status, key validity/revocation and verified JWT expiry. All Resolve requests for one platform intentionally serialize here, across different keys and player IDs. This is a throughput tradeoff, not an in-memory/distributed-lock shortcut.
2. Lock/read the scoped idempotency row. For a retained completed record, compare request hash; a mismatch is 409. For a match, lock the saved player within the same platform and external identity, recheck credential expiry and active status, then return the exact saved status/JSON without writes or TTL extension.
3. For a missing record, insert processing state in the same transaction. For an expired completed record still present, reuse that locked row in place; a safely pruned row follows the missing-record path. A committed processing record is an unexpected invariant violation: fail closed with 409 and investigate if it persists; do not guess ownership or automatically take over an unknown in-flight operation.
4. Find the platform/external identity. If absent, INSERT using the explicit creation allowlist. Recognize only MySQL 1062 for `users_platform_id_external_user_id_unique` from this business INSERT; reread the winner with a locking current read. Other SQL/index errors must propagate. The existing database unique index remains the final defense against other writers; they should adopt compatible platform-first locking.
5. Lock/reread the player, recheck current credential validity and player status, and construct the Resource snapshot. Reread IDs by identity rather than relying on a possibly truncated insertGetId value. Save player_id, original HTTP status, exact JSON, completed state and expiry; commit before emitting the HTTP response.

Lock order is platform → key → idempotency → player. Key-management/platform-status writers already use platform-first locks; Admin player-status changes lock the player and do not then take the platform lock. No admin role, Sanctum token, audit-history or wallet write is involved. This does not prove safety for arbitrary direct SQL writers or all future lock orders.

There is no separately committed processing reservation, so failure/crash before commit leaves neither a partially created player nor a durable processing row. A commit includes both the player and result. If the HTTP response is lost after commit, a retry with the same key/body reads that committed result, including an original 201. A new key for that same player is a new logical operation and returns 200. Ambiguous network outcomes must be retried with the original key; do not invent a new key merely because the response timed out. Expired JWTs require the platform to issue a fresh JWT, not a server refresh-token feature.

## Retention and expiry cleanup strategy

Retention is **48 hours from successful completion**, exceeding the required minimum 24 hours. Replays do not extend it. At `expires_at <= now`, the key may represent a new logical operation with different content; the old response guarantee has ended. A remaining expired completed row is replaced transactionally only if the new operation succeeds; failure restores that original row. If cleanup already deleted it, the next operation inserts a new scoped record. Neither path deletes or duplicates players.

The controlled `platform:prune-idempotencies` command now supports default dry-run preview and explicitly approved, bounded deletion of expired completed records. It takes platform-first SKIP LOCKED batches and rechecks expiry/state under lock, preserving active Resolve transactions and newly refreshed results. Scheduling is disabled by default and requires deployment opt-in. See [the operations runbook](platform-api-operations.md) for confirmation, backups, batch limits, scheduler setup and failure semantics. Retained FK references still matter for future player/platform deletion; no such API exists.

## Verification and remaining limits

See the latest final-hardening entry in [testing.md](testing.md) for results. Tests use only preflight-verified mysql:3306/laravel_testing with UTC and uncached testing configuration, existing-schema transaction isolation, DDL blocking and persistent-row/schema postflight. Only reviewed additive migrations were applied there, never a schema rebuild. Existing direct Migration up/down/rollback cases remain excluded. Development/production databases and GitHub Issue were not modified.

Real independent PHP/HTTP/MySQL workers test same-player creation with different keys, simultaneous exact replay, simultaneous body conflict, and intentional loss of the first committed response followed by retry in another process. Worker control signals are test-only, not production endpoints. Response-loss testing is an injected post-commit discard, not a full TCP/proxy fault test. Registration concurrency from phase one also remains covered. General state/permission races and arbitrary external writers are not fully verified; large-ID coverage serializes hydrated values without inserting large IDs; full stored Resolve/replay above PHP_INT_MAX and creation at a large AUTO_INCREMENT were not executed.

Application rate limits and controlled pruning are implemented. Production HTTPS/ingress policy, shared-storage topology verification, capacity/lock monitoring, approved scheduler provisioning and existing Symfony security-advisory remediation remain pre-production tasks. No broad dependency upgrade, Game Session, player logout, Wallet or settlement feature is included.
