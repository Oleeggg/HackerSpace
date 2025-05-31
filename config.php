<?php
// Включение обработки CORS в самом начале
header("Access-Control-Allow-Origin: " . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Настройки безопасности
header('Content-Security-Policy: default-src \'self\' https://cdnjs.cloudflare.com https://openrouter.ai');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Настройки ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
ini_set('ignore_repeated_errors', 1);
ini_set('ignore_repeated_source', 1);
ini_set('report_memleaks', 1);
ini_set('track_errors', 1);
ini_set('html_errors', 0);

// Удалить все буферы вывода
while (ob_get_level()) ob_end_clean();

// Начать буферизацию
ob_start();

// Режим отладки
define('DEBUG_MODE', true);

// Проверка BOM
if (ob_get_level()) ob_end_clean();

// Конфигурация API
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('DEVSTRAL_MODEL', 'deepseek/deepseek-r1-0528:free');

// Проверка API ключа
if (empty(OPENROUTER_API_KEY) {
    error_log('API key is not configured');
    http_response_code(500);
    die(json_encode([
        'error' => 'Internal server error',
        'message' => 'Server configuration error'
    ]));
}

// Создание директории для логов если не существует
if (!file_exists(__DIR__ . '/../logs')) {
    mkdir(__DIR__ . '/../logs', 0755, true);
}

// Инициализация сессии с улучшенными параметрами безопасности
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    session_start([
        'name' => 'HACKERSPACE_SESSID',
        'cookie_lifetime' => 86400,
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_httponly' => true,
        'use_strict_mode' => true,
        'use_only_cookies' => 1,
        'gc_maxlifetime' => 86400,
        'sid_length' => 128,
        'sid_bits_per_character' => 6
    ]);
}

// Генерация CSRF токена если не существует
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
        
        if (DEBUG_MODE) {
            error_log("Generated new CSRF token: " . $_SESSION['csrf_token']);
        }
    } catch (Exception $e) {
        error_log("CSRF token generation failed: " . $e->getMessage());
        http_response_code(500);
        die(json_encode([
            'error' => 'Internal server error',
            'message' => 'Security system initialization failed'
        ]));
    }
}

// Проверка времени жизни CSRF токена (обновляем каждые 24 часа)
if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time'] > 86400)) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}

// Защита от сессионной фиксации
if (empty($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

// Проверка безопасности сессии
function validate_session(): bool {
    if ($_SESSION['user_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '') ||
        $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        session_unset();
        session_destroy();
        return false;
    }
    return true;
}

// В конце файла - очистка буфера и установка заголовков
ob_end_flush();
?>