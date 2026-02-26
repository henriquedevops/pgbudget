<?php
/**
 * Telegram API helpers
 */

/**
 * Call a Telegram Bot API method.
 *
 * @param string $method   e.g. 'sendMessage'
 * @param array  $payload  JSON-encodable parameters
 * @param array  $cfg      Config array (needs 'bot_token')
 * @return array|null      Decoded response or null on failure
 */
function tg_api(string $method, array $payload, array $cfg): ?array {
    $url = 'https://api.telegram.org/bot' . $cfg['bot_token'] . '/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response ? json_decode($response, true) : null;
}

/**
 * Send a text message to a Telegram chat.
 *
 * @param int    $chat_id
 * @param string $text        Supports Markdown formatting
 * @param array  $cfg
 * @param string $parse_mode  'Markdown' or 'HTML'
 */
function tg_send(int $chat_id, string $text, array $cfg, string $parse_mode = 'Markdown'): void {
    tg_api('sendMessage', [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => $parse_mode,
    ], $cfg);
}
