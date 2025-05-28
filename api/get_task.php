<?php
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');

function makeApiRequestWithRetry($messages, $maxRetries = 2) {
    $retryCount = 0;
    $lastError = null;
    
    while ($retryCount < $maxRetries) {
        try {
            $headers = [
                'Authorization: Bearer ' . OPENROUTER_API_KEY,
                'Content-Type: application/json',
                'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                'X-Title: HackerSpaceWorkPage'
            ];

            $data = [
                'model' => 'mistralai/mistral-7b-instruct:free',
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 1500,
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
                CURLOPT_CONNECTTIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                return [
                    'code' => $httpCode,
                    'body' => $response
                ];
            }

            $lastError = "HTTP $httpCode: " . substr($response, 0, 200);
            error_log("Attempt $retryCount failed: $lastError");
            
        } catch (Exception $e) {
            $lastError = $e->getMessage();
        }
        
        $retryCount++;
        if ($retryCount < $maxRetries) {
            sleep(1); // Задержка перед повторной попыткой
        }
    }
    
    throw new Exception("API request failed after $maxRetries attempts. Last error: $lastError");
}

try {
    // Упрощенный запрос для теста стабильности
    $response = makeApiRequestWithRetry([
        [
            'role' => 'system',
            'content' => 'You are a helpful programming assistant. Always respond with valid JSON.'
        ],
        [
            'role' => 'user',
            'content' => 'Generate a simple calculator task in JavaScript. Return JSON with: title, description, example.'
        ]
    ]);

    $data = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }

    // Простая валидация ответа
    if (empty($data['choices'][0]['message']['content'])) {
        throw new Exception("Empty API response");
    }

    echo $response['body'];

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'retry_advice' => 'Please try again in a few moments'
    ]);
    error_log("Final error: " . $e->getMessage());
}