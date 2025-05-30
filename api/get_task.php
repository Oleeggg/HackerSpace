<?php
// Удаляем все возможные пробелы/символы перед PHP-тегом
declare(strict_types=1);

// Подключаем конфиг и устанавливаем заголовки
require_once(__DIR__ . '/../config.php');

// Очищаем буфер вывода и устанавливаем заголовки
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// Функция для отправки ошибок в JSON формате
function sendError(string $message, int $code = 500, array $details = []): void {
    http_response_code($code);
    echo json_encode([
        'status' => 'error',
        'message' => $message,
        'details' => $details,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Функция для валидации входных данных
function validateInput(array $input): array {
    $required = ['language', 'difficulty'];
    $allowedLanguages = ['javascript', 'php', 'python', 'html'];
    $allowedDifficulties = ['beginner', 'intermediate', 'advanced'];

    foreach ($required as $field) {
        if (empty($input[$field])) {
            sendError("Missing required field: {$field}", 400);
        }
    }

    if (!in_array(strtolower($input['language']), $allowedLanguages)) {
        sendError("Invalid language specified", 400);
    }

    if (!in_array(strtolower($input['difficulty']), $allowedDifficulties)) {
        sendError("Invalid difficulty level", 400);
    }

    return [
        'language' => strtolower($input['language']),
        'difficulty' => strtolower($input['difficulty'])
    ];
}

// Основной обработчик
try {
    // Получаем и валидируем входные данные
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError("Invalid JSON input", 400, [
            'json_error' => json_last_error_msg()
        ]);
    }

    $validated = validateInput($input);

    // Формируем строгий промпт для API
    $prompt = <<<PROMPT
    Сгенерируй задание по программированию со следующими параметрами:
    - Язык: {$validated['language']}
    - Уровень сложности: {$validated['difficulty']}
    
    Верни ответ СТРОГО в JSON формате со следующими полями:
    {
        "title": "Название задания",
        "description": "Подробное описание задания",
        "example": "Пример решения (код)",
        "initialCode": "Начальный код для решения",
        "difficulty": "{$validated['difficulty']}",
        "language": "{$validated['language']}"
    }
    PROMPT;

    // Отправляем запрос к OpenRouter API
    $apiResponse = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты помощник для генерации заданий по программированию. Всегда возвращаешь ответ в JSON формате.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ]);

    // Проверяем ответ API
    if ($apiResponse['code'] !== 200) {
        sendError("API request failed", 502, [
            'http_code' => $apiResponse['code'],
            'response' => $apiResponse['body']
        ]);
    }

    // Парсим ответ API
    $responseData = json_decode($apiResponse['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError("Invalid API response", 502, [
            'json_error' => json_last_error_msg(),
            'raw_response' => $apiResponse['body']
        ]);
    }

    // Извлекаем и валидируем контент
    if (!isset($responseData['choices'][0]['message']['content'])) {
        sendError("Unexpected API response structure", 502, [
            'response_data' => $responseData
        ]);
    }

    $content = json_decode($responseData['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError("AI returned invalid JSON", 502, [
            'ai_response' => $responseData['choices'][0]['message']['content']
        ]);
    }

    // Стандартизируем ответ
    $result = [
        'title' => $content['title'] ?? 'Programming Task',
        'description' => $content['description'] ?? '',
        'example' => $content['example'] ?? '',
        'initialCode' => $content['initialCode'] ?? getDefaultCode($validated['language']),
        'difficulty' => $content['difficulty'] ?? $validated['difficulty'],
        'language' => $content['language'] ?? $validated['language'],
        'metadata' => [
            'generated_at' => date('c'),
            'api_version' => '1.0'
        ]
    ];

    // Отправляем успешный ответ
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    sendError("Internal server error", 500, [
        'exception' => $e->getMessage(),
        'trace' => DEBUG_MODE ? $e->getTrace() : 'Disabled in production'
    ]);
}

// Вспомогательные функции
function getDefaultCode(string $language): string {
    $templates = [
        'javascript' => '// Your JavaScript code here\nfunction solve() {\n  // Solution\n}',
        'php' => '<?php\n// Your PHP code here\nfunction solve() {\n  // Solution\n}',
        'python' => '# Your Python code here\ndef solve():\n    # Solution',
        'html' => '<!-- Your HTML code here -->\n<div class="solution">\n  <!-- Solution -->\n</div>'
    ];
    return $templates[$language] ?? '';
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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response
    ];
}