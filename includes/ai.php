<?php
function callClaude(string $prompt, string $system = '', int $maxTokens = 512): ?string {
    $key = defined('AI_API_KEY') ? AI_API_KEY : '';
    if ($key === '') return null;

    $messages = [];
    if ($system !== '') {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $body = [
        'model'      => 'llama-3.3-70b-versatile',
        'max_tokens' => $maxTokens,
        'messages'   => $messages,
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    curl_close($ch);

    if (!$raw) return null;
    $data = json_decode($raw, true);
    return $data['choices'][0]['message']['content'] ?? null;
}
