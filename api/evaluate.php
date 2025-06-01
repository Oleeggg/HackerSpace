<?php
declare(strict_types=1);

// Очистка буфера
while (ob_get_level()) ob_end_clean();

// Настройка заголовков
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
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Invalid JSON input']));
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

// Проверка задания (без восстановления сессии)
if (empty($_SESSION['current_task'])) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'No active task found']));
}

$task = $_SESSION['current_task'];

try {
    // Формирование промпта (без проверки повторов)
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a code evaluation assistant. Respond with VALID JSON only using this structure:
{
  "score": 0-100,
  "correctness": 0-100,
  "efficiency": 0-100,
  "readability": 0-100,
  "message": "Brief summary",
  "details": "Detailed analysis",
  "suggestions": ["Suggestions"]
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
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception("CURL error: " . curl_error($ch));
    }
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("API returned HTTP $httpCode");
    }

    // Обработка ответа
    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid API response format");
    }

    if (empty($responseData['choices'][0]['message']['content'])) {
        throw new Exception("Empty content in API response");
    }

    $content = $responseData['choices'][0]['message']['content'];
    $evaluation = json_decode($content, true);
    
    // Fallback для парсинга
    if ($evaluation === null) {
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $evaluation = json_decode($matches[0], true);
        }
        if ($evaluation === null) {
            throw new Exception("Could not parse evaluation");
        }
    }

    // Нормализация
    $evaluation = array_merge([
        'score' => 0,
        'message' => 'No evaluation provided',
        'details' => '',
        'suggestions' => []
    ], $evaluation);

    // Ответ
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Evaluation Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Evaluation failed: ' . $e->getMessage(),
        'evaluation' => [
            'score' => 0,
            'message' => 'Evaluation failed',
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