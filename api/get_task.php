<?php
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');

// Включение подробного логгирования
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');

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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true, // Получаем заголовки в ответе
    ]);

    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    $headers = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    curl_close($ch);

    // Логирование для отладки
    error_log("API Response Headers: " . $headers);
    error_log("API Response Body: " . $body);

    // Проверка на HTML-ответ
    if (strpos($body, '<!DOCTYPE html>') === 0 || strpos($body, '<html') === 0) {
        throw new Exception("Server returned HTML instead of JSON. Check authentication.");
    }

    return [
        'code' => $http_code,
        'body' => $body
    ];
}

try {
    // Получаем входные данные
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception("Invalid JSON input");
    }

    // Формируем промпт
    $prompt = "Generate a programming task in " . ($input['language'] ?? 'javascript') . 
              " with " . ($input['difficulty'] ?? 'beginner') . " difficulty. " .
              "Return JSON with: title, description, example, initialCode";

    // Делаем запрос к API
    $response = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object'] // Явно запрашиваем JSON
    ]);

    // Проверяем код ответа
    if ($response['code'] !== 200) {
        throw new Exception("API request failed with HTTP code: " . $response['code']);
    }

    // Парсим JSON
    $data = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response: " . json_last_error_msg());
    }

    // Проверяем структуру ответа
    if (!isset($data['choices'][0]['message']['content'])) {
        throw new Exception("Unexpected API response structure");
    }

    // Возвращаем результат
    echo $data['choices'][0]['message']['content'];

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'Check server logs for more information'
    ]);
    error_log("Error in get_task.php: " . $e->getMessage());
}
    // Проверяем структуру ответа
    if (!isset($data['choices'][0]['message']['content'])) {
        throw new Exception("Unexpected API response structure");
    }

    // Декодируем содержимое сообщения
    $content = json_decode($data['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Если не JSON, создаем структуру вручную
        $content = [
            'title' => 'Programming Task',
            'description' => $data['choices'][0]['message']['content'],
            'example' => '',
            'initialCode' => '',
            'difficulty' => $input['difficulty'] ?? 'beginner'
        ];
    }

    // Возвращаем результат
    echo json_encode($content);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'Check server logs for more information'
    ]);
    error_log("Error in get_task.php: " . $e->getMessage());
}
