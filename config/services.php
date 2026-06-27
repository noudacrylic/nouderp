<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'is_production' => filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),
        'link_expiry_days' => (int) env('MIDTRANS_LINK_EXPIRY_DAYS', 7),
        'qris_expiry_minutes' => (int) env('MIDTRANS_QRIS_EXPIRY_MINUTES', 15),
    ],

    // Kurir — kredensial utama disimpan di tabel shipping_settings (per provider).
    // env hanya fallback/seed bila settingnya kosong.
    'biteship' => [
        'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),
        'api_key'  => env('BITESHIP_API_KEY'),
    ],

    // "Noud Bot" Telegram — notifikasi keluar (sendMessage) tidak butuh Tunnel.
    // Tanpa token, TelegramNotifier no-op aman. admin_chat_id = grup/akun penampung
    // notifikasi approval bila per-user telegram_chat_id belum di-link.
    'telegram' => [
        'token'         => env('TELEGRAM_BOT_TOKEN'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
    ],

    // Claude (Anthropic) — asisten pencatat keuangan via Telegram. Tanpa key, AI no-op aman.
    // Pakai Http langsung ke api.anthropic.com (tanpa SDK). model_text utk perintah teks,
    // model_vision utk baca struk (Fase 3). confirm_threshold: nominal di atas ini wajib konfirmasi.
    'anthropic' => [
        'key'               => env('ANTHROPIC_API_KEY'),
        'model_text'        => env('ANTHROPIC_MODEL_TEXT', 'claude-sonnet-4-6'),
        'model_vision'      => env('ANTHROPIC_MODEL_VISION', 'claude-opus-4-8'),
        'confirm_threshold' => (int) env('AI_CONFIRM_THRESHOLD', 100000),
        'conversation_ttl'  => (int) env('AI_CONVERSATION_TTL', 1200), // detik
    ],

    // Web Push (PWA Karyawan) — VAPID. Tanpa kunci, WebPushNotifier no-op aman.
    // openssl_conf: Windows/XAMPP butuh path openssl.cnf agar EC (ECDH/ES256) jalan;
    // di Linux biarkan kosong (openssl default sudah benar).
    'webpush' => [
        'public_key'   => env('VAPID_PUBLIC_KEY'),
        'private_key'  => env('VAPID_PRIVATE_KEY'),
        'subject'      => env('VAPID_SUBJECT', 'mailto:admin@noudacrylic.com'),
        'openssl_conf' => env('OPENSSL_CONF_PATH', 'C:/xampp/php/extras/ssl/openssl.cnf'),
    ],

];
