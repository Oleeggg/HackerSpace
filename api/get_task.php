<?php
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');

// Улучшенное логирование
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');

function extractJsonFromResponse($rawResponse) {
    // Попытка 1: Ищем чистый JSON
    $jsonStart = strpos($rawResponse, '{');
    $jsonEnd = strrpos($rawResponse, '}');
    
    if ($jsonStart !== false && $jsonEnd !== false) {
        $possibleJson = substr($rawResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
        if (json_decode($possibleJson)) {
            return $possibleJson;
        }
    }
    
    // Попытка 2: Удаляем Markdown-форматирование
    $cleaned = preg_replace('/^```(json)?|```$/m', '', $rawResponse);
    $cleaned = preg_replace('/^\*\*|\*\*$/m', '', $cleaned); // Удаляем **
    $cleaned = trim($cleaned);
    
    // Попытка 3: Ищем JSON после текстового префикса
    if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
        return $matches[0];
    }
    
    throw new Exception("Cannot extract JSON from response: " . substr($rawResponse, 0, 200));
}

function makeApiRequest($prompt) {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage'
    ];

    $data = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
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
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("API request failed with code $httpCode");
    }

    return $response;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception("Invalid input data");
    }

    // Жестко контролируемый промпт
    $prompt = <<<PROMPT
Требуется строго следовать инструкциям:
1. Язык: {$input['language']}
2. Уровень сложности: {$input['difficulty']}
3. Формат ответа: ТОЛЬКО чистый JSON без каких-либо обрамляющих символов
4. Поля JSON:
- title: string
- description: string
- example: string
- initialCode: string
- difficulty: string (на русском)

Пример КОРРЕКТНОГО ответа:
{
    "title": "Пример задачи",
    "description": "Описание задачи...",
    "example": "Пример решения...",
    "initialCode": "Исходный код...",
    "difficulty": "Начинающий"
}

Сгенерируйте задачу строго в указанном формате без каких-либо дополнительных комментариев или форматирования.
PROMPT;

    $rawResponse = makeApiRequest($prompt);
    $jsonString = extractJsonFromResponse($rawResponse);
    $data = json_decode($jsonString, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg() . " in: " . substr($jsonString, 0, 200));
    }

    // Валидация структуры
    $required = ['title', 'description', 'example', 'initialCode', 'difficulty'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    echo json_encode($data);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'Check server logs for details'
    ]);
    error_log("Error: " . $e->getMessage() . "\nResponse: " . ($rawResponse ?? ''));
}