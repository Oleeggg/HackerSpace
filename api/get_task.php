<?php
require_once('../config.php');

header('Content-Type: application/json');

// Ограничение частоты запросов
session_start();
if (!isset($_SESSION['last_task_request'])) {
    $_SESSION['last_task_request'] = 0;
}

$current_time = time();
if ($current_time - $_SESSION['last_task_request'] < 10) { // Не чаще чем раз в 10 секунд
    die(json_encode(['error' => 'Слишком частые запросы. Пожалуйста, подождите.']));
}
$_SESSION['last_task_request'] = $current_time;

$input = json_decode(file_get_contents('php://input'), true);
$difficulty = $input['difficulty'] ?? 'beginner';
$language = $input['language'] ?? 'javascript';

// Формируем промпт для нейросети
$prompt = "Сгенерируй задание по программированию со следующими параметрами:
Язык: $language
Уровень сложности: $difficulty
Формат ответа: JSON с полями title, description, example, initialCode, difficulty

Задание должно быть практическим и проверяемым. Пример должен быть на указанном языке.
Сложность должна быть на русском: Начинающий, Средний или Продвинутый.";

$messages = [
    [
        'role' => 'user',
        'content' => $prompt
    ]
];

$data = [
    'model' => DEVSTRAL_MODEL,
    'messages' => $messages,
    'temperature' => 0.7,
    'max_tokens' => 1500
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
    $task = json_decode($responseData['choices'][0]['message']['content'], true);
    if (json_last_error() === JSON_ERROR_NONE) {
        // Добавляем ID языка и сложности для последующей проверки
        $task['language'] = $language;
        $task['difficulty_level'] = $difficulty;
        $_SESSION['current_task'] = $task;
        echo json_encode($task);
    } else {
        echo json_encode(['error' => 'Неверный формат задания от AI']);
    }
} else {
    echo json_encode(['error' => 'Не удалось получить задание от AI']);
}