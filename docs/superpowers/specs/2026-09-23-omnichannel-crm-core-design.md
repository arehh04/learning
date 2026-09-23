# Omnichannel CRM — Core Platform Design

Status: Approved (design stage) — 2026-09-23
Scope: Sub-project 1 of 3 (see §1). This spec covers the core inbox platform only.

## 1. Scope & Decomposition

The full product vision (inspired by Respond.io) — multi-channel messaging, unified inbox,
AI/human routing, contacts, conversations, automation workflows, and future CRM integrations
(e.g. HubSpot) — is too large for one spec. It is decomposed into three sub-projects:

1. **Core platform (this spec)** — channel ingestion, unified inbox, contacts, conversations,
   AI-agent/human-agent routing, reply-back through the originating channel.
2. **Automation workflows** — future. Not designed here beyond ensuring the core doesn't
   architecturally preclude it.
3. **CRM integrations (HubSpot, etc.)** — future. Same treatment as #2.

This document designs #1 only.

## 2. Requirements (as clarified)

- **Tenancy**: single-tenant. One organization, one team. No `workspace_id`/tenant isolation
  anywhere in the schema.
- **Channels at MVP**: Telegram only. WhatsApp and email are future channels; the design must
  not preclude them, but only Telegram is implemented now.
- **AI Agent**: a routing *stub* for MVP, not a real LLM integration. Conversations can be in
  an `ai` ownership state structurally, but nothing populates it automatically yet — no LLM
  calls are made in this phase. Real AI logic is deferred.
- **Human agents**: multiple agents, with login/auth, for a team (not a single generic actor).
- **Assignment model**: manual claim. Routed/unassigned conversations sit in a shared queue;
  any agent can claim one (first to claim owns it). No auto-assignment/round-robin.
- **Philosophy**: "ugly first" — a small, functioning MVP over a fully-featured system.
  YAGNI on automation workflows, real AI, multi-tenancy, and additional channels.

## 3. Stack Decision

**Laravel (PHP) + PostgreSQL. No Redis at MVP.**

Rationale, reconstructed from discussion:

- **Why not stay on the existing Node/Express/TS starter in this repo**: that starter
  (`src/app.ts`, `src/users/`) is an unrelated toy CRUD demo; it was explicitly for repo
  initialization only, not a stack commitment.
- **Why not Go**: initially proposed for its concurrency model and compile-time interface
  enforcement. On reflection, the correctness guarantees this system actually needs
  (idempotent ingestion, atomic conversation claims) come from **PostgreSQL transactions and
  unique constraints**, not the application language's concurrency model — so Go's advantage
  here was overstated. Laravel achieves the same guarantees via `DB::transaction()` +
  `lockForUpdate()`.
- **Why not Rust**: rejected — too much ceremony for an I/O-bound, CRUD-heavy business
  application. Rust's performance ceiling addresses a bottleneck (CPU/memory) this system
  doesn't have; the cost is velocity, which directly conflicts with "ugly first."
- **Why not C++**: rejected outright — no modern web ecosystem fit, and manual memory
  management on untrusted webhook input from three different providers is a security
  liability, not a benefit.
- **Why Laravel over Node/TS or Go**: the deciding factor was the confirmed requirement for
  **multiple agents with login/auth**. Laravel provides this near-free (Breeze/Fortify),
  along with Eloquent/migrations that fit the relational contacts → conversations → messages
  domain well, a built-in queue system (database-backed, no Redis required) for outbound
  send retries, and built-in broadcasting (Reverb) as a natural future path to real-time
  inbox updates without introducing a new library later.
- **Why PostgreSQL over MongoDB**: the domain is relational with real integrity requirements
  (unique constraints for idempotency, foreign keys, transactional atomic claims). Postgres
  is the natural fit; MongoDB's schema flexibility is not a relevant benefit here, and its
  multi-document transactional story is weaker for exactly the guarantees this system leans on.
- **Why no Redis yet**: nothing to queue or fan out yet at MVP scope (Laravel's database
  queue driver covers outbound retry needs). Introduce Redis when either automation workflows
  need a real job runner, or websocket fanout across multiple instances becomes necessary.
- **AI/automation ecosystem concern (addressed, not a blocker)**: Python/Node have stronger
  ecosystems for LLM agent orchestration and workflow engines than PHP. This is a real gap,
  but it applies to sub-projects #2 and #3 (explicitly deferred), not to this core platform.
  Calling an LLM API from PHP (plain REST + JSON) is not technically blocked when that work
  starts. The design keeps the boundary clean (see §6, `ConversationRoutingService`) so that
  a future AI/automation engine can be built as a **separate service** (potentially in a
  different stack) that talks to this Laravel core over an internal API, rather than requiring
  a rewrite of the core CRM.

## 4. Architecture & Components

Single Laravel monolith — no separate services at this stage.

- **Webhook layer**: `POST /webhooks/telegram` receives Telegram Bot API updates.
- **Channel Adapter**: `ChannelAdapter` interface (`parseInbound()`, `send()`), with one
  implementation, `TelegramAdapter`. This is the seam future channels (WhatsApp, email)
  plug into without touching ingestion or routing logic.
- **Domain services**:
  - `MessageIngestionService` — dedupe + persist inbound messages.
  - `ConversationRoutingService` — decides a new conversation's initial ownership bucket.
  - `ClaimService` — atomic claim of an unassigned conversation by an agent.
  - `ReplyDispatchService` — sends an outbound reply via the appropriate channel adapter.
- **Persistence**: Eloquent models over PostgreSQL.
- **Auth**: Laravel Breeze/Fortify, multi-user agent login.
- **UI**: agent-facing inbox (Blade/Livewire). Uses polling/refresh for MVP, not websockets.
  Full UI design is out of scope for this document; noted here only because agents need an
  interface to claim/reply.

## 5. Message Flow

### Inbound

1. Telegram calls the webhook with an `Update` payload (`update_id`, `message.message_id`,
   `message.from`, `message.text`).
2. The controller verifies the `X-Telegram-Bot-Api-Secret-Token` header; requests that don't
   match are rejected before anything is persisted.
3. `MessageIngestionService` records the event by `update_id` in `webhook_events` first,
   inside a transaction. If already recorded, processing stops here — this is the fix for
   Telegram's at-least-once webhook redelivery.
4. Find-or-create `Contact`, keyed on `(channel_id, external_contact_id)` — unique
   constraint, atomic upsert.
5. Find-or-create `Conversation` for that contact: if the contact's most recent conversation
   is `closed`, **reopen it** rather than starting a new one (keeps history contiguous). Only
   create a new conversation if none exists yet.
6. Insert the `Message` row (`direction = inbound`), linked to the conversation.
7. If the conversation was just created, `ConversationRoutingService` sets its initial
   `owner_type`. For MVP — since the AI agent does nothing — the rule is trivial: everything
   lands in `unassigned` (the shared human queue). The `owner_type` enum still includes `ai`
   as a valid value; nothing routes into it automatically yet.

### Outbound

1. An agent claims an unassigned conversation. `ClaimService` runs inside a transaction with
   `lockForUpdate()`: checks `owner_type = unassigned`, then sets `owner_type = human`,
   `owner_agent_id = <agent>`. If another agent already claimed it, this fails cleanly with a
   conflict instead of silently double-assigning.
2. The agent sends a reply from the inbox. `ReplyDispatchService` inserts a `Message`
   (`direction = outbound`, `status = pending`), then calls `TelegramAdapter->send()`.
3. The send is dispatched through Laravel's **database-backed queue** (no Redis required),
   giving retry-on-failure without custom retry logic.
4. On success: message marked `sent`, Telegram's returned message id stored. On failure
   (network error, bot blocked, rate limit): message marked `failed`, retried per Laravel's
   standard queue retry/backoff config, surfaced in the UI.

## 6. Conversation State & Handoff

`conversations.owner_type`: `unassigned | ai | human`, plus `owner_agent_id` (nullable, set
only when `owner_type = human`).

Every ownership transition is written to `handoff_events` — a small cost (one insert per
transition) against a real debugging benefit: it's always possible to answer "who owned this
conversation, and when," rather than reconstructing history from current state alone. This
directly addresses the "ghost ownership" failure mode identified during design discussion
(a conversation with no clear owner, where both AI and human assume the other is handling it).

`ConversationRoutingService` is the single decision point for where a new conversation's
ownership starts. Keeping this logic isolated here (rather than scattered across ingestion
code) is what allows a future, more capable AI/automation engine to be swapped in later as a
service call, without restructuring the core platform.

## 7. Database Entities

| Table | Key columns | Notes |
|---|---|---|
| `users` | Laravel's built-in agent/user table | Login, multi-agent, per requirement. |
| `channels` | `id, type (telegram\|whatsapp\|email), label, credentials (encrypted), is_active` | A table, not config — adding a channel/account later is a data change. |
| `contacts` | `id, channel_id, external_contact_id, name, metadata(json)` | Unique `(channel_id, external_contact_id)`. |
| `conversations` | `id, contact_id, channel_id, status (open\|closed), owner_type (unassigned\|ai\|human), owner_agent_id (nullable), last_inbound_at, opened_at, closed_at` | `last_inbound_at` is unused at MVP but is the natural field a future WhatsApp 24h-window check would need. |
| `messages` | `id, conversation_id, direction (inbound\|outbound), sender_type (contact\|agent\|ai\|system), sender_id (nullable), body, external_message_id, status (received\|pending\|sent\|failed), raw_payload(json)` | `raw_payload` preserves the original webhook body for debugging/reprocessing without a schema change. |
| `webhook_events` | `id, channel_id, external_event_id, payload(json), processed_at` | Unique `(channel_id, external_event_id)`. Idempotency ledger — separate from `messages` because not every Telegram update is a chat message. |
| `handoff_events` | `id, conversation_id, from_owner_type, to_owner_type, agent_id (nullable), reason, created_at` | Audit trail for every ownership transition. |

No `workspace`/tenant table. No automation/workflow tables. No CRM-integration tables.

## 8. Error Handling

- **Duplicate webhooks** → caught by `webhook_events` unique `(channel_id, external_event_id)`
  inside the ingestion transaction; redelivered updates are a no-op.
- **Concurrent contact/conversation creation race** → DB unique constraints force the losing
  request to hit a constraint violation, which the ingestion service catches and re-reads
  instead of erroring out.
- **Double-claim race** → `ClaimService`'s `lockForUpdate()` transaction: the second claim
  attempt sees `owner_type != unassigned` and fails with a clear "already claimed" response.
- **Outbound send failure** → message marked `failed`; retried automatically via Laravel's
  queue retry/backoff, no bespoke retry logic.
- **Malformed/unverified webhook** → rejected at the controller if the secret token header
  doesn't match; never reaches ingestion.

## 9. Testing Approach (MVP-scoped)

Focused on the specific failure points identified during design, not exhaustive CRUD coverage:

- Same Telegram `update_id` delivered twice → exactly one `Message` row.
- Two concurrent claim attempts on the same conversation → exactly one succeeds.
- Inbound message to a contact with a closed conversation → reopens the existing conversation,
  does not fork a new one.
- Unverified webhook (bad/missing secret token) → rejected, nothing persisted.

Standard Laravel auth flows and basic CRUD are left to the framework's own test coverage
rather than re-tested here.

## 10. Explicitly Deferred

- WhatsApp, email channels — `ChannelAdapter` interface exists for this; no implementation yet.
- Real AI Agent logic (LLM calls) — `owner_type = ai` exists structurally; nothing populates
  it yet.
- Automation workflows — no tables, no engine; likely a separate service later, not bolted
  onto Laravel.
- CRM integrations (HubSpot, etc.) — no tables, no sync logic.
- Multi-tenancy — explicitly single-tenant; no `workspace_id` anywhere.
- Real-time push (websockets) — inbox uses polling/refresh for MVP; Reverb is a natural
  fast-follow, not designed in now.
