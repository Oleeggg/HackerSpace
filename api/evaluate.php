<?php
declare(strict_types=1);

// Очистка буфера
while (ob_get_level()) ob_end_clean();

// Настройка заголовков
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-CSRF-Token, X-Requested-With');

// Логирование ошибок
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/evaluate_errors.log');
error_reporting(E_ALL);

// Подключение конфигурации
require_once(__DIR__ . '/../config.php');

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Метод не поддерживается']));
}

// Инициализация сессии
session_start([
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

// Проверка CSRF токена
if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || 
    empty($_SESSION['csrf_token']) || 
    !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Ошибка проверки CSRF токена']));
}

// Получение входных данных
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Неверный JSON формат']));
}

// Валидация входных данных
$requiredFields = ['solution', 'language'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => "Обязательное поле отсутствует: $field"]));
    }
}

// Проверка поддерживаемых языков
$allowedLanguages = ['javascript', 'php', 'python', 'html', 'css'];
$language = strtolower($input['language']);
if (!in_array($language, $allowedLanguages)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => "Неподдерживаемый язык: $language"]));
}

// Проверка существования задания
if (empty($_SESSION['current_task'])) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'Задание не найдено']));
}

$task = $_SESSION['current_task'];

// Формирование промпта для оценки
$prompt = [
    'model' => DEVSTRAL_MODEL,
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Ты — ассистент для проверки кода. Отвечай ТОЛЬКО в формате JSON. Шаблон ответа: {
  "score": 0-100,
  "correctness": 0-100,
  "efficiency": 0-100,
  "readability": 0-100,
  "message": "Краткий вердикт",
  "details": "Подробный анализ",
  "suggestions": ["Конкретные", "рекомендации"]
}'
        ],
        [
            'role' => 'user',
            'content' => "Задание: {$task['description']}\nЯзык: $language\nРешение:\n{$input['solution']}"
        ]
    ],
    'temperature' => 0.2,
    'max_tokens' => 1500,
    'response_format' => ['type' => 'json_object']
];

try {
    // Отправка запроса к API
    $response = makeApiRequest($prompt);

    // Проверка HTTP статуса
    if ($response['code'] !== 200) {
        throw new Exception("API вернул статус {$response['code']}", $response['code']);
    }

    // Обработка ответа API
    $apiResponse = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Неверный формат ответа API");
    }

    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        throw new Exception("Неожиданная структура ответа API");
    }

    // Извлечение оценки
    $evaluation = json_decode($apiResponse['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Попытка извлечь JSON из строки
        if (preg_match('/\{.*\}/s', $apiResponse['choices'][0]['message']['content'], $matches)) {
            $evaluation = json_decode($matches[0], true);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Не удалось распарсить оценку");
        }
    }

    // Нормализация оценки
    $evaluation = array_merge([
        'score' => 0,
        'correctness' => 0,
        'efficiency' => 0,
        'readability' => 0,
        'message' => 'Проверка не выполнена',
        'details' => 'Не удалось получить детали оценки',
        'suggestions' => []
    ], $evaluation);

    // Формирование результата
    $result = [
        'success' => true,
        'evaluation' => $evaluation,
        'task_id' => $task['id'] ?? null,
        'language' => $language,
        'timestamp' => time()
    ];

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Логирование ошибки
    error_log("[" . date('Y-m-d H:i:s') . "] Ошибка: " . $e->getMessage());

    // Формирование ответа с ошибкой
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'evaluation' => [
            'score' => 0,
            'message' => 'Ошибка проверки',
            'details' => $e->getMessage()
        ]
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
        CURLOPT_TIMEOUT => 30,
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