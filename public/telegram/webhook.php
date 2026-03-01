<?php
/**
 * Telegram Webhook — pgbudget bot
 *
 * Phase 1: /start, /help, /list, free-text → new projected event
 * Phase 2: /balance, record transaction, mark event as realized
 * Phase 3: /undo, /accounts, /setledger, smarter success messages
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

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

$cfg_path = __DIR__ . '/../../config/telegram.php';
if (!file_exists($cfg_path)) { error_log('pgbudget Telegram: config/telegram.php not found'); exit; }
$cfg = require $cfg_path;

$incoming_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($cfg['webhook_secret'], $incoming_secret)) exit;

$body   = file_get_contents('php://input');
$update = json_decode($body, true);
if (!$update || !isset($update['message'])) exit;

$msg     = $update['message'];
$chat_id = (int)($msg['chat']['id'] ?? 0);
$text    = trim($msg['text'] ?? '');
if ($chat_id === 0 || $text === '') exit;

if (!isset($cfg['users'][$chat_id])) exit;
$user = $cfg['users'][$chat_id];

try {
    $db   = getDbConnection();
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$user['user_id']]);
} catch (Exception $e) {
    error_log('pgbudget Telegram: DB error — ' . $e->getMessage());
    exit;
}

// Resolve active ledger (may be overridden by /setledger)
$ledger_uuid = tg_ledger_get($chat_id, $user);

// Route
if ($text === '/start') {
    tg_state_clear($chat_id);
    handle_start($chat_id, $cfg);
} elseif ($text === '/help') {
    tg_state_clear($chat_id);
    handle_help($chat_id, $cfg);
} elseif ($text === '/list') {
    tg_state_clear($chat_id);
    handle_list($chat_id, $ledger_uuid, $db, $cfg);
} elseif ($text === '/balance') {
    tg_state_clear($chat_id);
    handle_balance($chat_id, $ledger_uuid, $db, $cfg);
} elseif ($text === '/undo') {
    tg_state_clear($chat_id);
    handle_undo($chat_id, $db, $cfg);
} elseif ($text === '/accounts') {
    tg_state_clear($chat_id);
    handle_accounts($chat_id, $cfg);
} elseif ($text === '/setledger') {
    tg_state_clear($chat_id);
    handle_setledger($chat_id, $user, $db, $cfg);
} elseif (str_starts_with($text, '/')) {
    tg_state_clear($chat_id);
    tg_send($chat_id, "Comando não reconhecido. Use /help para ver os comandos disponíveis.", $cfg);
} else {
    handle_free_text($chat_id, $text, $user, $ledger_uuid, $db, $cfg);
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
         . "• paguei Netflix 55,90 hoje no nubank\n"
         . "• recebi aluguel 2000 ontem\n\n"
         . "*Marcar evento como realizado:*\n"
         . "• pago conta de luz de março\n"
         . "• salário caiu\n\n"
         . "Use /help para ver todos os comandos.";
    tg_send($chat_id, $msg, $cfg);
}

function handle_help(int $chat_id, array $cfg): void {
    $msg = "*Comandos:*\n"
         . "/balance — saldo projetado dos próximos 2 meses\n"
         . "/list — próximos eventos (30 dias)\n"
         . "/accounts — contas configuradas\n"
         . "/setledger — trocar orçamento ativo\n"
         . "/undo — desfazer última ação do bot\n"
         . "/help — esta mensagem\n\n"
         . "*Adicionar evento futuro:*\n"
         . "• conta de luz 180 dia 10 de março\n"
         . "• salário 5000 dia 5 todo mês\n"
         . "• IPTU 1200 anual em julho\n\n"
         . "*Registrar transação já realizada:*\n"
         . "• paguei Netflix 55,90 no nubank\n"
         . "• recebi aluguel 2000 ontem\n"
         . "• comprei no samsung 350 hoje\n\n"
         . "*Marcar evento projetado como realizado:*\n"
         . "• pago conta de luz de março\n"
         . "• salário caiu\n"
         . "• recebi o aluguel de fevereiro";
    tg_send($chat_id, $msg, $cfg);
}

function handle_list(int $chat_id, string $ledger_uuid, PDO $db, array $cfg): void {
    try {
        $stmt = $db->prepare("
            SELECT name, direction, amount, event_date
            FROM api.projected_events
            WHERE ledger_uuid = ?
              AND is_realized = false
              AND event_date >= CURRENT_DATE
              AND event_date <= CURRENT_DATE + INTERVAL '30 days'
            ORDER BY event_date LIMIT 10
        ");
        $stmt->execute([$ledger_uuid]);
        $events = $stmt->fetchAll();

        if (empty($events)) { tg_send($chat_id, "Nenhum evento nos próximos 30 dias.", $cfg); return; }

        $lines = ["*Próximos eventos (30 dias):*\n"];
        foreach ($events as $e) {
            $icon  = $e['direction'] === 'inflow' ? '📈' : '📉';
            $lines[] = "{$icon} " . (new DateTime($e['event_date']))->format('d/m/Y')
                     . " — {$e['name']} (" . fmt_brl((int)$e['amount']) . ")";
        }
        tg_send($chat_id, implode("\n", $lines), $cfg);
    } catch (Exception $e) {
        error_log('pgbudget Telegram /list: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao buscar eventos.", $cfg);
    }
}

function handle_balance(int $chat_id, string $ledger_uuid, PDO $db, array $cfg): void {
    try {
        $stmt = $db->prepare("
            SELECT month, net_monthly_balance, cumulative_balance
            FROM api.get_projection_summary(?, CURRENT_DATE::date, 2)
            ORDER BY month LIMIT 2
        ");
        $stmt->execute([$ledger_uuid]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) { tg_send($chat_id, "Sem dados de projeção.", $cfg); return; }

        $lines = ["*Balanço projetado:*\n"];
        foreach ($rows as $r) {
            $net = (int)$r['net_monthly_balance'];
            $cum = (int)$r['cumulative_balance'];
            $lines[] = "*" . (new DateTime($r['month']))->format('M/Y') . "*";
            $lines[] = "  Net: " . ($net >= 0 ? '📈' : '📉') . " " . fmt_brl($net);
            $lines[] = "  Acumulado: " . fmt_brl($cum);
        }
        tg_send($chat_id, implode("\n", $lines), $cfg);
    } catch (Exception $e) {
        error_log('pgbudget Telegram /balance: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao buscar balanço.", $cfg);
    }
}

function handle_undo(int $chat_id, PDO $db, array $cfg): void {
    $action = tg_action_load($chat_id);
    if (!$action) {
        tg_send($chat_id, "Nenhuma ação recente para desfazer (limite: 1 hora).", $cfg);
        return;
    }
    try {
        if ($action['type'] === 'event') {
            $stmt = $db->prepare("SELECT api.delete_projected_event(?)");
            $stmt->execute([$action['uuid']]);
        } elseif ($action['type'] === 'transaction') {
            $stmt = $db->prepare("SELECT api.delete_transaction(?)");
            $stmt->execute([$action['uuid']]);

            // If the transaction had auto-matched a projected event, unrealize it too
            if (!empty($action['matched_event_uuid'])) {
                $event_uuid = $action['matched_event_uuid'];
                try {
                    // Determine if one-time or recurring
                    $stmt2 = $db->prepare("SELECT frequency FROM api.projected_events WHERE uuid = ?");
                    $stmt2->execute([$event_uuid]);
                    $event = $stmt2->fetch();
                    if ($event) {
                        if ($event['frequency'] === 'one_time') {
                            $db->prepare(
                                "UPDATE data.projected_events
                                 SET is_realized = false, linked_transaction_id = NULL
                                 WHERE uuid = ?"
                            )->execute([$event_uuid]);
                        } else {
                            $db->prepare(
                                "SELECT api.unrealize_projected_event_occurrence(?, date_trunc('month', now())::date)"
                            )->execute([$event_uuid]);
                        }
                    }
                } catch (Exception $e2) {
                    error_log('pgbudget Telegram /undo matched event: ' . $e2->getMessage());
                }
            }
        } elseif ($action['type'] === 'event_unrealize') {
            $stmt = $db->prepare("SELECT * FROM api.update_projected_event(p_event_uuid := ?, p_is_realized := false::boolean)");
            $stmt->execute([$action['uuid']]);
        } else {
            tg_send($chat_id, "Tipo de ação desconhecido — não foi possível desfazer.", $cfg);
            return;
        }
        tg_action_clear($chat_id);
        tg_send($chat_id, "↩️ Desfeito: _{$action['label']}_", $cfg);
    } catch (Exception $e) {
        error_log('pgbudget Telegram /undo: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao desfazer. O item pode já ter sido alterado.", $cfg);
    }
}

function handle_accounts(int $chat_id, array $cfg): void {
    $accounts = $cfg['accounts'] ?? [];
    $default  = $cfg['default_account_uuid'] ?? null;

    if (empty($accounts)) {
        tg_send($chat_id, "Nenhuma conta configurada em config/telegram.php.", $cfg);
        return;
    }
    $lines = ["*Contas configuradas:*\n"];
    foreach ($accounts as $keyword => $uuid) {
        $marker  = ($uuid === $default) ? ' ⭐ _padrão_' : '';
        $lines[] = "• `{$keyword}`{$marker}";
    }
    $lines[] = "\n_Mencione a palavra-chave ao registrar uma transação._";
    $lines[] = "_Ex: \"paguei Netflix 55,90 no *nubank*\"_";
    tg_send($chat_id, implode("\n", $lines), $cfg);
}

function handle_setledger(int $chat_id, array $user, PDO $db, array $cfg): void {
    try {
        $stmt = $db->prepare("SELECT uuid, name FROM api.ledgers ORDER BY name");
        $stmt->execute();
        $ledgers = $stmt->fetchAll();

        if (count($ledgers) === 1) {
            tg_send($chat_id, "Você tem apenas um orçamento: *{$ledgers[0]['name']}*", $cfg);
            return;
        }

        $lines   = ["*Escolha um orçamento:*\n"];
        $options = [];
        $active  = tg_ledger_get($chat_id, $user);
        foreach ($ledgers as $i => $l) {
            $n        = $i + 1;
            $check    = $active === $l['uuid'] ? ' ✅' : '';
            $lines[]  = "{$n}. {$l['name']}{$check}";
            $options[$n] = $l['uuid'];
        }
        $lines[] = "\nResponda com o número.";

        // Store options in state so the next free-text message is treated as a selection
        tg_state_save($chat_id, [['user' => '/setledger', 'bot' => '__ledger_select__', 'options' => $options]]);
        tg_send($chat_id, implode("\n", $lines), $cfg);
    } catch (Exception $e) {
        error_log('pgbudget Telegram /setledger: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao buscar orçamentos.", $cfg);
    }
}

// ---------------------------------------------------------------------------
// Free-text: check for pending ledger selection first, then detect intent
// ---------------------------------------------------------------------------

function handle_free_text(int $chat_id, string $text, array $user, string $ledger_uuid, PDO $db, array $cfg): void {
    // Ledger selection pending?
    $state = tg_state_load($chat_id);
    if (!empty($state) && ($state[0]['bot'] ?? '') === '__ledger_select__') {
        $options = $state[0]['options'] ?? [];
        $n = (int)trim($text);
        if ($n > 0 && isset($options[$n])) {
            tg_ledger_set($chat_id, $options[$n]);
            tg_state_clear($chat_id);
            tg_send($chat_id, "✅ Orçamento ativo alterado.", $cfg);
        } else {
            tg_send($chat_id, "Opção inválida. Responda com o número do orçamento ou use /setledger novamente.", $cfg);
        }
        return;
    }

    $today   = date('Y-m-d');
    $history = tg_state_load($chat_id);
    $parsed  = tg_parse_message($text, $history, $today, $cfg);

    if (isset($parsed['error'])) {
        error_log('pgbudget Telegram parser error: ' . $parsed['error']);
        tg_send($chat_id, "Desculpe, tive um problema ao interpretar sua mensagem. Pode tentar de novo?", $cfg);
        return;
    }

    switch ($parsed['intent'] ?? 'unknown') {
        case 'new_event':
            handle_new_event($chat_id, $text, $parsed['new_event'] ?? [], $history, $ledger_uuid, $db, $cfg);
            break;
        case 'record_transaction':
            handle_record_transaction($chat_id, $text, $parsed['transaction'] ?? [], $history, $ledger_uuid, $db, $cfg);
            break;
        case 'mark_realized':
            handle_mark_realized($chat_id, $text, $parsed['realization'] ?? [], $history, $ledger_uuid, $db, $cfg);
            break;
        default:
            tg_send($chat_id, "Não entendi. Use /help para ver exemplos do que posso fazer.", $cfg);
    }
}

// ---------------------------------------------------------------------------
// Intent handlers
// ---------------------------------------------------------------------------

function handle_new_event(int $chat_id, string $text, array $data, array $history, string $ledger_uuid, PDO $db, array $cfg): void {
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
        $stmt->execute([$ledger_uuid, $data['name'], $amount_cents,
                        $data['event_date'], $data['direction'], $frequency, $recurrence_end_date]);
        $result = $stmt->fetch();
        if (!$result) throw new Exception('No result from create_projected_event');

        tg_state_clear($chat_id);
        tg_action_save($chat_id, 'event', $result['uuid'], $data['name']);

        $freq_map  = ['one_time' => 'único', 'monthly' => 'mensal', 'annual' => 'anual', 'semiannual' => 'semestral'];
        $dir_label = $data['direction'] === 'inflow' ? 'entrada 📈' : 'saída 📉';
        $reply = "✅ *Evento criado:* {$result['name']}\n"
               . fmt_brl($amount_cents) . " · {$dir_label} · "
               . (new DateTime($data['event_date']))->format('d/m/Y')
               . " · " . ($freq_map[$frequency] ?? $frequency);
        if ($recurrence_end_date) {
            $reply .= " (até " . (new DateTime($recurrence_end_date))->format('d/m/Y') . ")";
        }
        $reply .= "\n\n_/undo para desfazer._";
        tg_send($chat_id, $reply, $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram new_event: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao criar o evento. Tente novamente.", $cfg);
    }
}

function handle_record_transaction(int $chat_id, string $text, array $data, array $history, string $ledger_uuid, PDO $db, array $cfg): void {
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

    $account_uuid = resolve_account($data['account_hint'] ?? null, $cfg);
    if (!$account_uuid) {
        tg_send($chat_id,
            "Conta não identificada. Use /accounts para ver as palavras-chave configuradas,\n"
          . "ou mencione a conta na mensagem (ex: \"no nubank\", \"no samsung\").", $cfg);
        return;
    }

    $amount_cents = (int) round((float)$data['amount_reais'] * 100);

    try {
        $stmt = $db->prepare("
            SELECT api.add_transaction(
                ?::text, ?::date, ?::text, ?::text, ?::bigint, ?::text,
                NULL::text, NULL::text, NULL::text
            )
        ");
        $stmt->execute([$ledger_uuid, $data['date'], $data['description'],
                        $data['direction'], $amount_cents, $account_uuid]);
        $tx_uuid = $stmt->fetchColumn();

        tg_state_clear($chat_id);

        // Check if the trigger auto-matched this transaction to a projected event
        $match_uuid = '';
        $match_name = '';
        try {
            $stmt2 = $db->prepare(
                "SELECT metadata->>'matched_event_uuid' AS e_uuid,
                        metadata->>'matched_event_name'  AS e_name
                 FROM data.transactions WHERE uuid = ?"
            );
            $stmt2->execute([$tx_uuid]);
            $match = $stmt2->fetch();
            if (!empty($match['e_uuid'])) {
                $match_uuid = $match['e_uuid'];
                $match_name = $match['e_name'];
            }
        } catch (Exception $e) {
            // Non-fatal — proceed without match info
            error_log('pgbudget Telegram match metadata: ' . $e->getMessage());
        }

        tg_action_save($chat_id, 'transaction', $tx_uuid,
                       "{$data['description']} " . fmt_brl($amount_cents),
                       $match_uuid);

        $dir_label    = $data['direction'] === 'inflow' ? 'entrada 📈' : 'saída 📉';
        $account_name = account_name_from_uuid($account_uuid, $cfg);
        $reply = "✅ *Transação registrada:* {$data['description']}\n"
               . fmt_brl($amount_cents) . " · {$dir_label} · "
               . (new DateTime($data['date']))->format('d/m/Y')
               . " · conta: *{$account_name}*";
        if ($match_uuid !== '') {
            $reply .= "\n✓ _Correspondeu ao evento planejado «{$match_name}» e marcou como realizado._";
        } else {
            $reply .= "\n_Nenhum evento correspondente; aparecerá como transação na projeção._";
        }
        $reply .= "\n\n_/undo se a conta estiver errada._";
        tg_send($chat_id, $reply, $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram record_transaction: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao registrar transação. Tente novamente.", $cfg);
    }
}

function handle_mark_realized(int $chat_id, string $text, array $data, array $history, string $ledger_uuid, PDO $db, array $cfg): void {
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
        if (!empty($data['month'])) {
            $stmt = $db->prepare("
                SELECT uuid, name, event_date, amount, direction FROM api.projected_events
                WHERE ledger_uuid = ? AND is_realized = false AND name ILIKE ?
                  AND date_trunc('month', event_date) = ?::date
                ORDER BY event_date LIMIT 3
            ");
            $stmt->execute([$ledger_uuid, '%' . $data['event_name'] . '%', $data['month']]);
        } else {
            $stmt = $db->prepare("
                SELECT uuid, name, event_date, amount, direction FROM api.projected_events
                WHERE ledger_uuid = ? AND is_realized = false AND name ILIKE ?
                ORDER BY event_date LIMIT 3
            ");
            $stmt->execute([$ledger_uuid, '%' . $data['event_name'] . '%']);
        }
        $matches = $stmt->fetchAll();

        if (empty($matches)) {
            tg_send($chat_id, "Nenhum evento encontrado com o nome \"{$data['event_name']}\".", $cfg);
            return;
        }

        $event = $matches[0];
        $stmt2 = $db->prepare("SELECT * FROM api.update_projected_event(p_event_uuid := ?, p_is_realized := true::boolean)");
        $stmt2->execute([$event['uuid']]);

        tg_state_clear($chat_id);
        tg_action_save($chat_id, 'event_unrealize', $event['uuid'], $event['name']);

        $dir_icon = $event['direction'] === 'inflow' ? '📈' : '📉';
        $reply = "✅ *Marcado como realizado:* {$event['name']}\n"
               . "{$dir_icon} " . fmt_brl((int)$event['amount']) . " · "
               . (new DateTime($event['event_date']))->format('d/m/Y');
        if (count($matches) > 1) {
            $reply .= "\n\n_Havia " . count($matches) . " eventos com esse nome — o mais próximo foi marcado._";
        }
        $reply .= "\n\n_/undo para desfazer._";
        tg_send($chat_id, $reply, $cfg);

    } catch (Exception $e) {
        error_log('pgbudget Telegram mark_realized: ' . $e->getMessage());
        tg_send($chat_id, "Erro ao marcar evento como realizado. Tente novamente.", $cfg);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function fmt_brl(int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    return $sign . 'R\$' . number_format(abs($cents) / 100, 2, ',', '.');
}

function resolve_account(?string $hint, array $cfg): ?string {
    if ($hint && isset($cfg['accounts'])) {
        foreach ($cfg['accounts'] as $keyword => $uuid) {
            if (str_contains(strtolower($hint), strtolower($keyword))) return $uuid;
        }
    }
    return $cfg['default_account_uuid'] ?? null;
}

function account_name_from_uuid(string $uuid, array $cfg): string {
    if (isset($cfg['accounts'])) {
        foreach ($cfg['accounts'] as $keyword => $acct_uuid) {
            if ($acct_uuid === $uuid) return ucfirst($keyword);
        }
    }
    return $uuid;
}
