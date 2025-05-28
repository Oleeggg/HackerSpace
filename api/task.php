<?php
header('Content-Type: application/json');
require_once('../vendor/autoload.php');

use OpenRouter\OpenRouterClient;

$client = new OpenRouterClient('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');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['task'])) {
        $response = $client->generateTask($data['task']);
        if (isset($response['error'])) {
            error_log("API Error: " . $response['error']);
            echo json_encode(['error' => $response['error']]);
        } else {
            echo json_encode(['response' => $response]);
        }
    }
}
?>
