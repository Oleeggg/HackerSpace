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
        'model' => 'mistralai/mistral-7b-instruct:free', // Используем бесплатную модель для теста
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 1000
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('CURL error: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response
    ];
}

try {
    // Простейший тестовый запрос
    $response = makeApiRequest([
        ['role' => 'user', 'content' => 'Ответь "Hello world"']
    ]);

    if ($response['code'] !== 200) {
        throw new Exception("API error {$response['code']}: " . $response['body']);
    }

    $data = json_decode($response['body'], true);
    if (!$data || !isset($data['choices'][0]['message']['content'])) {
        throw new Exception("Invalid API response");
    }

    echo json_encode([
        'success' => true,
        'response' => $data['choices'][0]['message']['content']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'Check API key and server configuration'
    ]);
    error_log("API Error: " . $e->getMessage());
}