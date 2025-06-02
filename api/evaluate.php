<?php
declare(strict_types=1);

// 1. Улучшенная очистка буфера и настройка заголовков
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-CSRF-Token, X-Requested-With, Content-Type');
header('Access-Control-Allow-Methods: POST');

// 2. Улучшенное логирование ошибок
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/evaluate_errors.log');
error_reporting(E_ALL);

// 3. Подключение конфига в самом начале
require_once(__DIR__ . '/../config.php');

// 4. Логирование входящего запроса для отладки
file_put_contents(__DIR__ . '/evaluate_debug.log', 
    "[" . date('Y-m-d H:i:s') . "] New Request\n" .
    "Headers: " . print_r(getallheaders(), true) . "\n" .
    "Input: " . file_get_contents('php://input') . "\n\n",
    FILE_APPEND
);

// 5. Проверка метода с поддержкой OPTIONS для CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Только POST метод разрешен']));
}

// 6. Улучшенная инициализация сессии
session_start([
    'name' => 'HackerSpaceSess',
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'use_strict_mode' => true,
    'cookie_samesite' => 'Lax'
]);

// 7. Проверка CSRF с подробным логированием
if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || empty($_SESSION['csrf_token'])) {
    error_log("CSRF token missing. Session token: " . ($_SESSION['csrf_token'] ?? 'null'));
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Отсутствует CSRF токен']));
}

if (!hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
    error_log("CSRF token mismatch. Session: " . $_SESSION['csrf_token'] . " vs Header: " . $_SERVER['HTTP_X_CSRF_TOKEN']);
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Неверный CSRF токен']));
}

// 8. Получение и валидация входных данных
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Неверный JSON: ' . json_last_error_msg(),
        'input' => substr(file_get_contents('php://input'), 0, 200) // Логируем часть ввода для отладки
    ]));
}

// 9. Проверка обязательных полей
$required = ['solution', 'language'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => "Обязательное поле отсутствует: $field"]));
    }
}

// 10. Проверка поддерживаемых языков
$allowedLanguages = ['javascript', 'php', 'python', 'html', 'css'];
if (!in_array(strtolower($input['language']), $allowedLanguages)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Неподдерживаемый язык программирования']));
}

// 11. Проверка наличия задания в сессии
if (empty($_SESSION['current_task'])) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'Задание не найдено. Сначала получите новое задание.']));
}

$task = $_SESSION['current_task'];

try {
    // 12. Формирование промпта с защитой от инъекций
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты - ассистент для проверки кода. Отвечай ТОЛЬКО в формате JSON:
{
  "score": "число 0-100", 
  "correctness": "число 0-100",
  "efficiency": "число 0-100",
  "readability": "число 0-100",
  "message": "краткое описание",
  "details": "подробный анализ",
  "suggestions": ["массив", "предложений"]
}'
            ],
            [
                'role' => 'user',
                'content' => "ЗАДАНИЕ: {$task['description']}\nЯЗЫК: {$input['language']}\nРЕШЕНИЕ:\n{$input['solution']}"
            ]
        ],
        'temperature' => 0.3,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ];

    // 13. Отправка запроса с улучшенной обработкой ошибок
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($prompt),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: HackerSpaceWorkPage'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HEADER => true
    ]);

    $rawResponse = curl_exec($ch);
    
    // 14. Обработка ошибок cURL
    if (curl_errno($ch)) {
        throw new Exception("Ошибка cURL: " . curl_error($ch));
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 15. Логирование полного ответа API
    file_put_contents(__DIR__ . '/api_response.log', 
        "[" . date('Y-m-d H:i:s') . "] Response:\nHTTP Code: $httpCode\nBody: $rawResponse\n",
        FILE_APPEND
    );

    if ($httpCode !== 200) {
        throw new Exception("API вернул код $httpCode");
    }

    // 16. Парсинг ответа с улучшенной обработкой ошибок
    $response = json_decode($rawResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Попытка исправить невалидный JSON
        $cleaned = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $rawResponse);
        $response = json_decode($cleaned, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Не удалось распарсить JSON ответа: " . json_last_error_msg());
        }
    }

    // 17. Проверка структуры ответа
    if (empty($response['choices'][0]['message']['content'])) {
        throw new Exception("Пустой ответ от API");
    }

    $content = $response['choices'][0]['message']['content'];
    
    // 18. Парсинг оценки с несколькими fallback-ами
    $evaluation = json_decode($content, true);
    if ($evaluation === null) {
        // Попытка извлечь JSON из markdown
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $evaluation = json_decode($matches[1], true);
        }
        
        // Попытка найти JSON в тексте
        if ($evaluation === null && preg_match('/\{.*\}/s', $content, $matches)) {
            $evaluation = json_decode($matches[0], true);
        }
        
        if ($evaluation === null) {
            throw new Exception("Не удалось распарсить оценку из ответа");
        }
    }

    // 19. Нормализация структуры ответа
    $evaluation = array_merge([
        'score' => 0,
        'correctness' => 0,
        'efficiency' => 0,
        'readability' => 0,
        'message' => 'Оценка не предоставлена',
        'details' => '',
        'suggestions' => []
    ], $evaluation);

    // 20. Валидация числовых значений
    $evaluation['score'] = min(100, max(0, (int)($evaluation['score'] ?? 0));
    $evaluation['correctness'] = min(100, max(0, (int)($evaluation['correctness'] ?? 0)));
    $evaluation['efficiency'] = min(100, max(0, (int)($evaluation['efficiency'] ?? 0)));
    $evaluation['readability'] = min(100, max(0, (int)($evaluation['readability'] ?? 0)));

    // 21. Успешный ответ
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation,
        'debug' => DEBUG_MODE ? [
            'raw_content' => $content,
            'response' => $response
        ] : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // 22. Улучшенная обработка ошибок
    error_log("[" . date('Y-m-d H:i:s') . "] Ошибка: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при проверке решения. Пожалуйста, попробуйте снова.',
        'details' => DEBUG_MODE ? $e->getMessage() : null,
        'evaluation' => [
            'score' => 0,
            'message' => 'Ошибка проверки',
            'details' => 'Техническая ошибка'
        ],
        'debug' => DEBUG_MODE ? [
            'trace' => $e->getTraceAsString(),
            'last_response' => $rawResponse ?? null
        ] : null
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Отправка запроса к API
 */
function makeApiRequest(array $data): array {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage'
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("CURL error: $error");
    }
    
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response
    ];
}