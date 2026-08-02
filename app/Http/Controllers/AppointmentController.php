<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\AppointmentService;

// ←← フェーズ6 Step6-3: AppointmentController 新設 ←←
class AppointmentController extends Controller
{
    public function __construct(
        private AppointmentService $appointmentService,
    ) {
    }

    /**
     * 面談事前メモを生成して返す（POST /api/appointment/memo）
     *
     * WP側（appointment-aggregator）からセッションデータを受け取り、
     * AppointmentService に生成を委譲して、結果を JSON で返す。
     * ReportController::generate() と同型。
     */
    public function memo(Request $request): JsonResponse
    {
        $request->validate([
            'session_data' => 'present|array',
        ]);

        $sessionData = $request->input('session_data');

        try {
            $memo = $this->appointmentService->generateMemo($sessionData);
        } catch (\RuntimeException $e) {
            report($e);
            return response()->json([
                'error' => '事前メモの生成に失敗しました。',
            ], 502);
        }

        return response()->json([
            'memo' => $memo,
        ]);
    }
}
