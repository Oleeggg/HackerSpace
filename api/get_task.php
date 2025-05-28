<?php
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');

function makeApiRequest($messages) {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage'
    ];

    $data = [
        'model' => DEVSTRAL_MODEL,
        'messages' => $messages,
        'temperature' => 0.5,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_FAILONERROR => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("CURL error: " . $error);
    }

    return [
        'code' => $httpCode,
        'body' => $response
    ];
}

function validateTask($task) {
    $requiredFields = ['title', 'description', 'example', 'initialCode', 'difficulty'];
    foreach ($requiredFields as $field) {
        if (!isset($task[$field]) || empty($task[$field])) {
            throw new Exception("Missing or empty required field: $field");
        }
    }

    if (!in_array($task['difficulty'], ['Начинающий', 'Средний', 'Продвинутый'])) {
        throw new Exception("Invalid difficulty value");
    }
}

try {
    // Получаем и валидируем входные данные
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON input: " . json_last_error_msg());
    }

    $language = $input['language'] ?? 'javascript';
    $difficulty = $input['difficulty'] ?? 'beginner';

    // Формируем строгий промпт
    $messages = [
        [
            'role' => 'system',
            'content' => "Вы генератор задач по программированию. Всегда отвечайте строго в формате JSON без каких-либо комментариев или форматирования."
        ],
        [
            'role' => 'user',
            'content' => "Сгенерируйте задачу на языке $language уровня сложности $difficulty. Ответ должен содержать следующие поля в JSON формате:
- title: название задачи
- description: описание задачи
- example: пример решения
- initialCode: начальный код для решения
- difficulty: уровень сложности (Начинающий, Средний или Продвинутый)

Пример правильного ответа:
{
    \"title\": \"Название задачи\",
    \"description\": \"Описание задачи...\",
    \"example\": \"Пример решения...\",
    \"initialCode\": \"Начальный код...\",
    \"difficulty\": \"Начинающий\"
}"
        ]
    ];

    // Отправляем запрос
    $response = makeApiRequest($messages);

    // Обрабатываем ответ
    if ($response['code'] !== 200) {
        throw new Exception("API returned HTTP {$response['code']}. Response: " . substr($response['body'], 0, 500));
    }

    $responseData = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Попытка извлечь JSON из ответа
        if (preg_match('/\{(?:[^{}]|(?R))*\}/', $response['body'], $matches)) {
            $responseData = json_decode($matches[0], true);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }
    }

    // Валидация ответа
    validateTask($responseData);

    // Успешный ответ
    echo json_encode($responseData);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'See server logs for more info'
    ]);
    error_log("Error: " . $e->getMessage() . "\nInput: " . json_encode($input) . "\nResponse: " . ($response['body'] ?? ''));
}