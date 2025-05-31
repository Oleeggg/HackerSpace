<?php
require_once(__DIR__ . '/../config.php');

// Очищаем буфер вывода и устанавливаем заголовки
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// Улучшенная функция для извлечения JSON из ответа
function extractJsonFromResponse($response) {
    // Если ответ уже JSON
    if (is_array($response)) {
        return $response;
    }
    
    // Пытаемся декодировать как чистый JSON
    $decoded = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }
    
    // Пытаемся извлечь JSON из строки (может быть обернут в HTML/Markdown)
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
        return json_decode($matches[1], true);
    }
    
    if (preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $response, $matches)) {
        return json_decode(html_entity_decode($matches[1]), true);
    }
    
    if (preg_match('/\{.*\}/s', $response, $matches)) {
        return json_decode($matches[0], true);
    }
    
    return null;
}

try {
    session_start();
    
    // Проверка CSRF токена
    if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || empty($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
        throw new Exception('CSRF token validation failed');
    }

    // Получаем и проверяем входные данные
    $jsonInput = file_get_contents('php://input');
    if ($jsonInput === false) {
        throw new Exception('Failed to read input data');
    }

    $input = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    // Валидация обязательных полей
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
    
    // Улучшенный промпт для оценки
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a code evaluation assistant. Respond STRICTLY with this JSON format:
{
  "score": 0-100,
  "message": "Evaluation summary",
  "details": "Detailed feedback",
  "suggestions": ["array", "of", "improvements"],
  "correctness": "0-100",
  "efficiency": "0-100",
  "readability": "0-100"
}
Return ONLY the JSON object, no additional text or formatting.'
            ],
            [
                'role' => 'user',
                'content' => "Evaluate this solution for the task:\n\nTask: {$task['description']}\n\nLanguage: {$input['language']}\n\nSolution:\n{$input['solution']}"
            ]
        ],
        'temperature' => 0.3, // Понижаем температуру для более предсказуемых ответов
        'max_tokens' => 1500,
        'response_format' => ['type' => 'json_object']
    ];

    // Отправляем запрос к API с обработкой таймаутов
    $response = makeApiRequest($prompt);

    // Сохраняем сырой ответ для отладки
    file_put_contents(__DIR__ . '/last_eval_response.txt', $response['body']);

    // Обрабатываем HTML ошибки
    if (strpos($response['body'], '<!DOCTYPE') !== false || strpos($response['body'], '<html') !== false) {
        throw new Exception("API returned HTML page. Service might be unavailable.");
    }

    // Парсим ответ API
    $apiResponse = extractJsonFromResponse($response['body']);
    
    if ($apiResponse === null) {
        throw new Exception("Failed to parse API response: " . substr($response['body'], 0, 200));
    }

    // Проверяем структуру ответа
    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        throw new Exception("Unexpected API response structure");
    }

    // Получаем и парсим содержимое
    $content = $apiResponse['choices'][0]['message']['content'];
    $evaluation = is_string($content) ? extractJsonFromResponse($content) : $content;
    
    if ($evaluation === null) {
        throw new Exception("Failed to parse evaluation content");
    }

    // Нормализуем оценку
    $defaultEvaluation = [
        'score' => 50,
        'message' => 'Evaluation completed',
        'details' => 'No detailed feedback provided',
        'suggestions' => [],
        'correctness' => 50,
        'efficiency' => 50,
        'readability' => 50
    ];
    
    $evaluation = array_merge($defaultEvaluation, $evaluation);
    
    // Ограничиваем значения 0-100
    foreach (['score', 'correctness', 'efficiency', 'readability'] as $key) {
        if (isset($evaluation[$key])) {
            $evaluation[$key] = max(0, min(100, (int)$evaluation[$key]));
        }
    }

    // Добавляем метаданные
    $evaluation['task_id'] = $task['id'] ?? null;
    $evaluation['language'] = $input['language'];
    $evaluation['timestamp'] = time();

    // Возвращаем результат
    echo json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Логируем ошибку
    error_log("Evaluation error: " . $e->getMessage());
    
    // Формируем понятное сообщение об ошибке
    $errorResponse = [
        'error' => $e->getMessage(),
        'score' => 0,
        'message' => 'Evaluation failed',
        'details' => 'An error occurred during evaluation',
        'suggestions' => [
            'Please check your solution and try again',
            'If the problem persists, contact support'
        ],
        'timestamp' => time()
    ];
    
    http_response_code(500);
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
}

// Улучшенная функция запроса с повторами и таймаутом
function makeApiRequest($data, $maxRetries = 2) {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage',
        'X-CSRF-Token: ' . ($_SESSION['csrf_token'] ?? '')
    ];

    $retryCount = 0;
    $lastError = null;
    
    while ($retryCount <= $maxRetries) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => OPENROUTER_API_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 60, // Увеличенный таймаут
            CURLOPT_HEADER => false
        ]);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $lastError = curl_error($ch);
            $retryCount++;
            if ($retryCount <= $maxRetries) {
                usleep(500000); // Пауза 0.5 сек перед повторной попыткой
                continue;
            }
            curl_close($ch);
            throw new Exception("API request failed after $maxRetries attempts: $lastError");
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'code' => $httpCode,
            'body' => $response
        ];
    }
    
    throw new Exception("Max retries ($maxRetries) reached: $lastError");
}
?>