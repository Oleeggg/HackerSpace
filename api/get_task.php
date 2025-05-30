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

function makeApiRequest(array $data): array {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'https://yourdomain.com'),
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
    
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("CURL Error: $error");
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
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
                [
                    'title' => 'Array operations',
                    'description' => 'Implement a function that filters even numbers and squares them.',
                    'example' => 'function processArray(arr) {\n  return arr.filter(x => x % 2 === 0).map(x => x * x);\n}',
                    'initialCode' => 'function processArray(arr) {\n  // Your code here\n}'
                ]
            ]
        ],
        'php' => [
            'beginner' => [
                [
                    'title' => 'String reversal',
                    'description' => 'Write a function that reverses a string.',
                    'example' => 'function reverseString($str) {\n  return strrev($str);\n}',
                    'initialCode' => 'function reverseString($str) {\n  // Your code here\n}'
                ]
            ]
        ]
    ];

    $availableTasks = $tasks[$input['language']][$input['difficulty']] ?? [];
    
    if (!empty($availableTasks)) {
        $randomIndex = array_rand($availableTasks);
        return array_merge($availableTasks[$randomIndex], [
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

    // Улучшенный промпт с требованием уникальности
    $prompt = <<<PROMPT
Generate a completely unique programming task with these requirements:
- Programming language: {$validatedInput['language']}
- Difficulty level: {$validatedInput['difficulty']}
- Must include at least one unique concept or twist
- Should not be a common textbook example

Include these elements in the response:
1. Creative title that reflects the task's uniqueness
2. Detailed description with specific requirements
3. Example solution code
4. Initial code template with placeholders
5. One random advanced concept to implement (e.g., recursion, closures, etc.)

Return the response in STRICT JSON format exactly like this:
{
    "title": "Unique Task Title",
    "description": "Detailed task description...",
    "example": "Example solution code...",
    "initialCode": "Initial code template...",
    "difficulty": "{$validatedInput['difficulty']}",
    "language": "{$validatedInput['language']}",
    "specialConcepts": ["concept1", "concept2"]
}

Make sure each generated task is truly different from previous ones!
PROMPT;

    $apiResponse = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a creative programming task generator. Generate completely unique tasks each time.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 1.2, // Более высокая креативность
        'top_p' => 0.9,
        'response_format' => ['type' => 'json_object'],
        'seed' => time() // Уникальное seed для каждого запроса
    ]);

    if ($apiResponse['code'] === 429 || $apiResponse['code'] !== 200) {
        // Используем fallback задание если API не доступно
        $task = getFallbackTask($validatedInput);
        $task['fallback'] = true;
        
        echo json_encode([
            'success' => true,
            'task' => $task,
            'rate_limited' => true
        ]);
        exit;
    }

    $content = json_decode($apiResponse['body'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Invalid API response JSON: " . json_last_error_msg());
    }

    $task = [
        'title' => $content['title'] ?? 'Programming Task',
        'description' => $content['description'] ?? '',
        'example' => $content['example'] ?? '',
        'initialCode' => $content['initialCode'] ?? getDefaultCode($validatedInput['language']),
        'difficulty' => $content['difficulty'] ?? $validatedInput['difficulty'],
        'language' => $content['language'] ?? $validatedInput['language'],
        'specialConcepts' => $content['specialConcepts'] ?? [],
        'generatedAt' => date('Y-m-d H:i:s')
    ];

    echo json_encode([
        'success' => true,
        'task' => $task,
        'fresh' => true // Показываем что задание свежее
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