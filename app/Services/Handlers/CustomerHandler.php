<?php

namespace App\Services\Handlers;

use Illuminate\Support\Facades\Log;

/**
 * CustomerHandler
 *
 * <FOLLOW_UP>タグが検出されたときの処理を担当するHandler。
 *
 * SearchHandler と同じ「保守的移行」アプローチで、
 * ChatController から extractFollowUp() が直接呼ばれる構造。
 * Phase 14 で TagDispatcher 経由に切り替え、
 * handle() メソッドが TagDispatcher から呼ばれる形に移行する予定。
 *
 * 役割:
 *   1. <FOLLOW_UP>タグの中身(JSON)を抽出
 *   2. パースして follow_up データとして返却
 *
 * SearchHandler との違い:
 *   - キャッシュ書き込みなし(中間アクションではないため)
 *   - SSEコールバック不要(検索のような一時停止→再開がないため)
 *   - wasProcessed() 不要(done の出し分けに影響しないため)
 */
class CustomerHandler implements TagHandlerInterface
{
    public function __construct()
    {
    }

    /**
     * TagHandlerInterface契約の実装。
     * Phase 14 で TagDispatcher 経由で呼ばれるようになる予定。
     *
     * 現時点では未使用(ChatControllerから extractFollowUp() が直接呼ばれる)。
     *
     * @param string $tagContent <FOLLOW_UP>タグの中身(JSON文字列)
     */
    public function handle(string $tagContent): void
    {
        Log::info('[CustomerHandler] handle() called (skeleton, not yet implemented)', [
            'content_length' => strlen($tagContent),
        ]);
    }

    /**
     * Claudeの応答全文から<FOLLOW_UP>タグを検出し、中身をパースして返す。
     * ChatController::stream() / continue() から呼ばれる。
     *
     * 検出パターン: <FOLLOW_UP>{JSON}</FOLLOW_UP>
     * JSONの期待構造:
     *   {
     *     "timing": "3日後",
     *     "content": "空室状況確認・内見候補日提示",
     *     "priority": 0.9
     *   }
     *
     * @param string $fullResponse Claudeの応答全文
     * @return array|null パース成功時は ['timing' => ..., 'content' => ..., 'priority' => ...]、
     *                    タグ未検出またはパース失敗時は null
     */
    public function extractFollowUp(string $fullResponse): ?array
    {
        // 1. <FOLLOW_UP>タグを正規表現で検出
        if (!preg_match('/<FOLLOW_UP>(.*?)<\/FOLLOW_UP>/s', $fullResponse, $matches)) {
            Log::info('[CustomerHandler] extractFollowUp: no FOLLOW_UP tag found');
            return null;
        }

        $jsonString = trim($matches[1]);

        // 2. JSONをパース
        $decoded = json_decode($jsonString, true);

        if (!is_array($decoded)) {
            Log::warning('[CustomerHandler] extractFollowUp: JSON parse failed', [
                'raw_content' => $jsonString,
            ]);
            return null;
        }

        Log::info('[CustomerHandler] extractFollowUp: successfully extracted', [
            'timing'   => $decoded['timing'] ?? null,
            'priority' => $decoded['priority'] ?? null,
        ]);

        return $decoded;
    }
}
