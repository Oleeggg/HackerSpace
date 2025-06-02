<?php
// Инициализация сессии и CSRF токена
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_httponly' => true,
        'use_strict_mode' => true
    ]);
}
// Включение обработки CORS в самом начале
header("Access-Control-Allow-Origin: " . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 🔥 ПЕРЕНЕСИТЕ ОПРЕДЕЛЕНИЕ КЛЮЧА ДО ПРОВЕРКИ
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('DEVSTRAL_MODEL', 'deepseek/deepseek-r1-0528:free');

// Настройки ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Удалить все буферы вывода

// Режим отладки
define('DEBUG_MODE', true);

// Проверка BOM
if (ob_get_level()) ob_end_clean();

// ✅ ПРАВИЛЬНАЯ ПРОВЕРКА КЛЮЧА
if (empty(OPENROUTER_API_KEY) || OPENROUTER_API_KEY === 'sk-or-v1-...') {
    die(json_encode(['error' => 'Invalid API key configuration']));
}

// Настройки безопасности
header('Content-Security-Policy: default-src \'self\' https://cdnjs.cloudflare.com https://openrouter.ai');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Content-Type: application/json; charset=utf-8');


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    if (DEBUG_MODE) {
        error_log("Generated new CSRF token: " . $_SESSION['csrf_token']);
    }
}

// В конце файла — очистить буфер
ob_end_clean();
?>