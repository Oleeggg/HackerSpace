<?php
declare(strict_types=1);

// 1. Инициализация сессии и обработка буферизации
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_httponly' => true,
        'use_strict_mode' => true
    ]);
}

// Очистка буферов вывода
while (ob_get_level()) ob_end_clean();

// 2. Настройка заголовков
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-CSRF-Token, X-Requested-With');

// 3. Логирование ошибок
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/evaluate_errors.log');
error_reporting(E_ALL);

require_once(__DIR__ . '/../config.php');

// 4. Обработка фатальных ошибок
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
            'debug' => DEBUG_MODE ? $error : null
        ]);
        exit;
    }
});

// 5. Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Only POST method allowed']));
}

// 6. Проверка CSRF токена
$csrfTokenHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (empty($csrfTokenHeader) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'CSRF token is missing']));
}

if (empty($_SESSION['csrf_token']) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Session CSRF token is missing']));
}

if (!hash_equals($_SESSION['csrf_token'], $csrfTokenHeader)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'CSRF token validation failed']));
}

// 7. Получение и валидация входных данных
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode([
        'success' => false, 
        'error' => 'Invalid JSON input: ' . json_last_error_msg()
    ]));
}

// 8. Проверка обязательных полей
$requiredFields = ['solution', 'language'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => "Missing required field: $field"]));
    }
}

// 9. Получение задания (из сессии или входных данных)
$task = $_SESSION['current_task'] ?? $input['task'] ?? null;
if (!$task) {
    http_response_code(404);
    die(json_encode([
        'success' => false, 
        'error' => 'Task not found. Please generate a new task first.'
    ]));
}

// 10. Валидация языка программирования
$allowedLanguages = ['javascript', 'php', 'python', 'html', 'css'];
if (!in_array(strtolower($input['language']), $allowedLanguages)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Unsupported language']));
}

try {
    // 11. Формирование промпта для оценки
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a strict code evaluation assistant. Respond ONLY with valid JSON using this structure:
{
  "score": 0-100,
  "correctness": 0-100,
  "efficiency": 0-100,
  "readability": 0-100,
  "message": "Brief evaluation summary",
  "details": "Detailed analysis",
  "suggestions": ["Array", "of", "improvements"]
}

Rules:
1. Be strict but fair
2. Consider code quality, correctness and efficiency
3. Provide actionable suggestions
4. NEVER include any text outside the JSON object'
            ],
            [
                'role' => 'user',
                'content' => "TASK: {$task['description']}\n\n" .
                             "LANGUAGE: {$input['language']}\n\n" .
                             "SOLUTION TO EVALUATE:\n{$input['solution']}"
            ]
        ],
        'temperature' => 0.3,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ];

    // 12. Отправка запроса к API
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
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception("API request failed: " . curl_error($ch));
    }
    
    curl_close($ch);

    // 13. Парсинг ответа
    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid API response format");
    }

    if ($httpCode !== 200) {
        throw new Exception("API returned HTTP $httpCode: " . ($responseData['error']['message'] ?? 'Unknown error'));
    }

    // 14. Извлечение контента оценки
    $content = $responseData['choices'][0]['message']['content'] ?? '';
    if (empty($content)) {
        throw new Exception("Empty evaluation content");
    }

    // 15. Парсинг JSON оценки (с поддержкой разных форматов)
    $evaluation = json_decode($content, true);
    if ($evaluation === null) {
        // Попытка извлечь JSON из markdown/текста
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches)) {
            $evaluation = json_decode($matches[1], true);
        } elseif (preg_match('/\{.*\}/s', $content, $matches)) {
            $evaluation = json_decode($matches[0], true);
        }
        
        if ($evaluation === null) {
            throw new Exception("Could not parse evaluation JSON");
        }
    }

    // 16. Нормализация структуры оценки
    $evaluation = array_merge([
        'score' => 0,
        'correctness' => 0,
        'efficiency' => 0,
        'readability' => 0,
        'message' => 'No evaluation provided',
        'details' => '',
        'suggestions' => []
    ], $evaluation);

    // 17. Валидация числовых значений
    $numericFields = ['score', 'correctness', 'efficiency', 'readability'];
    foreach ($numericFields as $field) {
        $evaluation[$field] = max(0, min(100, (int)($evaluation[$field] ?? 0)));
    }

    // 18. Успешный ответ
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation,
        'debug' => DEBUG_MODE ? [
            'task_id' => $task['id'] ?? null,
            'language' => $input['language'],
            'response_sample' => substr($response, 0, 200)
        ] : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // 19. Обработка ошибок
    error_log("[" . date('Y-m-d H:i:s') . "] Evaluation Error: " . $e->getMessage() . 
              "\nInput: " . json_encode($input, JSON_PRETTY_PRINT) . 
              "\nTask: " . json_encode($task, JSON_PRETTY_PRINT));
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Evaluation failed. Please try again.',
        'evaluation' => [
            'score' => 0,
            'message' => 'Evaluation failed: ' . $e->getMessage(),
            'details' => 'An error occurred during evaluation',
            'suggestions' => [
                'Check your solution for syntax errors',
                'Try simplifying your approach',
                'Make sure you understand the task requirements'
            ]
        ],
        'debug' => DEBUG_MODE ? [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
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