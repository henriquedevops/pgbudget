<?php
/**
 * Telegram Webhook — pgbudget bot
 *
 * Phase 1: /start, /help, /list, free-text → new projected event
 * Phase 2: /balance, record transaction, mark event as realized
 *
 * Register webhook:
 *   curl "https://api.telegram.org/bot{TOKEN}/setWebhook" \
 *     -F "url=https://{SERVER_IP}/pgbudget/telegram/webhook.php" \
 *     -F "certificate=@/etc/ssl/telegram-bot.pem" \
 *     -F "secret_token={WEBHOOK_SECRET}" \
 *     -F "allowed_updates=[\"message\"]"
 */

require_once '../../config/database.php';
require_once '../../includes/telegram.php';
require_once '../../includes/telegram-parser.php';

// Always respond 200 immediately so Telegram doesn't retry
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

// Load config
$cfg_path = __DIR__ . '/../../config/telegram.php';
if (!file_exists($cfg_path)) {
    error_log('pgbudget Telegram: config/telegram.php not found');
    exit;
}
$cfg = require $cfg_path;

// Verify webhook secret
$incoming_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($cfg['webhook_secret'], $incoming_secret)) exit;

// Parse update
$body   = file_get_contents('php://input');
$update = json_decode($body, true);
if (!$update || !isset($update['message'])) exit;

$msg     = $update['message'];
$chat_id = (int)($msg['chat']['id'] ?? 0);
$text    = trim($msg['text'] ?? '');
if ($chat_id === 0 || $text === '') exit;

// Auth
if (!isset($cfg['users'][$chat_id])) exit;
$user = $cfg['users'][$chat_id];

// DB
try {
    $db   = getDbConnection();
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$user['user_id']]);
} catch (Exception $e) {
    error_log('pgbudget Telegram: DB error — ' . $e->getMessage());
    exit;
}

// Route
if ($text === '/start') {
    tg_state_clear($chat_id);
    handle_start($chat_id, $cfg);
} elseif ($text === '/help') {
    tg_state_clear($chat_id);
    handle_help($chat_id, $cfg);
} elseif ($text === '/list') {
    tg_state_clear($chat_id);
    handle_list($chat_id, $user, $db, $cfg);
} elseif ($text === '/balance') {
    tg_state_clear($chat_id);
    handle_balance($chat_id, $user, $db, $cfg);
} elseif (str_starts_with($text, '/')) {
    tg_state_clear($chat_id);
    tg_send($chat_id, "Comando não reconhecido. Use /help para ver os comandos disponíveis.", $cfg);
} else {
    handle_free_text($chat_id, $text, $user, $db, $cfg);
}

// ---------------------------------------------------------------------------
// Command handlers
// ---------------------------------------------------------------------------

function handle_start(int $chat_id, array $cfg): void {
    $msg = "*Olá! Sou o assistente do pgbudget.* 💰\n\n"
         . "Mande uma mensagem em português ou inglês:\n\n"
         . "*Adicionar evento futuro:*\n"
         . "• conta de luz 180 reais dia 10 de março\n"
         . "• salário 5000 dia 5 todo mês\n\n"
         . "*Registrar transação passada:*\n"
         . "• paguei Netflix 55,90 hoje\n"
         . "• recebi aluguel 2000 ontem\n\n"
         . "*Marcar evento como realizado:*\n"
         . "• pago conta de luz de março\n"
         . "• salário caiu\n\n"
         . "Use /help para mais detalhes ou /balance para ver o saldo projetado.";
    tg_send($chat_id, $msg, $cfg);
}

function handle_help(int $chat_id, array $cfg): void {
    $msg = "*Comandos:*\n"
         . "/balance — saldo e projeção dos próximos 2 meses\n"
         . "/list — próximos eventos (30 dias)\n"
         . "/help — esta mensagem\n\n"
         . "*Adicionar evento futuro (texto livre):*\n"
         . "• conta de luz 180 reais dia 10 de março\n"
         . "• salário 5000 dia 5 todo mês\n"
         . "• IPTU 1200 anual em julho\n\n"
         . "*Registrar transação já realizada:*\n"
         . "• paguei Netflix 55,90\n"
         . "• recebi aluguel 2000 ontem no nubank\n"
         . "• comprei no samsung 350 hoje\n\n"
         . "*Marcar evento projetado como realizado:*\n"
         . "• pago conta de luz de março\n"
         . "• salário caiu\n"
         . "• recebi o aluguel de fevereiro";
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
        error_log('pgbudget Telegram /list: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao buscar eventos.", $cfg);
    }
}

function handle_balance(int $chat_id, array $user, PDO $db, array $cfg): void {
    try {
        $stmt = $db->prepare("
            SELECT month, net_monthly_balance, cumulative_balance
            FROM api.get_projection_summary(?, CURRENT_DATE::date, 2)
            ORDER BY month
            LIMIT 2
        ");
        $stmt->execute([$user['ledger_uuid']]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            tg_send($chat_id, "Sem dados de projeção disponíveis.", $cfg);
            return;
        }

        $lines = ["*Balanço projetado:*\n"];
        foreach ($rows as $r) {
            $month_label = (new DateTime($r['month']))->format('M/Y');
            $net  = (int)$r['net_monthly_balance'];
            $cum  = (int)$r['cumulative_balance'];
            $net_icon = $net >= 0 ? '📈' : '📉';
            $lines[] = "*{$month_label}*";
            $lines[] = "  Net: {$net_icon} " . fmt_brl($net);
            $lines[] = "  Acumulado: " . fmt_brl($cum);
        }
        tg_send($chat_id, implode("\n", $lines), $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram /balance: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao buscar balanço.", $cfg);
    }
}

// ---------------------------------------------------------------------------
// Free-text handler — detects intent and routes
// ---------------------------------------------------------------------------

function handle_free_text(int $chat_id, string $text, array $user, PDO $db, array $cfg): void {
    $today   = date('Y-m-d');
    $history = tg_state_load($chat_id);

    $parsed = tg_parse_message($text, $history, $today, $cfg);

    if (isset($parsed['error'])) {
        error_log('pgbudget Telegram parser error: ' . $parsed['error']);
        tg_send($chat_id, "Desculpe, tive um problema ao interpretar sua mensagem. Pode tentar de novo?", $cfg);
        return;
    }

    $intent = $parsed['intent'] ?? 'unknown';

    switch ($intent) {
        case 'new_event':
            handle_new_event($chat_id, $text, $parsed['new_event'] ?? [], $history, $user, $db, $cfg);
            break;
        case 'record_transaction':
            handle_record_transaction($chat_id, $text, $parsed['transaction'] ?? [], $history, $user, $db, $cfg);
            break;
        case 'mark_realized':
            handle_mark_realized($chat_id, $text, $parsed['realization'] ?? [], $history, $user, $db, $cfg);
            break;
        default:
            tg_send($chat_id, "Não entendi. Use /help para ver exemplos do que posso fazer.", $cfg);
    }
}

// ---------------------------------------------------------------------------
// Intent handlers
// ---------------------------------------------------------------------------

function handle_new_event(int $chat_id, string $text, array $data, array $history, array $user, PDO $db, array $cfg): void {
    if (!empty($data['clarify'])) {
        $history[] = ['user' => $text, 'bot' => $data['clarify']];
        tg_state_save($chat_id, $history);
        tg_send($chat_id, $data['clarify'], $cfg);
        return;
    }

    foreach (['name', 'amount_reais', 'event_date', 'direction'] as $f) {
        if (empty($data[$f])) {
            tg_send($chat_id, "Não consegui identificar o campo *{$f}*. Pode reformular?", $cfg);
            return;
        }
    }

    $amount_cents        = (int) round((float)$data['amount_reais'] * 100);
    $frequency           = $data['frequency'] ?? 'one_time';
    $recurrence_end_date = !empty($data['recurrence_end_date']) ? $data['recurrence_end_date'] : null;

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
            $user['ledger_uuid'], $data['name'], $amount_cents,
            $data['event_date'], $data['direction'], $frequency, $recurrence_end_date,
        ]);
        $result = $stmt->fetch();
        if (!$result) throw new Exception('No result from create_projected_event');

        tg_state_clear($chat_id);

        $freq_map  = ['one_time' => 'único', 'monthly' => 'mensal', 'annual' => 'anual', 'semiannual' => 'semestral'];
        $dir_label = $data['direction'] === 'inflow' ? 'entrada 📈' : 'saída 📉';
        $reply = "✅ *Evento criado:* {$result['name']}\n"
               . fmt_brl($amount_cents) . " · {$dir_label} · "
               . (new DateTime($data['event_date']))->format('d/m/Y')
               . " · " . ($freq_map[$frequency] ?? $frequency);
        if ($recurrence_end_date) {
            $reply .= " (até " . (new DateTime($recurrence_end_date))->format('d/m/Y') . ")";
        }
        tg_send($chat_id, $reply, $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram new_event: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao criar o evento. Tente novamente.", $cfg);
    }
}

function handle_record_transaction(int $chat_id, string $text, array $data, array $history, array $user, PDO $db, array $cfg): void {
    if (!empty($data['clarify'])) {
        $history[] = ['user' => $text, 'bot' => $data['clarify']];
        tg_state_save($chat_id, $history);
        tg_send($chat_id, $data['clarify'], $cfg);
        return;
    }

    foreach (['description', 'amount_reais', 'direction', 'date'] as $f) {
        if (empty($data[$f])) {
            tg_send($chat_id, "Não consegui identificar o campo *{$f}*. Pode reformular?", $cfg);
            return;
        }
    }

    // Resolve account from hint or default
    $account_uuid = resolve_account($data['account_hint'] ?? null, $cfg);
    if (!$account_uuid) {
        tg_send($chat_id, "Conta não identificada. Configure *default_account_uuid* no config ou mencione a conta (ex: nubank, santander).", $cfg);
        return;
    }

    $amount_cents = (int) round((float)$data['amount_reais'] * 100);

    try {
        // Use positional params with explicit casts to resolve overload ambiguity
        // (api.add_transaction has 3 overloads with the same first 6 parameters)
        $stmt = $db->prepare("
            SELECT api.add_transaction(
                ?::text, ?::date, ?::text, ?::text, ?::bigint, ?::text,
                NULL::text, NULL::text, NULL::text
            )
        ");
        $stmt->execute([
            $user['ledger_uuid'], $data['date'], $data['description'],
            $data['direction'], $amount_cents, $account_uuid,
        ]);

        tg_state_clear($chat_id);

        $dir_label = $data['direction'] === 'inflow' ? 'entrada 📈' : 'saída 📉';
        $account_name = account_name_from_uuid($account_uuid, $cfg);
        $reply = "✅ *Transação registrada:* {$data['description']}\n"
               . fmt_brl($amount_cents) . " · {$dir_label} · "
               . (new DateTime($data['date']))->format('d/m/Y')
               . " · {$account_name}";
        tg_send($chat_id, $reply, $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram record_transaction: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao registrar transação. Tente novamente.", $cfg);
    }
}

function handle_mark_realized(int $chat_id, string $text, array $data, array $history, array $user, PDO $db, array $cfg): void {
    if (!empty($data['clarify'])) {
        $history[] = ['user' => $text, 'bot' => $data['clarify']];
        tg_state_save($chat_id, $history);
        tg_send($chat_id, $data['clarify'], $cfg);
        return;
    }

    if (empty($data['event_name'])) {
        tg_send($chat_id, "Qual evento você quer marcar como realizado?", $cfg);
        return;
    }

    try {
        // Fuzzy-search non-realized events matching the name
        $sql = "
            SELECT uuid, name, event_date, amount, direction
            FROM api.projected_events
            WHERE ledger_uuid = ?
              AND is_realized = false
              AND name ILIKE ?
            ORDER BY event_date
            LIMIT 3
        ";
        // If month hint given, restrict to events in that month
        if (!empty($data['month'])) {
            $sql = "
                SELECT uuid, name, event_date, amount, direction
                FROM api.projected_events
                WHERE ledger_uuid = ?
                  AND is_realized = false
                  AND name ILIKE ?
                  AND date_trunc('month', event_date) = ?::date
                ORDER BY event_date
                LIMIT 3
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([$user['ledger_uuid'], '%' . $data['event_name'] . '%', $data['month']]);
        } else {
            $stmt = $db->prepare($sql);
            $stmt->execute([$user['ledger_uuid'], '%' . $data['event_name'] . '%']);
        }
        $matches = $stmt->fetchAll();

        if (empty($matches)) {
            tg_send($chat_id, "Nenhum evento encontrado com o nome \"{$data['event_name']}\".", $cfg);
            return;
        }

        // Mark the first (soonest) match as realized
        $event = $matches[0];
        $stmt2 = $db->prepare("
            SELECT * FROM api.update_projected_event(
                p_event_uuid  := ?,
                p_is_realized := true::boolean
            )
        ");
        $stmt2->execute([$event['uuid']]);

        tg_state_clear($chat_id);

        $dir_icon  = $event['direction'] === 'inflow' ? '📈' : '📉';
        $date_fmt  = (new DateTime($event['event_date']))->format('d/m/Y');
        $reply = "✅ *Marcado como realizado:* {$event['name']}\n"
               . "{$dir_icon} " . fmt_brl((int)$event['amount']) . " · {$date_fmt}";

        if (count($matches) > 1) {
            $reply .= "\n\n_Havia " . count($matches) . " eventos com esse nome — o mais próximo foi marcado._";
        }
        tg_send($chat_id, $reply, $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram mark_realized: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao marcar evento como realizado. Tente novamente.", $cfg);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Format cents as BRL string, e.g. R$1.234,56 */
function fmt_brl(int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    return $sign . 'R\$' . number_format(abs($cents) / 100, 2, ',', '.');
}

/**
 * Resolve an account UUID from a keyword hint or fall back to default.
 * Config 'accounts' map: ['nubank' => 'uuid', 'samsung' => 'uuid', ...]
 */
function resolve_account(?string $hint, array $cfg): ?string {
    if ($hint && isset($cfg['accounts'])) {
        $hint_lower = strtolower($hint);
        foreach ($cfg['accounts'] as $keyword => $uuid) {
            if (str_contains($hint_lower, strtolower($keyword))) {
                return $uuid;
            }
        }
    }
    return $cfg['default_account_uuid'] ?? null;
}

/** Return a human-readable account name for confirmation messages. */
function account_name_from_uuid(string $uuid, array $cfg): string {
    if (isset($cfg['accounts'])) {
        foreach ($cfg['accounts'] as $keyword => $acct_uuid) {
            if ($acct_uuid === $uuid) return ucfirst($keyword);
        }
    }
    return $uuid;
}
