<?php
require_once(__DIR__ . '/../config.php');

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    
    // Улучшенная проверка CSRF токена
    if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || empty($_SESSION['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
        throw new Exception('CSRF token validation failed');
    }

    $jsonInput = file_get_contents('php://input');
    if ($jsonInput === false) {
        throw new Exception('Failed to read input data');
    }

    $input = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    // Валидация входных данных
    $required = ['solution', 'language'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    if (!isset($_SESSION['current_task'])) {
        throw new Exception('No active task found');
    }

    $task = $_SESSION['current_task'];
    $prompt = "Evaluate this solution strictly in JSON format with these fields:
    - score (0-100)
    - message
    - details
    - suggestions[]
    
    Task: {$task['description']}
    Language: {$input['language']}
    Solution:\n{$input['solution']}";

    $response = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.5,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ]);

    if ($response['code'] !== 200) {
        throw new Exception("API request failed with HTTP code: " . $response['code']);
    }

    // Проверка на HTML в ответе
    if (strpos($response['body'], '<!DOCTYPE') !== false || strpos($response['body'], '<html') !== false) {
        throw new Exception("API returned HTML instead of JSON");
    }

    $data = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid API response: " . json_last_error_msg());
    }

    $evaluation = json_decode($data['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $evaluation = [
            'score' => 0,
            'message' => 'Evaluation failed',
            'details' => 'The AI returned invalid JSON: ' . json_last_error_msg(),
            'suggestions' => []
        ];
    }

    // Добавляем информацию о задании в ответ
    $evaluation['task_id'] = $task['id'] ?? null;
    $evaluation['language'] = $input['language'];

    echo json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'Evaluation error'
    ], JSON_UNESCAPED_UNICODE);
}

function makeApiRequest($data) {
    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage',
        'X-CSRF-Token: ' . ($_SESSION['csrf_token'] ?? '')
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
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
?>