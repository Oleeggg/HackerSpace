<?php
require_once('../config.php');

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['current_task'])) {
    die(json_encode(['error' => 'Нет активного задания']));
}

$input = json_decode(file_get_contents('php://input'), true);
$solution = $input['solution'] ?? '';
$language = $input['language'] ?? 'javascript';
$task = $_SESSION['current_task'];

// Формируем промпт для проверки решения
$prompt = "Проверь решение задания и дай развернутую оценку:
Задание: {$task['description']}
Язык программирования: $language
Уровень сложности: {$task['difficulty']}
Пример решения: {$task['example']}

Представленное решение:
$solution

Дай оценку по следующим критериям:
1. Корректность (0-100%)
2. Оптимальность
3. Читаемость кода
4. Соответствие стандартам языка

Формат ответа: JSON с полями score, message, details, suggestions (массив строк)";

$messages = [
    [
        'role' => 'user',
        'content' => $prompt
    ]
];

$data = [
    'model' => DEVSTRAL_MODEL,
    'messages' => $messages,
    'temperature' => 0.5, // Меньше креативности для проверки
    'max_tokens' => 2000
];

$headers = [
    'Authorization: Bearer ' . OPENROUTER_API_KEY,
    'Content-Type: application/json',
    'HTTP-Referer: ' . $_SERVER['HTTP_HOST'],
    'X-Title: HackerSpaceWorkPage'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, OPENROUTER_API_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die(json_encode(['error' => 'Ошибка API: ' . $response]));
}

$responseData = json_decode($response, true);

if (isset($responseData['choices'][0]['message']['content'])) {
    $evaluation = json_decode($responseData['choices'][0]['message']['content'], true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo json_encode($evaluation);
    } else {
        echo json_encode(['error' => 'Неверный формат оценки от AI']);
    }
} else {
    echo json_encode(['error' => 'Не удалось получить оценку от AI']);
}
?>