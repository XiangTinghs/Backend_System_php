# Wallet Testing Strategy — Issue #15

**Design draft — not implemented.**

**Every Wallet/Session case below is an unexecuted test plan. No tests, database checks, fixtures, migrations, or mock servers were run or provisioned for this documentation.** Approval to write this plan is not test execution or environment setup authorization.

Use the [Session design](game-session-design.md), [Wallet invariants](transfer-wallet-design.md#invariants), [schema proposal](wallet-database-schema.md), and [API draft](wallet-api-contract.md) as the requirements. Open choices must be approved before assertions encode them as fixed behavior.

## Existing Implementation

The repository has PHPUnit/Laravel tests for JWT, Resolve, Admin/RBAC/Audit, and related concurrency/maintenance. It has no Wallet/Session implementation or corresponding passing tests. [testing.md](testing.md) records historical verification; those results do not verify this proposal.

Existing `src/phpunit.xml` forces MySQL host mysql, port 3306, database laravel_testing, and empty DB_URL, and selects the testing environment. `src/tests/TestCase.php` requires testing/MySQL/laravel_testing. `src/tests/Support/TestingDatabase.php` checks effective uncached configuration, actual DB/port/UTC session, and required existing indexes. Resolve's independent-process tests provide a pattern for real MySQL concurrency with precisely scoped fixtures, not proof of Wallet concurrency safety.

## Approved Business Rules

Future tests must enforce effective Session uniqueness, duplicate-login reuse, initial funding before activation, active top-ups, closing admission/drain, full final outgoing transfer, zero-value suppression, unknown outcome protection, booked/held/available conservation, append-only atomic Ledger, tenant isolation, and controlled disabled-account responsibilities. Never substitute SQLite or development data. Do not describe planned checks as passed guarantees.

## Proposed Technical Design

### Database Isolation and Execution Preconditions

Before any separately authorized future database-writing execution:

1. Use the existing Docker PHP 8.3 service for PHP/Composer/Artisan/PHPUnit. Do not install host tools or dependencies as an automatic prerequisite.
2. Verify pre-bootstrap effective configuration, including cached configuration risks, before Laravel test lifecycle writes. Preserve all existing phpunit.xml/TestCase safeguards and inheritance; do not rely only on a post-bootstrap assertion.
3. Require testing environment, MySQL, writer host `mysql`, port `3306`, database `laravel_testing`, empty forced DB_URL, no socket/alternate URL, and effective `+00:00` connection/session timezone. Verify actual DATABASE()/port/session and required schema/index/migration state.
4. Verify the dedicated database and permissions exist. Compose initializes laravel only; missing prerequisites require setup authorization, not fallback to laravel or SQLite.
5. For existing-schema execution, preserve the reviewed preflight, DDL blocking, transaction rollback, competing-process checks, controlled fixture cleanup, and schema/migration/persistent-row fingerprints. Set RefreshDatabaseState only after a verified existing-schema preflight; do not weaken guards to suppress a failure.
6. A no-migration run also prohibits implicit RefreshDatabase rebuilds. Keep the direct Migration-invoking `UserModelTest::test_conversion_and_rollback_refuse_existing_rows_without_modifying_them` excluded unless separately authorized.
7. Real concurrency fixtures must be committed only in verified testing so independent connections can see them. Track exact synthetic IDs, stop workers in finally, clean only owned fixtures under an authorized test-maintenance identity, and check postflight. AUTO_INCREMENT counters may be excluded from row fingerprints as in existing practice; never hide persistent row drift.

An actual effective-configuration inspection is not performed by writing this plan. Runtime append-only permissions may intentionally prevent fixture DELETE; use separate approved testing maintenance credentials, not broader production runtime access.

### Requirement-to-test Matrix

| Requirement / scenario | Planned levels | Main assertion |
|---|---|---|
| Session unique effective slot | Integration, concurrency, migration | Direct inserts/transitions cannot create two effective rows |
| Duplicate login in pending/active/closing | Feature, concurrency | Same Session, no extra debit/transaction |
| Initial funding | Feature, failure injection | No activation before verified remote success and local atomic posting |
| Active top-up | Feature, concurrency | Separate dedup identities and capacity reservations |
| Full outgoing transfer | Feature, integration | Only final sealed balance, matching hold, confirmed remote credit |
| Zero-balance close | Feature | No zero transaction/Ledger/platform call |
| Ordinary new debit / hold admission | Unit, integration | Insufficient Available Balance rejects new spending/reservation; capture does not repeat this admission check |
| Capture with zero Available Balance | Integration | Matching held reservation captures with B >= A and B - A >= H_other, preserving other effective holds |
| Hold ownership and repeated capture | Integration, concurrency | No foreign-hold consumption, second debit, or second Ledger posting |
| Capture rollback | Integration, failure injection | Debit, sequence, Ledger, capture, transaction success, and events roll back together |
| Same command duplicate | Feature, concurrency | Saved response replay, one intent and posting |
| Same key, changed payload/target | Feature | 409, no second movement |
| Same business ID, changed key | Feature | No duplicate; final status per approved API-02 |
| Replay after state progression | Feature | Original accepted command not rejected by new-state guards |
| Timeout or eventual not_found | Integration, failure injection | Unknown preserved, no unsupported resend/release |
| Remote success, local commit failure | Failure injection | Recover one local posting, no repeat external effect |
| Worker crash/stale lease | Concurrency, failure injection | Query original ID; stale worker cannot overwrite |
| Duplicate Ledger posting | Integration, concurrency | Unique transaction posting; verify existing content |
| Closing versus bet | Concurrency | No accepted unregistered/post-closing liability |
| Closing versus payout | Concurrency | Legitimate obligations drain before trusted seal |
| Closing versus accepted incoming | Concurrency | Final outgoing amount includes completed prior credits |
| Cross-platform access | Feature, integration | Safe 404 and composite FK rejection |
| Disable during accepted operation | Feature, failure injection | No new business; existing responsibility not lost |
| Disabled-player operation matrix | Feature | Public GET/Close/replay outcomes remain conditional on API-03; existing disabled-platform rejection is preserved |
| Close transfer discovery | Feature | After API-04 approval, null before creation, scoped Public Transaction ID afterward, and isolated transaction lookup |
| Failed/replacement discovery | Feature | Selection assertions await API-04 approval; unknown reference is never hidden or replaced to authorize new payment |
| Money overflow | Unit, concurrency | B + reserved credits <= M; no remote debit without capacity |
| Append-only runtime | Integration | Effective runtime grants reject UPDATE/DELETE |

### Unit Tests

- Money parser rejects floats/numbers, signs, exponent, whitespace, grouping, negative/zero transfer, invalid precision, and values above M. Test exact additions/subtractions/comparisons at one-cent and maximum boundaries using strings, including wider intermediates.
- Available/held and incoming-capacity calculators include every unresolved state; unknown and remote_succeeded do not prematurely release reserved capacity. Future payout reservation is blocked until CAP-01 is defined.
- Session and transfer transition guards cover every legal/illegal pair and terminal non-reactivation. SES-01/SES-02 assertions remain pending decisions.
- Canonical hashes normalize object field ordering, JSON escaping, UUID case, and include endpoint/target/amount/currency. Changed content must differ; unknown fields are rejected rather than omitted silently.
- Gateway observations do not equate HTTP 200 with success or 5xx/not_found with failure. Unsupported capabilities fail closed. Public resolution summaries and safe error redaction are deterministic.

### Feature Tests

- Authenticate through real platform JWT verification, including missing/invalid/expired/revoked credentials, valid disabled platform, lock-wait expiry, and Admin/Platform isolation. No production auth bypass routes.
- Create pending, duplicate login in all effective states, new command after terminal state, and old command replay after termination. Verify existing pending/active/closing is not reset and duplicate login creates no implicit Transfer In, external debit, or additional money intent. Any explicit subsequent Transfer In has independent command identity and deduplication.
- Initial success, failure options after approval, unresolved initial contention, active top-up, closing rejection, disabled player, unknown stored status, and currency-not-configured/mismatch.
- Exact POST status/JSON/Location; string IDs; fixed decimal strings; malformed/nonobject JSON; missing/invalid UUID; unknown fields/query parameters; body-supplied platform/status/balance injection; normalization behavior scoped away from Resolve.
- Repeat initial funding after it made Session active, and after closing/closed: original accepted command replays rather than failing new-command guards. Changed payload still conflicts. Current credentials and tenant access remain required.
- Close pending/active, repeated Close while closing, already closed, zero balance, outstanding funding, draining obligations, missing/invalid seal evidence, and no partial-out/Adjustment API.
- GET latest status versus original command snapshot, safe domain errors, no lease/evidence/credential leakage, and current-wallet versus historical-Session semantics.
- Exercise the operation-specific authorization matrix: disabled players cannot start new Session commands or Transfer In; existing disabled-platform middleware rejects ordinary public access. Keep disabled-player public GET, Close, and replay assertions conditional on API-03 approval. Separately authorized internal settlement/recovery preserves existing responsibilities without admitting arbitrary new funding.
- After API-04 approval, verify `close_transfer_transaction_id` is null before an outgoing transaction exists and for zero-balance close without an outgoing transaction, then exposes the matching `wallet_transactions.public_id` after creation. Follow that ID through the proposed Get Wallet Transaction API. Verify platform/player/Session/currency/kind/purpose scoping, safe cross-platform rejection, and unchanged saved Create/Close responses.
- After API-04 selection-policy approval, verify failed-history/replacement selection and deterministic reference lookup. An unknown outgoing transaction retains its reference and cannot be replaced to evade payment safety. These are future assertions, not approval of the proposed selection policy.

### Integration Tests

- Verify compound FKs reject wrong platform/user/Session/currency and prevent financial history cascades. Verify uniqueness independently of services.
- Inject transaction/event/Ledger persistence failure at each local write. All business effects, sequences, hold transitions, and success events roll back together on the same connection.
- Check original identity/amount/Operation ID cannot be changed through service update paths. Direct privileged SQL remains outside the claimed service guarantee.
- Verify one transaction has one Ledger, correct delta/sign/before/after, continuous wallet_sequence, and correct booked/held/available. A recognized duplicate must match the existing posting, while unrelated unique/SQL failures propagate.
- Capture a valid own hold when Available Balance is zero: Booked 100.00 and own Hold 100.00 become Booked 0.00 and Held 0.00. Also verify an own Hold 100.00 with Booked 150.00 and other Holds 50.00 captures without consuming those other reservations.
- Reject capture with a missing, foreign, wrong-transaction, wrong-platform, wrong-player, wrong-Session, wrong-currency, mismatched-amount, released, or already captured hold. A matching completed capture retry instead verifies the existing result and performs no second debit or Ledger posting. Require trustworthy remote-success evidence.
- Verify `B >= A` and `B - A >= H_other`; reject insufficient Booked Balance or inconsistent reservations without releasing another hold or rewriting verified remote success as failure. Inject a local failure between debit, sequence, Ledger, capture, transaction completion, and event writes: the whole transaction rolls back and the original held reservation remains recoverable. No separate pre-release is allowed.
- Use a runtime-equivalent testing DB principal to attempt Ledger/events UPDATE/DELETE and confirm refusal; inspect effective inherited grants. Separate maintenance cleanup identity is a test prerequisite, not an implemented grant.
- Reconcile a consistent local snapshot and simulated external records: detect missing/duplicate/wrong-direction/wrong-currency/wrong-amount postings even when aggregate totals match.

### Mock and Stub Design

Propose a test-only Gateway adapter with its own durable operation store and controllable transport behavior. It records fixed Operation IDs and payload hashes, applies one effect under simulated reliable idempotency, and can report processing, delayed visibility, terminal success/failure, partial/malformed/contradictory evidence, or unavailable query.

Crucially support apply-effect-then-drop-response, not only a timeout before execution. Provide capability variants without reliable idempotency or with expired dedup windows and assert no automatic resend. A test HTTP harness may exercise true transport loss; an in-memory stub alone cannot prove independent-process behavior. No stub/mock server is created by this document.

### Concurrency Tests

Use independent PHP/HTTP/MySQL processes, deliberate barriers, and observed DB lock waits rather than timing-only sleeps or Sanctum actingAs simulation. Each case asserts final rows, Ledger count/amount, reservations, remote stub effects, and invariant preservation.

| Race | Barrier and expected outcome |
|---|---|
| Create/Create, different keys | Both reach slot competition; one effective Session |
| Direct effective INSERT/UPDATE competition | DB uniqueness remains final defense |
| Same Transfer In key/body | One accepted intent; exact response replay |
| Same key/different body | One winner, one conflict, no second debit |
| Two initial intents | Proposed one unresolved initial accepted; other conflicts |
| Top-ups near maximum | Serialized capacity decision; no over-reservation |
| Close/Transfer In | Accepted before fence drains; after fence rejected |
| Close/bet acceptance | Responsibility registration and admission boundary are atomic |
| Close/payout/seal | Payout before seal is included; unseen posting cannot sneak after seal |
| Outgoing creation/repeated Close | One outstanding/successful close transfer, matching hold |
| Two Workers/same transaction | One local posting/capture, stable Operation ID |
| Concurrent capture workers | One debit/posting/capture; a worker cannot consume another transaction's hold |
| Lease expiry/stale response | Old owner cannot downgrade/apply incompatible state |
| Disable/admission/dispatch | New business refused; in-flight responsibility resolved under policy |
| Reconciliation/live posting | Consistent local snapshot prevents false mismatches |

Bet/Payout concurrency cases require a separately approved responsibility registry/provider or a clearly scoped test harness. They do not claim currently absent game tables exist.

### Failure Injection Tests

Interrupt before/after intent commit, dispatch-start commit, HTTP send, remote effect, result persistence, local posting, local COMMIT acknowledgement, and public response. Recover in a fresh process/connection, not from a cached object.

- Before acceptance commit: no durable intent/effect; original identity may be retried.
- After acceptance, before dispatch: durable pending is rediscovered even if queue wakeup is lost.
- After dispatch-start, before known send: recovery conservatively queries; no inference of unsent from lease expiry.
- After remote effect, response lost: query original ID; incoming credits once or outgoing captures once.
- After remote success, local result/posting failure: original external effect is not repeated; local restoration is atomic.
- During confirmed-success capture, failure at any local write rolls back Booked Balance, wallet_sequence, Ledger, hold state, transaction success, and events together. Preserve the held reservation and remote-success recovery state; do not release funds separately or reclassify the remote result as failed.
- After COMMIT acknowledgement loss: read permanent identity on writer to distinguish committed/uncommitted state without new business identity.
- Outgoing timeout/retry exhaustion: hold and closing survive; manual workflow needs evidence.
- Terminal contradiction/partial result: freeze automatic transitions and preserve evidence.
- Database restore drill: suspend outbound processing, compare external history, avoid blind replay of old pending rows.
- Sealed Session receives unseen payout: identify barrier breach, do not quietly attach it to a later Session.

### Migration Tests

Unexecuted and separately authorized from no-DDL regression. Verify additive columns/defaults/keys/CHECKs/generated expressions on MySQL 8.4, preserve existing player identity/status/rows and migration history, and check compound-parent index order/collations.

Test schema drift refusal (preexisting wrong-type balance, unexpected nonzero money), nullable currency rollout with wallet disabled, rejection of unknown currency configuration, and nonempty destructive rollback refusal under the final approved migration design. No existing migration edit, rebuild, broad migrate command, --force bypass, or development DB is a valid workaround.

Migration cases require explicitly approved DDL against verified laravel_testing with a concrete setup/recovery plan. They cannot run inside the ordinary existing-schema runner's DDL prohibition. Do not reuse the old direct UserModelTest Migration case without its separate authorization.

### Regression Tests

Retain exact Resolve 200/201 replay, 48-hour retention, current-state auth, body normalization, original Resource ID typing, per-platform rate budgets, and controlled pruner behavior. Financial records must not enter Resolve pruning. Retain JWT/key-management, Admin token logout/revocation, RBAC, Platform/Player APIs, and synchronous Audit rollback/allowlists. No automatic wallet permissions or resource fields may appear.

Update only schema-sensitive assertions required by a later authorized implementation, such as UserModelTest's exact-column expectation. Do not weaken TestCase/phpunit.xml to run a selection. Historical passing counts remain historical and cannot be relabeled Wallet verification.

### Verification Gates and Reporting

Future sequence: approve open choices -> authorize required testing setup/migrations -> verify effective isolation -> targeted unit/feature/integration tests -> real concurrency/failure cases -> safe existing-schema regression -> postflight fingerprints/grant review. Report selected/excluded cases, process/connection realism, assertion counts, failures, cleanup, and limitations. Repeat/broaden checks only to resolve new changes or failures.

Documentation review may check relative links, JSON examples, table shape, and Mermaid structure without bootstrapping Laravel. That is not application test execution, MySQL proof, a rendered-diagram guarantee, or platform certification.

## External Platform Dependencies

Stub success proves provider behavior against the modeled contract only. A real adapter needs reviewed [EXT-01/EXT-02/EXT-03](wallet-api-contract.md#external-platform-dependencies) and platform integration tests with approved nonproduction credentials/accounts. Production topology, ingress, credentials, ledger grants, platform finality, and actual reconciliation SLA require independent verification.

## Open Questions

### TEST-01 Future Execution and Provisioning

Approve concrete testing setup/DDL/maintenance privileges and execution scope later. Resolve SES-01/SES-02/SES-03, API-01/API-02/API-03/[API-04](wallet-api-contract.md#api-04-close-transfer-discovery), DB-01 through DB-04, CAP-01, and operational/external contracts before their proposed outcomes become fixed assertions. Disabled-player public GET/Close/replay and failed/replacement discovery selection must not become fixed assertions before their respective approval. No tests in this plan have been executed, and no passing Wallet/Session guarantee is claimed.
