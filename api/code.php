<?php
require_once('../vendor/autoload.php');

use OpenRouter\OpenRouterClient;

$client = new OpenRouterClient('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');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['code'])) {
        $response = $client->checkCode($data['code']);
        if (isset($response['error'])) {
            error_log("API Error: " . $response['error']);
            echo json_encode(['error' => $response['error']]);
        } else {
            echo json_encode(['response' => $response]);
        }
    }
}
?>
