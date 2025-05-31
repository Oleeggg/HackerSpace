<?php
// Включение строгого режима и буферизации вывода
declare(strict_types=1);

// Удаление всех возможных выводов перед началом работы
while (ob_get_level()) ob_end_clean();
ob_start();

// Подключение конфигурации
require_once(__DIR__ . '/../config.php');

// Установка заголовков
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * Улучшенная обработка ответа API
 */
function processApiResponse(string $responseBody): array {
    // Логирование сырого ответа для отладки
    file_put_contents(__DIR__ . '/last_api_response.txt', $responseBody);
    
    // Проверка на HTML-ошибки
    if (str_starts_with(trim($responseBody), '<!DOCTYPE') || 
        str_starts_with(trim($responseBody), '<html')) {
        throw new Exception("API вернул HTML вместо JSON");
    }

    // Попытка прямого декодирования JSON
    $jsonData = json_decode($responseBody, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $jsonData;
    }
    
    // Попытки извлечения JSON из разных форматов
    $patterns = [
        '/```json\s*(\{.*\})\s*```/s',
        '/```\s*(\{.*\})\s*```/s',
        '/<pre><code>\s*(\{.*\})\s*<\/code><\/pre>/is',
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
    
    throw new Exception("Не удалось распарсить ответ API. Ответ: " . substr($responseBody, 0, 200));
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
        throw new Exception("API вернул статус {$response['code']}", $response['code']);
    }

    // Обработка ответа
    $apiResponse = processApiResponse($response['body']);
    
    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        throw new Exception("Неожиданная структура ответа API");
    }

    $content = $apiResponse['choices'][0]['message']['content'];
    $evaluation = is_string($content) ? json_decode($content, true) : $content;

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ошибка формата оценки: " . json_last_error_msg());
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
    error_log("[" . date('Y-m-d H:i:s') . "] Evaluation error: " . $e->getMessage());
    
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
 * Функция запроса к API с улучшенной обработкой ошибок
 */
function makeApiRequest(array $data, int $maxRetries = 3): array {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage'
    ];

    $retryCount = 0;
    $lastError = null;
    
    do {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => OPENROUTER_API_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $lastError = $error;
            if (++$retryCount <= $maxRetries) {
                usleep(500000 * $retryCount);
                continue;
            }
            throw new Exception("CURL ошибка после $maxRetries попыток: $lastError");
        }
        
        return [
            'code' => $httpCode,
            'body' => $response
        ];
        
    } while ($retryCount <= $maxRetries);
    
    throw new Exception("Достигнуто максимальное число попыток ($maxRetries): $lastError");
}
?>