<?php
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');

function makeApiRequest($prompt) {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage'
    ];

    $data = [
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
        'temperature' => 0.5,
        'max_tokens' => 1500,
        'response_format' => ['type' => 'json_object']
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => ''
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log('CURL Error: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => $response
    ];
}

try {
    // Получаем и валидируем входные данные
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $language = in_array($input['language'] ?? '', ['javascript', 'php', 'python', 'html']) 
        ? $input['language'] 
        : 'javascript';
        
    $difficulty = in_array($input['difficulty'] ?? '', ['beginner', 'intermediate', 'advanced']) 
        ? $input['difficulty'] 
        : 'beginner';

    // Формируем строгий промпт
    $prompt = <<<PROMPT
Generate a programming task with:
- Language: $language
- Difficulty: $difficulty
- Format: STRICT JSON without any formatting or comments
- Required fields:
  * title (string)
  * description (string)
  * example (code example)
  * initialCode (starter code)
  * difficulty (in Russian: Начинающий/Средний/Продвинутый)

Example of VALID response:
{"title":"Task title","description":"Task description","example":"Example code","initialCode":"Starter code","difficulty":"Начинающий"}
PROMPT;

    // Отправляем запрос
    $response = makeApiRequest($prompt);
    
    // Обрабатываем ошибки HTTP
    if ($response['code'] !== 200) {
        throw new Exception("API returned HTTP {$response['code']}. Response: " . substr($response['body'], 0, 200));
    }

    // Извлекаем JSON из ответа
    $jsonData = json_decode($response['body'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Пытаемся извлечь JSON из строки
        if (preg_match('/\{(?:[^{}]|(?R))*\}/', $response['body'], $matches)) {
            $jsonData = json_decode($matches[0], true);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }
    }

    // Валидация структуры
    $requiredFields = ['title', 'description', 'example', 'initialCode', 'difficulty'];
    foreach ($requiredFields as $field) {
        if (empty($jsonData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Успешный ответ
    echo json_encode($jsonData);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'See server logs for more info'
    ]);
    error_log("Error: " . $e->getMessage() . "\nRequest: " . json_encode($input) . "\nResponse: " . ($response['body'] ?? ''));
}