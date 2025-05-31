<?php
require_once(__DIR__ . '/../config.php');

// Очистка буфера и заголовки
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// Улучшенная функция обработки ответа API
function processApiResponse($responseBody) {
    // Сохраняем сырой ответ для отладки
    file_put_contents(__DIR__ . '/last_api_response.txt', $responseBody);
    
    // Пытаемся декодировать как чистый JSON
    $jsonData = json_decode($responseBody, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $jsonData;
    }
    
    // Пытаемся извлечь JSON из возможных оберток
    $jsonPatterns = [
        '/```json\s*(\{.*\})\s*```/s',    // Markdown с кодом JSON
        '/```\s*(\{.*\})\s*```/s',        // Markdown без указания json
        '/<pre><code>\s*(\{.*\})\s*<\/code><\/pre>/is', // HTML+pre+code
        '/<pre>\s*(\{.*\})\s*<\/pre>/is', // HTML+pre
        '/\{.*\}/s'                        // Просто JSON в тексте
    ];
    
    foreach ($jsonPatterns as $pattern) {
        if (preg_match($pattern, $responseBody, $matches)) {
            $jsonData = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $jsonData;
            }
        }
    }
    
    // Если ничего не помогло - пробуем очистить HTML и распарсить
    $cleaned = strip_tags($responseBody);
    $jsonData = json_decode($cleaned, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $jsonData;
    }
    
    throw new Exception("Failed to parse API response. First 200 chars: " . substr($responseBody, 0, 200));
}

try {
    session_start();
    
    // Проверка CSRF токена
    if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || empty($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
        throw new Exception('CSRF token validation failed');
    }

    // Получение входных данных
    $jsonInput = file_get_contents('php://input');
    if ($jsonInput === false) {
        throw new Exception('Failed to read input data');
    }

    $input = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    // Валидация
    $required = ['solution', 'language'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    if (!isset($_SESSION['current_task'])) {
        throw new Exception('No active task found');
    }

    $task = $_SESSION['current_task'];
    
    // Формирование строгого промпта
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты — ассистент для проверки кода. Отвечай ТОЛЬКО в формате JSON без каких-либо пояснений или оберток. Шаблон ответа:
{
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

    // Отправка запроса
    $response = makeApiRequest($prompt);

    // Проверка HTTP статуса
    if ($response['code'] !== 200) {
        throw new Exception("API returned status {$response['code']}. Response: " . substr($response['body'], 0, 200));
    }

    // Парсинг ответа
    $apiResponse = processApiResponse($response['body']);
    
    // Проверка структуры ответа AI
    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        throw new Exception("Unexpected API response structure");
    }

    // Получение и проверка контента
    $content = $apiResponse['choices'][0]['message']['content'];
    if (is_string($content)) {
        $evaluation = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid evaluation format: " . json_last_error_msg());
        }
    } else {
        $evaluation = $content;
    }

    // Нормализация оценки
    $defaultEvaluation = [
        'score' => 50,
        'correctness' => 50,
        'efficiency' => 50,
        'readability' => 50,
        'message' => 'Evaluation completed',
        'details' => 'No detailed feedback available',
        'suggestions' => []
    ];
    
    $evaluation = array_merge($defaultEvaluation, $evaluation);
    
    // Ограничение значений
    foreach (['score', 'correctness', 'efficiency', 'readability'] as $key) {
        $evaluation[$key] = max(0, min(100, (int)$evaluation[$key]));
    }

    // Формирование ответа
    $result = [
        'success' => true,
        'evaluation' => $evaluation,
        'task_id' => $task['id'] ?? null,
        'language' => $input['language'],
        'timestamp' => time()
    ];

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Логирование
    error_log("Evaluation error: " . $e->getMessage());
    
    // Формирование ошибки
    $errorResponse = [
        'success' => false,
        'error' => $e->getMessage(),
        'evaluation' => [
            'score' => 0,
            'message' => 'Evaluation failed',
            'details' => $e->getMessage()
        ]
    ];
    
    http_response_code(500);
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
}

// Улучшенная функция запроса
function makeApiRequest($data, $maxRetries = 3) {
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
        
        if (curl_errno($ch)) {
            $lastError = curl_error($ch);
            curl_close($ch);
            $retryCount++;
            if ($retryCount <= $maxRetries) {
                usleep(500000 * $retryCount); // Увеличивающаяся пауза
                continue;
            }
            throw new Exception("CURL error after $maxRetries attempts: $lastError");
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'code' => $httpCode,
            'body' => $response
        ];
        
    } while ($retryCount <= $maxRetries);
    
    throw new Exception("Max retries ($maxRetries) reached: $lastError");
}
?>