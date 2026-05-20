<?php
/**
 * GrokClient — thin PHP wrapper around the xAI Grok API.
 *
 * The Grok API is OpenAI-compatible, so we POST to:
 *   https://api.x.ai/v1/chat/completions
 *
 * Usage:
 *   $grok = new GrokClient($_ENV['GROK_API_KEY']);
 *   $reply = $grok->chat($messages, $tools);   // single round-trip
 *   $reply = $grok->run($messages, $tools, $toolExecutor); // agentic loop
 */

class GrokClient
{
    private const API_URL = 'https://api.x.ai/v1/chat/completions';
    private const TIMEOUT  = 30;

    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'grok-3-mini')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    // ------------------------------------------------------------------ //
    //  Single round-trip                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Send a messages array (plus optional tool definitions) to Grok and
     * return the raw API response array.
     *
     * @param array      $messages   OpenAI-format messages array
     * @param array|null $tools      Optional tool definitions
     * @param string     $toolChoice 'auto' | 'none' | 'required'
     */
    public function chat(array $messages, ?array $tools = null, string $toolChoice = 'auto'): array
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.4,
        ];

        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = $toolChoice;
        }

        return $this->post($payload);
    }

    // ------------------------------------------------------------------ //
    //  Agentic loop                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Run a full agentic loop: call Grok → if it wants a tool, execute it
     * and feed the result back → repeat until Grok stops calling tools.
     *
     * @param array    $messages     Starting messages array (mutated in-place)
     * @param array    $tools        Tool definitions
     * @param callable $toolExecutor fn(string $toolName, array $args): string
     *                               Should return a plain-text / JSON string result.
     * @param int      $maxTurns     Safety limit on tool-call iterations
     *
     * @return array  ['reply' => string, 'messages' => array, 'tool_calls_made' => array]
     */
    public function run(
        array    &$messages,
        array    $tools,
        callable $toolExecutor,
        int      $maxTurns = 6
    ): array {
        $toolCallsMade = [];

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = $this->chat($messages, $tools);

            if (!empty($response['error'])) {
                return [
                    'reply'           => 'AI error: ' . ($response['error']['message'] ?? 'Unknown error'),
                    'messages'        => $messages,
                    'tool_calls_made' => $toolCallsMade,
                ];
            }

            $choice  = $response['choices'][0] ?? null;
            $message = $choice['message'] ?? [];

            // Append assistant message to history
            $messages[] = $message;

            $finishReason = $choice['finish_reason'] ?? 'stop';

            // If Grok is done (no tool calls), return its text reply
            if ($finishReason !== 'tool_calls' || empty($message['tool_calls'])) {
                return [
                    'reply'           => $message['content'] ?? '',
                    'messages'        => $messages,
                    'tool_calls_made' => $toolCallsMade,
                ];
            }

            // Execute each tool call and feed results back
            foreach ($message['tool_calls'] as $toolCall) {
                $toolName = $toolCall['function']['name']      ?? '';
                $argsJson = $toolCall['function']['arguments'] ?? '{}';
                $toolId   = $toolCall['id']                    ?? uniqid('tc_');

                $args   = json_decode($argsJson, true) ?? [];
                $result = '';

                try {
                    $result = (string) call_user_func($toolExecutor, $toolName, $args);
                } catch (Throwable $e) {
                    error_log("GrokClient tool error [{$toolName}]: " . $e->getMessage());
                    $result = json_encode(['error' => $e->getMessage()]);
                }

                $toolCallsMade[] = ['tool' => $toolName, 'args' => $args, 'result' => $result];

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolId,
                    'content'      => $result,
                ];
            }
        }

        // Safety fallback — return whatever last text Grok produced
        $last = end($messages);
        return [
            'reply'           => $last['content'] ?? 'The assistant reached its action limit.',
            'messages'        => $messages,
            'tool_calls_made' => $toolCallsMade,
        ];
    }

    // ------------------------------------------------------------------ //
    //  HTTP layer                                                          //
    // ------------------------------------------------------------------ //

    private function post(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("GrokClient curl error: {$err}");
            return ['error' => ['message' => "Network error: {$err}"]];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            error_log("GrokClient bad response (HTTP {$status}): {$raw}");
            return ['error' => ['message' => "Unexpected response from AI service (HTTP {$status})"]];
        }

        return $decoded;
    }
}
