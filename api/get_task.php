<?php
declare(strict_types=1);
require_once(__DIR__ . '/../config.php');

// Очистка буфера и заголовки
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

function logError(string $message): void {
    file_put_contents(__DIR__ . '/api_errors.log', date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

try {
    // Валидация метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die(json_encode(['error' => 'Only POST method allowed']));
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Invalid JSON input: " . json_last_error_msg());
    }

    // Проверка обязательных полей
    $required = ['language', 'difficulty'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new InvalidArgumentException("Missing required field: $field");
        }
    }

    // Формируем промпт с явным требованием JSON
    $prompt = <<<PROMPT
    Generate a programming task with:
    - Language: {$input['language']}
    - Difficulty: {$input['difficulty']}
    
    Return STRICT JSON format with these fields:
    {
        "title": "Task title",
        "description": "Detailed description",
        "example": "Code example",
        "initialCode": "Starter code",
        "difficulty": "{$input['difficulty']}",
        "language": "{$input['language']}"
    }
    PROMPT;

    $apiResponse = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a programming task generator. Always respond with valid JSON.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7,
        'response_format' => ['type' => 'json_object']
    ]);

    // Проверка ответа API
    if ($apiResponse['code'] !== 200) {
        logError("API Error: HTTP {$apiResponse['code']} - {$apiResponse['body']}");
        throw new RuntimeException("API request failed with status {$apiResponse['code']}");
    }

    $responseData = json_decode($apiResponse['body'], true);
    if (!isset($responseData['choices'][0]['message']['content'])) {
        throw new RuntimeException("Unexpected API response structure");
    }

    $content = json_decode($responseData['choices'][0]['message']['content'], true);
    if (empty($content)) {
        throw new RuntimeException("Empty task content received");
    }

    // Формируем ответ
    echo json_encode([
        'success' => true,
        'task' => [
            'title' => $content['title'] ?? 'Programming Task',
            'description' => $content['description'] ?? '',
            'example' => $content['example'] ?? '',
            'initialCode' => $content['initialCode'] ?? getDefaultCode($input['language']),
            'difficulty' => $content['difficulty'] ?? $input['difficulty'],
            'language' => $content['language'] ?? $input['language']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => DEBUG_MODE ? $e->getTraceAsString() : null
    ]);
    logError("Error: " . $e->getMessage());
}

function makeApiRequest(array $data): array {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Title: HackerSpaceWorkPage'
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("CURL Error: $error");
    }
    
    curl_close($ch);
    return ['code' => $httpCode, 'body' => $response];
}

function getDefaultCode(string $language): string {
    $templates = [
        'javascript' => '// Your code here\nfunction solution() {\n  // Implement your solution\n}',
        'php' => "<?php\n// Your code here\nfunction solution() {\n  // Implement your solution\n}",
        'python' => '# Your code here\ndef solution():\n    # Implement your solution',
        'html' => '<!-- Your HTML here -->\n<div class="solution">\n  <!-- Implement your solution -->\n</div>'
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
?>