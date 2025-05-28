<?php
class OpenRouterClient {
    private $apiKey;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function generateTask($task) {
        $url = 'hhttps://openrouter.ai/api/v1/generate';
        $data = [
            'model' => 'mistralai/devstral-small:free',
            'prompt' => $task,
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\nAuthorization: Bearer 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'" . $this->apiKey . "\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
            ],
        ];

        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            error_log("Failed to call API: " . print_r(error_get_last(), true));
            return ['error' => 'Failed to call API'];
        }

        return json_decode($result, true);
    }

    public function checkCode($code) {
        $url = 'https://openrouter.ai/api/v1/check';
        $data = [
            'model' => 'mistralai/devstral-small:free',
            'code' => $code,
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\nAuthorization: Bearer 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'" . $this->apiKey . "\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
            ],
        ];

        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            error_log("Failed to call API: " . print_r(error_get_last(), true));
            return ['error' => 'Failed to call API'];
        }

        return json_decode($result, true);
    }
}
?>