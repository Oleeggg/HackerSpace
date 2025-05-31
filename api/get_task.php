<?php
declare(strict_types=1);
require_once(__DIR__ . '/../config.php');

// Очистка буфера и заголовки
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Логирование ошибок
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');
ini_set('display_errors', 0);

function logError(string $message): void {
    error_log(date('[Y-m-d H:i:s] ') . $message);
}

function validateInput(array $input): array {
    $required = ['language', 'difficulty'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new InvalidArgumentException("Missing required field: $field");
        }
    }

    $allowedLanguages = ['javascript', 'php', 'python', 'html'];
    if (!in_array(strtolower($input['language']), $allowedLanguages)) {
        throw new InvalidArgumentException("Invalid language specified");
    }

    $allowedDifficulties = ['beginner', 'intermediate', 'advanced'];
    if (!in_array(strtolower($input['difficulty']), $allowedDifficulties)) {
        throw new InvalidArgumentException("Invalid difficulty level");
    }

    return [
        'language' => strtolower($input['language']),
        'difficulty' => strtolower($input['difficulty'])
    ];
}

function extractJsonFromResponse(string $responseBody): string {
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $responseBody, $matches)) {
        return $matches[1];
    }
    
    if (preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $responseBody, $matches)) {
        return $matches[1];
    }
    
    if (preg_match('/\{.*\}/s', $responseBody, $matches)) {
        return $matches[0];
    }
    
    return $responseBody;
}

function parseApiResponse(string $responseBody): array {
    file_put_contents(__DIR__ . '/last_api_response.txt', $responseBody);
    
    $jsonString = extractJsonFromResponse($responseBody);
    
    $data = json_decode($jsonString, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Invalid API response JSON: " . json_last_error_msg() . ". Raw response: " . substr($responseBody, 0, 200));
    }

    if (isset($data['choices'][0]['message']['content'])) {
        $content = $data['choices'][0]['message']['content'];
        
        if (is_string($content)) {
            $content = extractJsonFromResponse($content);
            $content = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("Invalid task content JSON: " . json_last_error_msg());
            }
        }
        
        $data = $content;
    }

    $requiredFields = ['title', 'description'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            throw new RuntimeException("Missing required field in task: $field");
        }
    }

    return $data;
}

try {
    // Проверка AJAX запроса
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        throw new RuntimeException('Direct access not allowed');
    }

    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Only POST method allowed');
    }

    // Получение и проверка входных данных
    $jsonInput = file_get_contents('php://input');
    if ($jsonInput === false) {
        throw new RuntimeException('Failed to read input data');
    }

    $input = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Invalid JSON input: " . json_last_error_msg());
    }

    $validatedInput = validateInput($input);

    // Очищаем предыдущее задание из сессии
    if (isset($_SESSION['current_task'])) {
        unset($_SESSION['current_task']);
    }

    // Формирование промпта
    $prompt = <<<PROMPT
    Generate a programming task in STRICT JSON format (no Markdown, no code blocks) with these fields:
    {
        "title": "Task title",
        "description": "Detailed task description with requirements",
        "example": "Code example solution",
        "initialCode": "Starter code for the task",
        "difficulty": "{$validatedInput['difficulty']}",
        "language": "{$validatedInput['language']}"
    }
    
    Important:
    - Return ONLY the JSON object
    - Do not wrap response in Markdown or HTML
    - Do not include any explanations
    PROMPT;

    $apiResponse = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a programming task generator. Respond ONLY with valid JSON object containing the task.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7,
        'response_format' => ['type' => 'json_object']
    ]);

    if ($apiResponse['code'] !== 200) {
        throw new RuntimeException("API request failed with status {$apiResponse['code']}: " . substr(strip_tags($apiResponse['body']), 0, 100));
    }

    $content = parseApiResponse($apiResponse['body']);

    // Сохраняем задание в сессии
    $_SESSION['current_task'] = [
        'id' => uniqid('task_', true),
        'title' => $content['title'] ?? 'Programming Task',
        'description' => $content['description'] ?? '',
        'example' => $content['example'] ?? '',
        'initialCode' => $content['initialCode'] ?? getDefaultCode($validatedInput['language']),
        'difficulty' => $content['difficulty'] ?? $validatedInput['difficulty'],
        'language' => $content['language'] ?? $validatedInput['language']
    ];

    // Формирование ответа
    $response = [
        'success' => true,
        'task' => $_SESSION['current_task']
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    $errorResponse = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    
    if (DEBUG_MODE) {
        $errorResponse['trace'] = $e->getTraceAsString();
        $errorResponse['raw_response'] = file_exists(__DIR__ . '/last_api_response.txt') 
            ? file_get_contents(__DIR__ . '/last_api_response.txt')
            : null;
    }
    
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    logError("Error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
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
        CURLOPT_TIMEOUT => 60,
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