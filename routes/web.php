<?php

use Illuminate\Support\Facades\Route;
use App\Services\ClaudeClient;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-claude', function () {
    $client = new ClaudeClient();

    $messages = [
        ['role' => 'user', 'content' => 'こんにちは！一言だけ返してください。'],
    ];

    $result = '';

    $client->streamMessage($messages, function (string $chunk) use (&$result) {
        $result .= $chunk;
    });

    return response($result);
});
