<?php

namespace App\Services\Handlers;

use App\Services\Handlers\Exceptions\SessionNotFoundException;
use App\Services\SearchService;
use Illuminate\Support\Facades\Log;

/**
 * SearchHandler
 *
 * <SEARCH target="...">タグが検出されたときの処理を担当するHandler。
 *
 * Phase 13 では ChatController から「保守的移行」のアプローチで
 * handleSearchTag() / handleSearchResults() が直接呼ばれる構造を採用。
 * Phase 14 で SEARCH も TagDispatcher 経由に切り替え、
 * handle() メソッドが TagDispatcher から呼ばれる形に移行する予定。
 *
 * 機能2 Step 5 で target 属性パース対応に改修：
 *   - SearchService::extractConditions() の戻り値構造変更に対応
 *     （['target' => ..., 'conditions' => ...] のネスト構造を受け取り、
 *      取り出して個別に扱う形に）
 *   - キャッシュに target と conditions を保存
 *   - SSE ペイロードに target を含めて発火（target と conditions を平坦化）
 *   - handleSearchResults() の整形を target 非依存で改善
 *     （0件の場合の特別扱い、件数の冒頭明示）
 *
 * 役割:
 *   1. <SEARCH target="...">タグの中身を SearchService に渡して
 *      target と conditions を取得
 *   2. 会話状態のキャッシュ書き込み(session_idをキーに)
 *   3. search_request SSE イベントの発火(コールバック経由)
 *   4. /api/chat/continue での復元処理と検索結果の整形
 *
 * SSE 発火について:
 *   Handler自身はecho/flushを持たない。
 *   呼び出し元(ChatController)から渡されるコールバックを通じて
 *   通知することで、HTTPプロトコルの詳細から切り離す。
 *
 * 処理済みフラグについて:
 *   wasProcessed() で「検索リクエストとして処理したか」を返す。
 *   ChatControllerはこのフラグを参照して、done を出すか
 *   search_request で接続を閉じるかを判断する。
 */
class SearchHandler implements TagHandlerInterface
{
    /**
     * 検索リクエストとして処理したかどうかのフラグ。
     * ChatControllerが done を出すか判断するために参照する。
     */
    private bool $processed = false;

    public function __construct()
    {
        // SearchService は静的メソッドとして呼び出すため、依存注入は不要。
        // 現時点では他に必要な依存もない。
    }

    /**
     * TagHandlerInterface契約の実装。
     * Phase 14 で TagDispatcher 経由で呼ばれるようになる予定。
     *
     * 機能2 完了時点では未使用(ChatControllerから handleSearchTag() が直接呼ばれる)。
     * Phase 13 で導入された「保守的移行」の構造を機能2 でも維持する。
     *
     * @param string $tagContent <SEARCH target="...">タグの中身(JSON文字列)
     */
    public function handle(string $tagContent): void
    {
        Log::info('[SearchHandler] handle() called (skeleton, not yet implemented)', [
            'content_length' => strlen($tagContent),
        ]);
        // TODO: Phase 14 で TagDispatcher 経由のフローを実装する
    }

    /**
     * <SEARCH target="...">タグ検出時の処理を行う(保守的移行用のエントリポイント)。
     * ChatController::stream() から呼ばれる。
     *
     * 処理の流れ:
     *   1. SearchService::extractConditions() で target と conditions を抽出
     *      target属性なしの<SEARCH>や壊れたJSONの場合はSearchServiceがnullを返すため、
     *      検索は走らない（判断Dの実装）
     *   2. キャッシュに会話状態を保存(session_idキー、10分有効)
     *      target と conditions もキャッシュデータの中身として保存（判断Eの実装）
     *   3. SSEコールバックで search_request イベントを通知
     *      ペイロードは target と conditions を平坦化した形で発火
     *   4. processedフラグを立てる
     */
    public function handleSearchTag(
        string $fullResponse,
        string $sessionId,
        array $messages,
        callable $sseCallback,
    ): void
    {
        // 1. SearchService::extractConditions() で target と conditions を抽出
        $extracted = SearchService::extractConditions($fullResponse);

        if ($extracted === null) {
            // <SEARCH target="...">タグが見つからない、target属性なし、JSON壊れなどの場合
            Log::info('[SearchHandler] handleSearchTag: no valid SEARCH tag found, skipping');
            return;
        }

        $target = $extracted['target'];
        $conditions = $extracted['conditions'];

        // 2. キャッシュに会話状態を保存(10分有効)
        //    target と conditions はキャッシュデータの中身として保持（判断Eの実装）
        cache()->put($sessionId, [
            'messages'      => $messages,
            'firstResponse' => $fullResponse,
            'target'        => $target,
            'conditions'    => $conditions,
        ], now()->addMinutes(10));

        // 3. SSEコールバックで search_request イベントを通知
        //    ペイロードは target と conditions を平坦化（並列の別キーとして持つ）
        $sseCallback([
            'search_request' => true,
            'target'         => $target,
            'conditions'     => $conditions,
            'session_id'     => $sessionId,
        ]);

        // 4. 処理済みフラグを立てる
        $this->processed = true;

        Log::info('[SearchHandler] handleSearchTag completed', [
            'session_id'       => $sessionId,
            'target'           => $target,
            'conditions_count' => count($conditions),
        ]);
    }

    /**
     * 検索結果が戻ってきた時の復元処理を行う。
     * ChatController::continue() から呼ばれる。
     *
     * 処理の流れ:
     *   1. キャッシュから会話状態を復元(なければ SessionNotFoundException)
     *   2. assistant の前回応答を messages に追加
     *   3. user としての検索結果を messages に追加
     *      整形仕様(機能2 Step 5、判断Fの実装):
     *        - 0件の場合: 「検索結果：0件\n該当する項目が見つかりませんでした。」
     *        - 1件以上: 「検索結果：N件\n[JSON]」
     *      target 非依存。要請⑤(PromptBuilder のスキーマ駆動化)と同じ哲学で
     *      target 別の分岐を一切入れない。
     *   4. 復元された messages を返す(Claudeへの2回目送信用)
     *
     * @param string $sessionId     キャッシュキー(WordPress側から渡される)
     * @param array  $searchResults WordPress側から返された検索結果
     * @return array Claudeへの2回目送信用に組み立てられた messages
     * @throws SessionNotFoundException キャッシュにセッションがなかった場合
     */
    public function handleSearchResults(
        string $sessionId,
        array $searchResults,
    ): array
    {
        // 1. キャッシュから会話状態を復元
        $cached = cache()->get($sessionId);

        if ($cached === null) {
            Log::warning('[SearchHandler] handleSearchResults: session not found in cache', [
                'session_id' => $sessionId,
            ]);
            throw new SessionNotFoundException(
                "セッションが見つかりません: {$sessionId}"
            );
        }

        // 2. assistant の前回応答を messages に追加
        $messages = $cached['messages'];
        $messages[] = [
            'role'    => 'assistant',
            'content' => $cached['firstResponse'],
        ];

        // 3. user としての検索結果を messages に追加(target 非依存の整形、判断Fの実装)
        $resultCount = count($searchResults);

        if ($resultCount === 0) {
            $resultsContent = "検索結果：0件\n該当する項目が見つかりませんでした。";
        } else {
            $resultsContent = sprintf(
                "検索結果：%d件\n%s",
                $resultCount,
                json_encode($searchResults, JSON_UNESCAPED_UNICODE)
            );
        }

        $messages[] = [
            'role'    => 'user',
            'content' => $resultsContent,
        ];

        Log::info('[SearchHandler] handleSearchResults completed', [
            'session_id'              => $sessionId,
            'restored_messages_count' => count($messages),
            'search_results_count'    => $resultCount,
        ]);

        // 4. 復元された messages を返す
        return $messages;
    }

    /**
     * 検索リクエストとして処理したかどうかを返す。
     * ChatControllerが done を出すか判断するために使う。
     */
    public function wasProcessed(): bool
    {
        return $this->processed;
    }
}
