<?php
/**
 * Claude-based natural language parser for pgbudget Telegram bot.
 * Detects intent (new_event / record_transaction / mark_realized / unknown)
 * and extracts the relevant fields in one API call.
 *
 * Also provides file-based conversation state helpers (10-minute TTL).
 */

/**
 * Parse a free-text message and detect intent + extract fields using Claude Haiku.
 *
 * @param string $user_msg   Current message from the user
 * @param array  $history    Previous exchanges: [['user'=>..., 'bot'=>...], ...]
 * @param string $today      Current date as YYYY-MM-DD
 * @param array  $cfg        Config array (needs 'claude_api_key', 'claude_model')
 * @param array  $categories Available categories: [['uuid'=>..., 'name'=>...], ...]
 * @return array  {intent, new_event|null, transaction|null, realization|null, category_change|null}
 *                or ['error' => '...'] on failure.
 */
function tg_parse_message(string $user_msg, array $history, string $today, array $cfg, array $categories = []): array {
    $category_block = '';
    if (!empty($categories)) {
        $lines = [];
        foreach ($categories as $cat) {
            $lines[] = '  - ' . $cat['name'] . ' (uuid: ' . $cat['uuid'] . ')';
        }
        $category_list  = implode("\n", $lines);
        $category_block = <<<CAT

AVAILABLE CATEGORIES for transaction.category_uuid (outflow transactions only):
{$category_list}
Pick the UUID of the best-matching category based on the description/merchant.
Use null if inflow, if it is a transfer between accounts, or if no category fits well.
CAT;
    }

    $system = <<<PROMPT
You are a financial assistant for a budget app. Today is {$today}.

Determine the user's INTENT and extract the relevant data.

INTENTS:
1. new_event — Adding a FUTURE or recurring planned income/expense to the budget projection.
   Examples: "conta de luz 180 dia 10 de março", "salário 5000 dia 5 todo mês", "IPTU 1200 em julho"

2. record_transaction — Recording a financial movement that ALREADY HAPPENED or is happening today.
   Keywords: paguei, comprei, recebi, vendi, gastei, transferi, saquei.
   Examples: "paguei Netflix 55,90", "recebi aluguel 2000 hoje", "comprei no nubank 150"

3. mark_realized — Marking a PREVIOUSLY PLANNED event in the projection as done/received.
   Keywords: pago, já recebi, já paguei, caiu, realizei, chegou, confirmar.
   Examples: "pago conta de luz de março", "salário caiu", "recebi o aluguel de fevereiro"

4. change_category — Correcting the category of the last recorded transaction.
   Keywords: muda categoria, categoriza como, era, na verdade é, categoria errada.
   Examples: "muda categoria para restaurante", "era combustível", "categoriza como saúde"

5. unknown — Intent cannot be determined.

EXTRACTION RULES:
- Dates: "amanhã"=tomorrow, "hoje"=today, "dia 15"=15th of current/next month,
  "próxima sexta"=next Friday, "semana que vem"=next Monday.
- Portuguese months: janeiro=01, fevereiro=02, março=03, abril=04, maio=05,
  junho=06, julho=07, agosto=08, setembro=09, outubro=10, novembro=11, dezembro=12.
- Direction: expenses/bills/pagamentos/despesa → outflow; salary/income/receita → inflow.
- Frequency: "todo mês"/"mensal" → monthly; "todo ano"/"anual" → annual;
  "semestral"/"a cada 6 meses" → semiannual; default → one_time.
- account_hint: extract institution keyword if mentioned
  (nubank, santander, caixa, samsung, picpay, elo, mercado pago, inter, itau, bradesco).
- If a required field for the intent is missing, set "clarify" to a single short
  question in the same language as the user. Otherwise set "clarify" to null.
{$category_block}
Return ONLY a raw JSON object — no prose, no markdown, no code fences.

Schema:
{
  "intent": "new_event"|"record_transaction"|"mark_realized"|"change_category"|"unknown",
  "new_event": {
    "name": string|null,
    "amount_reais": number|null,
    "event_date": "YYYY-MM-DD"|null,
    "direction": "inflow"|"outflow"|null,
    "frequency": "one_time"|"monthly"|"annual"|"semiannual"|null,
    "recurrence_end_date": "YYYY-MM-DD"|null,
    "clarify": string|null
  }|null,
  "transaction": {
    "description": string|null,
    "amount_reais": number|null,
    "direction": "inflow"|"outflow"|null,
    "date": "YYYY-MM-DD"|null,
    "account_hint": string|null,
    "category_uuid": string|null,
    "clarify": string|null
  }|null,
  "realization": {
    "event_name": string|null,
    "month": "YYYY-MM-01"|null,
    "clarify": string|null
  }|null,
  "category_change": {
    "category_name": string|null,
    "clarify": string|null
  }|null
}
PROMPT;

    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => 'user',      'content' => $h['user']];
        $messages[] = ['role' => 'assistant', 'content' => $h['bot']];
    }
    $messages[] = ['role' => 'user', 'content' => $user_msg];

    $payload = [
        'model'      => $cfg['claude_model'],
        'max_tokens' => 512,
        'system'     => $system,
        'messages'   => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $cfg['claude_api_key'],
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $http_code !== 200) {
        return ['error' => 'Claude API error (HTTP ' . $http_code . '): ' . $response];
    }

    $body    = json_decode($response, true);
    $content = trim($body['content'][0]['text'] ?? '');

    // Strip markdown code fences Claude sometimes wraps the JSON in
    $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
    $content = preg_replace('/\s*```$/m', '', $content);
    $content = trim($content);

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return ['error' => 'Could not parse Claude response: ' . $content];
    }

    return $parsed;
}

/**
 * Extract transaction data from a receipt image using Claude vision.
 *
 * @param string      $image_b64  Base64-encoded image bytes
 * @param string      $media_type MIME type (e.g. 'image/jpeg')
 * @param string|null $caption    Optional caption the user sent with the photo
 * @param string      $today      Current date as YYYY-MM-DD
 * @param array       $cfg        Config array (needs 'claude_api_key', 'claude_model')
 * @param array       $categories Available categories: [['uuid'=>..., 'name'=>...], ...]
 * @return array  {transaction: {...}} or ['error' => '...'] on failure
 */
function tg_parse_receipt_image(string $image_b64, string $media_type, ?string $caption, string $today, array $cfg, array $categories = []): array {
    $category_block = '';
    if (!empty($categories)) {
        $lines = [];
        foreach ($categories as $cat) {
            $lines[] = '  - ' . $cat['name'] . ' (uuid: ' . $cat['uuid'] . ')';
        }
        $category_list  = implode("\n", $lines);
        $category_block = <<<CAT

AVAILABLE CATEGORIES for transaction.category_uuid:
{$category_list}
Pick the UUID that best matches the merchant/product. Use null if unclear or for refunds.
CAT;
    }

    $system = <<<PROMPT
You are a financial assistant analyzing a receipt image for a budget app. Today is {$today}.

Extract transaction data from the receipt.

RULES:
- description: merchant or store name — keep it short and clear.
- amount_reais: the TOTAL amount paid (decimal number, no currency symbol).
  Use the grand total, not subtotals or partial amounts.
- date: date printed on the receipt as YYYY-MM-DD; if absent or illegible use today ({$today}).
- direction: "outflow" for a purchase/payment; "inflow" for a refund or credit receipt.
- account_hint: payment method shown on the receipt — extract any of:
  (pix, débito, crédito, nubank, santander, caixa, samsung, picpay, elo, mercado pago, inter, itaú, bradesco).
  Return null if no payment method is visible.
- clarify: if this is not a receipt or the total cannot be read, set to a short question in Portuguese.
  Otherwise null.
{$category_block}
Return ONLY a raw JSON object — no prose, no markdown, no code fences.

Schema:
{
  "transaction": {
    "description":   string|null,
    "amount_reais":  number|null,
    "direction":     "inflow"|"outflow"|null,
    "date":          "YYYY-MM-DD"|null,
    "account_hint":  string|null,
    "category_uuid": string|null,
    "clarify":       string|null
  }
}
PROMPT;

    $content = [
        [
            'type'   => 'image',
            'source' => [
                'type'       => 'base64',
                'media_type' => $media_type,
                'data'       => $image_b64,
            ],
        ],
        [
            'type' => 'text',
            'text' => $caption
                ? 'Legenda do usuário: ' . $caption . "\nExtraia os dados de transação deste recibo."
                : 'Extraia os dados de transação deste recibo.',
        ],
    ];

    $payload = [
        'model'      => $cfg['claude_model'],
        'max_tokens' => 384,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $cfg['claude_api_key'],
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $http_code !== 200) {
        return ['error' => 'Claude API error (HTTP ' . $http_code . '): ' . $response];
    }

    $body    = json_decode($response, true);
    $raw     = trim($body['content'][0]['text'] ?? '');
    $raw     = preg_replace('/^```(?:json)?\s*/m', '', $raw);
    $raw     = preg_replace('/\s*```$/m', '',         $raw);
    $parsed  = json_decode(trim($raw), true);

    if (!is_array($parsed)) {
        return ['error' => 'Could not parse Claude response: ' . $raw];
    }

    return $parsed;
}

/**
 * Transcribe a voice/audio file using Groq Whisper.
 *
 * @param string $file_path  Local filesystem path to the audio file (ogg, mp3, etc.)
 * @param array  $cfg        Config array (needs 'groq_api_key')
 * @return string|null       Transcribed text, or null on failure
 */
function tg_transcribe_audio(string $file_path, array $cfg): ?string {
    if (empty($cfg['groq_api_key'])) return null;

    $curl_file = new CURLFile($file_path, 'audio/ogg', basename($file_path));

    $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'            => $curl_file,
            'model'           => 'whisper-large-v3-turbo',
            'response_format' => 'text',
            // No 'language' param → Whisper auto-detects (PT and EN both work)
        ],
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['groq_api_key'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $http_code !== 200) {
        error_log('pgbudget Telegram Whisper error (HTTP ' . $http_code . '): ' . $response);
        return null;
    }

    return trim($response) ?: null;
}

// ---------------------------------------------------------------------------
// Conversation state — file-based, per chat_id, 10-minute TTL
// ---------------------------------------------------------------------------

function _tg_state_path(int $chat_id): string {
    return sys_get_temp_dir() . '/pgbudget_tg_' . $chat_id . '.json';
}

/** Load stored conversation context (empty array if none / expired). */
function tg_state_load(int $chat_id): array {
    $path = _tg_state_path($chat_id);
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    if (!$data || (time() - ($data['ts'] ?? 0)) > 600) return [];
    return $data['context'] ?? [];
}

/** Persist conversation context (at most last 2 exchanges). */
function tg_state_save(int $chat_id, array $context): void {
    file_put_contents(
        _tg_state_path($chat_id),
        json_encode(['ts' => time(), 'context' => array_slice($context, -2)])
    );
}

/** Delete stored state (call after successful action or a /command). */
function tg_state_clear(int $chat_id): void {
    $path = _tg_state_path($chat_id);
    if (file_exists($path)) unlink($path);
}

// ---------------------------------------------------------------------------
// Last-action tracking — for /undo (1-hour TTL)
// ---------------------------------------------------------------------------

/** Save the last bot action so /undo can reverse it. */
function tg_action_save(int $chat_id, string $type, string $uuid, string $label, string $matched_event_uuid = '', array $extras = []): void {
    $data = ['type' => $type, 'uuid' => $uuid, 'label' => $label, 'ts' => time()];
    if ($matched_event_uuid !== '') {
        $data['matched_event_uuid'] = $matched_event_uuid;
    }
    foreach ($extras as $k => $v) {
        $data[$k] = $v;
    }
    file_put_contents(
        sys_get_temp_dir() . '/pgbudget_tg_action_' . $chat_id . '.json',
        json_encode($data)
    );
}

/** Load the last action (null if none or expired after 1 hour). */
function tg_action_load(int $chat_id): ?array {
    $path = sys_get_temp_dir() . '/pgbudget_tg_action_' . $chat_id . '.json';
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    if (!$data || (time() - ($data['ts'] ?? 0)) > 3600) return null;
    return $data;
}

/** Clear the last action record. */
function tg_action_clear(int $chat_id): void {
    $path = sys_get_temp_dir() . '/pgbudget_tg_action_' . $chat_id . '.json';
    if (file_exists($path)) unlink($path);
}

// ---------------------------------------------------------------------------
// Ledger selection — persistent (no TTL), per chat_id
// ---------------------------------------------------------------------------

/** Get the active ledger UUID for a chat (falls back to config default). */
function tg_ledger_get(int $chat_id, array $user): string {
    $path = sys_get_temp_dir() . '/pgbudget_tg_ledger_' . $chat_id . '.json';
    if (file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        if (!empty($data['ledger_uuid'])) return $data['ledger_uuid'];
    }
    return $user['ledger_uuid'];
}

/** Persist the selected ledger UUID for a chat. */
function tg_ledger_set(int $chat_id, string $ledger_uuid): void {
    file_put_contents(
        sys_get_temp_dir() . '/pgbudget_tg_ledger_' . $chat_id . '.json',
        json_encode(['ledger_uuid' => $ledger_uuid, 'ts' => time()])
    );
}
