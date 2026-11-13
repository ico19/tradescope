<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
ts_boot();
ts_security_headers();

if (ts_auth_check()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ts_csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Sessione scaduta, riprova.';
    } elseif (ts_auth_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Credenziali non valide.';
        usleep(400000);
    }
}
$title = (string)ts_opt('ui', 'title', 'TradeScope');
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= ts_e($title) ?> - Accesso</title>
<link rel="stylesheet" href="assets/app.css?v=<?= TS_VERSION ?>">
</head>
<body>
<div class="login-wrap">
  <form class="login-card" method="post" autocomplete="off">
    <h1><span class="brand-dot" style="display:inline-block;margin-right:8px"></span><?= ts_e($title) ?></h1>
    <p class="sub">Area riservata - accesso protetto</p>
    <?php if ($error): ?><div class="alert err"><?= ts_e($error) ?></div><?php endif; ?>
    <div class="field">
      <label>Utente</label>
      <input type="text" name="username" required autofocus>
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>
    <input type="hidden" name="csrf" value="<?= ts_e(ts_csrf_token()) ?>">
    <button class="btn" type="submit" style="width:100%">Entra</button>
  </form>
</div>
</body>
</html>
