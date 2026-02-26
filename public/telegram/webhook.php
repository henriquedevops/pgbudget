<?php
/**
 * Telegram Webhook Entry Point
 *
 * Register once with Telegram (replace TOKEN and SECRET with real values):
 *
 *   curl "https://api.telegram.org/bot{TOKEN}/setWebhook" \
 *     -d "url=https://vps60674.publiccloud.com.br/pgbudget/public/telegram/webhook.php" \
 *     -d "secret_token={SECRET}" \
 *     -d "allowed_updates=[\"message\"]"
 *
 * Verify registration:
 *   curl "https://api.telegram.org/bot{TOKEN}/getWebhookInfo"
 *
 * Copy config/telegram.example.php → config/telegram.php and fill in credentials.
 */

require_once '../../config/database.php';
require_once '../../includes/telegram.php';
require_once '../../includes/telegram-parser.php';

// Always respond 200 immediately so Telegram doesn't retry on any processing error
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

// Load bot config
$cfg_path = __DIR__ . '/../../config/telegram.php';
if (!file_exists($cfg_path)) {
    error_log('pgbudget Telegram: config/telegram.php not found');
    exit;
}
$cfg = require $cfg_path;

// Verify webhook secret (Telegram header X-Telegram-Bot-Api-Secret-Token)
$incoming_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($cfg['webhook_secret'], $incoming_secret)) {
    exit; // Silently ignore — wrong or missing secret
}

// Parse the incoming update
$body   = file_get_contents('php://input');
$update = json_decode($body, true);

if (!$update || !isset($update['message'])) {
    exit; // Not a message update (could be edited_message, etc.)
}

$msg     = $update['message'];
$chat_id = (int)($msg['chat']['id'] ?? 0);
$text    = trim($msg['text'] ?? '');

if ($chat_id === 0 || $text === '') exit;

// Authorisation — only respond to configured chat IDs
if (!isset($cfg['users'][$chat_id])) {
    exit; // Unknown user — silently ignore
}
$user = $cfg['users'][$chat_id];

// Set DB user context for RLS
try {
    $db   = getDbConnection();
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$user['user_id']]);
} catch (Exception $e) {
    error_log('pgbudget Telegram: DB connection error — ' . $e->getMessage());
    exit;
}

// Route by command vs. free text
if ($text === '/start') {
    tg_state_clear($chat_id);
    handle_start($chat_id, $cfg);
} elseif ($text === '/help') {
    tg_state_clear($chat_id);
    handle_help($chat_id, $cfg);
} elseif ($text === '/list') {
    tg_state_clear($chat_id);
    handle_list($chat_id, $user, $db, $cfg);
} elseif (str_starts_with($text, '/')) {
    tg_state_clear($chat_id);
    tg_send($chat_id, "Comando não reconhecido. Use /help para ver os comandos disponíveis.", $cfg);
} else {
    handle_new_event($chat_id, $text, $user, $db, $cfg);
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

function handle_start(int $chat_id, array $cfg): void {
    $msg = "*Olá! Sou o assistente do pgbudget.* 💰\n\n"
         . "Mande uma mensagem em português ou inglês para adicionar um evento ao seu orçamento.\n\n"
         . "*Exemplos:*\n"
         . "• conta de luz 180 reais dia 10 de março\n"
         . "• electricity bill R\$180 on March 10\n"
         . "• salário 5000 dia 5 todo mês\n"
         . "• IPTU 1200 anual em julho\n\n"
         . "Use /help para mais informações ou /list para ver seus próximos eventos.";
    tg_send($chat_id, $msg, $cfg);
}

function handle_help(int $chat_id, array $cfg): void {
    $msg = "*Comandos:*\n"
         . "/list — próximos eventos (30 dias)\n"
         . "/help — esta mensagem\n\n"
         . "*Adicionar evento (texto livre):*\n"
         . "• conta de luz 180 reais dia 10 de março\n"
         . "• electricity bill R\$180 on March 10\n"
         . "• salário 5000 dia 5 todo mês\n"
         . "• IPTU 1200 anual em julho\n"
         . "• aluguel recebido 2500 dia 1 todo mês até dezembro\n\n"
         . "*Campos reconhecidos:* nome, valor, data, tipo (entrada/saída), frequência, data de término da recorrência.";
    tg_send($chat_id, $msg, $cfg);
}

function handle_list(int $chat_id, array $user, PDO $db, array $cfg): void {
    try {
        $stmt = $db->prepare("
            SELECT name, direction, amount, event_date, frequency
            FROM api.projected_events
            WHERE ledger_uuid = ?
              AND is_realized = false
              AND event_date >= CURRENT_DATE
              AND event_date <= CURRENT_DATE + INTERVAL '30 days'
            ORDER BY event_date
            LIMIT 10
        ");
        $stmt->execute([$user['ledger_uuid']]);
        $events = $stmt->fetchAll();

        if (empty($events)) {
            tg_send($chat_id, "Nenhum evento nos próximos 30 dias.", $cfg);
            return;
        }

        $lines = ["*Próximos eventos (30 dias):*\n"];
        foreach ($events as $e) {
            $date   = (new DateTime($e['event_date']))->format('d/m/Y');
            $amount = 'R\$' . number_format(abs($e['amount']) / 100, 2, ',', '.');
            $icon   = $e['direction'] === 'inflow' ? '📈' : '📉';
            $lines[] = "{$icon} {$date} — {$e['name']} ({$amount})";
        }

        tg_send($chat_id, implode("\n", $lines), $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram /list error: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao buscar eventos. Tente novamente.", $cfg);
    }
}

function handle_new_event(int $chat_id, string $text, array $user, PDO $db, array $cfg): void {
    $today   = date('Y-m-d');
    $history = tg_state_load($chat_id);

    // Call Claude to extract event fields
    $parsed = tg_parse_event($text, $history, $today, $cfg);

    if (isset($parsed['error'])) {
        error_log('pgbudget Telegram parser error: ' . $parsed['error']);
        tg_send($chat_id, "Desculpe, tive um problema ao interpretar sua mensagem. Pode tentar de novo?", $cfg);
        return;
    }

    // Claude needs more information — ask the clarifying question and save context
    if (!empty($parsed['clarify'])) {
        $history[] = ['user' => $text, 'bot' => $parsed['clarify']];
        tg_state_save($chat_id, $history);
        tg_send($chat_id, $parsed['clarify'], $cfg);
        return;
    }

    // Validate all required fields are present
    foreach (['name', 'amount_reais', 'event_date', 'direction'] as $field) {
        if (empty($parsed[$field])) {
            tg_send($chat_id, "Não consegui identificar o *{$field}*. Pode reformular a mensagem?", $cfg);
            return;
        }
    }

    $amount_cents        = (int) round((float)$parsed['amount_reais'] * 100);
    $frequency           = $parsed['frequency'] ?? 'one_time';
    $recurrence_end_date = !empty($parsed['recurrence_end_date']) ? $parsed['recurrence_end_date'] : null;

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.create_projected_event(
                p_ledger_uuid         := ?,
                p_name                := ?,
                p_amount              := ?::bigint,
                p_event_date          := ?::date,
                p_direction           := ?,
                p_frequency           := ?,
                p_recurrence_end_date := ?::date
            )
        ");
        $stmt->execute([
            $user['ledger_uuid'],
            $parsed['name'],
            $amount_cents,
            $parsed['event_date'],
            $parsed['direction'],
            $frequency,
            $recurrence_end_date,
        ]);

        $result = $stmt->fetch();
        if (!$result) {
            throw new Exception('api.create_projected_event returned no rows');
        }

        tg_state_clear($chat_id);

        // Build confirmation message
        $amount_fmt = 'R\$' . number_format($amount_cents / 100, 2, ',', '.');
        $date_fmt   = (new DateTime($parsed['event_date']))->format('d/m/Y');
        $dir_label  = $parsed['direction'] === 'inflow' ? 'entrada 📈' : 'saída 📉';
        $freq_map   = [
            'one_time'   => 'único',
            'monthly'    => 'mensal',
            'annual'     => 'anual',
            'semiannual' => 'semestral',
        ];
        $freq_label = $freq_map[$frequency] ?? $frequency;

        $reply = "✅ *Evento criado:* {$result['name']}\n"
               . "{$amount_fmt} · {$dir_label} · {$date_fmt} · {$freq_label}";

        if ($recurrence_end_date) {
            $end_fmt = (new DateTime($recurrence_end_date))->format('d/m/Y');
            $reply  .= " (até {$end_fmt})";
        }

        tg_send($chat_id, $reply, $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram create event error: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao criar o evento. Verifique os dados e tente novamente.", $cfg);
    }
}
