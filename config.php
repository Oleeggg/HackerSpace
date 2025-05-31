<?php
// Режим отладки
define('DEBUG_MODE', true);

// Проверка BOM
if (ob_get_level()) ob_end_clean();

// 🔥 ПЕРЕНЕСИТЕ ОПРЕДЕЛЕНИЕ КЛЮЧА ДО ПРОВЕРКИ
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('DEVSTRAL_MODEL', 'deepseek/deepseek-r1-0528:free');

// ✅ ПРАВИЛЬНАЯ ПРОВЕРКА КЛЮЧА (используйте константу, а не строку)
if (empty(OPENROUTER_API_KEY) || OPENROUTER_API_KEY === 'sk-or-v1-...') {
    die("Invalid API key configuration");
}

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