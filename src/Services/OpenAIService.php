<?php

namespace RTippin\Messenger\Services;

use OpenAI;

class OpenAIService
{
    protected $client;

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function translateText($text, $targetLanguage = "Burmese")
    {
        $response = $this->client->chat()->create([
            'model' => 'gpt-4', // gpt-3.5-turbo, gpt-4, etc.
            'messages' => [
                ["role" => "system", "content" => "You are a helpful translation assistant."],
                ["role" => "user", "content" => "Translate the following text to {$targetLanguage}: {$text} without any extra words."]
            ],
        ]);

        return $response['choices'][0]['message']['content'] ?? 'Translation error';
    }

    public function detectLanguage($text)
    {
        $response = $this->client->chat()->create([
            'model' => 'gpt-4', // gpt-3.5-turbo, gpt-4
            'messages' => [
                ["role" => "system", "content" => "You are a language detection AI. Respond with only the name of the language in English, without any extra words."],
                ["role" => "user", "content" => "What language is this text written in? '{$text}'"]
            ],
        ]);

        return trim($response['choices'][0]['message']['content'] ?? 'Unknown');
    }
}
