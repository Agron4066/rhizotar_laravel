<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ClaudeClient
{
    private Client $client;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
        $this->model  = config('services.anthropic.model', 'claude-sonnet-4-5');

        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'timeout'  => 120,
            'headers'  => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
        ]);
    }

    /**
     * Claude APIにストリーミングリクエストを送り、
     * チャンクが届くたびに $onChunk コールバックを呼ぶ
     *
     * @param array    $messages  会話履歴 [['role'=>'user','content'=>'...'], ...]
     * @param callable $onChunk   テキストチャンクを受け取るコールバック
     * @param string   $system    システムプロンプト（任意）
     */
    public function streamMessage(array $messages, callable $onChunk, string $system = ''): void
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => 4096,
            'stream'     => true,
            'messages'   => $messages,
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        try {
            $response = $this->client->post('/v1/messages', [
                'json'            => $body,
                'stream'          => true,
            ]);

            $stream = $response->getBody();

            while (!$stream->eof()) {
                $line = $this->readLine($stream);

                if (!str_starts_with($line, 'data:')) {
                    continue;
                }

                $jsonStr = trim(substr($line, 5));

                if ($jsonStr === '[DONE]') {
                    break;
                }

                $data = json_decode($jsonStr, true);

                if (
                    isset($data['type']) &&
                    $data['type'] === 'content_block_delta' &&
                    isset($data['delta']['text'])
                ) {
                    $onChunk($data['delta']['text']);
                }
            }

        } catch (GuzzleException $e) {
            throw new \RuntimeException('Claude API error: ' . $e->getMessage(), 0, $e);
        }
    }


    /**
     * Claude APIに非ストリーミングリクエストを送り、
     * 応答テキストを文字列で返す
     *
     * @param array  $messages  会話履歴
     * @param string $system    システムプロンプト（任意）
     * @return string 応答テキスト
     */
    public function sendMessage(array $messages, string $system = ''): string
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => 4096,
            'messages'   => $messages,
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        try {
            $response = $this->client->post('/v1/messages', [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $text = '';
            foreach ($data['content'] as $block) {
                if ($block['type'] === 'text') {
                    $text .= $block['text'];
                }
            }

            return $text;

        } catch (GuzzleException $e) {
            throw new \RuntimeException('Claude API error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * ストリームから1行読み取る
     */
    private function readLine($stream): string
    {
        $line = '';
        while (!$stream->eof()) {
            $char = $stream->read(1);
            if ($char === "\n") {
                break;
            }
            $line .= $char;
        }
        return rtrim($line, "\r");
    }
}
