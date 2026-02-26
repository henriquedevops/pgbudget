<?php
/**
 * Claude-based natural language parser for projected event creation.
 * Also provides file-based conversation state helpers (10-minute TTL).
 */

/**
 * Parse a free-text message into projected event fields using Claude Haiku.
 *
 * @param string $user_msg  Current message from the user
 * @param array  $history   Previous exchanges: [['user'=>..., 'bot'=>...], ...]
 * @param string $today     Current date as YYYY-MM-DD
 * @param array  $cfg       Config array (needs 'claude_api_key', 'claude_model')
 * @return array  Parsed fields, or ['error' => '...'] on failure,
 *                or ['clarify' => '...'] when more info is needed.
 */
function tg_parse_event(string $user_msg, array $history, string $today, array $cfg): array {
    $system = <<<PROMPT
You are a financial event extractor for a budget app. Today is {$today}.

Extract from the user's message:
  - name         (string, short description in the same language as the message)
  - amount_reais (float, always positive)
  - event_date   (YYYY-MM-DD)
  - direction    ("inflow" or "outflow")
  - frequency    ("one_time" | "monthly" | "annual" | "semiannual")
  - recurrence_end_date (YYYY-MM-DD or null)

Rules:
  - Infer direction from context: bills/expenses/pagamentos/despesa → outflow;
    salary/income/recebimento/receita/salário → inflow.
  - "todo mês" / "mensal" / "monthly" → monthly
  - "todo ano" / "anual" / "annual" → annual
  - "semestral" / "semiannual" / "a cada 6 meses" → semiannual
  - Default frequency if not mentioned: one_time
  - Relative dates: "amanhã" = tomorrow, "semana que vem" = next Monday,
    "dia 15" = 15th of current month (or next month if that day is already past),
    "próxima sexta" = next Friday.
  - Portuguese month names: janeiro=01, fevereiro=02, março=03, abril=04,
    maio=05, junho=06, julho=07, agosto=08, setembro=09, outubro=10,
    novembro=11, dezembro=12.
  - Return ONLY a JSON object, no prose, no markdown fences.
  - If any required field (name, amount_reais, event_date, direction) cannot be
    determined from the conversation, set ALL extracted fields to null and set
    "clarify" to a single short question in the same language as the user's message.
  - If all required fields are present, set "clarify" to null.

Output schema (strict JSON):
{"name":string|null,"amount_reais":number|null,"event_date":"YYYY-MM-DD"|null,"direction":"inflow"|"outflow"|null,"frequency":"one_time"|"monthly"|"annual"|"semiannual"|null,"recurrence_end_date":"YYYY-MM-DD"|null,"clarify":string|null}
PROMPT;

    // Build messages array with conversation history for context
    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => 'user',      'content' => $h['user']];
        $messages[] = ['role' => 'assistant', 'content' => $h['bot']];
    }
    $messages[] = ['role' => 'user', 'content' => $user_msg];

    $payload = [
        'model'      => $cfg['claude_model'],
        'max_tokens' => 256,
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

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return ['error' => 'Could not parse Claude response: ' . $content];
    }

    return $parsed;
}

// ---------------------------------------------------------------------------
// Conversation state — file-based, per chat_id, 10-minute TTL
// ---------------------------------------------------------------------------

function _tg_state_path(int $chat_id): string {
    return sys_get_temp_dir() . '/pgbudget_tg_' . $chat_id . '.json';
}

/**
 * Load the stored conversation context for a chat (empty array if none / expired).
 */
function tg_state_load(int $chat_id): array {
    $path = _tg_state_path($chat_id);
    if (!file_exists($path)) return [];

    $data = json_decode(file_get_contents($path), true);
    if (!$data || (time() - ($data['ts'] ?? 0)) > 600) return [];  // 10-min TTL

    return $data['context'] ?? [];
}

/**
 * Persist the conversation context (at most last 2 exchanges).
 */
function tg_state_save(int $chat_id, array $context): void {
    file_put_contents(
        _tg_state_path($chat_id),
        json_encode(['ts' => time(), 'context' => array_slice($context, -2)])
    );
}

/**
 * Delete stored state (call after a successful event creation or a /command).
 */
function tg_state_clear(int $chat_id): void {
    $path = _tg_state_path($chat_id);
    if (file_exists($path)) unlink($path);
}
