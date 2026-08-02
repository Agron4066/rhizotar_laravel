<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        return response()->stream(function () {
            $message = 'こんにちは！これはストリーミングのテストです。VPSでSSEが動いています！';
            $chars = mb_str_split($message);

            foreach ($chars as $char) {
                echo "data: " . json_encode(['text' => $char]) . "\n\n";
                ob_flush();
                flush();
                usleep(80000); // 0.08秒待機
            }

            // 終了シグナルを送る
            echo "data: " . json_encode(['done' => true]) . "\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
