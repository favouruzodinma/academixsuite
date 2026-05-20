<?php
/**
 * GroqClient — thin PHP wrapper around Groq's OpenAI-compatible Chat API.
 *
 * Endpoint:
 *   https://api.groq.com/openai/v1/chat/completions
 *
 * Usage:
 *   $groq = new GroqClient($_ENV['GROQ_API_KEY']);
 *   $reply = $groq->chat($messages, $tools);
 *   $reply = $groq->run($messages, $tools, $toolExecutor);
 */

class GroqClient
{
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const TIMEOUT = 30;

    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'llama-3.3-70b-versatile')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    /**
     * Send a messages array plus optional tool definitions to Groq.
     */
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
     * Run an agent loop: call Groq, execute requested tools, feed results back.
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
                if (stripos($errMsg, 'rate limit') !== false || stripos($errMsg, 'tokens per minute') !== false) {
                    $errMsg = 'Groq rate limit reached. Please wait a few seconds and try again. If it keeps happening, switch to a lighter Groq model or upgrade the Groq billing tier.';
                }
                return [
                    'reply' => 'AI error: ' . $errMsg,
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
                    error_log("GroqClient tool error [{$toolName}]: " . $e->getMessage());
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
            error_log("GroqClient curl error: {$err}");
            return ['error' => ['message' => "Network error: {$err}", 'type' => 'network']];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            error_log("GroqClient bad response (HTTP {$status}): {$raw}");
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

            error_log("GroqClient HTTP {$status} error: {$errMsg}");
            return ['error' => ['message' => $errMsg, 'type' => 'http', 'http_status' => $status]];
        }

        return $decoded;
    }
}
