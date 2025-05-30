<?php
require_once(__DIR__ . '/../config.php');

// Очистка буфера перед любым выводом
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    // Получаем и валидируем входные данные
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON input: " . json_last_error_msg());
    }

    // Формируем промпт с явным требованием JSON
    $prompt = "Generate a programming task in " . ($input['language'] ?? 'javascript') . 
              " with " . ($input['difficulty'] ?? 'beginner') . " difficulty. " .
              "Return STRICT JSON format with these fields: " .
              "title (string), description (string), example (string), initialCode (string), difficulty (string). " .
              "Example: {\"title\":\"Task Title\",\"description\":\"Task description...\",\"example\":\"Code example\",\"initialCode\":\"// Starter code\",\"difficulty\":\"beginner\"}";

    $response = makeApiRequest([
        'model' => DEVSTRAL_MODEL,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
        'max_tokens' => 2000,
        'response_format' => ['type' => 'json_object']
    ]);

    if ($response['code'] !== 200) {
        throw new Exception("API request failed with HTTP code: " . $response['code']);
    }

    // Декодируем основной ответ
    $data = json_decode($response['body'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid API response: " . json_last_error_msg());
    }

    // Проверяем и валидируем структуру ответа
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
            'initialCode' => '// Your code here',
            'difficulty' => $input['difficulty'] ?? 'beginner'
        ];
    }

    // Проверяем обязательные поля
    $requiredFields = ['title', 'description', 'initialCode'];
    foreach ($requiredFields as $field) {
        if (!isset($content[$field])) {
            $content[$field] = '';
        }
    }

    echo json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'Server error occurred'
    ], JSON_UNESCAPED_UNICODE);
}
?>
