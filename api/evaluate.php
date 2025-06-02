<?php
declare(strict_types=1);

// 1. Подключение конфигурации (первым делом)
require_once __DIR__.'/../config.php';

// 2. Настройка обработки ошибок
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Fatal error: ".$error['message']." in ".$error['file'].":".$error['line']);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
            'debug' => DEBUG_MODE ? $error : null
        ]);
    }
});

// 3. Очистка буферов и настройка заголовков
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    // 4. Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Only POST requests allowed', 405);
    }

    // 5. Проверка CSRF токена
    if (!validateCsrfToken()) {
        throw new RuntimeException('CSRF token validation failed', 403);
    }

    // 6. Получение и валидация входных данных
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON input: '.json_last_error_msg(), 400);
    }

    $requiredFields = ['solution', 'language', 'task_id'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            throw new RuntimeException("Missing required field: $field", 400);
        }
    }

    // 7. Проверка языка программирования
    $allowedLanguages = ['javascript', 'python', 'php', 'html', 'css'];
    if (!in_array(strtolower($input['language']), $allowedLanguages)) {
        throw new RuntimeException('Unsupported programming language', 400);
    }

    // 8. Проверка существования задачи
    if (empty($_SESSION['current_task']) || $_SESSION['current_task']['id'] !== $input['task_id']) {
        throw new RuntimeException('Task not found or expired', 404);
    }

    $task = $_SESSION['current_task'];

    // 9. Подготовка промпта для оценки
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты — ассистент для проверки кода. Ответь строго в JSON формате:
{
  "score": 0-100,
  "correctness": "соответствие заданию",
  "efficiency": "оптимальность решения",
  "readability": "читаемость кода",
  "feedback": "развернутый комментарий",
  "improvements": ["список", "улучшений"]
}

Важно:
- Только JSON, без Markdown
- Оценивай строго'
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

    // 10. Отправка запроса к API
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($prompt),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: '.($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: HackerSpaceEvaluator'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new RuntimeException('API connection error: '.curl_error($ch));
    }
    
    if ($httpCode !== 200) {
        throw new RuntimeException("API returned HTTP $httpCode", $httpCode);
    }

    curl_close($ch);

    // 11. Парсинг ответа
    $responseData = json_decode($response, true);
    if (empty($responseData['choices'][0]['message']['content'])) {
        throw new RuntimeException('Invalid API response structure');
    }

    $evaluation = json_decode($responseData['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Failed to parse evaluation');
    }

    // 12. Нормализация оценки
    $evaluation = array_merge([
        'score' => 0,
        'correctness' => 'Не проверено',
        'efficiency' => 'Не проверено',
        'readability' => 'Не проверено',
        'feedback' => 'Нет комментариев',
        'improvements' => []
    ], $evaluation);

    // 13. Сохранение результата
    $_SESSION['last_evaluation'] = [
        'task_id' => $task['id'],
        'solution' => $input['solution'],
        'evaluation' => $evaluation,
        'timestamp' => time()
    ];

    // 14. Успешный ответ
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    // 15. Обработка ошибок
    http_response_code($e->getCode() ?: 500);
    error_log("Evaluation error: ".$e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => DEBUG_MODE ? [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTrace()
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