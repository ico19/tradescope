<?php
/**
 * TradeScope - compatibilita'.
 * Dalla versione 2.1 il client dell'intelligenza artificiale vive in ai.php
 * e supporta sia Anthropic (Claude) sia OpenAI (GPT).
 * Questo file resta solo per non rompere eventuali inclusioni esistenti.
 */
declare(strict_types=1);
require_once __DIR__ . '/ai.php';
