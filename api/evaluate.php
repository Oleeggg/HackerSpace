<?php
declare(strict_types=1);

// Очистка буфера и начало новой буферизации
while (ob_get_level()) ob_end_clean();
ob_start();

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
 * Улучшенная обработка ответа API с расширенной проверкой JSON
 */
function processApiResponse(string $responseBody): array {
    // Нормализация строки
    $responseBody = trim($responseBody);
    
    // Логирование сырого ответа
    file_put_contents(__DIR__ . '/api_responses.log', 
        "\n[" . date('Y-m-d H:i:s') . "] API Response\n" . $responseBody . "\n",
        FILE_APPEND
    );

    // Проверка на HTML-ошибки
    if (str_starts_with($responseBody, '<!DOCTYPE') || 
        str_starts_with($responseBody, '<html')) {
        throw new Exception("API вернул HTML вместо JSON: " . substr($responseBody, 0, 200));
    }

    // Попытка прямого декодирования JSON
    $jsonData = json_decode($responseBody, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $this->validateEvaluation($jsonData);
    }
    
    // Попытки извлечения JSON из разных форматов
    $patterns = [
        '/```json\s*(\{.*\})\s*```/s',
        '/```\s*(\{.*\})\s*```/s',
        '/\{.*\}/s'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $responseBody, $matches)) {
            try {
                $jsonData = json_decode($matches[1], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $this->validateEvaluation($jsonData);
                }
            } catch (Exception $e) {
                continue;
            }
        }
    }
    
    // Попытка исправить распространённые проблемы с JSON
    $fixedJson = $this->attemptJsonFix($responseBody);
    $jsonData = json_decode($fixedJson, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $this->validateEvaluation($jsonData);
    }
    
    throw new Exception("Не удалось распарсить ответ API. Ошибка JSON: " . json_last_error_msg() . ". Ответ: " . substr($responseBody, 0, 500));
}

/**
 * Попытка исправить распространённые проблемы с JSON
 */
private function attemptJsonFix(string $json): string {
    // Удаление висячих запятых
    $fixed = preg_replace('/,\s*([}\]])/', '$1', $json);
    
    // Исправление незакавыченных ключей
    $fixed = preg_replace('/(\w+)\s*:/', '"$1":', $fixed);
    
    // Удаление лишних символов в начале/конце
    $fixed = trim($fixed);
    $fixed = preg_replace('/^[^{[]*/', '', $fixed);
    $fixed = preg_replace('/[^}\]]*$/', '', $fixed);
    
    return $fixed;
}

/**
 * Валидация структуры оценки
 */
private function validateEvaluation(array $data): array {
    $requiredFields = ['score', 'correctness', 'efficiency', 'readability', 'message'];
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data)) {
            throw new Exception("Отсутствует обязательное поле в оценке: {$field}");
        }
    }
    
    // Нормализация значений
    $data['score'] = max(0, min(100, (int)$data['score']));
    $data['correctness'] = max(0, min(100, (int)$data['correctness']));
    $data['efficiency'] = max(0, min(100, (int)$data['efficiency']));
    $data['readability'] = max(0, min(100, (int)$data['readability']));
    
    if (!isset($data['details'])) {
        $data['details'] = '';
    }
    
    if (!isset($data['suggestions']) || !is_array($data['suggestions'])) {
        $data['suggestions'] = [];
    }
    
    return $data;
}

/**
 * Улучшенная функция запроса к API
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
        "Data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n",
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
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_FAILONERROR => true
    ]);

    $response = curl_exec($ch);
    
    if ($response === false) {
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        throw new Exception("CURL error {$errno}: {$error}");
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    // Логирование ответа
    file_put_contents(__DIR__ . '/api_incoming.log', 
        "\n[" . date('Y-m-d H:i:s') . "] Response from OpenRouter\n" . 
        "HTTP Code: {$httpCode}\n" .
        "Headers: {$headers}\n" .
        "Body length: " . strlen($body) . " bytes\n",
        FILE_APPEND
    );

    if ($httpCode >= 400) {
        throw new Exception("API вернул ошибку. HTTP код: {$httpCode}. Тело ответа: " . substr($body, 0, 500));
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
            throw new Exception("Обязательное поле отсутствует: {$field}", 400);
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

    // Обработка ответа
    $apiResponse = processApiResponse($response['body']);
    
    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        throw new Exception("Неожиданная структура ответа API: " . json_encode($apiResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    $content = $apiResponse['choices'][0]['message']['content'];
    $evaluation = is_string($content) ? json_decode($content, true) : $content;

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ошибка формата оценки: " . json_last_error_msg() . ". Контент: " . substr($content, 0, 500));
    }

    // Нормализация и валидация оценки
    $evaluation = $this->validateEvaluation($evaluation);

    // Формирование итогового ответа
    $result = [
        'success' => true,
        'evaluation' => $evaluation,
        'task_id' => $task['id'] ?? null,
        'language' => $input['language'],
        'timestamp' => time(),
        'debug' => [
            'api_response' => $apiResponse,
            'processed_content' => $content
        ]
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
            'details' => $e->getMessage(),
            'trace' => DEBUG_MODE ? $e->getTraceAsString() : null
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}