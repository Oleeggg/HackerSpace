<?php
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');

// Функция для логирования ошибок
function logError($message) {
    file_put_contents(__DIR__ . '/api_errors.log', date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

try {
    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests allowed', 405);
    }

    // Получение входных данных
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON input', 400);
    }

    // Валидация параметров
    $difficulty = $input['difficulty'] ?? 'beginner';
    $language = $input['language'] ?? 'javascript';

    $allowedDifficulties = ['beginner', 'intermediate', 'advanced'];
    $allowedLanguages = ['javascript', 'php', 'python', 'html'];

    if (!in_array($difficulty, $allowedDifficulties) {
        throw new Exception('Invalid difficulty level', 400);
    }

    // Формирование промпта
    $prompt = "Generate a programming task with:
    - Language: $language
    - Difficulty: $difficulty
    - Response format: JSON with title, description, example, initialCode
    - Example must use $language syntax";

    // Подготовка запроса
    $data = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
        'max_tokens' => 2000
    ];

    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage'
    ];

    // Отправка запроса
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Обработка ошибок CURL
    if ($error) {
        throw new Exception("CURL error: $error", 500);
    }

    // Анализ ответа
    if ($httpCode !== 200) {
        logError("API returned $httpCode: " . $response);
        throw new Exception("API request failed with code $httpCode", $httpCode);
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['choices'][0]['message']['content'])) {
        throw new Exception('Invalid API response structure', 500);
    }

    // Возвращаем результат
    echo $responseData['choices'][0]['message']['content'];

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['error' => $e->getMessage()]);
    logError($e->getMessage());
}