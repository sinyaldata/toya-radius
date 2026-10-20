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

    // Dipakai SoftEtherVpnService — buat eksekusi vpncmd otomatis dari
    // halaman VPN khusus owner (routes/web.php, prefix /vpn-owner).
    'softether' => [
        'vpncmd_path' => env('SOFTETHER_VPNCMD_PATH', '/usr/local/vpnserver/vpncmd'),
        'password' => env('SOFTETHER_SERVER_PASSWORD'),
        // Password buat MASUK ke halaman /vpn-owner — SENGAJA beda dari
        // password admin server SoftEther di atas. Ini yang cuma owner
        // aplikasi tau, bukan bagian dari akun user Toya Radius manapun.
        'owner_password' => env('VPN_OWNER_PASSWORD'),
    ],
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    ],
    'openai' => ['api_key' => env('OPENAI_API_KEY')],

    // Dipakai L2tpAccountService — baca/tulis akun L2TP langsung ke file
    // chap-secrets yang dipakai xl2tpd (solusi VPN yang terbukti jalan
    // dengan IP tunnel BENERAN tetap, bukan trik pref-src).
    'l2tp' => [
        'chap_secrets_path' => env('L2TP_CHAP_SECRETS_PATH', '/etc/ppp/chap-secrets'),
    ],

    // Domain khusus halaman "walled garden" isolir (App\Http\Controllers\
    // IsolirLandingController) -- mis. "isolir.toyaqi.web.id". DNS domain
    // ini harus diarahkan ke server yang sama dengan aplikasi ini, dan
    // nginx/webserver-nya harus meneruskan permintaan untuk domain ini ke
    // aplikasi Laravel yang sama (server_name tambahan, root yang sama).
    // Kosongkan (jangan isi ISOLIR_DOMAIN) kalau belum siap -- halaman
    // isolir tetap bisa diakses lewat /isolir di domain utama.
    'isolir' => [
        'domain' => env('ISOLIR_DOMAIN'),
    ],

];
