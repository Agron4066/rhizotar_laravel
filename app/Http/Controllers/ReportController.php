<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ReportService;

class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService,
    ) {
    }

    /**
     * 日次レポートを生成して返す（POST /api/report/generate）
     *
     * WP側（report-aggregator）から前日のセッション群を受け取り、
     * ReportService に生成を委譲して、結果を JSON で返す。
     * このコントローラ自身は集計もDBアクセスもせず、入力検証と
     * 委譲・JSON化だけを担う。
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'sessions' => 'present|array',
            'date'     => 'required|string',
        ]);

        $sessions = $request->input('sessions');
        $date     = $request->input('date');

        try {
            $report = $this->reportService->generate($sessions, $date);
        } catch (\RuntimeException $e) {
            // ClaudeClient が API エラー時に投げる RuntimeException をここで受ける。
            // ChatController（ストリーム）と違い JSON エンドポイントなので、
            // 明示的にログを残してエラー JSON を返す。
            report($e);
            return response()->json([
                'error' => 'レポートの生成に失敗しました。',
            ], 502);
        }

        return response()->json([
            'date'   => $date,
            'report' => $report,
        ]);
    }
}
