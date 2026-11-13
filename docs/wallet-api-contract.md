# Wallet API Contract Draft — Issue #15

**Design draft — not implemented.**

This document owns proposed wire contracts and the internal Gateway boundary. Endpoints, field encodings, errors, interfaces, and replay response policies are proposals, not deployed APIs or approved external platform specifications. See [Session semantics](game-session-design.md), [financial recovery](transfer-wallet-design.md), [schema](wallet-database-schema.md), and [unexecuted tests](wallet-testing-strategy.md).

## Existing Implementation

Only Player Resolve is currently exposed under the Platform business route group. It uses [Platform JWT](platform-jwt-authentication.md) and its own [admission limiter](platform-api-operations.md). The [Resolve contract](player-resolve-api.md), Resource ID typing, errors, 48-hour snapshots, field normalization exceptions, and middleware remain unchanged.

PlatformContext is readonly and carries verified string platformId, issuer, keyId, kid, and integer expiresAt. RS256 verification is live against registered issuer/key/platform state; JWTs are reusable and at most 300 seconds long. Invalid credentials return `401 {"message":"Unauthenticated."}`. A disabled platform returns `403 {"message":"Platform is disabled."}` only after valid signature/claims/key checks. No outbound credential or real Wallet Gateway exists.

## Approved Business Rules

Create returns the existing effective Session on duplicate login, without money movement. Initial Transfer In is required before activation; active allows additional funding. Closing refuses new funding/bets, drains already accepted funding and game obligations, and returns the full final balance. MVP exposes no ordinary partial Transfer Out or Adjustment API. Money uses DECIMAL(18,2), with no float calculations and one fixed platform currency. Disabled owners/players cannot initiate new business, but controlled processing preserves existing obligations. Terminal Sessions never reactivate.

## Proposed Technical Design

### Authentication and Authorization

All proposed public routes use existing `platform.jwt`, then a separately designed Session/Wallet limiter, target scoping, Form Request validation, and transaction-local live checks. Do not assume the Resolve-only limiter protects new routes. Rate values and storage topology remain API-03.

Apply operation-specific authorization after credential and ownership checks, and recheck applicable policy after lock waits. Do not apply a blanket active-player requirement to every operation. The platform ID always comes from PlatformContext, never input. Protected resource ownership is verified before revealing it. Admin Sanctum is separate and does not authorize a platform request.

The following matrix distinguishes approved restrictions from unresolved public access policies. It does not modify existing Platform JWT middleware. Eligibility remains subject to command replay, Session state, and ownership checks.

| Operation | Enabled platform / enabled player | Enabled platform / disabled player | Disabled platform | Authorization boundary |
|---|---|---|---|---|
| Create Session — new command | Eligible under Session guards | Denied: new business | Rejected by existing middleware | Platform JWT and scoped admission |
| Transfer In — new command | Eligible under state/capacity guards | Denied: new funding | Rejected by existing middleware | Platform JWT and scoped admission |
| GET Session / Transaction | Scoped read | Unresolved public read policy — API-03 | Rejected by existing middleware | Current credentials and tenant isolation |
| Close Session — new command | Eligible under close guards | Unresolved public Close policy — API-03 | Rejected by existing middleware | Requires explicit approval for disabled-player access |
| Internal Settlement / Recovery | Existing responsibilities only | Controlled continuation of existing responsibilities | Controlled continuation of existing responsibilities | Separate scoped service/operator authorization |

Existing platform middleware blocks disabled platforms even for GET, Close, and replay. Keep it unchanged. Internal recovery must not bypass JWT as a general public backdoor, impersonate a platform request, or create arbitrary new funding. Exact internal actors, permissions, and evidence requirements remain subject to API-03 and OPS-01.

Previously accepted command replay must not be rejected solely because the Session progressed, but current authentication and operation-specific authorization still apply. Disabled-player public GET, Close, and replay access are not approved and must not be inferred from the internal recovery exception.

### Platform Isolation and Validation Order

Use platform-scoped player/Session/transaction lookup and the schema's composite FKs. A missing, invalid, or cross-platform resource returns safe 404 without another tenant's details. Query on the writer when reconciling ambiguous commit outcomes.

Proposed write order: authenticate -> scope target existence -> validate JSON/headers -> acquire locks and recheck current security/ownership under the operation-specific policy -> identify retained command and compare hash -> replay if matching and currently authorized -> apply new-command state guards only if actually new -> commit acceptance. A matching replay is not rejected merely because the Session changed state. Current credential/authorization failures remain failures and do not cancel the original responsibility. Disabled-player public access and replay remain unresolved under API-03.

For Create, the scoped target is player_id; it may create a Session only after matching command lookup. For Transfer In/Close, the scoped target is the path Session. Reject unknown body fields and all query parameters on these MVP routes. Require a JSON object and `application/json` for POST; GET has no body. Preserve existing Resolve behavior.

### Money and Identifier Encoding

Proposal API-01: amounts are JSON strings in canonical fixed two-decimal form, from `"0.01"` to `"9999999999999999.99"` for Transfer In. Balances may be `"0.00"`. Reject JSON numbers, signs, exponent notation, surrounding whitespace, grouping separators, more/fewer decimal places, and overflow. Do not round before validating. A less restrictive 0–2-decimal input policy is an alternative requiring approval.

New resource IDs are strings: public UUIDs for Session/transaction and decimal strings for player IDs. Validate unsigned BIGINT player range without integer truncation. This does not alter Resolve's existing mixed safe-integer/string representation. TWD in examples is illustrative, not a configured platform currency.

Currency is an exact configured uppercase code. Create derives it; Transfer In supplies it for mismatch detection. Do not accept platform_id, status, balance, purpose, timestamps, permissions, or tokens in commands. Scope any normalization middleware exception to these new routes; do not silently trim monetary/identity content or extend/change Resolve's exception.

### Endpoint Summary

All paths below are proposed provider endpoints, not real external-platform wallet paths.

| Method | Endpoint | Input | Success |
|---|---|---|---|
| POST | `/api/v1/platform/game-sessions` | request_id, player_id; Idempotency-Key | 201 new / 200 existing |
| GET | `/api/v1/platform/game-sessions/{session_id}` | Path UUID | 200 current status |
| POST | `/api/v1/platform/game-sessions/{session_id}/transfer-ins` | business_transaction_id, amount, currency; key | 202 accepted |
| POST | `/api/v1/platform/game-sessions/{session_id}/close` | request_id; key | 202 closing / 200 already closed |
| GET | `/api/v1/platform/wallet-transactions/{transaction_id}` | Path UUID | 200 current status |

Successful POSTs include Location for the resulting Session/transaction. 202 means a durable local command was accepted, never that the platform moved funds or local Ledger posted. No public Transfer Out trigger accepts an arbitrary amount.

### Create or Get Session

```http
POST /api/v1/platform/game-sessions
Authorization: Bearer <platform-issued JWT>
Idempotency-Key: 11111111-1111-4111-8111-111111111111
Content-Type: application/json
```

```json
{
  "request_id": "22222222-2222-4222-8222-222222222222",
  "player_id": "123"
}
```

Proposed 201 for a new pending Session, or 200 for the same existing pending/active/closing Session:

```json
{
  "data": {
    "session_id": "33333333-3333-4333-8333-333333333333",
    "player_id": "123",
    "currency": "TWD",
    "status": "pending"
  }
}
```

Create never calls Debit. It requires an enabled player/platform with configured currency. A new request_id after an earlier terminal Session may create another Session; retry of an earlier accepted command returns that original Session snapshot.

### Transfer In

```http
POST /api/v1/platform/game-sessions/33333333-3333-4333-8333-333333333333/transfer-ins
Authorization: Bearer <platform-issued JWT>
Idempotency-Key: 44444444-4444-4444-8444-444444444444
Content-Type: application/json
```

```json
{
  "business_transaction_id": "55555555-5555-4555-8555-555555555555",
  "amount": "100.00",
  "currency": "TWD"
}
```

Proposed 202:

```json
{
  "data": {
    "transaction_id": "66666666-6666-4666-8666-666666666666",
    "session_id": "33333333-3333-4333-8333-333333333333",
    "kind": "transfer_in",
    "amount": "100.00",
    "currency": "TWD",
    "status": "pending"
  }
}
```

The service derives initial/topup from the locked Session state. Pending permits one unresolved initial intent under the proposed concurrency policy; active permits separately deduplicated top-ups with incoming capacity reservations. Closing/terminal rejects a new command, but a matching accepted command still replays. Remote failure is not cached as a successful money movement; the durable 202 acceptance remains the original command response and GET exposes the final failure.

### Close Session

```http
POST /api/v1/platform/game-sessions/33333333-3333-4333-8333-333333333333/close
Authorization: Bearer <platform-issued JWT>
Idempotency-Key: 77777777-7777-4777-8777-777777777777
Content-Type: application/json
```

```json
{
  "request_id": "88888888-8888-4888-8888-888888888888"
}
```

Proposed 202 while closing:

```json
{
  "data": {
    "session_id": "33333333-3333-4333-8333-333333333333",
    "status": "closing",
    "settlement_state": "draining"
  }
}
```

No amount/currency override is accepted. First close on pending/active commits closing; repeated new Close commands during closing return progress without another outgoing intent. A new command on closed returns 200 with status closed and settlement_state sealed. Failed handling remains a proposed 409, dependent on SES-01. Matching original commands replay their original response rather than switching to current state.

The coordinator waits for [close guards](game-session-design.md#closing-and-terminal-states); zero balance creates no transfer. A definitive outgoing failure does not allow repeated Close to silently create a new Operation ID; replacement authorization is OPS-02.

### Get Session Status

GET the Session Location, 200:

The `close_transfer_transaction_id` field below is a Proposed Design under [API-04](#api-04-close-transfer-discovery), not an approved wire contract. It is null when no close Transfer Out transaction has been created.

```json
{
  "data": {
    "session_id": "33333333-3333-4333-8333-333333333333",
    "player_id": "123",
    "currency": "TWD",
    "status": "closing",
    "settlement_state": "draining",
    "close_transfer_transaction_id": null,
    "wallet": {
      "booked_balance": "100.00",
      "held_amount": "0.00",
      "available_balance": "100.00"
    }
  }
}
```

The wallet object is a consistent snapshot of the player's current wallet, not historical funds owned by the requested old Session. A later Session may have changed it. No terminal balance history is implied. Do not expose fence evidence, Worker ownership, or credentials.

After a close Transfer Out is created, the proposed field contains its `wallet_transactions.public_id`, usable with GET `/api/v1/platform/wallet-transactions/{transaction_id}`. It never returns the internal bigint ID. For example, a created full-balance hold may be represented as:

```json
{
  "data": {
    "session_id": "33333333-3333-4333-8333-333333333333",
    "player_id": "123",
    "currency": "TWD",
    "status": "closing",
    "settlement_state": "sealed",
    "close_transfer_transaction_id": "99999999-9999-4999-8999-999999999999",
    "wallet": {
      "booked_balance": "100.00",
      "held_amount": "100.00",
      "available_balance": "0.00"
    }
  }
}
```

Resolve this reference only from transactions matching the authenticated platform and requested Session, with the same player and currency, kind `transfer_out`, and purpose `close`. The transaction-status lookup independently enforces the same tenant isolation; knowledge of an ID grants no access. Never accept this field from client input or expose an unscoped reference.

The field is derived from the proposed transaction relationship; it adds no Schema column or FK. It belongs only to dynamic GET Session responses and does not modify saved Create/Close acceptance responses. Failed/replacement selection remains the unapproved policy in API-04, and replacement creation remains governed by OPS-02. Zero-balance close with no outgoing transaction keeps the field null.

### Get Wallet Transaction Status

GET the transaction Location, 200:

```json
{
  "data": {
    "transaction_id": "66666666-6666-4666-8666-666666666666",
    "business_transaction_id": "55555555-5555-4555-8555-555555555555",
    "session_id": "33333333-3333-4333-8333-333333333333",
    "kind": "transfer_in",
    "amount": "100.00",
    "currency": "TWD",
    "status": "unknown",
    "resolution": "pending_confirmation",
    "completed_at": null
  }
}
```

Status uses the [transaction state machine](transfer-wallet-design.md#wallet-transaction-state-machine). Proposed resolution mapping: `manual_review` if reconciliation requires manual handling; otherwise `complete` for succeeded/failed, `pending_confirmation` for unknown/remote_pending, and `processing` for remaining states. Completed time is UTC ISO 8601 or NULL. Resolution is a public summary, not a second financial state. No internal last_error text, lease, PEM, raw platform response, or evidence URI is returned.

### Idempotency and Replay

All POSTs require a UUID Idempotency-Key, normalized lowercase. Create/Close also require request_id; Transfer In requires business_transaction_id. Proposed endpoint namespaces and storage appear in [schema](wallet-database-schema.md#game-session-commands).

Canonical hashing uses validated fixed-order fields, a versioned endpoint identity, and target identity. Include player for Create, path Session for Close/Transfer In, command/business ID, and amount/currency when applicable. JSON field ordering/escaping and UUID casing normalize; content changes do not. Reject unknown fields rather than hiding them from the hash.

| Case | Proposed behavior |
|---|---|
| Same scoped key and matching canonical content | Return original accepted status/JSON, no new operation |
| Same key with changed content or target | 409 IDEMPOTENCY_CONFLICT |
| New command login with an effective Session | Return existing Session, no implicit funding |
| Original command replay after Session progressed/terminated | Replay original snapshot after current security/scope validation |
| Same business/request ID with a different key | No new money movement; exact response is API-02, not approved |
| Prior command accepted but response/commit acknowledgement lost | Read original identity on writer, never invent a new ID |
| Key/ID older than Resolve retention | Financial identity still non-reusable; Resolve TTL is unrelated |

Proposed default is exact acceptance-response replay with GET for current progress. Snapshot archival versus permanent deduplication is OPS-01. If snapshots are eventually archived, a versioned response policy must be approved; do not delete the identity and interpret it as a new command. New payload/state validation must not incorrectly reject an already accepted matching replay, while current authentication/authorization still applies.

### HTTP Status and Error Codes

Existing JWT responses remain message-only. New domain/validation errors propose `message` and `code`, plus `errors` for 422. Exact messages are draft contracts to finalize before implementation; do not add codes to existing Resolve/JWT responses incidentally.

| HTTP | Proposed code or preserved format | Meaning |
|---|---|---|
| 401 | Existing Unauthenticated message | Missing/invalid/expired/revoked credential |
| 403 | Existing Platform is disabled message | Valid credential but platform disabled |
| 403 | PLAYER_DISABLED | Forbidden new player business |
| 404 | RESOURCE_NOT_FOUND | Invalid/missing/foreign resource |
| 409 | IDEMPOTENCY_CONFLICT | Same key, different canonical command |
| 409 | BUSINESS_ID_ALREADY_USED | Candidate API-02 policy only |
| 409 | SESSION_STATE_CONFLICT | Illegal new operation/transition |
| 409 | INITIAL_TRANSFER_IN_PROGRESS | Different unresolved initial intent |
| 409 | WALLET_CAPACITY_EXCEEDED | Cannot reserve future credit capacity |
| 409 | INSUFFICIENT_AVAILABLE_BALANCE | Debit/hold cannot be safely accepted; normally internal in this MVP |
| 409 | WALLET_NOT_CONFIGURED | Platform has no approved currency |
| 422 | VALIDATION_FAILED | Invalid body/header/type/amount/currency/unknown fields |
| 429 | RATE_LIMITED | New endpoint budget exhausted |
| 503 | SERVICE_UNAVAILABLE | Cannot reliably accept; retry same identity |
| 500 | Safe general server error | Unexpected persistence failure; do not infer rollback |

```json
{
  "message": "The request data is invalid.",
  "code": "VALIDATION_FAILED",
  "errors": {
    "amount": ["A positive amount with exactly two decimal places is required."]
  }
}
```

Unsettled rounds and unknown transfers are normal asynchronous closing progress, not necessarily an HTTP error for an already accepted Close. New business validation failure writes no success event. Transport errors after acceptance never prove no remote effect.

### Platform Wallet Gateway Interface

This illustrative provider-owned interface normalizes capabilities; it does not prescribe external URLs, authentication, or real response JSON:

```php
interface PlatformWalletGateway
{
    public function capabilities(): WalletGatewayCapabilities;

    public function debitMainWallet(WalletTransferCommand $command): WalletTransferObservation;

    public function creditMainWallet(WalletTransferCommand $command): WalletTransferObservation;

    public function queryTransaction(WalletOperationReference $reference): WalletTransferObservation;

    public function reconciliationPage(WalletReconciliationQuery $query): WalletReconciliationPage;
}
```

These classes/methods do not exist. A command carries trusted platform/external-player identity, fixed Operation ID, direction, exact amount/currency, reviewed contract version, and trace identity. An observation supplies succeeded/failed/pending/unknown/not_found, operation/external references, matching immutable fields, observation time, safe code, and evidence reference. Missing trustworthy evidence normalizes to unknown, not success.

Capabilities come from a reviewed adapter/configuration, never caller claims: reliable idempotency and duration, original-ID lookup, terminal not_found semantics, finality, partial/fee behavior, and reconciliation access. Pin an accepted intent to its reviewed contract; credential rotation must preserve recoverability. No adapter or mock platform API is implemented here.

### Required External Capabilities

| Capability | Required contract detail | Recovery consequence if absent |
|---|---|---|
| Debit Main Wallet | Exact player/direction/amount/currency and durable operation reference | Cannot safely enable incoming flow |
| Credit Main Wallet | Same immutable identity, atomic effect/failure semantics | Cannot safely enable outgoing flow |
| Query Transaction | Lookup by original provider Operation ID after response loss | Unknown needs another trusted/manual evidence route |
| Idempotency | One effect for same ID/content; conflict for changed content; known retention | No automatic resend |
| Terminality | Meaning and irreversibility of success/failure; eventual not_found behavior | No automatic failure/release decision |
| Reconciliation | Paginated records, stable cursor/time semantics, IDs, direction, amounts, currencies | Operational verification remains blocked |
| Outbound authentication | Reviewed transport/credential/rotation/revocation contract | No authenticated integration |
| Optional callbacks | Verifiable signature, event identity, duplicates and ordering | Callback is not trusted evidence |

Automatic query recovery requires dependable query semantics and binding evidence. Automatic resend additionally requires reliable idempotency for the complete recovery window; only original Operation ID and identical content may be used. No default timeout/retry count establishes these guarantees. HTTPS, controlled endpoint configuration, redirect/SSRF protection, and credential isolation must be addressed in the concrete adapter review.

### Security and Logging

Never log tokens, private keys, Authorization, or whole Requests/Models/platform payloads. Use allowlisted safe events and controlled evidence storage. Outbound endpoint configuration is privileged; no request-supplied URL or cross-platform credential selection. Neither platform JWT nor knowledge of a transaction ID authorizes arbitrary Bet/Payout/Adjustment or manual success declaration. Preserve existing Admin/RBAC boundaries; grant no wallet permissions automatically.

## External Platform Dependencies

### EXT-01 Outbound Transport and Authentication

Real platform endpoints, transport requirements, outbound authentication, credential custody/rotation, and adapter implementation remain unknown. Existing inbound JWT is not an outbound credential.

### EXT-02 Idempotency, Query, and Finality

Confirm operation/external-ID uniqueness scope, idempotency retention, query visibility, not_found meaning, terminal outcomes, partial success, fees, reversals, and exact evidence bindings. No safe automatic resend or truthful failure-release decision is established without these contracts.

### EXT-03 Reconciliation and Optional Callbacks

Confirm transaction export/query paging/time boundaries, availability, retention, callback verification/deduplication/order, and the platform's reconciliation contact. Operational owner/evidence policy also requires OPS-01.

## Open Questions

### API-01 Wire Contract Approval

Approve proposed paths, UUID business/request IDs, string resource IDs, fixed-two-decimal input versus 0–2-decimal normalization, currency checks, exact response/error messages, and immutable acceptance-response replay. None changes Resolve. Public exposure of current wallet balance in Session GET is also a proposal, not an existing field contract. The new close-transfer discovery field requires the separate API-04 decision.

### API-02 Business Identity With a Different Key

Option A returns 409 BUSINESS_ID_ALREADY_USED and a same-platform Location even for matching content. Option B returns the original accepted resource/response and durably binds the new key as an alias; that requires an additional reviewed alias model so the key cannot later be reused for unrelated content. Changed immutable content always conflicts. Choose before schema/API finalization; this draft does not approve either option.

### API-03 Admission and Internal Authorization

Approve new endpoint budgets/storage, normalization exceptions, and scoped internal recovery permissions. Explicitly decide disabled-player access for public GET, new Close commands, and replay of previously accepted commands. None of those public access rights is approved by this draft. Preserve existing disabled-platform JWT rejection and Resolve limits. Internal restoration must not depend on the original 300-second JWT remaining valid.

### API-04 Close Transfer Discovery

Approve whether GET Session exposes nullable `close_transfer_transaction_id` as proposed above. Its value would be the scoped close Transfer Out's Public Transaction ID, never a new Schema field or a change to saved command responses. Documentation authorization is not wire-contract approval.

Proposed selection policy, awaiting approval: retain the reference to the latest created close Transfer Out, including a failed transaction, until an explicitly authorized replacement is created. Select deterministically by greatest internal transaction ID within the scoped relationship. Return null only when no close Transfer Out exists; an unknown transaction must not be hidden by returning null or replaced with a new operation. OPS-02 still governs whether a replacement may be created. Selecting a reference does not authorize another payment.
