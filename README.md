# VM Sales OS

An enterprise AI sales, CRM, chat, knowledge and agent platform that lives inside WordPress.

This repository is **v0.2.0** of the platform described in the master spec: the core foundation
plus the operator layer (inbox, deal board, sequences, WhatsApp, reporting) and the
intelligence layer (cross-session lead memory, send governor, integration observability).
It is a working plugin, not a scaffold: install it, add one API key, and the site has an AI sales
assistant that captures leads, scores them, answers from your own content, and reports on itself.

## What works today

| Area | State |
| --- | --- |
| Service container, event bus, module registry | Working — modules toggle independently |
| Versioned migrations, 15 brand-scoped tables | Working |
| AI layer: 4 drivers, failover chain, circuit breaker, cost metering | Working |
| Tool calling with capability gating | Working |
| Chat widget + conversation store + spam guard | Working |
| CRM: contacts, deals, activities, hybrid lead scoring | Working |
| Knowledge base: chunking, embeddings, hybrid retrieval | Working |
| Agents: definitions, orchestrator with step log, hourly brain | Working |
| Automation: event → condition → queued action runtime | Runtime working, visual builder not built |
| Inbox: human takeover, replies, notes, assignment | Working |
| Deal board: kanban, drag-to-move, weighted forecast | Working |
| Sequences: triggers, waits, merge tags, AI personalisation, tracking | Working |
| Lead capture form (`[vmsos_lead_form]`) | Working |
| WhatsApp Cloud API (inbound + outbound) | Working |
| Reports: volume, capture rate, cost per lead, provider reliability | Working |
| REST API under `vmsos/v1` | Working |
| Admin app (dashboard, inbox, leads, deals, sequences, reports, knowledge, agents) | Working |
| Lead memory: durable per-contact record injected into every prompt | Working |
| Send governor: quiet hours + per-channel daily caps | Working |
| Integration log: outbound health and failures | Working |
| Lead detail: edit, timeline, remembered context, delete | Working |
| Unit tests (chunker, spam guard, automation rules, send governor) | Working — `composer test` |
| Voice, Telegram/Messenger, visual builder, billing, marketplace | Not started — see `docs/ROADMAP.md` |

## Install

1. Copy the `vm-sales-os` folder into `wp-content/plugins/`.
2. Activate it. Tables are created on activation and on any version bump.
3. Go to **Sales OS → Settings**, set the failover order and add at least one key.
4. Paste what your business does into "What the business does" — the assistant will not guess.
5. Add knowledge under **Sales OS → Knowledge**, or click "Import pages and posts".

Keys can also live in `wp-config.php`, which always wins over the database:

```php
define( 'VMSOS_AI_OPENAI_KEY', '...' );
define( 'VMSOS_AI_ANTHROPIC_KEY', '...' );
```

## Providers

One driver covers every OpenAI-shaped endpoint. Set the base URL and it works:

| Target | Base URL |
| --- | --- |
| OpenAI | `https://api.openai.com/v1` |
| OpenRouter | `https://openrouter.ai/api/v1` |
| DeepSeek / Groq / Mistral / Together | their `/v1` host |
| Ollama, vLLM, LM Studio (self-hosted, no key) | `http://127.0.0.1:11434/v1` |

Anthropic and Gemini have native drivers. AI Puffer (AI Power / AIPKit) uses its own REST API
at `{site}/wp-json/aipkit/v1` with a bearer key — set the site URL and, optionally, a bot ID.

## Requirements

PHP 8.1+, WordPress 6.4+, MySQL 5.7+ or MariaDB 10.3+. Redis or another persistent object cache is
strongly recommended: query-embedding caching and rate limiting both use it.

## Hooks

```php
add_filter( 'vmsos_current_brand_id', fn() => 3 );          // multi-tenant scoping
add_filter( 'vmsos_ai_chain', fn( $chain ) => [ 'ollama' ] ); // force local models
add_filter( 'vmsos_prompt_sales_agent', fn( $t ) => $t . '...' );
add_action( 'vmsos_register_ai_providers', fn( $m ) => $m->register( new MyProvider() ) );
add_action( 'vmsos_event_contact.hot', fn( $p ) => notify( $p['contact_id'] ) );
```

## Licence

GPL-2.0-or-later.


## Two things to set up before sending email

1. **Sender address.** Sales OS → Settings → Email. Use an address on your own
   domain with SPF and DKIM configured. Without that, sequence mail goes to spam
   and the open rates in Reports will be meaningless.
2. **Consent.** By default only contacts who ticked a consent box are emailed.
   Turn that off only if you have another lawful basis for contacting them.

## WhatsApp

Sales OS → Settings → WhatsApp. You need a Meta Cloud API phone number ID, an
access token, a verify token you invent, and the app secret. Point Meta's
webhook at the URL shown under the app secret field. Inbound messages become
ordinary conversations — the assistant answers them, the inbox can take them
over, and replies go back out over WhatsApp automatically.

Payloads are rejected unless the app secret is set and the signature matches.
This is intentional: an unauthenticated webhook that starts conversations is an
open invitation to burn your AI budget.

## Cron

Two schedules do the work. `vmsos_queue_tick` drains the job queue and
`vmsos_sequences_tick` advances sequences, both every minute. WordPress cron
only runs when someone visits the site, so on a low-traffic install replace it
with a real cron entry:

```
* * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null
```

...and set `define( 'DISABLE_WP_CRON', true );` in `wp-config.php`.
