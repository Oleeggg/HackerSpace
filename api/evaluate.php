<?php
require_once(__DIR__ . '/../config.php');

// Очищаем буфер вывода и устанавливаем заголовки
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// Функция для безопасного извлечения текста из HTML
function extractTextFromHtml($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $text = '';
    
    // Извлекаем текст из основных элементов
    foreach ($xpath->query('//p|//div|//h1|//h2|//h3|//title') as $node) {
        $text .= $node->textContent . "\n";
    }
    
    return trim($text) ?: strip_tags($html);
}

try {
    session_start();
    
    // Улучшенная проверка CSRF токена
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
    
    // Формируем промпт для оценки
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a code evaluation assistant. Always respond with valid JSON containing: score (0-100), message, details, and suggestions.'
            ],
            [
                'role' => 'user',
                'content' => "Evaluate this solution for the task:\n\nTask: {$task['description']}\n\nLanguage: {$input['language']}\n\nSolution:\n{$input['solution']}"
            ]
        ],
        'temperature' => 0.5,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ];

    // Отправляем запрос к API
    $response = makeApiRequest($prompt);

    // Обрабатываем возможные ошибки API
    if ($response['code'] !== 200) {
        $errorDetails = "HTTP code: {$response['code']}";
        
        // Если получили HTML ошибку
        if (strpos($response['body'], '<!DOCTYPE') !== false || strpos($response['body'], '<html') !== false) {
            $errorDetails .= "\n" . extractTextFromHtml($response['body']);
        } else {
            $errorDetails .= "\nResponse: " . substr($response['body'], 0, 200);
        }
        
        throw new Exception("API request failed. $errorDetails");
    }

    // Парсим ответ API
    $apiResponse = json_decode($response['body'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid API response format: " . json_last_error_msg());
    }

    // Извлекаем содержимое сообщения
    if (!isset($apiResponse['choices'][0]['message']['content'])) {
        throw new Exception("Unexpected API response structure");
    }

    $content = $apiResponse['choices'][0]['message']['content'];
    
    // Парсим оценку
    if (is_string($content)) {
        $evaluation = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Если не удалось распарсить, создаем базовую оценку
            $evaluation = [
                'score' => 50,
                'message' => 'Solution submitted but evaluation failed',
                'details' => 'Could not parse AI response: ' . substr($content, 0, 200),
                'suggestions' => ['Please check your solution manually.']
            ];
        }
    } else {
        $evaluation = $content;
    }

    // Добавляем обязательные поля, если их нет
    $evaluation['score'] = $evaluation['score'] ?? 50;
    $evaluation['message'] = $evaluation['message'] ?? 'Evaluation completed';
    $evaluation['details'] = $evaluation['details'] ?? 'No detailed feedback provided';
    $evaluation['suggestions'] = $evaluation['suggestions'] ?? [];

    // Добавляем информацию о задании
    $evaluation['task_id'] = $task['id'] ?? null;
    $evaluation['language'] = $input['language'];

    // Возвращаем результат
    echo json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Формируем понятное сообщение об ошибке
    $errorResponse = [
        'error' => $e->getMessage(),
        'details' => 'Evaluation failed',
        'score' => 0,
        'message' => 'Evaluation error occurred',
        'suggestions' => ['Please try again later or contact support.']
    ];
    
    http_response_code(500);
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
}

function makeApiRequest($data) {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage',
        'X-CSRF-Token: ' . ($_SESSION['csrf_token'] ?? '')
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
        CURLOPT_HEADER => false
    ]);

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("CURL request failed: " . $error);
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response
    ];
}
?>