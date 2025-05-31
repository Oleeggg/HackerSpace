<?php
declare(strict_types=1);

// Очистка буфера
if (ob_get_level() > 0) {
    ob_end_clean();
}

// Логирование ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/evaluate_errors.log');

// Подключение конфигурации
require_once(__DIR__ . '/../config.php');

// Установка заголовков
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Логирование запроса
file_put_contents(__DIR__ . '/api_requests.log', 
    "\n[" . date('Y-m-d H:i:s') . "] New request\n" . 
    "Headers: " . json_encode(getallheaders()) . "\n" .
    "Input: " . file_get_contents('php://input') . "\n",
    FILE_APPEND
);

/**
 * Улучшенная обработка ответа API
 */
function processApiResponse(string $responseBody): array {
    // Логирование сырого ответа
    file_put_contents(__DIR__ . '/api_responses.log', 
        "\n[" . date('Y-m-d H:i:s') . "] API Response\n" . $responseBody . "\n",
        FILE_APPEND
    );

    // Проверка на HTML-ошибки
    if (str_starts_with(trim($responseBody), '<!DOCTYPE') || 
        str_starts_with(trim($responseBody), '<html')) {
        throw new Exception("API вернул HTML вместо JSON: " . substr($responseBody, 0, 200));
    }

    // Попытка декодирования JSON
    $jsonData = json_decode($responseBody, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $jsonData;
    }
    
    // Попытки извлечения JSON из разных форматов
    $patterns = [
        '/```json\s*(\{.*\})\s*```/s',
        '/```\s*(\{.*\})\s*```/s',
        '/\{.*\}/s'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $responseBody, $matches)) {
            $jsonData = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $jsonData;
            }
        }
    }
    
    throw new Exception("Не удалось распарсить ответ API. Ответ: " . substr($responseBody, 0, 500));
}

/**
 * Функция запроса к API с улучшенной обработкой ошибок
 */
function makeApiRequest(array $data): array {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage'
    ];

    // Логирование запроса
    file_put_contents(__DIR__ . '/api_outgoing.log', 
        "\n[" . date('Y-m-d H:i:s') . "] Outgoing to OpenRouter\n" . 
        "Headers: " . json_encode($headers) . "\n" .
        "Data: " . json_encode($data) . "\n",
        FILE_APPEND
    );

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $error = curl_error($ch);
    curl_close($ch);

    // Логирование ответа
    file_put_contents(__DIR__ . '/api_incoming.log', 
        "\n[" . date('Y-m-d H:i:s') . "] Response from OpenRouter\n" . 
        "HTTP Code: $httpCode\n" .
        "Headers: $headers\n" .
        "Body: $body\n" .
        "Error: $error\n",
        FILE_APPEND
    );

    if ($error) {
        throw new Exception("CURL error: $error");
    }

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body
    ];
}

try {
    // Проверка сессии и CSRF-токена
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || 
        empty($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
        throw new Exception('Ошибка проверки CSRF-токена', 403);
    }

    // Получение и валидация входных данных
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Неверный JSON-ввод: ' . json_last_error_msg(), 400);
    }

    $required = ['solution', 'language'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Обязательное поле отсутствует: $field", 400);
        }
    }

    if (!isset($_SESSION['current_task'])) {
        throw new Exception('Активное задание не найдено', 404);
    }

    $task = $_SESSION['current_task'];
    
    // Формирование промпта
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
                'content' => "Задание: {$task['description']}\nЯзык: {$input['language']}\nРешение:\n{$input['solution']}"
            ]
        ],
        'temperature' => 0.2,
        'max_tokens' => 1500,
        'response_format' => ['type' => 'json_object']
    ];

    // Отправка запроса к API
    $response = makeApiRequest($prompt);

    // Проверка HTTP-статуса
    if ($response['code'] !== 200) {
        throw new Exception("OpenRouter API вернул статус {$response['code']}. Ответ: " . substr($response['body'], 0, 500), $response['code']);
    }

    // Обработка ответа
    $apiResponse = processApiResponse($response['body']);
    
    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        throw new Exception("Неожиданная структура ответа API: " . json_encode($apiResponse, JSON_UNESCAPED_UNICODE));
    }

    $content = $apiResponse['choices'][0]['message']['content'];
    $evaluation = is_string($content) ? json_decode($content, true) : $content;

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ошибка формата оценки: " . json_last_error_msg() . ". Контент: " . substr($content, 0, 500));
    }

    // Нормализация оценки
    $evaluation = array_merge([
        'score' => 50,
        'correctness' => 50,
        'efficiency' => 50,
        'readability' => 50,
        'message' => 'Проверка завершена',
        'details' => 'Детальный анализ недоступен',
        'suggestions' => []
    ], $evaluation);

    // Формирование итогового ответа
    $result = [
        'success' => true,
        'evaluation' => $evaluation,
        'task_id' => $task['id'] ?? null,
        'language' => $input['language'],
        'timestamp' => time()
    ];

    // Очистка буфера и вывод результата
    ob_end_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Очистка буфера перед выводом ошибки
    ob_end_clean();
    
    // Логирование ошибки
    error_log("[" . date('Y-m-d H:i:s') . "] Evaluation error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
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