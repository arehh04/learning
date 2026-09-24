# AI Agent — Design

Status: Approved (design stage) — 2026-09-24
Scope: activates the `owner_type = 'ai'` structural stub left in the core platform spec (`2026-09-23-omnichannel-crm-core-design.md`). Not one of that spec's two deferred sub-projects (automation workflows, CRM integrations) — this is a self-contained addition to sub-project 1's existing routing seam.

## 1. Requirements (as clarified)

- **Behavior**: "First responder" — the AI genuinely attempts to answer using conversation context, not just light triage. Falls back to a human whenever it isn't confident.
- **Confidence signal**: must be a real, calibrated signal, not an LLM's self-reported confidence (well-documented as unreliable) — this is the reason a second model (Jev) is used specifically as a confidence gate.
- **Business rules / knowledge**: explicitly deferred by the user ("later i will tailor to business rules," "RAG system that later being integrate"). This design leaves the seam (a single system-prompt config point) but does not build a knowledge base, RAG pipeline, or business-rules engine.
- **Handoff UX**: when the AI hands off on low confidence, the human agent sees the AI's rejected draft as a pre-filled hint in the reply box, not a blank box — "easier for human agent, less human intervention."
- **Philosophy carried over from the core platform**: same "ugly first" bias — minimal viable version of each piece, defer what isn't needed yet.

## 2. External Services

### Anthropic (Claude) — drafts the reply

Plain HTTP API (`Http::post()`), same integration pattern as `TelegramAdapter` — no SDK. Used only to generate natural-language reply text from conversation history + a minimal system prompt.

**Model**: `claude-haiku-4-5-20251001` by default (configurable via `ANTHROPIC_MODEL`, see §8) — fast and cheap for a first-pass draft, which is the right trade-off here specifically *because* Jev exists as a calibrated backstop on top of it. If draft quality turns out too low once real usage exists, bumping to Sonnet is a config change, not a code change.

### Jev (TypeSafe AI) — confidence gate

Released 2026-09-15. Does not generate text — returns **typed, calibrated decisions** (`Choice`, `Score`, `Noul` primitives) with confidence/probability fields, trained via "Reinforcement Learning for Calibrated Decisions." Chosen specifically because LLM self-reported confidence is a known weak point, and Jev is purpose-built for exactly this "should I act or defer" judgment — fast (~70-500ms) and effectively free (`$0.042`/M input tokens, output tokens free).

**API** (verified against `https://docs.typesafe.ai/api.md`):

- `POST https://api.typesafe.ai/v1/systemone`
- Auth: `Authorization: Bearer <API_KEY>`
- Request:
  ```json
  {
    "state": "<conversation + draft reply>",
    "model": "jev-latest",
    "questions": {
      "confident_to_send": {
        "type": "noul",
        "instructions": "Given the conversation and the drafted reply, is this reply confident and appropriate to send to the customer as-is?",
        "criteria": { "true": "confident and appropriate to send", "false": "uncertain, incorrect, or needs a human" }
      }
    }
  }
  ```
- Response:
  ```json
  {
    "model": "jev-latest",
    "answers": { "confident_to_send": { "type": "noul", "noul": 0.0 } },
    "usage": { "input_tokens": 0, "output_tokens": 0 }
  }
  ```
  `noul` is a 0.0–1.0 probability; thresholded against `AI_CONFIDENCE_THRESHOLD`.
- Status codes: 401 (bad key), 422 (validation), 429 (rate limit), 529 (overloaded) — all treated as gate failure (see §5).
- No PHP SDK exists (Python and JavaScript only per `docs.typesafe.ai`) — irrelevant here since this app calls the raw HTTP API directly, same as it already does for Telegram.

## 3. Architecture & Components

- `app/Services/AI/ClaudeAdapter.php` — `draft(Conversation $conversation): string`. Builds a prompt from conversation history + a minimal, hardcoded system prompt (the business-rules/RAG seam — currently one string, expandable later without changing the call site). Calls the Anthropic Messages API.
- `app/Services/AI/JevAdapter.php` — `evaluateConfidence(Conversation $conversation, string $draft): float`. Calls Jev's `noul` primitive, returns the 0.0–1.0 score.
- `app/Services/AI/AiReplyService.php` — `handle(Conversation $conversation): void`. Orchestrates: draft → gate → send-or-handoff (see §4). The single place that decides what "AI replied" means; `ClaudeAdapter`/`JevAdapter` know nothing about conversations, ownership, or messages — they're pure external-API wrappers, same separation `TelegramAdapter` already models.
- `app/Jobs/GenerateAiReplyJob.php` — queued (`database` driver, same as `SendTelegramReplyJob`), constructed with `int $conversationId`, calls `AiReplyService::handle()`.

## 4. Trigger & Flow

### Routing change (the activation point)

`ConversationRoutingService::decideInitialOwner()` changes from unconditionally returning `Conversation::OWNER_UNASSIGNED` to:
- returning `Conversation::OWNER_AI` when `AI_AGENT_ENABLED` is true (default),
- returning `Conversation::OWNER_UNASSIGNED` when the kill switch is off.

### Ingestion hook

`MessageIngestionService::ingest()`, after inserting an inbound `Message`, dispatches `GenerateAiReplyJob` **whenever the conversation's current `owner_type` is `ai`** — this covers both a brand-new conversation and a follow-up message in an existing AI-owned thread. If `owner_type` is `human` or `unassigned`, no job is dispatched — the AI does not process conversations it doesn't currently own.

### `AiReplyService::handle()`

1. Re-fetch the conversation; if `owner_type` is no longer `ai` (a human claimed it since the job was queued), do nothing and return — avoids a stale job racing a human's claim.
2. `ClaudeAdapter::draft()` → reply text.
3. `JevAdapter::evaluateConfidence()` → score.
4. **Score ≥ `AI_CONFIDENCE_THRESHOLD`**: create the `Message` (`direction=outbound`, `sender_type=ai`, `status=pending`), dispatch the existing `SendTelegramReplyJob` unchanged — the AI's send path rejoins the already-built outbound pipeline at this point, no new send logic.
5. **Score < threshold, or Jev's call failed/errored** (a draft exists in both cases): create the `Message` with `status=draft` (never dispatched to Telegram — this is what powers the hint in §6), flip the conversation to `owner_type=unassigned`, and write a `HandoffEvent` (`reason='low_confidence'` or `'ai_error'` respectively).
6. **Claude's call failed/errored** (no draft exists): skip straight to handoff — no `Message` row is created, flip `owner_type=unassigned`, write a `HandoffEvent` (`reason='ai_error'`). See §5 for why this case has no draft to show.

### One-way handoff

Once a conversation leaves `ai` ownership, nothing routes it back automatically — matches "always route back to human," not an oscillating AI/human tug-of-war. A conversation only returns to AI ownership if a human explicitly did so (not built in this design — no such action exists yet, consistent with YAGNI).

## 5. Error Handling

Both external calls (Claude, Jev) can fail (network, rate limit, timeout, malformed response). **Every failure mode resolves to the same fail-safe: hand off to a human**, never a stuck conversation, never a crash, never a silent drop:
- Claude API failure → skip Jev entirely, go straight to step 5 above with `reason='ai_error'` and no draft message (nothing to show as a hint in this case — the human starts blank, same as pre-AI-Agent behavior).
- Jev API failure → treat as below-threshold (draft still saved as `status=draft`, still shown as a hint — Claude's draft existing doesn't depend on Jev being reachable).
- Both `ClaudeAdapter` and `JevAdapter` wrap their HTTP calls in try/catch and surface a typed exception (`AiDraftingFailedException`, `AiConfidenceCheckFailedException`) that `AiReplyService` catches explicitly — no bare `RuntimeException` reuse from `TelegramAdapter`, since these are a different failure domain.

## 6. UI — the draft hint

`Inbox::claim()` (existing) needs no change to its claiming logic. `Inbox::select()`/`render()` gains: when the selected conversation has a `Message` with `status=draft` and no later outbound message superseding it, pre-fill `replyBody` with that draft's `body` instead of leaving it empty. The draft message itself renders in the transcript like any other message (visually marked as an unsent AI draft, not a real sent message — exact styling is an implementation detail, not a design decision). The agent can edit or send it as-is through the existing `sendReply()` path unchanged.

## 7. Schema Changes

- `messages.status` enum: add `'draft'` alongside the existing `received|pending|sent|failed`. One migration, additive, no data migration needed (no existing rows use it).
- No other schema changes. `owner_type='ai'` and `handoff_events.reason` (free-text string) already support everything above.

## 8. Config

```
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-haiku-4-5-20251001
TYPESAFE_AI_API_KEY=
AI_CONFIDENCE_THRESHOLD=0.7
AI_AGENT_ENABLED=true
```

`AI_AGENT_ENABLED=false` is the production kill switch — flips routing back to the pre-AI-Agent behavior (`unassigned`) with no code change, no deploy.

## 9. Testing Approach (mirrors the core platform spec's scoping)

Focused on the specific behaviors this design introduces, not exhaustive LLM-output testing (which is inherently non-deterministic and out of scope to assert on):
- High Jev score → AI reply sent through the existing outbound pipeline, conversation stays `ai`-owned.
- Low Jev score → conversation flips to `unassigned`, `HandoffEvent` logged with `reason='low_confidence'`, draft `Message` saved with `status=draft`.
- Claude API failure → handoff with `reason='ai_error'`, no draft message created.
- Jev API failure → handoff with `reason='ai_error'`, draft message still saved and still usable as a hint.
- A human claiming the conversation before the queued job runs → job is a no-op (re-fetch check in step 1 of §4).
- `AI_AGENT_ENABLED=false` → new conversations route to `unassigned` exactly as they did before this feature existed.
- Inbox: claiming a conversation with a `status=draft` message pre-fills the reply box with the draft's body; claiming one without a draft leaves it blank (unchanged existing behavior).

External API calls are faked in tests (`Http::fake()`), same convention already used for `TelegramAdapterTest`/`SendTelegramReplyJobTest` — no live Claude/Jev calls in the test suite.

## 10. Explicitly Deferred

- **Business rules / business-specific knowledge**: the system prompt is a single hardcoded string for now. No configuration UI, no per-business customization mechanism.
- **RAG / knowledge base integration**: no retrieval, no vector store, no document ingestion. `ClaudeAdapter::draft()`'s prompt-construction is the seam this plugs into later, but nothing is built now.
- **Returning a conversation to AI ownership after a human handles it**: no such action exists. Once handed off, always human from then on (within that conversation).
- **Multi-turn AI reasoning/tool use**: `ClaudeAdapter::draft()` is a single-shot completion (conversation history in, one reply out) — no agentic tool-calling loop, no MCP integration.
- **Tuning `AI_CONFIDENCE_THRESHOLD` per-business or dynamically**: one global `.env` value.
