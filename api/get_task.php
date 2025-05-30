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
    $hour = (int)(time() / 3) % TASK_VARIATIONS;
    return md5($input['language'] . $input['difficulty'] . $hour);
}

function getCachedTask(string $key): ?array {
    global $cacheDir;
    $cacheFile = $cacheDir . '/' . $key . '.json';
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (time() - ($data['generated_at'] ?? 0) < CACHE_EXPIRE) {
            return $data;
        }
        unlink($cacheFile);
    }
    
    return null;
}

function cacheTask(string $key, array $task): void {
    global $cacheDir;
    $task['generated_at'] = time();
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
    $decodedContent = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $decodedContent = json_decode($matches[0], true);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid task content JSON: " . json_last_error_msg() . ". Content: " . substr($content, 0, 200));
        }
    }

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
    $tasks = [
        'javascript' => [
            'beginner' => [
                [
                    'title' => 'Sum of two numbers',
                    'description' => 'Write a function that takes two numbers and returns their sum.',
                    'example' => 'function sum(a, b) { return a + b; }',
                    'initialCode' => 'function sum(a, b) {\n  // Your code here\n}'
                ],
                [
                    'title' => 'Find maximum',
                    'description' => 'Write a function that finds the maximum of two numbers.',
                    'example' => 'function max(a, b) { return a > b ? a : b; }',
                    'initialCode' => 'function max(a, b) {\n  // Your code here\n}'
                ]
            ],
            'intermediate' => [
                // Добавьте больше вариаций
            ]
        ],
        // Добавьте другие языки
    ];

    $hour = (int)(time() / 3600) % TASK_VARIATIONS;
    $availableTasks = $tasks[$input['language']][$input['difficulty']] ?? [];
    
    if (!empty($availableTasks)) {
        $taskIndex = $hour % count($availableTasks);
        return array_merge($availableTasks[$taskIndex], [
            'difficulty' => $input['difficulty'],
            'language' => $input['language']
        ]);
    }

    return [
        'title' => 'Sample Task',
        'description' => 'Implement a function that solves the problem.',
        'example' => 'function solution() {}',
        'initialCode' => getDefaultCode($input['language']),
        'difficulty' => $input['difficulty'],
        'language' => $input['language']
    ];
}

try {
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        throw new RuntimeException('Direct access not allowed');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Only POST method allowed');
    }

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
    
    $cachedTask = getCachedTask($cacheKey);
    if ($cachedTask) {
        echo json_encode([
            'success' => true,
            'task' => $cachedTask,
            'cached' => true
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $hour = (int)(time() / 3600) % TASK_VARIATIONS;
    $prompt = <<<PROMPT
Generate a unique programming task with:
- Language: {$validatedInput['language']}
- Difficulty: {$validatedInput['difficulty']}
- Variation: {$hour}

The task should be creative and not a common textbook example. Return STRICT JSON format with:
{
    "title": "Unique task title",
    "description": "Detailed description with specific requirements",
    "example": "Code example solving the task",
    "initialCode": "Starter code with TODOs",
    "difficulty": "{$validatedInput['difficulty']}",
    "language": "{$validatedInput['language']}"
}
PROMPT;

    $apiResponse = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a creative programming task generator. Generate unique tasks based on time variation.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.9,
        'top_p' => 0.9,
        'response_format' => ['type' => 'json_object'],
        'seed' => $hour
    ]);

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

    $task = [
        'title' => $content['title'] ?? 'Programming Task',
        'description' => $content['description'] ?? '',
        'example' => $content['example'] ?? '',
        'initialCode' => $content['initialCode'] ?? getDefaultCode($validatedInput['language']),
        'difficulty' => $content['difficulty'] ?? $validatedInput['difficulty'],
        'language' => $content['language'] ?? $validatedInput['language'],
        'generated_at' => time()
    ];

    cacheTask($cacheKey, $task);

    echo json_encode([
        'success' => true,
        'task' => $task
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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