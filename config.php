<?php
// Режим отладки
define('DEBUG_MODE', true);

// Настройки OpenRouter API
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('DEVSTRAL_MODEL', 'mistralai/mistral-7b-instruct:free');
define('DEBUG_MODE', true);
define('TASK_VARIATIONS', 5); // Количество вариаций заданий
define('CACHE_EXPIRE', 3600); // 1 час в секундах

// Проверка API ключа (после определения!)
if (empty(OPENROUTER_API_KEY) || OPENROUTER_API_KEY === 'sk-or-v1-...') {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Invalid API key configuration']));
}

// Настройки безопасности
header('Content-Security-Policy: default-src \'self\' https://cdnjs.cloudflare.com https://openrouter.ai');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Генерация CSRF токена
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_secure' => true,
        'cookie_httponly' => true
    ]);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>