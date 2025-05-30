<?php
declare(strict_types=1);
require_once(__DIR__ . '/../config.php');

// Очистка буфера и установка заголовков
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Логирование ошибок
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');
ini_set('display_errors', 0);

function clean_output(): void {
    while (ob_get_level() > 0) {
        if (!ob_end_clean()) {
            break;
        }
    }
}

function json_response(array $data, int $status = 200): void {
    clean_output();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function validate_input(array $input): array {
    $required = ['language', 'difficulty'];
    $missing = array_diff($required, array_keys($input));
    
    if (!empty($missing)) {
        throw new InvalidArgumentException(
            'Не указаны обязательные поля: ' . implode(', ', $missing)
        );
    }

    $allowedLanguages = ['javascript', 'php', 'python', 'html'];
    if (!in_array(strtolower($input['language']), $allowedLanguages, true)) {
        throw new InvalidArgumentException(
            'Указан недопустимый язык программирования. Допустимые: ' . implode(', ', $allowedLanguages)
        );
    }

    $allowedDifficulties = ['beginner', 'intermediate', 'advanced'];
    if (!in_array(strtolower($input['difficulty']), $allowedDifficulties, true)) {
        throw new InvalidArgumentException(
            'Указан недопустимый уровень сложности. Допустимые: ' . implode(', ', $allowedDifficulties)
        );
    }

    return [
        'language' => strtolower($input['language']),
        'difficulty' => strtolower($input['difficulty'])
    ];
}

function make_api_request(array $data, int $retryCount = 0): array {
    $maxRetries = 3;
    if ($retryCount >= $maxRetries) {
        throw new RuntimeException("Превышено максимальное количество попыток запроса ($maxRetries)");
    }

    if ($retryCount > 0) {
        $delay = min(pow(2, $retryCount) + rand(1, 1000) / 1000, 10);
        sleep((int)$delay);
    }

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
        CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
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

    if ($httpCode === 429) {
        $retryAfter = 5;
        if (preg_match('/retry-after:\s*(\d+)/i', $headers, $matches)) {
            $retryAfter = min((int)$matches[1], 15); // Не больше 15 секунд
        }
        sleep($retryAfter);
        return make_api_request($data, $retryCount + 1);
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("API вернуло код ошибки: $httpCode");
    }

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body
    ];
}

function get_default_code(string $language): string {
    $templates = [
        'javascript' => '// Ваш код здесь\nfunction solution() {\n  // Реализуйте решение\n}',
        'php' => "<?php\n// Ваш код здесь\nfunction solution() {\n  // Реализуйте решение\n}",
        'python' => '# Ваш код здесь\ndef solution():\n    # Реализуйте решение',
        'html' => '<!-- Ваш HTML здесь -->\n<div class="solution">\n  <!-- Реализуйте решение -->\n</div>'
    ];
    
    return $templates[$language] ?? '';
}

function get_fallback_task(array $validatedInput): array {
    return [
        'title' => 'Пример задания',
        'description' => 'Это задание было загружено из локального хранилища',
        'example' => 'Пример решения',
        'initialCode' => get_default_code($validatedInput['language']),
        'difficulty' => $validatedInput['difficulty'],
        'language' => $validatedInput['language'],
        'concept' => 'базовые концепции',
        'generatedAt' => date('Y-m-d H:i:s'),
        'aiGenerated' => false
    ];
}

try {
    // Проверка AJAX запроса
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        throw new RuntimeException('Прямой доступ запрещен', 403);
    }

    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Разрешен только POST метод', 405);
    }

    // Получение и парсинг входных данных
    $jsonInput = file_get_contents('php://input');
    if ($jsonInput === false) {
        throw new RuntimeException('Ошибка чтения входных данных', 400);
    }

    try {
        $input = json_decode($jsonInput, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Неверный JSON вход: " . $e->getMessage(), 400);
    }

    $validatedInput = validate_input($input);

    // Концепции для каждого уровня
    $concepts = [
        'javascript' => [
            'beginner' => ['переменные', 'условия', 'циклы', 'функции', 'массивы'],
            'intermediate' => ['замыкания', 'промисы', 'асинхронность', 'обработка ошибок', 'работа с DOM'],
            'advanced' => ['оптимизация производительности', 'паттерны проектирования', 'Web Workers', 'сложные алгоритмы']
        ],
        'php' => [
            'beginner' => ['переменные', 'условия', 'циклы', 'функции', 'массивы'],
            'intermediate' => ['ООП', 'исключения', 'PDO', 'сессии', 'куки'],
            'advanced' => ['оптимизация запросов', 'паттерны проектирования', 'асинхронное программирование', 'кэширование']
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

    $prompt = "Сгенерируй {$validatedInput['difficulty']} задание по {$validatedInput['language']}. " .
              "Тема: $randomConcept. Верни JSON с полями: title, description, example, initialCode.\n\n" .
              "Задание должно быть уникальным. Seed: " . microtime(true);

    $apiData = [
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
        'temperature' => 1.3,
        'top_p' => 0.95,
        'response_format' => ['type' => 'json_object'],
        'seed' => (int)(microtime(true) * 1000)
    ];

    try {
        $apiResponse = make_api_request($apiData);
        
        if (empty($apiResponse['body'])) {
            throw new RuntimeException("Пустой ответ от API");
        }

        $content = json_decode($apiResponse['body'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Неверный JSON ответ API: " . json_last_error_msg());
        }

        // Валидация ответа от нейросети
        $requiredFields = ['title', 'description', 'example', 'initialCode'];
        foreach ($requiredFields as $field) {
            if (empty($content[$field])) {
                $content[$field] = "Не удалось сгенерировать $field";
            }
        }

        $task = [
            'title' => $content['title'],
            'description' => $content['description'],
            'example' => $content['example'],
            'initialCode' => $content['initialCode'] ?? get_default_code($validatedInput['language']),
            'difficulty' => $validatedInput['difficulty'],
            'language' => $validatedInput['language'],
            'concept' => $randomConcept,
            'generatedAt' => date('Y-m-d H:i:s'),
            'aiGenerated' => true
        ];

        json_response([
            'success' => true,
            'task' => $task,
            'fresh' => true
        ]);

    } catch (Exception $apiError) {
        // Fallback на локальное задание при ошибке API
        error_log("API Error: " . $apiError->getMessage());
        
        $task = get_fallback_task($validatedInput);
        
        json_response([
            'success' => true,
            'task' => $task,
            'fresh' => false,
            'warning' => 'Использовано локальное задание: ' . $apiError->getMessage()
        ]);
    }

} catch (InvalidArgumentException $e) {
    json_response([
        'success' => false,
        'error' => $e->getMessage()
    ], 400);
} catch (RuntimeException $e) {
    json_response([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
} catch (Exception $e) {
    json_response([
        'success' => false,
        'error' => 'Произошла непредвиденная ошибка'
    ], 500);
}