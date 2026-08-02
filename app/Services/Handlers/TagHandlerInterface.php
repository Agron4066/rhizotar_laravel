<?php

namespace App\Services\Handlers;

/**
 * TagHandlerInterface
 *
 * Claudeの応答ストリームから検出されたタグを処理するHandlerが
 * 実装すべき共通契約。
 *
 * このインターフェースの存在意義:
 *   TagDispatcherは具体的なHandlerクラス(SearchHandler等)を知らず、
 *   「このインターフェースを実装した何か」とだけ会話する。
 *   これによりタグの種類が増えても、TagDispatcher本体には
 *   一切手を入れずに新しいHandlerクラスを追加するだけで済む。
 *
 * 実装クラス:
 *   - SearchHandler (Phase 13 ステップ3で実装)
 *   - 将来: AppointmentHandler, DocumentHandler 等
 */
interface TagHandlerInterface
{
    /**
     * 検出されたタグの中身を処理する。
     *
     * 例: <SEARCH>条件A</SEARCH> が検出された場合、
     *     $tagContent には "条件A" が渡される。
     *
     * 戻り値はvoid。Handlerは副作用(SSEイベント発火、
     * キャッシュ書き込み等)として動作する。
     * 失敗時は例外を投げて呼び出し元に伝える。
     *
     * @param string $tagContent タグの中身(タグ名や囲み記号は含まない)
     */
    public function handle(string $tagContent): void;
}
