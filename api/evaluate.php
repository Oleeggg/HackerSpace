<?php
require_once('../config.php');

header('Content-Type: application/json');

try {
    session_start();
    if (!isset($_SESSION['current_task'])) {
        throw new Exception('Нет активного задания');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Неверный JSON во входных данных');
    }

    $solution = $input['solution'] ?? '';
    $language = $input['language'] ?? 'javascript';
    $task = $_SESSION['current_task'];

    // ... (остальной код остается без изменений до curl_exec)

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Ошибка API: HTTP код ' . $httpCode);
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Неверный JSON от API');
    }

    if (!isset($responseData['choices'][0]['message']['content'])) {
        throw new Exception('Неожиданная структура ответа API');
    }

    $evaluation = json_decode($responseData['choices'][0]['message']['content'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Если не JSON, создаем структуру вручную
        $evaluation = [
            'score' => 0,
            'message' => 'Ошибка при оценке решения',
            'details' => $responseData['choices'][0]['message']['content'],
            'suggestions' => []
        ];
    }

    echo json_encode($evaluation);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'Произошла ошибка при оценке решения'
    ]);
    error_log("Error in evaluate.php: " . $e->getMessage());
}