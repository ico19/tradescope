<?php
/**
 * TradeScope - file di configurazione.
 * Questo file viene generato automaticamente da install.php.
 * Puo' essere modificato a mano in qualsiasi momento.
 */
return [
    // ---- Database -------------------------------------------------------
    // driver: 'sqlite' (consigliato su hosting condiviso) oppure 'mysql'
    'db' => [
        'driver'   => 'sqlite',
        'sqlite'   => __DIR__ . '/../storage/tradescope.sqlite',
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => '',
        'user'     => '',
        'pass'     => '',
        'charset'  => 'utf8mb4',
    ],

    // ---- Accesso --------------------------------------------------------
    'auth' => [
        'username'      => 'admin',
        'password_hash' => '',      // generato da install.php
        'session_name'  => 'tradescope_sid',
        'session_ttl'   => 43200,   // 12 ore
    ],

    // ---- Fornitore di intelligenza artificiale --------------------------
    // provider: 'anthropic' (Claude) oppure 'openai' (GPT)
    'ai' => [
        'provider' => 'anthropic',
    ],

    // ---- API Anthropic (Claude) ----------------------------------------
    'claude' => [
        'api_key'     => '',
        'model'       => 'claude-sonnet-5',
        'max_tokens'  => 6000,
        'timeout'     => 120,
        'api_version' => '2023-06-01',
        'base_url'    => 'https://api.anthropic.com/v1/messages',
    ],

    // ---- API OpenAI (GPT) ----------------------------------------------
    'openai' => [
        'api_key'      => '',
        'model'        => 'gpt-5.6-terra',
        'timeout'      => 120,
        'organization' => '',
        'base_url'     => 'https://api.openai.com/v1/responses',
    ],

    // ---- Motore ---------------------------------------------------------
    'engine' => [
        'cron_token'          => '',   // token per invocare cron.php via URL
        'candles'             => 400,  // candele scaricate per analisi (2.0: minimo 300)
        'horizon_bars'        => 24,   // barre di proiezione futura
        'use_v2'              => 1,    // 1 = motore 2.0 (confluenza, MTF, derivati), 0 = motore 1.0
        'use_derivatives'     => 1,    // 1 = funding, open interest, long/short ratio, Fear & Greed
        'max_per_run'         => 3,    // simboli analizzati per ogni esecuzione cron
        'min_minutes_between' => 30,   // minuti minimi tra due analisi dello stesso simbolo
        'http_cache_ttl'      => 45,   // cache risposte di mercato (secondi)
        'retention_days'      => 120,  // giorni di storico analisi mantenuti
    ],

    // ---- Interfaccia ----------------------------------------------------
    'ui' => [
        'title'    => 'TradeScope',
        'timezone' => 'Europe/Rome',
        'locale'   => 'it',
    ],
];
