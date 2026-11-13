<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
ts_boot();
ts_auth_logout();
header('Location: login.php');
