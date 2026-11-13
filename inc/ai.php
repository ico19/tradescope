<?php
/**
 * TradeScope - client dei fornitori di intelligenza artificiale
 *
 * Sono supportati due fornitori, intercambiabili dalle impostazioni:
 *   - anthropic : API Messages di Claude
 *   - openai    : API Responses di OpenAI
 */
declare(strict_types=1);

function ts_ai_providers(): array {
    return [
        'anthropic' => 'Anthropic - Claude',
        'openai'    => 'OpenAI - GPT',
    ];
}

/** Elenco dei modelli disponibili, per fornitore */
function ts_ai_catalog(): array {
    return [
        'anthropic' => [
            'claude-sonnet-5'            => 'Claude Sonnet 5 - equilibrio qualita/costo (consigliato)',
            'claude-opus-5'              => 'Claude Opus 5 - analisi piu profonda',
            'claude-fable-5-1'           => 'Claude Fable 5.1 - ragionamento avanzato',
            'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5 - rapido ed economico',
        ],
        'openai' => [
            'gpt-5.6-terra' => 'GPT-5.6 Terra - equilibrio qualita/costo (consigliato)',
            'gpt-5.6-sol'   => 'GPT-5.6 Sol - analisi piu profonda',
            'gpt-6-astra'   => 'GPT-6 Astra - massima capacita',
            'gpt-5.6-luna'  => 'GPT-5.6 Luna - rapido ed economico',
        ],
    ];
}

/** Modelli di un fornitore (o di tutti, se non specificato) */
function ts_ai_models(?string $provider = null): array {
    $cat = ts_ai_catalog();
    if ($provider === null) {
        $all = [];
        foreach ($cat as $models) { $all += $models; }
        return $all;
    }
    return $cat[$provider] ?? [];
}

/** Compatibilita' con il codice della versione 1.x */
function ts_claude_models(): array { return ts_ai_models(); }

function ts_ai_provider_of_model(string $model): ?string {
    foreach (ts_ai_catalog() as $provider => $models) {
        if (isset($models[$model])) return $provider;
    }
    return null;
}

function ts_ai_active_provider(): string {
    $p = (string)ts_opt('ai', 'provider', 'anthropic');
    return isset(ts_ai_providers()[$p]) ? $p : 'anthropic';
}

function ts_ai_default_model(string $provider): string {
    $defaults = ['anthropic' => 'claude-sonnet-5', 'openai' => 'gpt-5.6-terra'];
    $configured = (string)ts_opt($provider === 'openai' ? 'openai' : 'claude', 'model', '');
    if ($configured !== '' && isset(ts_ai_models($provider)[$configured])) return $configured;
    return $defaults[$provider] ?? 'claude-sonnet-5';
}

function ts_ai_api_key(string $provider): string {
    return (string)ts_opt($provider === 'openai' ? 'openai' : 'claude', 'api_key', '');
}

function ts_ai_provider_label(string $provider): string {
    return ts_ai_providers()[$provider] ?? $provider;
}

/**
 * Invia la richiesta al fornitore selezionato.
 * @return array{ok:bool, text:string, usage:array, error:string, model:string, provider:string}
 */
function ts_ai_call(string $system, string $userPrompt, ?string $model = null, ?string $provider = null, int $maxTokens = 0): array {
    // Il fornitore si deduce dal modello, se riconosciuto
    if ($provider === null && $model !== null) $provider = ts_ai_provider_of_model($model);
    $provider = $provider ?: ts_ai_active_provider();
    $model    = $model ?: ts_ai_default_model($provider);

    if (ts_ai_api_key($provider) === '') {
        return ['ok' => false, 'text' => '', 'usage' => [], 'model' => $model, 'provider' => $provider,
                'error' => 'Chiave API ' . ts_ai_provider_label($provider) . ' non configurata'];
    }
    $maxTokens = $maxTokens ?: (int)ts_opt('claude', 'max_tokens', 6000);

    return $provider === 'openai'
        ? ts_ai_call_openai($system, $userPrompt, $model, $maxTokens)
        : ts_ai_call_anthropic($system, $userPrompt, $model, $maxTokens);
}

/**
 * Compatibilita' con il codice della versione 1.x: il quarto parametro resta
 * il numero di token, il fornitore viene dedotto dal modello o dalle impostazioni.
 */
function ts_claude_call(string $system, string $userPrompt, ?string $model = null, int $maxTokens = 0): array {
    return ts_ai_call($system, $userPrompt, $model, null, $maxTokens);
}

/* ------------------------------------------------------------- Anthropic */

function ts_ai_call_anthropic(string $system, string $userPrompt, string $model, int $maxTokens): array {
    $timeout = (int)ts_opt('claude', 'timeout', 120);
    $url     = (string)ts_opt('claude', 'base_url', 'https://api.anthropic.com/v1/messages');
    $version = (string)ts_opt('claude', 'api_version', '2023-06-01');

    $payload = [
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'temperature' => 0.2,
        'system'      => $system,
        'messages'    => [
            ['role' => 'user',      'content' => $userPrompt],
            ['role' => 'assistant', 'content' => '{'],   // prefill: forza output JSON
        ],
    ];
    $headers = ['x-api-key: ' . ts_ai_api_key('anthropic'), 'anthropic-version: ' . $version];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $res  = ts_http_post_json($url, $payload, $headers, $timeout);
        $data = json_decode($res['body'] ?: '[]', true);

        if ($res['ok'] && isset($data['content'][0])) {
            $text = '';
            foreach ($data['content'] as $block) {
                if (($block['type'] ?? 'text') === 'text') $text .= (string)($block['text'] ?? '');
            }
            return [
                'ok' => true, 'text' => '{' . $text, 'error' => '',
                'usage' => ['in' => (int)($data['usage']['input_tokens'] ?? 0), 'out' => (int)($data['usage']['output_tokens'] ?? 0)],
                'model' => $model, 'provider' => 'anthropic',
            ];
        }

        $errMsg = $data['error']['message'] ?? ($res['error'] ?: 'errore sconosciuto');
        ts_log('ai', 'Anthropic: chiamata fallita', ['code' => $res['code'], 'msg' => mb_substr($errMsg, 0, 300), 'tentativo' => $attempt]);
        $retriable = in_array($res['code'], [429, 500, 502, 503, 529], true) || $res['code'] === 0;
        if (!$retriable || $attempt === 3) {
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => $errMsg, 'model' => $model, 'provider' => 'anthropic'];
        }
        sleep(2 * $attempt);
    }
    return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'numero massimo di tentativi superato', 'model' => $model, 'provider' => 'anthropic'];
}

/* ---------------------------------------------------------------- OpenAI */

function ts_ai_call_openai(string $system, string $userPrompt, string $model, int $maxTokens): array {
    $timeout = (int)ts_opt('openai', 'timeout', 120);
    $url     = (string)ts_opt('openai', 'base_url', 'https://api.openai.com/v1/responses');
    $headers = ['Authorization: Bearer ' . ts_ai_api_key('openai')];
    $org     = (string)ts_opt('openai', 'organization', '');
    if ($org !== '') $headers[] = 'OpenAI-Organization: ' . $org;

    $payload = [
        'model'             => $model,
        'instructions'      => $system,
        'input'             => [['role' => 'user', 'content' => $userPrompt]],
        'max_output_tokens' => $maxTokens,
        'temperature'       => 0.2,
        'text'              => ['format' => ['type' => 'json_object']],
    ];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $res  = ts_http_post_json($url, $payload, $headers, $timeout);
        $data = json_decode($res['body'] ?: '[]', true);

        if ($res['ok']) {
            $text = ts_openai_extract_text($data);
            if ($text !== '') {
                return [
                    'ok' => true, 'text' => $text, 'error' => '',
                    'usage' => ['in' => (int)($data['usage']['input_tokens'] ?? 0), 'out' => (int)($data['usage']['output_tokens'] ?? 0)],
                    'model' => $model, 'provider' => 'openai',
                ];
            }
            $status = (string)($data['status'] ?? '');
            $why    = $data['incomplete_details']['reason'] ?? '';
            return ['ok' => false, 'text' => '', 'usage' => [], 'model' => $model, 'provider' => 'openai',
                    'error' => 'risposta vuota' . ($status ? " (stato: $status" . ($why ? ", $why" : '') . ')' : '')];
        }

        $errMsg  = $data['error']['message'] ?? ($res['error'] ?: 'errore sconosciuto');
        $lowered = mb_strtolower($errMsg);
        ts_log('ai', 'OpenAI: chiamata fallita', ['code' => $res['code'], 'msg' => mb_substr($errMsg, 0, 300), 'tentativo' => $attempt]);

        // Alcuni modelli non accettano temperature o il formato JSON forzato:
        // si rimuove il parametro segnalato e si riprova subito.
        $adjusted = false;
        if (isset($payload['temperature']) && str_contains($lowered, 'temperature')) {
            unset($payload['temperature']); $adjusted = true;
        }
        if (isset($payload['text']) && (str_contains($lowered, 'text.format') || str_contains($lowered, 'json_object') || str_contains($lowered, 'response_format'))) {
            unset($payload['text']); $adjusted = true;
        }
        if ($adjusted) { $attempt--; continue; }

        $retriable = in_array($res['code'], [429, 500, 502, 503], true) || $res['code'] === 0;
        if (!$retriable || $attempt === 3) {
            return ['ok' => false, 'text' => '', 'usage' => [], 'error' => $errMsg, 'model' => $model, 'provider' => 'openai'];
        }
        sleep(2 * $attempt);
    }
    return ['ok' => false, 'text' => '', 'usage' => [], 'error' => 'numero massimo di tentativi superato', 'model' => $model, 'provider' => 'openai'];
}

/** Estrae il testo dalla risposta dell'API Responses di OpenAI */
function ts_openai_extract_text(array $data): string {
    if (!empty($data['output_text']) && is_string($data['output_text'])) return $data['output_text'];
    $text = '';
    foreach (($data['output'] ?? []) as $item) {
        if (!is_array($item)) continue;
        foreach (($item['content'] ?? []) as $block) {
            if (!is_array($block)) continue;
            if (in_array($block['type'] ?? '', ['output_text', 'text'], true) && isset($block['text'])) {
                $text .= (string)$block['text'];
            }
        }
    }
    // Compatibilita' con l'endpoint Chat Completions, se configurato manualmente
    if ($text === '' && isset($data['choices'][0]['message']['content'])) {
        $text = (string)$data['choices'][0]['message']['content'];
    }
    return $text;
}

/* ------------------------------------------------------------- Utilita' */

/** Estrae il primo oggetto JSON valido da una risposta testuale */
function ts_extract_json(string $text): ?array {
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
    $start = strpos($text, '{');
    if ($start === false) return null;
    $depth = 0; $inStr = false; $esc = false;
    for ($i = $start, $n = strlen($text); $i < $n; $i++) {
        $ch = $text[$i];
        if ($inStr) {
            if ($esc)             { $esc = false; }
            elseif ($ch === '\\') { $esc = true; }
            elseif ($ch === '"')  { $inStr = false; }
            continue;
        }
        if ($ch === '"') { $inStr = true; continue; }
        if ($ch === '{') $depth++;
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $out = json_decode(substr($text, $start, $i - $start + 1), true);
                return is_array($out) ? $out : null;
            }
        }
    }
    // JSON troncato: tentativo di chiusura automatica
    $out = json_decode(substr($text, $start) . str_repeat('}', max(0, $depth)), true);
    return is_array($out) ? $out : null;
}

/** Verifica rapida di una chiave API */
function ts_ai_test(?string $provider = null): array {
    $provider = $provider ?: ts_ai_active_provider();
    $r = ts_ai_call(
        'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo.',
        'Restituisci esattamente questo oggetto: {"status":"ok"}',
        null,
        $provider,
        200
    );
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'provider' => $provider];
    $j = ts_extract_json($r['text']);
    return [
        'ok'       => is_array($j),
        'error'    => is_array($j) ? '' : 'risposta non interpretabile',
        'model'    => $r['model'],
        'provider' => $provider,
    ];
}

/** Compatibilita' con il codice della versione 1.x */
function ts_claude_test(): array { return ts_ai_test('anthropic'); }
