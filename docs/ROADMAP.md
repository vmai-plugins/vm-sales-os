# Roadmap

The master spec lists roughly 500 features across 20 domains. Building all of it at once produces a
demo that impresses and then breaks. This is the delivery order, chosen so each phase ships something
usable on its own and every later phase plugs into the core already built.

## Phase 0 — Core (shipped, v0.1.0)

Container, event bus, modules, migrations, brand scoping, queue, REST, admin shell, AI failover
layer, tool registry, chat + widget, CRM + scoring, knowledge + RAG, agents + orchestrator, hourly
brain. **This is the load-bearing part.** Everything below is a module that hangs off it.

## Phase 1 — Make the core earn its keep

- Conversation inbox with human takeover and reply-from-admin
- Deal board (drag between stages), quotes with INR formatting
- Email channel: send via `wp_mail`/SMTP, inbound parsing, sequences with stop-on-reply
- Automation visual builder over the existing rule runtime
- WooCommerce: product knowledge sync, order context in chat, abandoned-cart trigger
- CSV import/export, GDPR export and erase

## Phase 2 — Channels

- WhatsApp: Cloud API and self-hosted gateway (WAHA / Evolution) behind one channel interface
- Telegram, Messenger, Instagram through the same interface
- Unified inbox across channels
- Send governor: quiet hours, per-channel daily caps, cross-channel failover

## Phase 3 — Voice

- Speech-to-text and text-to-speech behind provider interfaces (self-hosted Piper/Vosk supported)
- Asterisk ARI bridge for inbound and outbound calls
- Call recording, disposition capture, transcript into the contact timeline

## Phase 4 — Scale and intelligence

- Vector store adapter (pgvector / Qdrant) swapped in behind the existing retriever interface
- Streaming replies over SSE
- Forecasting, churn and CLV models over the activity ledger
- Session replay and funnel analytics

## Phase 5 — Commercial

- White label, agency mode, per-brand billing
- Licence server, update channel, extension SDK, marketplace
- Multi-region deployment, Docker compose, CI/CD, health telemetry

## Deliverables from the master prompt

| # | Deliverable | State |
| --- | --- | --- |
| 1–3 | Architecture, folder structure, database schema | Done — see `docs/ARCHITECTURE.md` and `src/Database/Schema.php` |
| 4 | REST API specification | Routes live under `vmsos/v1`; written spec pending |
| 5 | GraphQL schema | Phase 4 |
| 6 | AI architecture | Done |
| 7 | CRM architecture | Core done; deals UI in Phase 1 |
| 8 | Marketing automation | Runtime done; builder in Phase 1 |
| 9 | Chat architecture | Done |
| 10 | WhatsApp | Phase 2 |
| 11 | Voice | Phase 3 |
| 12 | Queue system | Done |
| 13 | Security architecture | Done — see Security posture |
| 14–15 | Deployment, Docker | Phase 5 |
| 16–17 | Testing, CI/CD | Harness scaffolded in `composer.json`; suites pending |
| 18 | Coding standards | `phpcs.xml.dist` |
| 19–20 | UI design system, wireframes | Admin design tokens in `assets/css/admin.css` |
| 21–23 | API, developer, user docs | README + architecture; full set pending |
| 24 | Upgrade and migration system | Done |
| 25–27 | SDK, licensing, marketplace | Phase 5 |
| 28–29 | Performance, scalability | Indexing and caching done; adapters in Phase 4 |
| 30 | Implementation plan | This document |

## One caveat worth stating plainly

The spec asks for a plugin that replaces Intercom, Zendesk, HubSpot, GoHighLevel and a dozen others.
Each of those is a company. What a single WordPress plugin can genuinely be is the *AI sales layer*
for a WordPress business: it knows the site's content, captures and scores leads, talks on the
channels the business already uses, and hands humans the context. That is the product this codebase
is built to become, and it is a better product than a thin imitation of all twelve.

---

## Shipped in 0.2.0

- **Inbox with human takeover** — filtered thread list with live counts, full
  transcript, take over / give back to AI, assignment, internal notes, close
  and reopen. Handover alerts by email, throttled to one per thread per hour.
- **Visitor-side delivery** — public polling endpoint plus widget polling, so a
  human reply lands in the visitor's open chat without a refresh.
- **Deal board** — kanban over the existing pipeline with drag-and-drop stage
  moves, stage probability applied on move, weighted forecast and 30-day
  won/lost, activity logged on every move.
- **Email sequences** — sequences, steps with per-step waits, enrolment
  triggered by event or by hand, merge tags, opt-in AI personalisation per
  step, queued sending, open/click/unsubscribe tracking, stop-on-engagement,
  and a send-test-to-me button.
- **Lead capture form** — `[vmsos_lead_form]` shortcode with honeypot and
  throttling, feeding the same contacts table and scoring path as chat.
- **WhatsApp channel** — Cloud API webhook with signature verification,
  inbound messages become normal conversations, outbound human replies route
  back automatically.
- **Reports** — conversations, leads and AI spend per day, capture rate, cost
  per lead, provider reliability table, email engagement totals.
- **Unit tests** — chunker, spam guard and automation rule matching, runnable
  without a WordPress install.

Still open from the original 30 deliverables: voice/Asterisk, Telegram and
Messenger, a visual automation builder, WooCommerce, pgvector, SSE streaming,
GraphQL, white-label licensing and the marketplace. The honest summary from
the first release still stands — this is the AI sales layer for a WordPress
business, not a replacement for twelve companies.

---

## Shipped in 0.3.0

- **Lead memory** — durable per-contact record (summary, facts, preferences,
  objections, buying stage, next best action), injected into every future
  prompt on any channel, refreshed on the queue every N replies and again when
  a conversation closes. Visible on the lead detail screen.
- **Send governor** — quiet hours and per-channel daily caps for marketing
  sends only, with postpone-not-drop semantics and cap accounting on success.
- **Integration log** — outbound observability for email and WhatsApp, 24h
  health table and recent failures in Reports, self-trimming.
- **Lead detail screen** — edit name, email, phone, stage and requirements;
  timeline; remembered context; delete.
- **Send budget panel** in Reports so today's remaining allowance is visible
  before a campaign starts.
- **13 more unit tests** covering window maths, midnight rollover, cap
  behaviour and malformed configuration.

Still open: inbound email reply handling, voice/Asterisk, Telegram and
Messenger, visual automation builder, WooCommerce, pgvector, SSE streaming,
GraphQL, white-label licensing, marketplace, integration tests.
