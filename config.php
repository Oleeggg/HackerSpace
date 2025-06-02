<?php
/**
 * Конфигурационный файл с улучшенной обработкой сессий и безопасности
 */

// 1. Настройки ошибок (в самом начале)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/errors.log');

// 2. Безопасные заголовки (до любого вывода)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// 3. Настройка CORS для API
header("Access-Control-Allow-Origin: " . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");

// 4. Обработка OPTIONS запросов для CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 5. Инициализация сессии с улучшенными настройками безопасности
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'name' => 'HS_SESSID', // Уникальное имя сессии
        'cookie_lifetime' => 86400, // 24 часа
        'cookie_secure' => isset($_SERVER['HTTPS']), // Только HTTPS
        'cookie_httponly' => true, // Недоступно для JS
        'cookie_samesite' => 'Lax', // Защита от CSRF
        'use_strict_mode' => true, // Строгий режим сессии
        'use_only_cookies' => 1, // Только cookie-сессии
        'hash_function' => 'sha256', // Алгоритм хеширования
        'gc_maxlifetime' => 86400 // Время жизни сессии
    ]);
}

// 6. Генерация CSRF токена если отсутствует
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    // Регенерация ID сессии для защиты от фиксации
    session_regenerate_id(true);
}

// 7. Настройки API OpenRouter
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('DEVSTRAL_MODEL', 'deepseek/deepseek-r1-0528:free');

// 8. Проверка валидности API ключа
if (empty(OPENROUTER_API_KEY) || OPENROUTER_API_KEY === 'sk-or-v1-...') {
    error_log('Invalid API key configuration');
    die(json_encode(['error' => 'Server configuration error']));
}

// 9. Дополнительные заголовки безопасности
header('Content-Security-Policy: default-src \'self\' https://cdnjs.cloudflare.com https://openrouter.ai');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
header('Referrer-Policy: strict-origin-when-cross-origin');

// 10. Режим отладки
define('DEBUG_MODE', true);

// 11. Функция для очистки буферов
function cleanOutputBuffers() {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

// 12. Очистка буферов перед любым выводом
cleanOutputBuffers();

// 13. Функция для валидации CSRF токена
function validateCsrfToken() {
    if (empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        if (DEBUG_MODE) error_log('CSRF token not provided');
        return false;
    }
    
    if (!hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
        if (DEBUG_MODE) {
            error_log('CSRF token mismatch');
            error_log('Session token: ' . $_SESSION['csrf_token']);
            error_log('Received token: ' . $_SERVER['HTTP_X_CSRF_TOKEN']);
        }
        return false;
    }
    
    return true;
}

// 14. Установка времени по умолчанию
date_default_timezone_set('Europe/Moscow');
?>