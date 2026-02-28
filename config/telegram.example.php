<?php
/**
 * Telegram Bot Configuration — TEMPLATE
 *
 * Copy this file to config/telegram.php and fill in your values.
 * config/telegram.php is gitignored — never commit real credentials.
 *
 * Setup steps:
 *   1. Create a bot via @BotFather on Telegram → copy the bot token
 *   2. Choose a random webhook secret (any long random string)
 *   3. Get a Claude API key from https://console.anthropic.com
 *   4. Fill in your Telegram chat_id (message @userinfobot to find yours)
 *   5. Generate self-signed cert and configure Apache (see scripts/setup-telegram-ssl.sh)
 *   6. Register the webhook:
 *        curl "https://api.telegram.org/bot{TOKEN}/setWebhook" \
 *          -F "url=https://{SERVER_IP}/pgbudget/telegram/webhook.php" \
 *          -F "certificate=@/etc/ssl/telegram-bot.pem" \
 *          -F "secret_token={WEBHOOK_SECRET}" \
 *          -F "allowed_updates=[\"message\"]"
 */
return [
    // From @BotFather
    'bot_token' => 'REPLACE_WITH_BOT_TOKEN',

    // Random string you chose when registering the webhook
    'webhook_secret' => 'REPLACE_WITH_WEBHOOK_SECRET',

    // Anthropic API key
    'claude_api_key' => 'REPLACE_WITH_CLAUDE_API_KEY',
    'claude_model'   => 'claude-haiku-4-5-20251001',

    // Map Telegram chat_id (integer) → pgbudget user context
    // Get your chat_id by messaging @userinfobot on Telegram
    'users' => [
        // 123456789 => [
        //     'user_id'     => 'm43str0',   // app.current_user_id / RLS identity
        //     'ledger_uuid' => 'eNF2EkfD',  // default ledger UUID
        // ],
    ],

    // Default account UUID for recording transactions when no account is mentioned.
    // Use the UUID from api.accounts (type = asset, e.g. your main checking account).
    'default_account_uuid' => null,

    // Keyword → account UUID map for account recognition in free-text messages.
    // Claude extracts the institution keyword; we match it here.
    // Keys are matched case-insensitively and by substring.
    'accounts' => [
        // 'nubank'    => 'UUID_OF_NUBANK_CHECKING',
        // 'santander' => 'UUID_OF_SANTANDER_CHECKING',
        // 'samsung'   => 'UUID_OF_SAMSUNG_CC',
        // 'picpay'    => 'UUID_OF_PICPAY_CC',
        // 'nubank_cc' => 'UUID_OF_NUBANK_CC',
    ],
];
