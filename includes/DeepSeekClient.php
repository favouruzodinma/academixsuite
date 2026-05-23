<?php
/**
 * DeepSeekClient — OpenAI-compatible wrapper for DeepSeek Chat Completions.
 *
 * Endpoint:
 *   https://api.deepseek.com/chat/completions
 */

class DeepSeekClient
{
    private const API_URL = 'https://api.deepseek.com/chat/completions';
    private const TIMEOUT = 30;

    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'deepseek-v4-flash')
    {
        $this->apiKey = $apiKey;
        $this->model = $model ?: 'deepseek-v4-flash';
    }

    public function chat(array $messages, ?array $tools = null, string $toolChoice = 'auto', ?int $maxTokens = null): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.4,
        ];

        if ($maxTokens !== null && $maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice;
        }

        return $this->post($payload);
    }

    /**
     * Run an agent loop: ask DeepSeek, execute tool calls, then feed results back.
     *
     * @return array{reply:string,messages:array,tool_calls_made:array}
     */
    public function run(
        array &$messages,
        array $tools,
        callable $toolExecutor,
        int $maxTurns = 6,
        ?int $maxTokens = null
    ): array {
        $toolCallsMade = [];

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = $this->chat($messages, $tools, 'auto', $maxTokens);

            if (!empty($response['error'])) {
                $err = $response['error'];
                $errMsg = '';
                if (is_string($err)) {
                    $errMsg = $err;
                } elseif (is_array($err)) {
                    $errMsg = $err['message'] ?? $err['type'] ?? $err['code'] ?? '';
                }
                if ($errMsg === '') {
                    $errMsg = 'Unknown error from AI service';
                }
                return [
                    'reply' => 'AI error: ' . $this->friendlyError($errMsg),
                    'messages' => $messages,
                    'tool_calls_made' => $toolCallsMade,
                ];
            }

            $choice = $response['choices'][0] ?? null;
            $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
            $messages[] = $message;

            $finishReason = $choice['finish_reason'] ?? 'stop';
            if ($finishReason !== 'tool_calls' || empty($message['tool_calls'])) {
                return [
                    'reply' => (string)($message['content'] ?? ''),
                    'messages' => $messages,
                    'tool_calls_made' => $toolCallsMade,
                ];
            }

            foreach ($message['tool_calls'] as $toolCall) {
                $toolName = $toolCall['function']['name'] ?? '';
                $argsJson = $toolCall['function']['arguments'] ?? '{}';
                $toolId = $toolCall['id'] ?? uniqid('tc_');
                $args = json_decode($argsJson, true) ?: [];

                try {
                    $result = (string) call_user_func($toolExecutor, $toolName, $args);
                } catch (Throwable $e) {
                    error_log("DeepSeekClient tool error [{$toolName}]: " . $e->getMessage());
                    $result = json_encode(['error' => $e->getMessage()]);
                }

                $toolCallsMade[] = ['tool' => $toolName, 'args' => $args, 'result' => $result];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolId,
                    'content' => $result,
                ];
            }
        }

        $last = end($messages);
        return [
            'reply' => is_array($last) ? (string)($last['content'] ?? 'The assistant reached its action limit.') : 'The assistant reached its action limit.',
            'messages' => $messages,
            'tool_calls_made' => $toolCallsMade,
        ];
    }

    private function post(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("DeepSeekClient curl error: {$err}");
            return ['error' => ['message' => "Network error: {$err}", 'type' => 'network']];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            error_log("DeepSeekClient bad response (HTTP {$status}): {$raw}");
            return ['error' => ['message' => "Unexpected response from AI service (HTTP {$status})", 'type' => 'parse', 'http_status' => $status]];
        }

        if ($status >= 400) {
            $errMsg = '';
            if (isset($decoded['error']['message'])) {
                $errMsg = $decoded['error']['message'];
            } elseif (is_string($decoded['error'] ?? null)) {
                $errMsg = $decoded['error'];
            } elseif (isset($decoded['error']['type'])) {
                $errMsg = $decoded['error']['type'] . (isset($decoded['error']['code']) ? ': ' . $decoded['error']['code'] : '');
            }
            if ($errMsg === '') {
                $errMsg = "AI service returned HTTP {$status}";
            }

            $friendly = $this->friendlyError($errMsg);
            error_log("DeepSeekClient HTTP {$status} error: {$friendly}");
            return ['error' => ['message' => $friendly, 'type' => 'http', 'http_status' => $status]];
        }

        return $decoded;
    }

    private function friendlyError(string $message): string
    {
        if (stripos($message, 'rate limit') !== false || stripos($message, 'too many requests') !== false) {
            return 'DeepSeek rate limit reached. Please wait a few seconds and try again.';
        }
        if (stripos($message, 'insufficient') !== false || stripos($message, 'balance') !== false || stripos($message, 'quota') !== false) {
            return 'DeepSeek account credit or quota is not available. Please check your DeepSeek billing balance.';
        }
        if (stripos($message, 'authentication') !== false || stripos($message, 'api key') !== false || stripos($message, 'unauthorized') !== false) {
            return 'DeepSeek API key was rejected. Please confirm the key in your .env file.';
        }

        return $message;
    }
}
