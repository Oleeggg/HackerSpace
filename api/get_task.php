<?php
declare(strict_types=1);

require_once __DIR__.'/../config.php';

// 1. Настройка вывода и заголовков
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 2. Функция для логирования
function logError(string $message, array $context = []): void {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    file_put_contents(__DIR__.'/task_errors.log', json_encode($logEntry)."\n", FILE_APPEND);
}

try {
    // 3. Проверка AJAX запроса
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        throw new RuntimeException('Direct access not allowed', 403);
    }

    // 4. Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Only POST method allowed', 405);
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

    // 7. Валидация параметров
    $allowedLanguages = ['javascript', 'python', 'php', 'html'];
    $allowedDifficulties = ['beginner', 'intermediate', 'advanced'];

    if (empty($input['language']) || !in_array(strtolower($input['language']), $allowedLanguages)) {
        throw new RuntimeException('Invalid programming language specified', 400);
    }

    if (empty($input['difficulty']) || !in_array(strtolower($input['difficulty']), $allowedDifficulties)) {
        throw new RuntimeException('Invalid difficulty level', 400);
    }

    $language = strtolower($input['language']);
    $difficulty = strtolower($input['difficulty']);

    // 8. Подготовка промпта
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты генератор программистских задач. Отвечай ТОЛЬКО в JSON формате:
{
    "title": "Название задачи",
    "description": "Подробное описание",
    "example": "Пример решения",
    "initialCode": "Начальный код",
    "difficulty": "Уровень сложности",
    "language": "Язык программирования"
}'
            ],
            [
                'role' => 'user',
                'content' => "Сгенерируй задачу на языке $language уровня сложности $difficulty. 
                Задача должна быть практической и интересной."
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ];

    // 9. Отправка запроса к API
    $startTime = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($prompt),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: '.($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: HackerSpaceTaskGenerator'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // 10. Логирование времени выполнения
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    file_put_contents(__DIR__.'/api_performance.log', 
        date('[Y-m-d H:i:s] ')."Execution time: {$executionTime}ms\n", 
        FILE_APPEND
    );

    if ($curlError) {
        throw new RuntimeException("API connection failed: ".$curlError, 500);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("API returned HTTP $httpCode", $httpCode);
    }

    // 11. Парсинг ответа
    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid API response format', 500);
    }

    if (empty($responseData['choices'][0]['message']['content'])) {
        throw new RuntimeException('Empty API response', 500);
    }

    $content = json_decode($responseData['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Попытка извлечь JSON из строки
        if (preg_match('/\{(?:[^{}]|(?R))*\}/x', $responseData['choices'][0]['message']['content'], $matches)) {
            $content = json_decode($matches[0], true);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse task content', 500);
        }
    }

    // 12. Валидация структуры задачи
    $requiredFields = ['title', 'description'];
    foreach ($requiredFields as $field) {
        if (empty($content[$field])) {
            throw new RuntimeException("Task missing required field: $field", 500);
        }
    }

    // 13. Подготовка задачи
    $task = [
        'id' => uniqid('task_', true),
        'title' => $content['title'],
        'description' => $content['description'],
        'example' => $content['example'] ?? '',
        'initialCode' => $content['initialCode'] ?? getDefaultCode($language),
        'difficulty' => $content['difficulty'] ?? $difficulty,
        'language' => $content['language'] ?? $language,
        'created_at' => time(),
        'expires_at' => time() + 3600 // Задача действительна 1 час
    ];

    // 14. Сохранение в сессии
    $_SESSION['current_task'] = $task;
    $_SESSION['last_task_request'] = time();

    // 15. Успешный ответ
    echo json_encode([
        'success' => true,
        'task' => $task,
        'debug' => DEBUG_MODE ? [
            'api_response' => substr($response, 0, 200),
            'execution_time' => $executionTime.'ms'
        ] : null
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    // 16. Обработка ошибок
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
    logError($e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => DEBUG_MODE ? [
            'trace' => $e->getTrace()
        ] : null
    ], JSON_UNESCAPED_UNICODE);
}

// 17. Функция для получения шаблонного кода
function getDefaultCode(string $language): string {
    $templates = [
        'javascript' => "// Ваше решение здесь\nfunction solution() {\n  // Реализуйте функцию\n}",
        'python' => "# Ваше решение здесь\ndef solution():\n    # Реализуйте функцию",
        'php' => "<?php\n// Ваше решение здесь\nfunction solution() {\n  // Реализуйте функцию\n}",
        'html' => "<!-- Ваше решение здесь -->\n<div class=\"solution\">\n  <!-- Реализуйте решение -->\n</div>"
    ];

    return $templates[strtolower($language)] ?? '';
}

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
        CURLOPT_HEADER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("CURL Error: $error");
    }
    
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response
    ];
}