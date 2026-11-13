<?php
/**
 * TradeScope - installazione guidata
 * Al termine dell'installazione questo file va eliminato dal server.
 */
declare(strict_types=1);

define('TS_ROOT', __DIR__);
define('TS_STORAGE', __DIR__ . '/storage');
define('TS_VERSION', '2.1.0');
require_once __DIR__ . '/inc/util.php';

$configFile = __DIR__ . '/inc/config.php';
$installed  = is_file($configFile);
$errors = [];
$done = false;

/* ------------------------------------------------------------ requisiti */
$checks = [
    'PHP 8.0 o superiore'        => version_compare(PHP_VERSION, '8.0.0', '>='),
    'Estensione PDO'             => extension_loaded('pdo'),
    'PDO SQLite'                 => extension_loaded('pdo_sqlite'),
    'PDO MySQL (facoltativo)'    => extension_loaded('pdo_mysql'),
    'cURL o allow_url_fopen'     => function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
    'Estensione JSON'            => extension_loaded('json'),
    'Estensione mbstring'        => extension_loaded('mbstring'),
    'Cartella scrivibile'        => is_writable(__DIR__),
];
$blocking = ['PHP 8.0 o superiore', 'Estensione PDO', 'Estensione JSON', 'Estensione mbstring', 'cURL o allow_url_fopen', 'Cartella scrivibile'];
$canInstall = true;
foreach ($blocking as $b) { if (!$checks[$b]) $canInstall = false; }

/* ---------------------------------------------------------- esecuzione */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canInstall) {
    $user   = trim((string)($_POST['username'] ?? 'admin'));
    $pass   = (string)($_POST['password'] ?? '');
    $pass2  = (string)($_POST['password2'] ?? '');
    $driver = (string)($_POST['db_driver'] ?? 'sqlite');
    $apiKey    = trim((string)($_POST['api_key'] ?? ''));
    $openaiKey = trim((string)($_POST['openai_api_key'] ?? ''));
    $aiProv    = (string)($_POST['ai_provider'] ?? 'anthropic');
    if (!in_array($aiProv, ['anthropic', 'openai'], true)) $aiProv = 'anthropic';
    $model     = (string)($_POST['model'] ?? '');
    $claudeModels = ['claude-sonnet-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-haiku-4-5-20251001'];
    $openaiModels = ['gpt-5.6-terra', 'gpt-5.6-sol', 'gpt-6-astra', 'gpt-5.6-luna'];
    $claudeModel = in_array($model, $claudeModels, true) ? $model : 'claude-sonnet-5';
    $openaiModel = in_array($model, $openaiModels, true) ? $model : 'gpt-5.6-terra';
    $tz     = (string)($_POST['timezone'] ?? 'Europe/Rome');
    $title  = trim((string)($_POST['title'] ?? 'TradeScope')) ?: 'TradeScope';

    if ($user === '') $errors[] = 'Nome utente obbligatorio.';
    if (strlen($pass) < 10) $errors[] = 'La password deve avere almeno 10 caratteri.';
    if ($pass !== $pass2) $errors[] = 'Le due password non coincidono.';

    $dbCfg = [
        'driver'  => $driver,
        'sqlite'  => __DIR__ . '/storage/tradescope.sqlite',
        'host'    => trim((string)($_POST['db_host'] ?? 'localhost')),
        'port'    => (int)($_POST['db_port'] ?? 3306),
        'name'    => trim((string)($_POST['db_name'] ?? '')),
        'user'    => trim((string)($_POST['db_user'] ?? '')),
        'pass'    => (string)($_POST['db_pass'] ?? ''),
        'charset' => 'utf8mb4',
    ];

    if (!$errors) {
        // Verifica connessione al database
        try {
            if ($driver === 'mysql') {
                new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbCfg['host'], $dbCfg['port'], $dbCfg['name']),
                        $dbCfg['user'], $dbCfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } else {
                if (!is_dir(TS_STORAGE)) @mkdir(TS_STORAGE, 0750, true);
                new PDO('sqlite:' . $dbCfg['sqlite'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
        } catch (Throwable $e) {
            $errors[] = 'Connessione al database non riuscita: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        foreach ([TS_STORAGE, TS_STORAGE . '/cache', TS_STORAGE . '/logs'] as $d) {
            if (!is_dir($d)) @mkdir($d, 0750, true);
        }
        // Protezione della cartella storage
        @file_put_contents(TS_STORAGE . '/.htaccess',
            "Require all denied\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
        @file_put_contents(TS_STORAGE . '/index.html', '');
        @file_put_contents(__DIR__ . '/inc/.htaccess',
            "Require all denied\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");

        $cronToken = bin2hex(random_bytes(16));
        $cfg = [
            'db'   => $dbCfg,
            'auth' => [
                'username'      => $user,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'session_name'  => 'tradescope_sid',
                'session_ttl'   => 43200,
            ],
            'ai' => ['provider' => $aiProv],
            'openai' => [
                'api_key'      => $openaiKey,
                'model'        => $openaiModel,
                'timeout'      => 120,
                'organization' => '',
                'base_url'     => 'https://api.openai.com/v1/responses',
            ],
            'claude' => [
                'api_key'     => $apiKey,
                'model'       => $claudeModel,
                'max_tokens'  => 4000,
                'timeout'     => 120,
                'api_version' => '2023-06-01',
                'base_url'    => 'https://api.anthropic.com/v1/messages',
            ],
            'engine' => [
                'cron_token'          => $cronToken,
                'candles'             => 300,
                'horizon_bars'        => 24,
                'max_per_run'         => 3,
                'min_minutes_between' => 30,
                'http_cache_ttl'      => 45,
                'retention_days'      => 120,
            ],
            'ui' => ['title' => $title, 'timezone' => $tz, 'locale' => 'it'],
        ];

        $php = "<?php\n/**\n * TradeScope - configurazione generata il " . gmdate('Y-m-d H:i') . " UTC\n */\nreturn "
             . var_export($cfg, true) . ";\n";
        // il percorso del database SQLite resta relativo alla cartella dell'applicazione
        $php = str_replace(var_export(__DIR__ . '/storage/tradescope.sqlite', true), "__DIR__ . '/../storage/tradescope.sqlite'", $php);

        if (@file_put_contents($configFile, $php) === false) {
            $errors[] = 'Impossibile scrivere inc/config.php. Verifica i permessi della cartella.';
        } else {
            require_once __DIR__ . '/inc/bootstrap.php';
            ts_boot(false);
            ts_db_migrate();

            // Watchlist iniziale
            if (!ts_watchlist()) {
                ts_watchlist_add('BTCUSDT', 'binance', '4h', 'Bitcoin');
                ts_watchlist_add('ETHUSDT', 'binance', '4h', 'Ethereum');
                ts_watchlist_add('SOLUSDT', 'binance', '4h', 'Solana');
            }
            @file_put_contents(TS_STORAGE . '/installed.lock', gmdate('c'));
            $done = true;
        }
    }
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://'
         . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>TradeScope - Installazione</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="login-wrap" style="align-items:flex-start;padding-top:40px">
  <div class="login-card" style="max-width:640px">
    <h1><span class="brand-dot" style="display:inline-block;margin-right:8px"></span>Installazione TradeScope</h1>
    <p class="sub">Configurazione iniziale del sistema di analisi</p>

    <?php if ($done): ?>
      <div class="alert ok">
        <b>Installazione completata.</b><br>
        Per sicurezza elimina ora il file <code>install.php</code> dal server.
      </div>
      <p class="small">Cron da impostare in hPanel (Avanzate &rarr; Cron Job), ogni 15 minuti:</p>
      <pre class="code">/usr/bin/php <?= ts_e(str_replace('\\', '/', __DIR__)) ?>/cron.php</pre>
      <a class="btn" href="login.php" style="display:inline-block;margin-top:10px">Vai all'accesso</a>

    <?php elseif ($installed && !isset($_POST['username'])): ?>
      <div class="alert warn">
        Il file <code>inc/config.php</code> esiste gia': il sistema risulta installato.<br>
        Elimina <code>install.php</code> dal server oppure rimuovi <code>inc/config.php</code> per reinstallare da zero.
      </div>
      <a class="btn" href="login.php">Vai all'accesso</a>

    <?php else: ?>

      <h3 style="border:none;padding:0;margin:18px 0 8px">Requisiti del server</h3>
      <?php foreach ($checks as $k => $v): ?>
        <div class="lvl"><span class="muted"><?= ts_e($k) ?></span>
          <span class="<?= $v ? 'up' : (in_array($k, $blocking, true) ? 'down' : 'flat') ?>"><?= $v ? 'ok' : 'assente' ?></span></div>
      <?php endforeach; ?>

      <?php if (!$canInstall): ?>
        <div class="alert err" style="margin-top:14px">Alcuni requisiti obbligatori non sono soddisfatti: contatta l'assistenza dell'hosting.</div>
      <?php endif; ?>

      <?php foreach ($errors as $e): ?><div class="alert err"><?= ts_e($e) ?></div><?php endforeach; ?>

      <form method="post" style="margin-top:18px" autocomplete="off">
        <h3 style="border:none;padding:0;margin:0 0 10px">Accesso</h3>
        <div class="form-row">
          <div class="field"><label>Nome utente</label><input type="text" name="username" value="<?= ts_e((string)($_POST['username'] ?? 'admin')) ?>" required></div>
          <div class="field"><label>Titolo del sistema</label><input type="text" name="title" value="<?= ts_e((string)($_POST['title'] ?? 'TradeScope')) ?>"></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Password (min. 10 caratteri)</label><input type="password" name="password" required></div>
          <div class="field"><label>Conferma password</label><input type="password" name="password2" required></div>
        </div>

        <hr class="sep">
        <h3 style="border:none;padding:0;margin:0 0 10px">Database</h3>
        <div class="field">
          <label>Tipo</label>
          <select name="db_driver" id="dbDriver">
            <option value="sqlite">SQLite (consigliato, nessuna configurazione)</option>
            <option value="mysql"<?= (($_POST['db_driver'] ?? '') === 'mysql') ? ' selected' : '' ?>>MySQL / MariaDB</option>
          </select>
        </div>
        <div id="mysqlFields" style="display:none">
          <div class="form-row">
            <div class="field"><label>Host</label><input type="text" name="db_host" value="<?= ts_e((string)($_POST['db_host'] ?? 'localhost')) ?>"></div>
            <div class="field"><label>Porta</label><input type="number" name="db_port" value="<?= ts_e((string)($_POST['db_port'] ?? '3306')) ?>"></div>
          </div>
          <div class="field"><label>Nome database</label><input type="text" name="db_name" value="<?= ts_e((string)($_POST['db_name'] ?? '')) ?>"></div>
          <div class="form-row">
            <div class="field"><label>Utente</label><input type="text" name="db_user" value="<?= ts_e((string)($_POST['db_user'] ?? '')) ?>"></div>
            <div class="field"><label>Password</label><input type="password" name="db_pass"></div>
          </div>
        </div>

        <hr class="sep">
        <h3 style="border:none;padding:0;margin:0 0 10px">Motore di analisi</h3>
        <div class="field">
          <label>Fornitore di intelligenza artificiale</label>
          <select name="ai_provider">
            <option value="anthropic">Anthropic - Claude</option>
            <option value="openai"<?= (($_POST['ai_provider'] ?? '') === 'openai') ? ' selected' : '' ?>>OpenAI - GPT</option>
          </select>
        </div>
        <div class="field">
          <label>Chiave API Anthropic (puoi inserirla anche dopo)</label>
          <input type="password" name="api_key" placeholder="sk-ant-...">
        </div>
        <div class="field">
          <label>Chiave API OpenAI (puoi inserirla anche dopo)</label>
          <input type="password" name="openai_api_key" placeholder="sk-...">
        </div>
        <div class="form-row">
          <div class="field">
            <label>Modello predefinito</label>
            <select name="model">
              <optgroup label="Anthropic - Claude">
                <option value="claude-sonnet-5">claude-sonnet-5 (equilibrio qualita/costo)</option>
                <option value="claude-opus-5">claude-opus-5 (analisi piu profonda)</option>
                <option value="claude-fable-5-1">claude-fable-5-1 (ragionamento avanzato)</option>
                <option value="claude-haiku-4-5-20251001">claude-haiku-4-5 (economico)</option>
              </optgroup>
              <optgroup label="OpenAI - GPT">
                <option value="gpt-5.6-terra">gpt-5.6-terra (equilibrio qualita/costo)</option>
                <option value="gpt-5.6-sol">gpt-5.6-sol (analisi piu profonda)</option>
                <option value="gpt-6-astra">gpt-6-astra (massima capacita)</option>
                <option value="gpt-5.6-luna">gpt-5.6-luna (economico)</option>
              </optgroup>
            </select>
          </div>
          <div class="field">
            <label>Fuso orario</label>
            <select name="timezone">
              <?php foreach (['Europe/Rome','Europe/London','UTC','America/New_York','Asia/Singapore'] as $tz): ?>
                <option value="<?= ts_e($tz) ?>"><?= ts_e($tz) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <button class="btn" type="submit" style="width:100%"<?= $canInstall ? '' : ' disabled' ?>>Installa</button>
      </form>

      <script>
        var d = document.getElementById('dbDriver'), f = document.getElementById('mysqlFields');
        function sync(){ f.style.display = d.value === 'mysql' ? 'block' : 'none'; }
        d.addEventListener('change', sync); sync();
      </script>
    <?php endif; ?>

    <p class="small muted" style="margin-top:20px">Percorso di installazione: <code><?= ts_e(str_replace('\\','/',__DIR__)) ?></code><br>
    URL: <code><?= ts_e($baseUrl) ?></code></p>
  </div>
</div>
</body>
</html>
