<?php
// Режим отладки
define('DEBUG_MODE', true);

// Проверка BOM
if (ob_get_level()) ob_end_clean();

// Проверка API ключа
if (!defined('OPENROUTER_API_KEY') || empty(OPENROUTER_API_KEY) || OPENROUTER_API_KEY === 'sk-or-v1-...') {
    die("Invalid API key configuration");
}

// Настройки OpenRouter API
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('DEVSTRAL_MODEL', 'mistralai/devstral-small:free');

// Настройки безопасности
header('Content-Security-Policy: default-src \'self\' https://cdnjs.cloudflare.com https://openrouter.ai');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Content-Type: application/json; charset=utf-8');

// Генерация CSRF токена если его нет
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>