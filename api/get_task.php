<?php
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');

// Улучшенное логирование
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');

function cleanJsonResponse($rawResponse) {
    // Удаляем Markdown обратные кавычки, если они есть
    if (strpos($rawResponse, '```json') !== false) {
        $rawResponse = preg_replace('/^```json|```$/m', '', $rawResponse);
    }
    
    // Удаляем возможные лишние символы в начале/конце
    $rawResponse = trim($rawResponse);
    
    return $rawResponse;
}

function makeApiRequest($data) {
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
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response
    ];
}

try {
    // Получаем входные данные
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception("Invalid JSON input");
    }

    // Улучшенный промпт с явным указанием формата
    $prompt = "Generate a programming task in " . ($input['language'] ?? 'javascript') . 
              " with " . ($input['difficulty'] ?? 'beginner') . " difficulty. " .
              "Return ONLY pure JSON (without markdown formatting) with these fields: " .
              "title, description, example, initialCode, difficulty. " .
              "Example must use proper " . ($input['language'] ?? 'javascript') . " syntax.";

    // Делаем запрос к API
    $response = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ]);

    // Проверяем код ответа
    if ($response['code'] !== 200) {
        throw new Exception("API request failed with HTTP code: " . $response['code']);
    }

    // Очищаем ответ от Markdown-форматирования
    $cleanedResponse = cleanJsonResponse($response['body']);
    error_log("Cleaned response: " . $cleanedResponse);

    // Парсим JSON
    $data = json_decode($cleanedResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response: " . json_last_error_msg() . 
                          "\nOriginal response: " . $response['body']);
    }

    // Проверяем структуру ответа
    $requiredFields = ['title', 'description', 'example', 'initialCode'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Missing required field: " . $field);
        }
    }

    // Возвращаем результат
    echo json_encode($data);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'Check server logs for more information'
    ]);
    error_log("Error in get_task.php: " . $e->getMessage());
}