<?php
/**
 * TradeScope - impostazioni
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
ts_boot();
ts_auth_require();
ts_security_headers();

$msg = ''; $msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ts_csrf_check($_POST['csrf'] ?? null)) {
        $msg = 'Sessione scaduta, riprova.'; $msgType = 'err';
    } else {
        $form = (string)($_POST['form'] ?? '');

        if ($form === 'engine') {
            foreach ([
                'ai_provider'     => ['ai', 'provider'],
                'claude_api_key'  => ['claude', 'api_key'],
                'claude_model'    => ['claude', 'model'],
                'claude_max_tokens' => ['claude', 'max_tokens'],
                'openai_api_key'  => ['openai', 'api_key'],
                'openai_model'    => ['openai', 'model'],
                'openai_organization' => ['openai', 'organization'],
                'engine_candles'  => ['engine', 'candles'],
                'engine_max_per_run' => ['engine', 'max_per_run'],
                'engine_min_minutes_between' => ['engine', 'min_minutes_between'],
                'engine_horizon_bars' => ['engine', 'horizon_bars'],
                'engine_retention_days' => ['engine', 'retention_days'],
                'ui_title'        => ['ui', 'title'],
            ] as $field => [$sec, $key]) {
                if (!isset($_POST[$field])) continue;
                $val = trim((string)$_POST[$field]);
                // le chiavi API non vengono sovrascritte con un valore vuoto
                if (in_array($field, ['claude_api_key', 'openai_api_key'], true) && $val === '') continue;
                ts_setting_set('cfg_' . $sec . '_' . $key, $val);
            }
            $msg = 'Impostazioni salvate.';
        }

        if ($form === 'password') {
            $new = (string)($_POST['new_password'] ?? '');
            $cur = (string)($_POST['current_password'] ?? '');
            $auth = ts_config('auth');
            if (!password_verify($cur, (string)$auth['password_hash'])) {
                $msg = 'Password attuale errata.'; $msgType = 'err';
            } elseif (strlen($new) < 10) {
                $msg = 'La nuova password deve avere almeno 10 caratteri.'; $msgType = 'err';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $file = __DIR__ . '/inc/config.php';
                $src  = (string)file_get_contents($file);
                $new_src = preg_replace_callback(
                    "/('password_hash'\s*=>\s*)'[^']*'/",
                    static fn(array $m): string => $m[1] . "'" . addcslashes($hash, "'\\") . "'",
                    $src,
                    1
                );
                if ($new_src && @file_put_contents($file, $new_src) !== false) {
                    $msg = 'Password aggiornata.';
                } else {
                    $msg = 'File di configurazione non scrivibile. Inserisci a mano questo hash in inc/config.php: ' . ts_e($hash);
                    $msgType = 'err';
                }
            }
        }

        if ($form === 'cron') {
            $tok = trim((string)($_POST['cron_token'] ?? ''));
            if ($tok === '') $tok = bin2hex(random_bytes(16));
            ts_setting_set('cfg_engine_cron_token', $tok);
            $msg = 'Token cron aggiornato.';
        }
    }
}

$apiKey     = ts_ai_api_key('anthropic');
$openaiKey  = ts_ai_api_key('openai');
$aiProvider = ts_ai_active_provider();
$cronToken = (string)ts_opt('engine', 'cron_token', '');
$title = (string)ts_opt('ui', 'title', 'TradeScope');
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://'
         . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
$lastRun = ts_db()->query('SELECT * FROM ts_runs ORDER BY id DESC LIMIT 1')->fetch();
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ts_e($title) ?> - Impostazioni</title>
<link rel="stylesheet" href="assets/app.css?v=<?= TS_VERSION ?>">
</head>
<body>
<header class="topbar">
  <div class="brand"><span class="brand-dot"></span><?= ts_e($title) ?></div>
  <div class="spacer"></div>
  <nav>
    <a href="index.php">Dashboard</a>
    <a href="history.php">Storico</a>
    <a href="settings.php" class="active">Impostazioni</a>
    <a href="logout.php">Esci</a>
  </nav>
</header>

<div class="layout" style="grid-template-columns:1fr">
  <main style="max-width:900px;margin:0 auto;width:100%">

    <?php if ($msg): ?><div class="alert <?= $msgType === 'ok' ? 'ok' : 'err' ?>"><?= $msg ?></div><?php endif; ?>

    <div class="card">
      <h2>Motore di analisi</h2>
      <div class="body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= ts_e(ts_csrf_token()) ?>">
          <input type="hidden" name="form" value="engine">
          <div class="field">
            <label>Fornitore usato per le analisi automatiche</label>
            <select name="ai_provider">
              <?php foreach (ts_ai_providers() as $pid => $plabel): ?>
                <option value="<?= ts_e($pid) ?>"<?= $pid === $aiProvider ? ' selected' : '' ?>><?= ts_e($plabel) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="small muted" style="margin:6px 0 0">Vale per il cron. Dalla dashboard puoi comunque lanciare
            un'analisi manuale con qualsiasi modello di cui hai inserito la chiave.</p>
          </div>

          <hr class="sep">
          <h3 style="border:none;padding:0;margin:0 0 10px">Anthropic - Claude</h3>
          <div class="field">
            <label>Chiave API Anthropic <?= $apiKey ? '<span class="up">(configurata)</span>' : '<span class="down">(mancante)</span>' ?></label>
            <input type="password" name="claude_api_key" placeholder="<?= $apiKey ? 'lascia vuoto per non modificare' : 'sk-ant-...' ?>" autocomplete="new-password">
          </div>
          <div class="form-row">
            <div class="field">
              <label>Modello Claude</label>
              <select name="claude_model">
                <?php $m = ts_ai_default_model('anthropic');
                foreach (ts_ai_models('anthropic') as $id => $desc): ?>
                  <option value="<?= ts_e($id) ?>"<?= $id === $m ? ' selected' : '' ?>><?= ts_e($desc) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Verifica</label>
              <button class="btn ghost" type="button" data-test="anthropic" style="width:100%">Verifica chiave Anthropic</button>
              <span class="small muted" id="testOut-anthropic"></span>
            </div>
          </div>

          <hr class="sep">
          <h3 style="border:none;padding:0;margin:0 0 10px">OpenAI - GPT</h3>
          <div class="field">
            <label>Chiave API OpenAI <?= $openaiKey ? '<span class="up">(configurata)</span>' : '<span class="down">(mancante)</span>' ?></label>
            <input type="password" name="openai_api_key" placeholder="<?= $openaiKey ? 'lascia vuoto per non modificare' : 'sk-...' ?>" autocomplete="new-password">
          </div>
          <div class="form-row">
            <div class="field">
              <label>Modello GPT</label>
              <select name="openai_model">
                <?php $mo = ts_ai_default_model('openai');
                foreach (ts_ai_models('openai') as $id => $desc): ?>
                  <option value="<?= ts_e($id) ?>"<?= $id === $mo ? ' selected' : '' ?>><?= ts_e($desc) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Verifica</label>
              <button class="btn ghost" type="button" data-test="openai" style="width:100%">Verifica chiave OpenAI</button>
              <span class="small muted" id="testOut-openai"></span>
            </div>
          </div>
          <div class="field">
            <label>ID organizzazione OpenAI (facoltativo)</label>
            <input type="text" name="openai_organization" value="<?= ts_e((string)ts_opt('openai','organization','')) ?>" placeholder="org-...">
          </div>

          <hr class="sep">
          <h3 style="border:none;padding:0;margin:0 0 10px">Parametri comuni</h3>
          <div class="form-row">
            <div class="field">
              <label>Token massimi per risposta</label>
              <input type="number" name="claude_max_tokens" value="<?= (int)ts_opt('claude','max_tokens',6000) ?>" min="1000" max="16000">
            </div>
            <div class="field">
              <label>&nbsp;</label>
              <p class="small muted" style="margin:0">Vale per entrambi i fornitori.</p>
            </div>
          </div>
          <div class="form-row">
            <div class="field">
              <label>Candele scaricate per analisi</label>
              <input type="number" name="engine_candles" value="<?= (int)ts_opt('engine','candles',300) ?>" min="100" max="1000">
            </div>
            <div class="field">
              <label>Barre di proiezione futura</label>
              <input type="number" name="engine_horizon_bars" value="<?= (int)ts_opt('engine','horizon_bars',24) ?>" min="6" max="60">
            </div>
          </div>
          <div class="form-row">
            <div class="field">
              <label>Strumenti analizzati per esecuzione cron</label>
              <input type="number" name="engine_max_per_run" value="<?= (int)ts_opt('engine','max_per_run',3) ?>" min="1" max="20">
            </div>
            <div class="field">
              <label>Minuti minimi tra due analisi dello stesso strumento</label>
              <input type="number" name="engine_min_minutes_between" value="<?= (int)ts_opt('engine','min_minutes_between',30) ?>" min="5" max="1440">
            </div>
          </div>
          <div class="form-row">
            <div class="field">
              <label>Giorni di storico conservati</label>
              <input type="number" name="engine_retention_days" value="<?= (int)ts_opt('engine','retention_days',120) ?>" min="7" max="3650">
            </div>
            <div class="field">
              <label>Titolo dell'applicazione</label>
              <input type="text" name="ui_title" value="<?= ts_e($title) ?>">
            </div>
          </div>
          <button class="btn" type="submit">Salva impostazioni</button>
        </form>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Analisi automatiche (cron)</h2>
      <div class="body">
        <p class="small muted">Il cron aggiorna le analisi anche quando il sito non e' aperto: e' cio' che rende il sistema
        sempre consultabile con dati recenti. Su Hostinger: <b>hPanel &rarr; Avanzate &rarr; Cron Job</b>.</p>

        <p class="small"><b>Comando consigliato (esecuzione PHP diretta):</b></p>
        <pre class="code">/usr/bin/php <?= ts_e(str_replace('\\', '/', __DIR__)) ?>/cron.php</pre>

        <p class="small"><b>In alternativa, tramite URL:</b></p>
        <pre class="code">wget -q -O /dev/null "<?= ts_e($baseUrl) ?>/cron.php?token=<?= ts_e($cronToken) ?>"</pre>

        <p class="small muted">Frequenza consigliata: ogni 15 minuti (<code>*/15 * * * *</code>) oppure ogni ora per contenere i costi API.</p>

        <form method="post" style="margin-top:14px">
          <input type="hidden" name="csrf" value="<?= ts_e(ts_csrf_token()) ?>">
          <input type="hidden" name="form" value="cron">
          <div class="field">
            <label>Token cron (lascia vuoto per generarne uno nuovo)</label>
            <input type="text" name="cron_token" value="<?= ts_e($cronToken) ?>">
          </div>
          <button class="btn ghost" type="submit">Aggiorna token</button>
        </form>

        <?php if ($lastRun): ?>
          <hr class="sep">
          <div class="small">
            <b>Ultima esecuzione:</b> <?= ts_e($lastRun['started_at']) ?> UTC -
            analizzati <?= (int)$lastRun['analyzed'] ?>, errori <?= (int)$lastRun['errors'] ?>
            <?php if (!empty($lastRun['detail'])): ?>
              <pre class="code" style="margin-top:8px"><?= ts_e((string)$lastRun['detail']) ?></pre>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Sicurezza</h2>
      <div class="body">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= ts_e(ts_csrf_token()) ?>">
          <input type="hidden" name="form" value="password">
          <div class="form-row">
            <div class="field">
              <label>Password attuale</label>
              <input type="password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="field">
              <label>Nuova password (minimo 10 caratteri)</label>
              <input type="password" name="new_password" required autocomplete="new-password">
            </div>
          </div>
          <button class="btn" type="submit">Aggiorna password</button>
        </form>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Diagnostica</h2>
      <div class="body small">
        <div class="lvl"><span class="muted">Versione</span><span class="mono"><?= TS_VERSION ?></span></div>
        <div class="lvl"><span class="muted">PHP</span><span class="mono"><?= ts_e(PHP_VERSION) ?></span></div>
        <div class="lvl"><span class="muted">Database</span><span class="mono"><?= ts_e(ts_db_driver()) ?></span></div>
        <div class="lvl"><span class="muted">Fornitore IA attivo</span><span class="mono"><?= ts_e(ts_ai_provider_label($aiProvider)) ?> - <?= ts_e(ts_ai_default_model($aiProvider)) ?></span></div>
        <div class="lvl"><span class="muted">cURL</span><span class="mono"><?= function_exists('curl_init') ? 'disponibile' : 'assente (fallback stream)' ?></span></div>
        <div class="lvl"><span class="muted">Cartella storage scrivibile</span><span class="mono"><?= is_writable(TS_STORAGE) ? 'si' : 'no' ?></span></div>
      </div>
    </div>

  </main>
</div>

<div class="toast" id="toast"></div>
<script>
Array.prototype.forEach.call(document.querySelectorAll('[data-test]'), function (btn) {
  btn.addEventListener('click', function () {
    var prov = btn.dataset.test;
    var out = document.getElementById('testOut-' + prov);
    out.textContent = 'verifica in corso...';
    btn.disabled = true;
    var fd = new FormData();
    fd.append('csrf', <?= json_encode(ts_csrf_token()) ?>);
    fd.append('provider', prov);
    fetch('api.php?action=test_ai', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        out.innerHTML = j.ok
          ? '<span class="up">chiave valida (' + (j.model || '') + ')</span>'
          : '<span class="down">' + (j.error || 'errore') + '</span>';
      })
      .catch(function () { out.innerHTML = '<span class="down">errore di rete</span>'; })
      .then(function () { btn.disabled = false; });
  });
});
</script>
</body>
</html>
