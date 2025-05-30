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
    // Улучшенные fallback-задания для каждого языка и уровня сложности
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
                ],
                [
                    'title' => 'Promise chain',
                    'description' => 'Create a function that chains three promises sequentially.',
                    'example' => 'async function chainPromises(promises) {\n  let result;\n  for (let promise of promises) {\n    result = await promise(result);\n  }\n  return result;\n}',
                    'initialCode' => 'async function chainPromises(promises) {\n  // Your code here\n}'
                ]
            ],
            'advanced' => [
                [
                    'title' => 'Memoization',
                    'description' => 'Implement a memoization decorator for functions with multiple arguments.',
                    'example' => 'function memoize(fn) {\n  const cache = new Map();\n  return (...args) => {\n    const key = JSON.stringify(args);\n    if (cache.has(key)) return cache.get(key);\n    const result = fn(...args);\n    cache.set(key, result);\n    return result;\n  };\n}',
                    'initialCode' => 'function memoize(fn) {\n  // Your code here\n}'
                ],
                [
                    'title' => 'Custom Observable',
                    'description' => 'Implement a simple Observable class with subscribe and next methods.',
                    'example' => 'class Observable {\n  constructor() {\n    this.subscribers = [];\n  }\n  subscribe(fn) {\n    this.subscribers.push(fn);\n    return () => {\n      this.subscribers = this.subscribers.filter(sub => sub !== fn);\n    };\n  }\n  next(value) {\n    this.subscribers.forEach(fn => fn(value));\n  }\n}',
                    'initialCode' => 'class Observable {\n  // Your code here\n}'
                ]
            ]
        ],
        'python' => [
            'beginner' => [
                [
                    'title' => 'String reversal',
                    'description' => 'Write a function that reverses a string.',
                    'example' => 'def reverse_string(s):\n    return s[::-1]',
                    'initialCode' => 'def reverse_string(s):\n    # Your code here\n    pass'
                ],
                [
                    'title' => 'List sum',
                    'description' => 'Write a function that sums all numbers in a list.',
                    'example' => 'def sum_list(numbers):\n    return sum(numbers)',
                    'initialCode' => 'def sum_list(numbers):\n    # Your code here\n    pass'
                ]
            ],
            'intermediate' => [
                [
                    'title' => 'Decorator',
                    'description' => 'Create a decorator that logs function execution time.',
                    'example' => 'import time\ndef timer(func):\n    def wrapper(*args, **kwargs):\n        start = time.time()\n        result = func(*args, **kwargs)\n        end = time.time()\n        print(f"Execution time: {end - start} seconds")\n        return result\n    return wrapper',
                    'initialCode' => 'import time\n\ndef timer(func):\n    # Your code here\n    pass'
                ],
                [
                    'title' => 'Context manager',
                    'description' => 'Implement a context manager for file operations.',
                    'example' => 'class FileManager:\n    def __init__(self, filename, mode):\n        self.filename = filename\n        self.mode = mode\n    def __enter__(self):\n        self.file = open(self.filename, self.mode)\n        return self.file\n    def __exit__(self, exc_type, exc_val, exc_tb):\n        self.file.close()',
                    'initialCode' => 'class FileManager:\n    # Your code here\n    pass'
                ]
            ],
            'advanced' => [
                [
                    'title' => 'Metaclass',
                    'description' => 'Create a metaclass that adds a class registry.',
                    'example' => 'class RegistryMeta(type):\n    registry = {}\n    def __new__(cls, name, bases, namespace):\n        new_class = super().__new__(cls, name, bases, namespace)\n        cls.registry[name] = new_class\n        return new_class',
                    'initialCode' => 'class RegistryMeta(type):\n    # Your code here\n    pass'
                ],
                [
                    'title' => 'Async generator',
                    'description' => 'Implement an async generator that yields data from an async source.',
                    'example' => 'async def async_gen():\n    for i in range(5):\n        await asyncio.sleep(1)\n        yield i',
                    'initialCode' => 'import asyncio\n\nasync def async_gen():\n    # Your code here\n    pass'
                ]
            ]
        ],
        'html' => [
            'beginner' => [
                [
                    'title' => 'Basic form',
                    'description' => 'Create a contact form with name, email and message fields.',
                    'example' => '<form>\n  <label>Name: <input type="text" name="name"></label>\n  <label>Email: <input type="email" name="email"></label>\n  <label>Message: <textarea name="message"></textarea></label>\n  <button type="submit">Send</button>\n</form>',
                    'initialCode' => '<!-- Create your form here -->'
                ],
                [
                    'title' => 'Navigation menu',
                    'description' => 'Create a horizontal navigation menu with 5 links.',
                    'example' => '<nav>\n  <ul>\n    <li><a href="#home">Home</a></li>\n    <li><a href="#about">About</a></li>\n    <li><a href="#services">Services</a></li>\n    <li><a href="#portfolio">Portfolio</a></li>\n    <li><a href="#contact">Contact</a></li>\n  </ul>\n</nav>',
                    'initialCode' => '<!-- Create your navigation here -->'
                ]
            ],
            'intermediate' => [
                [
                    'title' => 'Responsive grid',
                    'description' => 'Create a responsive 3-column grid that stacks on mobile.',
                    'example' => '<style>\n  .grid {\n    display: grid;\n    grid-template-columns: repeat(3, 1fr);\n    gap: 20px;\n  }\n  @media (max-width: 768px) {\n    .grid { grid-template-columns: 1fr; }\n  }\n</style>\n<div class="grid">\n  <div class="item">1</div>\n  <div class="item">2</div>\n  <div class="item">3</div>\n</div>',
                    'initialCode' => '<!-- Create your responsive grid here -->'
                ],
                [
                    'title' => 'CSS animations',
                    'description' => 'Create a button with hover and focus animations.',
                    'example' => '<style>\n  .btn {\n    transition: all 0.3s ease;\n    transform: scale(1);\n  }\n  .btn:hover {\n    transform: scale(1.05);\n    box-shadow: 0 5px 15px rgba(0,0,0,0.1);\n  }\n  .btn:active {\n    transform: scale(0.95);\n  }\n</style>\n<button class="btn">Animated Button</button>',
                    'initialCode' => '<!-- Create your animated button here -->'
                ]
            ],
            'advanced' => [
                [
                    'title' => 'CSS custom properties',
                    'description' => 'Create a theme switcher using CSS variables.',
                    'example' => '<style>\n  :root {\n    --primary: #6200ee;\n    --background: #ffffff;\n  }\n  .dark {\n    --primary: #bb86fc;\n    --background: #121212;\n  }\n  body {\n    background: var(--background);\n    color: var(--text);\n  }\n</style>\n<button onclick="document.body.classList.toggle(\'dark\')">Toggle Theme</button>',
                    'initialCode' => '<!-- Create your theme switcher here -->'
                ],
                [
                    'title' => 'CSS Grid layout',
                    'description' => 'Create a complex magazine-style layout using CSS Grid.',
                    'example' => '<style>\n  .layout {\n    display: grid;\n    grid-template-areas:\n      "header header header"\n      "sidebar main main"\n      "footer footer footer";\n    grid-gap: 20px;\n  }\n  .header { grid-area: header; }\n  .sidebar { grid-area: sidebar; }\n  .main { grid-area: main; }\n  .footer { grid-area: footer; }\n</style>\n<div class="layout">\n  <header class="header">Header</header>\n  <aside class="sidebar">Sidebar</aside>\n  <main class="main">Main Content</main>\n  <footer class="footer">Footer</footer>\n</div>',
                    'initialCode' => '<!-- Create your grid layout here -->'
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

    // Уникальные промпты для каждого языка и уровня сложности
    $prompts = [
        'javascript' => [
            'beginner' => "Generate a beginner JavaScript task focusing on basic syntax and simple algorithms. Include: 1) Clear title, 2) Detailed description, 3) Example solution, 4) Initial code template with placeholders. Task should teach fundamentals like variables, conditionals, and simple functions.",
            'intermediate' => "Create an intermediate JavaScript task involving array methods, closures, or promises. Include: 1) Creative title, 2) Detailed problem statement, 3) Example solution, 4) Starter code. Focus on practical scenarios like data processing or API handling.",
            'advanced' => "Design an advanced JavaScript challenge covering topics like prototypes, decorators, or async patterns. Include: 1) Complex problem, 2) Performance considerations, 3) Example solution, 4) Partial implementation. Challenge should require deep JS knowledge."
        ],
        'python' => [
            'beginner' => "Generate a beginner Python task about basic syntax and data structures. Include: 1) Simple title, 2) Clear instructions, 3) Example solution, 4) Starter code. Focus on lists, strings, or basic functions.",
            'intermediate' => "Create an intermediate Python task involving decorators, context managers, or OOP. Include: 1) Practical title, 2) Real-world scenario, 3) Example solution, 4) Partial implementation. Should require Python-specific features.",
            'advanced' => "Design an advanced Python challenge with metaclasses, async/await, or advanced patterns. Include: 1) Complex problem, 2) Performance aspects, 3) Example solution, 4) Skeleton code. Should test expert Python knowledge."
        ],
        'html' => [
            'beginner' => "Generate a beginner HTML/CSS task about basic page structure. Include: 1) Simple title, 2) Clear requirements, 3) Example solution, 4) Starter markup. Focus on semantic HTML and basic CSS.",
            'intermediate' => "Create an intermediate HTML/CSS task involving responsive design or animations. Include: 1) Practical title, 2) Design specifications, 3) Example solution, 4) Partial code. Should require media queries or transitions.",
            'advanced' => "Design an advanced HTML/CSS challenge with CSS Grid, custom properties, or complex layouts. Include: 1) Complex requirements, 2) Accessibility considerations, 3) Example solution, 4) Starting point. Should test modern CSS skills."
        ]
    ];

    $prompt = $prompts[$validatedInput['language']][$validatedInput['difficulty']] ?? 
        "Generate a {$validatedInput['difficulty']} level {$validatedInput['language']} programming task with title, description, example, and initial code.";

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