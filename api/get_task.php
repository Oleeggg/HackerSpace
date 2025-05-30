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
            throw new InvalidArgumentException("Не указано обязательное поле: $field");
        }
    }

    $allowedLanguages = ['javascript', 'php', 'python', 'html'];
    if (!in_array(strtolower($input['language']), $allowedLanguages)) {
        throw new InvalidArgumentException("Указан недопустимый язык программирования");
    }

    $allowedDifficulties = ['beginner', 'intermediate', 'advanced'];
    if (!in_array(strtolower($input['difficulty']), $allowedDifficulties)) {
        throw new InvalidArgumentException("Указан недопустимый уровень сложности");
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
        throw new RuntimeException("Ошибка CURL: $error");
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
        'javascript' => '// Ваш код здесь\nfunction solution() {\n  // Реализуйте решение\n}',
        'php' => "<?php\n// Ваш код здесь\nfunction solution() {\n  // Реализуйте решение\n}",
        'python' => '# Ваш код здесь\ndef solution():\n    # Реализуйте решение',
        'html' => '<!-- Ваш HTML здесь -->\n<div class="solution">\n  <!-- Реализуйте решение -->\n</div>'
    ];
    
    return $templates[strtolower($language)] ?? '';
}

try {
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        throw new RuntimeException('Прямой доступ запрещен');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Разрешен только POST метод');
    }

    $jsonInput = file_get_contents('php://input');
    if ($jsonInput === false) {
        throw new RuntimeException('Ошибка чтения входных данных');
    }

    $input = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Неверный JSON вход: " . json_last_error_msg());
    }

    $validatedInput = validateInput($input);

    // Генерация уникального промпта для каждого запроса
    $promptTemplates = [
        'javascript' => [
            'beginner' => "Сгенерируй уникальное задание по JavaScript для начинающих. Требования:\n"
                . "1. Креативное название на русском\n2. Подробное описание задачи\n"
                . "3. Пример решения\n4. Шаблон кода для начала работы\n\n"
                . "Задание должно охватывать: {{concept}}. Сгенерируй JSON с полями: title, description, example, initialCode.",
            'intermediate' => "Придумай промежуточное задание по JavaScript. Требования:\n"
                . "1. Практическое название\n2. Четкие условия задачи\n"
                . "3. Пример реализации\n4. Заготовка кода с комментариями\n\n"
                . "Тема: {{concept}}. Верни JSON-ответ с полями: title, description, example, initialCode.",
            'advanced' => "Разработай сложное задание по JavaScript для экспертов. Требования:\n"
                . "1. Техническое название\n2. Подробная постановка проблемы\n"
                . "3. Оптимальное решение\n4. Частичная реализация\n\n"
                . "Фокус на: {{concept}}. Ответ должен быть в JSON с указанными полями."
        ],
        // Аналогичные шаблоны для других языков...
    ];

    // Концепции для каждого уровня
    $concepts = [
        'javascript' => [
            'beginner' => ['переменные', 'условия', 'циклы', 'функции', 'массивы'],
            'intermediate' => ['замыкания', 'промисы', 'асинхронность', 'обработка ошибок', 'работа с DOM'],
            'advanced' => ['оптимизация производительности', 'паттерны проектирования', 'Web Workers', 'сложные алгоритмы']
        ],
        'python' => [
            'beginner' => ['списки', 'словари', 'функции', 'условия', 'циклы'],
            'intermediate' => ['декораторы', 'генераторы', 'контекстные менеджеры', 'ООП'],
            'advanced' => ['метаклассы', 'асинхронность', 'оптимизация кода', 'C-расширения']
        ],
        'html' => [
            'beginner' => ['базовая разметка', 'семантические теги', 'формы', 'таблицы'],
            'intermediate' => ['адаптивный дизайн', 'CSS анимации', 'препроцессоры', 'доступность'],
            'advanced' => ['CSS Grid', 'кастомные свойства', 'оптимизация загрузки', 'Web Components']
        ]
    ];

    // Выбираем случайную концепцию
    $randomConcept = $concepts[$validatedInput['language']][$validatedInput['difficulty']][array_rand(
        $concepts[$validatedInput['language']][$validatedInput['difficulty']]
    )];

    $prompt = str_replace(
        '{{concept}}', 
        $randomConcept,
        $promptTemplates[$validatedInput['language']][$validatedInput['difficulty']] ??
            "Сгенерируй {$validatedInput['difficulty']} задание по {$validatedInput['language']}. " .
            "Верни JSON с полями: title, description, example, initialCode."
    );

    // Добавляем инструкцию для гарантии уникальности
    $prompt .= "\n\nЗадание должно быть полностью уникальным и отличаться от предыдущих. " .
               "Используй текущее время как seed: " . microtime(true);

    $apiResponse = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты профессиональный генератор программистских заданий. ' .
                    'Каждое задание должно быть уникальным и соответствовать уровню сложности.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 1.3, // Максимальная креативность
        'top_p' => 0.95,
        'response_format' => ['type' => 'json_object'],
        'seed' => (int)(microtime(true) * 1000) // Уникальное seed для каждого запроса
    ]);

    if ($apiResponse['code'] !== 200) {
        throw new RuntimeException("API вернуло код ошибки: {$apiResponse['code']}");
    }

    $content = json_decode($apiResponse['body'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Неверный JSON ответ API: " . json_last_error_msg());
    }

    // Валидация ответа от нейросети
    $requiredFields = ['title', 'description', 'example', 'initialCode'];
    foreach ($requiredFields as $field) {
        if (empty($content[$field])) {
            throw new RuntimeException("Ответ API не содержит обязательное поле: $field");
        }
    }

    $task = [
        'title' => $content['title'],
        'description' => $content['description'],
        'example' => $content['example'],
        'initialCode' => $content['initialCode'] ?? getDefaultCode($validatedInput['language']),
        'difficulty' => $validatedInput['difficulty'],
        'language' => $validatedInput['language'],
        'concept' => $randomConcept,
        'generatedAt' => date('Y-m-d H:i:s'),
        'aiGenerated' => true
    ];

    echo json_encode([
        'success' => true,
        'task' => $task,
        'fresh' => true
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
        'error' => $e->getMessage(),
        'ai_fallback' => false
    ];
    
    if (DEBUG_MODE) {
        $errorResponse['trace'] = $e->getTraceAsString();
    }
    
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    logError("Ошибка: " . $e->getMessage() . "\nТрассировка: " . $e->getTraceAsString());
}