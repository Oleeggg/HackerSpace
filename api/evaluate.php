<?php
declare(strict_types=1);

// Регистрация обработчика фатальных ошибок
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
            'debug' => DEBUG_MODE ? $error : null
        ]);
        exit;
    }
});

// Очистка буфера и настройка заголовков
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-CSRF-Token, X-Requested-With');

// Логирование
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/evaluate_errors.log');
error_reporting(E_ALL);

require_once(__DIR__ . '/../config.php');

// Проверка метода
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Only POST method allowed']));
}

// Инициализация сессии
session_start([
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

// Проверка CSRF
if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || empty($_SESSION['csrf_token']) || 
    !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'CSRF token validation failed']));
}

// Получение данных
$input = file_get_contents('php://input');
if ($input === false) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Failed to read input data']));
}

$input = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Invalid JSON input: ' . json_last_error_msg()]));
}

// Валидация
$required = ['solution', 'language'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => "Missing required field: $field"]));
    }
}

$allowedLanguages = ['javascript', 'php', 'python', 'html', 'css'];
if (!in_array(strtolower($input['language']), $allowedLanguages)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Unsupported language']));
}

if (empty($_SESSION['current_task'])) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'Task not found']));
}

$task = $_SESSION['current_task'];

try {
    // Формирование промпта
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a code evaluation assistant. Respond STRICTLY with VALID JSON ONLY using this exact structure:
{
  "score": 0-100,
  "correctness": 0-100,
  "efficiency": 0-100, 
  "readability": 0-100,
  "message": "brief summary",
  "details": "detailed analysis",
  "suggestions": ["array", "of", "improvements"]
}

IMPORTANT:
- Do NOT include any additional text outside the JSON
- Do NOT wrap response in markdown or code blocks
- Do NOT include any explanations'
            ],
            [
                'role' => 'user',
                'content' => "TASK: {$task['description']}\nLANGUAGE: {$input['language']}\nSOLUTION:\n{$input['solution']}"
            ]
        ],
        'temperature' => 0.3,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ];

    // Отправка запроса
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($rawResponse, 0, $headerSize);
    $body = substr($rawResponse, $headerSize);
    
    if (curl_errno($ch)) {
        throw new Exception("CURL error: " . curl_error($ch));
    }
    
    curl_close($ch);

    // Проверка на HTML в ответе
    if (preg_match('/<(html|body|div|br)[^>]*>/i', $body)) {
        throw new Exception("API returned HTML content instead of JSON");
    }

    // Логирование сырого ответа
    file_put_contents(__DIR__ . '/api_response.log', 
        "[" . date('Y-m-d H:i:s') . "] Response:\n" . 
        "HTTP Code: $httpCode\nHeaders: $headers\nBody: " . substr($body, 0, 1000) . "\n",
        FILE_APPEND
    );

    if ($httpCode !== 200) {
        throw new Exception("API returned HTTP $httpCode");
    }

    // Парсинг ответа с улучшенной обработкой ошибок
    $response = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Попытка извлечь JSON из возможного текстового ответа
        if (preg_match('/\{.*\}/s', $body, $matches)) {
            $response = json_decode($matches[0], true);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid API response format. Raw response start: " . substr($body, 0, 200));
        }
    }

    if (empty($response['choices'][0]['message']['content'])) {
        throw new Exception("Empty content in API response");
    }

    $content = $response['choices'][0]['message']['content'];
    
    // Парсинг оценки с несколькими уровнями fallback
    $evaluation = json_decode($content, true);
    if ($evaluation === null) {
        // Попытка извлечь JSON из markdown
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $evaluation = json_decode($matches[1], true);
        }
        
        // Попытка найти первый JSON в тексте
        if ($evaluation === null && preg_match('/\{.*\}/s', $content, $matches)) {
            $evaluation = json_decode($matches[0], true);
        }
        
        if ($evaluation === null) {
            throw new Exception("Could not parse evaluation from: " . substr($content, 0, 200));
        }
    }

    // Нормализация структуры ответа
    $evaluation = array_merge([
        'score' => 0,
        'correctness' => 0,
        'efficiency' => 0,
        'readability' => 0,
        'message' => 'No evaluation provided',
        'details' => '',
        'suggestions' => []
    ], $evaluation);

    // Валидация числовых значений
    $numericFields = ['score', 'correctness', 'efficiency', 'readability'];
    foreach ($numericFields as $field) {
        if (!is_numeric($evaluation[$field])) {
            $evaluation[$field] = 0;
        } else {
            $evaluation[$field] = max(0, min(100, (int)$evaluation[$field]));
        }
    }

    // Успешный ответ
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation,
        'debug' => DEBUG_MODE ? ['raw_content' => $content] : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Evaluation Error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Evaluation failed. Please try again.',
        'evaluation' => [
            'score' => 0,
            'message' => 'Evaluation failed',
            'details' => $e->getMessage()
        ],
        'debug' => DEBUG_MODE ? [
            'trace' => $e->getTraceAsString(),
            'last_response' => isset($body) ? substr($body, 0, 500) : null
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