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

// Настройка кэширования
$cacheDir = __DIR__ . '/../cache';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

function logError(string $message): void {
    error_log(date('[Y-m-d H:i:s] ') . $message);
}

function getCacheKey(array $input): string {
    return md5($input['language'] . $input['difficulty']);
}

function getCachedTask(string $key): ?array {
    global $cacheDir;
    $cacheFile = $cacheDir . '/' . $key . '.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) { // Кэш на 24 часа
        $content = file_get_contents($cacheFile);
        if ($content !== false) {
            return json_decode($content, true);
        }
    }
    
    return null;
}

function cacheTask(string $key, array $task): void {
    global $cacheDir;
    $cacheFile = $cacheDir . '/' . $key . '.json';
    file_put_contents($cacheFile, json_encode($task));
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

function parseApiResponse(string $responseBody): array {
    // Проверка на HTML ошибки
    if (strpos($responseBody, '<!DOCTYPE') !== false || strpos($responseBody, '<html') !== false) {
        $dom = new DOMDocument();
        @$dom->loadHTML($responseBody);
        $errorText = '';
        
        foreach ($dom->getElementsByTagName('p') as $p) {
            $errorText .= $p->textContent . "\n";
        }
        
        throw new RuntimeException("API returned HTML error: " . trim($errorText) ?: substr(strip_tags($responseBody), 0, 200));
    }

    $data = json_decode($responseBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Invalid API response JSON: " . json_last_error_msg());
    }

    if (!isset($data['choices'][0]['message']['content'])) {
        throw new RuntimeException("Unexpected API response structure");
    }

    $content = $data['choices'][0]['message']['content'];
    
    // Попробуем сначала как строку JSON
    $decodedContent = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Если не JSON, возможно это строка с JSON внутри
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $decodedContent = json_decode($matches[0], true);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid task content JSON: " . json_last_error_msg() . ". Content: " . substr($content, 0, 200));
        }
    }

    // Проверка обязательных полей в задании
    $requiredFields = ['title', 'description'];
    foreach ($requiredFields as $field) {
        if (!isset($decodedContent[$field])) {
            throw new RuntimeException("Missing required field in task: $field");
        }
    }

    return $decodedContent;
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
        CURLOPT_HEADER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Разделяем заголовки и тело ответа
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("CURL Error: $error");
    }
    
    curl_close($ch);

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body
    ];
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

function getFallbackTask(array $input): array {
    return [
        'title' => 'Sample Task (Rate Limited)',
        'description' => 'Please wait before requesting new tasks. Here\'s a sample task: Implement a function that adds two numbers.',
        'example' => 'function add(a, b) { return a + b; }',
        'initialCode' => getDefaultCode($input['language']),
        'difficulty' => $input['difficulty'],
        'language' => $input['language']
    ];
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
    $cacheKey = getCacheKey($validatedInput);
    
    // Проверяем кэш
    $cachedTask = getCachedTask($cacheKey);
    if ($cachedTask) {
        echo json_encode([
            'success' => true,
            'task' => $cachedTask,
            'cached' => true
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Формирование промпта
    $prompt = <<<PROMPT
    Generate a programming task with:
    - Language: {$validatedInput['language']}
    - Difficulty: {$validatedInput['difficulty']}
    
    Return STRICT JSON format with these fields:
    {
        "title": "Task title",
        "description": "Detailed description",
        "example": "Code example",
        "initialCode": "Starter code",
        "difficulty": "{$validatedInput['difficulty']}",
        "language": "{$validatedInput['language']}"
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

    // Обработка ошибки 429 (Rate Limit Exceeded)
    if ($apiResponse['code'] === 429) {
        $fallbackTask = getFallbackTask($validatedInput);
        cacheTask($cacheKey, $fallbackTask);
        
        echo json_encode([
            'success' => true,
            'task' => $fallbackTask,
            'rate_limited' => true,
            'message' => 'Rate limit exceeded. Using fallback task.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($apiResponse['code'] !== 200) {
        throw new RuntimeException("API request failed with status {$apiResponse['code']}: " . substr(strip_tags($apiResponse['body']), 0, 100));
    }

    $content = parseApiResponse($apiResponse['body']);

    // Формирование задачи для ответа
    $task = [
        'title' => $content['title'] ?? 'Programming Task',
        'description' => $content['description'] ?? '',
        'example' => $content['example'] ?? '',
        'initialCode' => $content['initialCode'] ?? getDefaultCode($validatedInput['language']),
        'difficulty' => $content['difficulty'] ?? $validatedInput['difficulty'],
        'language' => $content['language'] ?? $validatedInput['language']
    ];

    // Кэшируем задачу
    cacheTask($cacheKey, $task);

    // Формирование ответа
    $response = [
        'success' => true,
        'task' => $task
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
    }
    
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    logError("Error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
}
?>