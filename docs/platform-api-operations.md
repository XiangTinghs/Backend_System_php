# Platform Resolve rate limits and idempotency maintenance

This runbook supplements [Player Resolve](player-resolve-api.md) and [JWT authentication](platform-jwt-authentication.md). Deployment does not grant administrator permissions or enable deletion automatically. No development/production database operation, live permission grant or production scheduler activation was performed by this change.

## Application admission control

`POST /api/v1/platform/players/resolve` runs `platform.jwt` → `platform.resolve.limit` → validation → transactional Resolve. The limiter derives its identifier only from the verified immutable PlatformContext: `resolve:v1:platform:<platform ID>`. Body fields, external player IDs, IP addresses, JWT jti and the Idempotency-Key never choose the budget. Different platforms have independent budgets; one player's traffic can exhaust its own platform's shared budget, but cannot consume another platform's budget.

Default: **120 admitted requests per fixed 60-second window**, starting with the first authenticated request. Set `PLATFORM_RESOLVE_PER_MINUTE` in deployment configuration. `config/platform_api.php` also supports explicit platform overrides, for example `'resolve_platform_limits' => [123 => 60]`. Limits must be integers between 1 and 1,000,000; invalid configuration fails closed. Apply identical configuration and synchronized clocks to every replica, using the normal reviewed config-cache/deployment procedure. Changing limits mid-window changes that window's admission threshold; it does not reset the counter.

Authenticated validation failures, downstream failures and successful idempotent replays consume an admission. Invalid JWTs fail before admission. A blocked attempt does not increment the budget. Valid JWT reuse remains supported; reuse does not bypass the platform quota. A replay admitted within the quota still returns the original saved body/status; quota errors are not saved idempotency results.

| Result | Response |
|---|---|
| Admitted | Downstream status/body; `X-RateLimit-Limit` and `X-RateLimit-Remaining` |
| Budget exhausted | 429 `{"message":"Too many requests."}`, `Retry-After` seconds (at least 1), limit/remaining headers |
| Storage/configuration failure during admission | 503 `{"message":"Platform rate limit service unavailable."}`, `Retry-After: 1` |

429/503 never invoke validation/controller/Resolve and never insert players or idempotency records. There is no unlimited fallback, local store fallback or exception text/token disclosure. A database outage during earlier JWT verification may instead produce a server error before admission; it still cannot authorize a business write.

### Shared atomic storage

The dedicated named Laravel cache store `platform_api_limiter` uses the application's **shared MySQL writer** and the new `platform_api_rate_limits` table. Schema: `key` VARCHAR(255) primary key, `value` MEDIUMTEXT, `expiration` BIGINT with an index, matching Laravel DatabaseStore's representation. Additive migration `2026_11_09_000004_create_platform_api_rate_limits_table` creates only this table. Existing migrations and other columns are unchanged. Apply the reviewed additive migration before enabling the updated route; a missing table fails closed. Deployment migrations require their own environment review/authorization.

Laravel 13 RateLimiter provides the timer/counter mechanics. A separate RateLimiter instance avoids modifying the default/Admin limiter. Admission locks the platform row, checks and increments the counter on the same MySQL connection/transaction, then commits before business handling. This closes the generic check-then-hit race without a leased mutex. Transactions retry recognized deadlocks up to three times; an exhausted/error path fails closed. Reads inside this transaction use the writer rather than a replica. No rate counter is stored in the idempotency/player table.

The store rejects non-DatabaseStore drivers and connections other than the application's default MySQL connection. All replicas **must connect to the same primary/database**, with the same `PLATFORM_API_RATE_LIMIT_PREFIX` (default `platform-api:`); per-container databases, local/file/array stores, failover-to-local caches and inconsistent prefixes are unsupported. The dedicated table is separate from the default cache; ordinary default `cache:clear` does not reset platform quotas. Never clear the dedicated store as routine cache maintenance because doing so resets active budgets. Old counter keys are reused per platform; expired values are handled by Laravel on access, not an ever-growing key per request/window.

Two independent HTTP processes verified that a one-request budget admits one request and rejects the other with 429. That test verifies the shared-store design, not the actual production topology. Before public rollout, verify every replica's effective database/prefix/configuration and exercise one shared budget through at least two replicas. Actual production infrastructure was not accessed. Sustained load, fairness and platform-lock contention still require capacity assessment.

### Edge protection and Admin isolation

Nginx/Ingress must provide TLS, body/connection limits and appropriate pre-authentication IP/burst/abuse protection. This protects RSA verification and database access before a trusted platform identity exists. Application limits provide authenticated per-platform fairness and correctness; they do not replace edge DDoS protection. Scope any new edge rules to the Platform path and review proxy/client-IP trust separately; no Admin middleware, default limiter or Nginx configuration was changed here. Edge deployment policy remains a pre-production configuration task.

## Expired idempotency cleanup

The API still retains successful results for **48 hours from completion**, without extending expiry on retry. Before expiry, cleanup cannot remove them. At expiry, the key may begin a new logical request whether its completed row is lazily reused or has been pruned. A lost response must therefore be retried within retention with the original key/body and valid current credentials. Players are never deleted by maintenance.

The controlled Artisan command defaults to read-only dry run:

```bash
docker compose exec php php artisan platform:prune-idempotencies
# Explicitly reviewed manual deletion; requires the confirmation prompt below.
docker compose exec php php artisan platform:prune-idempotencies --apply --batch-size=100 --max-batches=10
```

Preview shows environment, connection, host/database, a fixed cutoff from MySQL `UTC_TIMESTAMP()`, eligible count, maximum deletion bound, and up to ten record/platform IDs and expiry times. It does not print request hashes, response bodies, JWTs or Authorization headers. Eligibility is strictly `state = completed AND expires_at <= cutoff`. Processing and unexpired records are preserved. No caller-supplied future cutoff exists.

Before manual apply, approve the actual target environment/database, previewed expired response records, batch bound and recovery approach. Deletion removes saved response snapshots; recovery requires an approved backup, while the expired API replay guarantee has already ended. Type exactly `DELETE EXPIRED <actual database name>` at the interactive prompt. Noninteractive manual apply is rejected; there is no force option. CLI access is an operations trust boundary, not an Admin API authorization path.

Defaults: 100 rows per short transaction and at most 10 batches per invocation. Both options accept 1–1000. Each batch selects one eligible platform in ID order using `FOR UPDATE SKIP LOCKED`, then locks at most the batch size of its expired completed records in ID order with SKIP LOCKED. Delete rechecks exact IDs, platform, state and expiry under lock. This follows Resolve's platform-first order, skips active Resolve transactions and avoids taking locks on players/keys. A refreshed row is not deleted; a locked/busy platform is deferred to a later invocation. A small deletion batch can briefly serialize a subsequently arriving Resolve for that platform; no table-wide delete/lock is issued. Eligibility scans and preview counts can still consume resources: monitor query duration and tune scheduling/batch bounds for actual scale.

Each batch commits independently, with at most three deadlock retries. On failure that batch rolls back, the command fails, and earlier completed batches remain deleted. Investigate before retrying; repeating the approved operation uses a new database-time cutoff. A zero-result batch stops the invocation, so skipped work may remain until the next run. Duplicate cleanup workers are safe through database row locks; they must not be treated as a reason to increase the approved aggregate I/O budget. Audit history, permissions, tokens, keys, platforms and players are untouched. Cleanup activity is visible in command output/process exit status; ship scheduler output to the approved operations log (no row payloads).

### Scheduler opt-in

Default `PLATFORM_IDEMPOTENCY_PRUNE_ENABLED=false`: no scheduled prune event is registered. After approving this recurring bounded deletion policy for the concrete deployment/database and backup process, set it to `true` consistently and refresh application configuration through the normal deployment process. This enables the hourly event in `routes/console.php`:

```text
platform:prune-idempotencies --apply --scheduled --no-interaction --batch-size=100 --max-batches=10
```

`--scheduled` requires both explicit deployment opt-in and `--apply`; it cannot silently enable itself. Provision one designated scheduler owner. For example, from the correct deployed repository directory, an approved cron job can run `docker compose exec -T php php artisan schedule:run` once per minute; container/orchestrator scheduling may instead invoke the same Artisan scheduler in the PHP service. Capture failures/output and alert on persistent backlog or 503s. No schedule worker/cron entry is installed by this change. Database SKIP LOCKED provides safety if scheduler ownership briefly overlaps; there is no dependency on a per-container cache scheduler mutex. Enabling the flag authorizes only this documented expired-completed-response policy, never general data resets.

## Rollout and remaining prerequisites

Only the new additive table migration was applied to verified `laravel_testing`; no development/production cleanup or scheduler was run. See [testing](testing.md) for guarded execution and concurrent tests. Bootstrap privileged membership still needs the complete inherited-permission/member preview and explicit approval in the [permission runbook](platform-key-permission-operations.md). Existing Symfony security advisories remain a separate formal pre-production remediation task; no broad Composer upgrade is included. Production shared-storage checks, edge policy, capacity/lock monitoring, backups and approved scheduler provisioning remain deployment work.
