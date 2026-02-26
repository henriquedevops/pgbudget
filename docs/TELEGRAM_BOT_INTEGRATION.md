# Telegram Bot Integration Plan

## Overview

A Telegram bot that lets users interact with pgbudget via natural language messages.
Priority feature: **create projected events** by typing a free-form message.

### Core Flow

```
User (Telegram) → Webhook POST → PHP handler → Claude Haiku (NLP) → pgbudget DB → Reply
```

- **Transport**: Telegram webhook (HTTPS POST to our endpoint)
- **NLP**: Claude API (`claude-haiku-4-5-20251001`) parses free text → structured event fields
- **Auth**: `config/telegram.php` maps Telegram `chat_id` → pgbudget `user_id` + default `ledger_uuid`
- **DB access**: same `getDbConnection()` + `set_config('app.current_user_id', ...)` pattern as existing PHP pages
- **No session/state required** for single-turn commands; last 2 messages included for clarification turns

---

## Files

| File | Purpose |
|------|---------|
| `config/telegram.php` | Bot token, webhook secret, Claude API key, `chat_id → user` mapping — **gitignored** |
| `config/telegram.example.php` | Template committed to git with placeholder values |
| `public/telegram/webhook.php` | Entry point: verify secret, parse update, route to handlers |
| `includes/telegram.php` | `tg_send(chat_id, text)` and `tg_api(method, payload)` helpers |
| `includes/telegram-parser.php` | Claude API call → extracts projected event fields from free text |

---

## Priority 1 — New Projected Event

### User Input Examples

```
conta de luz 180 reais dia 10 de março
add electricity bill R$180 on March 10
receber salário 5000 dia 5 todo mês
IPTU 1200 anual em julho
```

### NLP Extraction (Claude Haiku)

**System prompt:**

```
You are a financial event extractor for a budget app. Today is {DATE}.

Extract from the user's message:
  - name         (string, short description)
  - amount_reais (float, always positive)
  - event_date   (YYYY-MM-DD)
  - direction    ("inflow" or "outflow")
  - frequency    ("one_time" | "monthly" | "annual" | "semiannual")
  - recurrence_end_date (YYYY-MM-DD or null)

Rules:
  - Infer direction: bills/expenses/pagamentos → outflow; salary/income/recebimento → inflow
  - "todo mês" / "mensal" / "monthly" → monthly
  - "todo ano" / "anual" / "annual" → annual
  - "semestral" / "semiannual" → semiannual
  - Default frequency: one_time
  - Relative dates: "amanhã" = tomorrow, "semana que vem" = next Monday,
    "dia 15" = 15th of current month (or next month if already past)
  - Portuguese month names: janeiro=01, fevereiro=02, março=03, abril=04, maio=05,
    junho=06, julho=07, agosto=08, setembro=09, outubro=10, novembro=11, dezembro=12
  - Return ONLY a JSON object, no prose.
  - If any required field (name, amount_reais, event_date, direction) cannot be
    determined from the message, set ALL extracted fields to null and set
    "clarify" to a single short question in the same language as the user's message.
  - If all required fields are present, set "clarify" to null.

Output schema:
{
  "name": string | null,
  "amount_reais": number | null,
  "event_date": "YYYY-MM-DD" | null,
  "direction": "inflow" | "outflow" | null,
  "frequency": "one_time" | "monthly" | "annual" | "semiannual" | null,
  "recurrence_end_date": "YYYY-MM-DD" | null,
  "clarify": string | null
}
```

**Context passed to Claude:** the user's current message plus the previous bot exchange (last 2 messages) so that a clarifying answer (e.g. "outflow") is understood in context.

### Successful Response

```
✅ Evento criado: Conta de Luz
💸 R$ 180,00 · saída · 10 mar 2026 · único

Ver projeção: https://…/pgbudget/reports/cash-flow-projection.php?ledger=eNF2EkfD
```

### Clarification Turn

If Claude returns `clarify`:
```
Bot: "Entrada ou saída? (ex: receita / despesa)"
User: "despesa"
→ bot re-calls Claude with original message + this exchange as context → creates event
```

### DB Call (maps to existing API)

```php
$stmt = $db->prepare("
    SELECT * FROM api.create_projected_event(
        p_ledger_uuid := ?,
        p_name        := ?,
        p_amount      := ?::bigint,
        p_event_date  := ?::date,
        p_direction   := ?,
        p_frequency   := ?,
        p_recurrence_end_date := ?::date
    )
");
$stmt->execute([
    $ledger_uuid,
    $parsed['name'],
    (int) round($parsed['amount_reais'] * 100),  // reais → cents
    $parsed['event_date'],
    $parsed['direction'],
    $parsed['frequency'] ?? 'one_time',
    $parsed['recurrence_end_date'],              // nullable
]);
```

---

## Commands (Phase 1 MVP)

| User Input | Behaviour |
|------------|-----------|
| `/start` | Welcome message + link to web app |
| `/help` | Usage examples in PT and EN |
| `/list` | Upcoming projected events (next 30 days) from `api.projected_events` |
| Any free text | Parse as new projected event → create or ask one clarifying question |

---

## Configuration (`config/telegram.php`)

```php
<?php
return [
    'bot_token'      => 'BOT_TOKEN_HERE',
    'webhook_secret' => 'RANDOM_SECRET_TOKEN_HERE',  // verified via X-Telegram-Bot-Api-Secret-Token header
    'claude_api_key' => 'sk-ant-...',
    'claude_model'   => 'claude-haiku-4-5-20251001',

    // Map Telegram chat_id → pgbudget user context
    'users' => [
        123456789 => [
            'user_id'     => 1,           // data.users.id (for set_config)
            'user_data'   => 'm43str0',   // RLS identity
            'ledger_uuid' => 'eNF2EkfD',  // default ledger
        ],
    ],
];
```

This file is **gitignored**. The committed `config/telegram.example.php` contains the same structure with placeholder values.

---

## Security

| Concern | Mitigation |
|---------|-----------|
| Spoofed webhook calls | Verify `X-Telegram-Bot-Api-Secret-Token` header matches config value; reject with 403 if missing/wrong |
| Unauthorized users | Only `chat_id` values present in `config.users` get any response; all others are silently ignored (HTTP 200 + no reply) |
| Prompt injection via message text | Claude output is parsed as JSON and field values are bound via PDO parameters — never interpolated into SQL or HTML |
| Credentials in git | `config/telegram.php` is gitignored; only the `.example.php` template is committed |

---

## Webhook Setup (one-time CLI command)

```bash
curl "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -d "url=https://vps60674.publiccloud.com.br/pgbudget/public/telegram/webhook.php" \
  -d "secret_token=RANDOM_SECRET_TOKEN_HERE" \
  -d "allowed_updates=[\"message\"]"
```

Verify:
```bash
curl "https://api.telegram.org/bot{TOKEN}/getWebhookInfo"
```

---

## Phase 2 — Additional Features

These are not in scope for the MVP but are the natural next steps:

### `/balance` command
Query `api.get_projection_summary` for current month + next month and reply with:
```
📊 Balanço — fevereiro 2026
  Net:        -R$ 1.200,00
  Acumulado:  +R$ 4.500,00
```

### Quick transaction recording
```
paguei Netflix 55,90
recebi aluguel 2000
```
→ parse → `api.add_transaction` with best-guess account (requires account-selection logic or a `/setaccount` command)

### Mark event as realized
```
pago conta de luz de março
```
→ fuzzy-match event name → confirm with user → `api.update_projected_event(is_realized=true)`

### Multi-ledger support
```
/setledger
```
→ bot lists available ledgers → user picks one → stored as the default for that chat_id (in a lightweight state file or the DB)

---

## Implementation Order

1. `config/telegram.example.php` + `.gitignore` entry
2. `includes/telegram.php` — `tg_send` / `tg_api`
3. `includes/telegram-parser.php` — Claude call + JSON parsing
4. `public/telegram/webhook.php` — routing, `/start`, `/help`, free-text → new event
5. Test end-to-end with a real Telegram bot in the test ledger
6. `/list` command
7. Phase 2 features
