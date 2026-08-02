<?php

namespace App\Services\Handlers;

use Illuminate\Support\Facades\Log;

/**
 * AnalysisHandler
 *
 * <ANALYSIS>タグが検出されたときの処理を担当するHandler。
 * CustomerHandler と同じ「保守的移行」アプローチで、
 * ChatController から extractAnalysis() が直接呼ばれる構造。
 *
 * 実装クラス:
 *   - SearchHandler
 *   - CustomerHandler
 *   - AnalysisHandler（このクラス）
 */
class AnalysisHandler implements TagHandlerInterface
{
    public function __construct()
    {
    }

    /**
     * TagHandlerInterface 契約の実装（スケルトン）。
     * Phase 14 で TagDispatcher 経由に切り替わるまで未使用。
     */
    public function handle(string $tagContent): void
    {
        Log::info('[AnalysisHandler] handle() called (skeleton, not yet implemented)', [
            'content_length' => strlen($tagContent),
        ]);
    }

    /**
     * Claude の応答全文から <ANALYSIS> タグを検出し、
     * 中身の JSON を検証・整形して返す。
     *
     * スタブ段階では AnalysisService がダミー値を返すため、
     * このメソッドは常に固定スキーマの配列を返す。
     *
     * 戻り値のスキーマ（汎用4キー）：
     *   [
     *       'summary'       => '会話の要約',
     *       'acquired_info' => ['取得できた情報1', ...],
     *       'missing_info'  => ['まだ聞けていない情報1', ...],
     *       'temperature'   => '高|中|低|未判定',
     *   ]
     *
     * タグ未検出・JSONパース失敗時は null を返す（異常ではなく正常系）。
     */
    public function extractAnalysis(string $fullResponse): array
    {
        $analysisService = new \App\Services\AnalysisService();
        return $analysisService->analyzeConversation($fullResponse);
    }
}
