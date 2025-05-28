<?php
header('Content-Type: application/json');

require_once('phpmailer/src/PHPMailer.php');
require_once('phpmailer/src/SMTP.php');
require_once('phpmailer/src/Exception.php');

// Получаем данные из запроса
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['prompt'])) {
    echo json_encode(['success' => false, 'error' => 'Не указан prompt']);
    exit;
}

// Здесь вызываем функцию для работы с Devstral API
$result = call_devstral_api($data['prompt'], $data['creativity'], $data['max_tokens']);

if ($result === false) {
    echo json_encode(['success' => false, 'error' => 'Ошибка при обращении к API Devstral']);
    exit;
}

// Возвращаем результат
echo json_encode([
    'success' => true,
    'result' => $result['choices'][0]['text'] // Уточните структуру ответа API
]);

function call_devstral_api($prompt, $temperature, $max_tokens) {
    // Реализация API вызова (см. выше)
    // ...
}
?>