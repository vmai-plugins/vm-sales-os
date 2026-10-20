# Architecture

## Boot sequence

```
plugins_loaded (priority 5)
  └─ Plugin::boot()
       ├─ container: EventBus, ModuleRegistry
       ├─ ModuleRegistry::discover()      modules declared, not yet loaded
       ├─ Migrator::maybeMigrate()        only when db_version < SCHEMA_VERSION
       ├─ QueueManager::boot()            cron schedule + handler registration
       ├─ RestRouter::boot()              defers to rest_api_init
       ├─ AdminArea::boot()               admin only
       └─ ModuleRegistry::bootAll()
            ├─ register()  all active modules bind services
            └─ boot()      all active modules attach hooks
```

Two-phase module loading matters: every module has bound its services before any module starts
wiring hooks, so a module can depend on another's services without caring about load order.

## Module isolation

Modules never call each other directly. Chat asks for knowledge through
`vmsos_chat_knowledge_context`; if the Knowledge module is off, the filter returns empty and the
assistant says it will check with a human. CRM exposes lead capture as a *tool*, not an API the chat
module imports. Turning off a module degrades behaviour; it never fatals.

## The AI layer

```
ChatRequest ─→ ProviderManager
                 ├─ candidates()   configured + tool-capable + in chain
                 ├─ circuit breaker (3 failures → 5 min cooldown)
                 ├─ provider->chat()
                 ├─ UsageMeter::record()   tokens, cost, latency, per brand
                 └─ on throw → next provider
              ─→ ChatResponse (content, toolCalls, tokens, raw)
```

Failover is per-request, not per-configuration: a 429 on OpenAI silently becomes an Anthropic call
on the same turn. All four drivers normalise tool calls into one shape, so the tool loop in
`ChatService` and `Orchestrator` is provider-agnostic.

## Tool safety

`ToolRegistry` binds every tool to a capability. The chat widget runs with `['public']`; agents run
with whatever their row grants, usually `['internal']`. A model that hallucinates a call to
`pipeline_snapshot` from a public chat gets a refusal string, not data. This is the boundary that
matters most in the whole plugin: the model chooses *which* tool to call, never *whether it is
allowed to*.

## Retrieval

Chunk on paragraph boundaries with overlap → embed in batches of 16 → store as packed float32 in
MySQL. Query time fuses two rankings with reciprocal rank fusion: cosine similarity over embeddings,
and MySQL FULLTEXT for exact terms. Semantic search alone misses product SKUs; keyword alone misses
paraphrase. Query vectors are cached for an hour — the same question gets asked constantly and every
miss is a paid call.

When no embedding provider is configured, indexing still stores the text and retrieval falls back to
keyword only. Degradation, not failure.

## Multi-tenancy

Every business table has `brand_id` as the first column of its primary index. `BrandContext::current()`
resolves it once per request through the `vmsos_current_brand_id` filter, and `Repository` injects it
into every read and write. Agency installs and multisite get real isolation without a second schema.

## Queue

Jobs are claimed with a conditional UPDATE, so two overlapping cron runs cannot double-execute one
job. Failures back off 1m → 5m → 15m → 1h → 6h and then park as `failed` with the error kept on the
row. Jobs reserved for more than 15 minutes are assumed dead and returned to the pool.

## Schema

15 tables: brands, settings, audit_log, jobs, companies, contacts, pipelines, deals, activities,
conversations, messages, kb_documents, kb_chunks, agents, agent_runs, ai_usage.

Indexing follows access, not intuition — `(brand_id, stage, score)` on contacts because the lead list
always filters by brand, then stage, then sorts by score.

## Security posture

- Capabilities: `vmsos_manage` (settings, deletion) and `vmsos_operate` (read, act on leads).
- Public chat endpoint: honeypot, hashed-IP rate limit, entropy spam guard, no PII in the response.
- Credentials: AES-256-GCM in the vault, keyed off `wp_salt()`, never echoed back to the browser.
  `wp-config.php` constants override the database.
- Logging redacts anything key-shaped before it reaches the audit table.
- IPs are stored as HMACs, never in the clear.
- Uninstall drops nothing unless `vmsos_erase_data_on_uninstall` is set.

---

## Phase 1 additions (0.2.0)

### Human takeover, and why the assistant goes quiet

The chat pipeline has one rule that everything else follows from: **a thread
has exactly one voice at a time.** When `conversations.handover` is set,
`ChatService::reply()` stores the visitor's turn, fires
`conversation.awaiting_human`, and returns an empty reply. It does not call a
provider. Nothing "queues up" behind the human and gets sent later.

That means the widget has to learn about a reply it did not ask for, so
`GET /chat/poll` returns turns newer than a message id the widget already
holds. Polling rather than websockets is a deliberate choice: shared hosting,
page caches and corporate proxies all break long-lived connections, and an
eight-second poll on an open chat panel is cheap.

The poll endpoint is public, so it checks that the caller's visitor hash
matches the one the conversation was opened with. A guessed UUID on its own
returns 403 rather than someone else's transcript.

Delivery is not the inbox's job. `InboxService::reply()` writes the turn and
fires `vmsos_outbound_message`. The web widget picks it up by polling; the
WhatsApp adapter listens on that hook and pushes it to the Cloud API. Adding
a channel means adding a listener, not touching the inbox.

### Message roles

| Role | Visitor sees it | Model sees it |
|---|---|---|
| `user` | yes | yes |
| `assistant` | yes | yes |
| `agent` (human) | yes | yes, mapped to `assistant` |
| `note` | never | never |
| `tool` | never | yes, inside the loop |

A human reply is mapped to `assistant` in `history()` so that when the thread
is handed back, the model reads one coherent conversation instead of an
unexplained gap. Internal notes are filtered out at the query level, not in
the view, so there is no code path where a note can reach a visitor.

### Sequences

Each step carries the wait *before* it runs. On enrolment `next_run_at` is set
from step one's delay; `SequenceRunner` picks up due enrolments every minute,
executes the step and schedules the next. The runner never sends anything
itself — it pushes a job onto the `mail` queue, because a slow SMTP host
should not stall the tick for everyone else.

Consent is checked twice: once when the step comes due, and again inside the
job just before sending. Between those two moments a contact can unsubscribe,
and the second check is the one that respects it.

Tracking uses a random 32-character token per send. Open, click and
unsubscribe all resolve through that token, so no contact id, email or
sequence id ever appears in a URL that ends up in someone's browser history
or a corporate mail scanner's logs. The open pixel returns a valid GIF even
for an unknown token — confirming which tokens are real would be a small
enumeration oracle.

AI personalisation is opt-in per step and never load-bearing. If the provider
chain fails, or returns something less than a third or more than three times
the length of the template, the merged template goes out unchanged. A generic
email is a worse email; an email containing an error message is a lost
customer.

### Reporting

`MetricsService` gap-fills every daily series before returning it. A chart
with missing days does not just look wrong, it reads as a trend that is not
there. Ratios return `null` rather than `0` when the denominator is zero, so
the UI can print an em dash instead of claiming a 0% capture rate on a day
with no traffic.

---

## Phase 2 additions (0.3.0)

Two of these came directly from reading the VMAI Sales Agent v9.3.1 source.
Both solve problems this codebase had not yet hit but would have.

### Lead memory

Chat history gives the model twenty turns of one conversation. That is
continuity, not memory — return next week on WhatsApp instead of the widget
and the assistant has forgotten you exist.

`MemoryEngine` keeps one evolving record per contact: summary, durable facts,
preferences, objections, buying stage, next best action. It is appended to the
system prompt through `vmsos_chat_system_prompt` for every future conversation
with that person, on any channel.

Three deliberate constraints:

- **Refresh every N replies, not every reply.** Re-summarising each turn
  roughly doubles the cost of a conversation for very little extra fidelity.
  Default is four; it is a setting.
- **Refresh happens on the queue.** A visitor never waits for a summarisation
  call to finish.
- **Failure keeps the old record.** If the provider chain is down or the model
  returns unparseable JSON, the previous memory stays and the conversation
  continues. A memory refresh is an optimisation, never a precondition for
  answering someone.

The JSON parser extracts the outermost object rather than trusting the
envelope, because models wrap JSON in prose and code fences no matter how
firmly the system prompt asks them not to.

### Send governor

Every marketing send now passes `SendGovernor::check()`. Two protections:
quiet hours, and a per-channel daily cap.

The rule that matters more than either: **this governs marketing only.**
Sequences and campaigns are throttled; a conversational reply never is.
Someone chatting at 11pm gets an instant answer. Throttling that would not be
compliance, it would be a broken product.

A blocked send is postponed to the next opening, never dropped and never
counted as a failure — the enrolment stays on the same step and is rescheduled.
The cap is only decremented on a *successful* send, so a broken SMTP host
cannot silently eat the whole day's budget while delivering nothing.

Window maths is pure and static, so overnight windows, midnight rollover and
malformed input are unit tested without WordPress. A malformed window never
blocks sending: a typo in a settings field must not silently stop all outreach.

### Integration log

Every outbound call — sequence email, WhatsApp message — records integration,
event, status, message and duration. The Reports tab shows a 24-hour health
table plus the ten most recent failures. Table trimming runs on a 1-in-50
sampled write so logging never adds a DELETE to every call.

The point is that a failing integration becomes something you can see on a
screen, rather than something you discover when a customer asks why nobody
replied.

### What was deliberately not copied from VMAI

- **The 169KB admin class.** 104 AJAX handlers and 27 render methods in one
  file is untestable and every change risks everything. Controllers here stay
  per-module.
- **Call-site tenant scoping.** VMAI has `brand_id` on its leads table and 86
  statements that never filter by it, so an agency install can leak leads
  between clients. That is not carelessness, it is what happens when scoping
  lives at the call site. Here it lives in `Repository`, where a query cannot
  forget it.
- **Estimated token accounting.** VMAI's per-brand quota adds a flat 1000
  tokens per call and resets its own TTL on every write, so the day never
  cleanly rolls over. `UsageMeter` records actual reported usage.
