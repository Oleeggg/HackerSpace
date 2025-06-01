<?php
declare(strict_types=1);

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
    // Формирование промпта с улучшенными инструкциями
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a code evaluation assistant. ALWAYS respond with VALID JSON ONLY using this exact structure:
{
  "score": "number 0-100",
  "correctness": "number 0-100",
  "efficiency": "number 0-100", 
  "readability": "number 0-100",
  "message": "brief summary",
  "details": "detailed analysis",
  "suggestions": ["array", "of", "improvements"]
}'
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

    // Отправка запроса с улучшенной обработкой
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
        CURLOPT_CONNECTTIMEOUT => 15,
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

    // Логирование сырого ответа для отладки
    file_put_contents(__DIR__ . '/api_response.log', 
        "[" . date('Y-m-d H:i:s') . "] Response:\n" . 
        "HTTP Code: $httpCode\nHeaders: $headers\nBody: $body\n",
        FILE_APPEND
    );

    if ($httpCode !== 200) {
        throw new Exception("API returned HTTP $httpCode");
    }

    // Улучшенная обработка ответа
    $response = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid API response format");
    }

    if (empty($response['choices'][0]['message']['content'])) {
        throw new Exception("Empty content in API response");
    }

    $content = $response['choices'][0]['message']['content'];
    
    // Парсинг оценки с несколькими fallback-ами
    $evaluation = json_decode($content, true);
    if ($evaluation === null) {
        // Попытка 1: Извлечь JSON из markdown
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $evaluation = json_decode($matches[1], true);
        }
        
        // Попытка 2: Найти первый JSON в тексте
        if ($evaluation === null && preg_match('/\{.*\}/s', $content, $matches)) {
            $evaluation = json_decode($matches[0], true);
        }
        
        if ($evaluation === null) {
            throw new Exception("Could not parse evaluation from: " . substr($content, 0, 200));
        }
    }

    // Нормализация структуры
    $evaluation = array_merge([
        'score' => 0,
        'correctness' => 0,
        'efficiency' => 0,
        'readability' => 0,
        'message' => 'No evaluation provided',
        'details' => '',
        'suggestions' => []
    ], $evaluation);

    // Валидация оценки
    if (!is_numeric($evaluation['score']) || $evaluation['score'] < 0 || $evaluation['score'] > 100) {
        $evaluation['score'] = 0;
    }

    // Успешный ответ
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation,
        'debug' => DEBUG_MODE ? ['raw_content' => $content] : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Evaluation Error: " . $e->getMessage());
    
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
            'last_response' => $body ?? null
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
        CURLOPT_TIMEOUT => 100,
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