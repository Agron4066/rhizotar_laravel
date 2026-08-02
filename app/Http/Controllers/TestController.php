<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'APIは正常に動作しています',
            'version' => '2.0.0',
        ]);
    }
}
