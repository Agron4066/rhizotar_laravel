<?php

namespace App\Orchestrators;

use App\Services\TagDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * SystemOrchestrator
 *
 * 全サービスクラスの実行順序を制御する指揮者。
 * 各サービスは互いを直接呼び出さず、すべてここを経由する。
 *
 * Phase 13(最小版): 骨格のみ実装。
 *   - ステップ2でTagDispatcherが追加される
 *   - ステップ3でSearchHandlerが追加される(TagDispatcher経由で利用)
 *   - ステップ5で各メソッドの中身を実装し、必要なサービスを依存注入で追加する
 */
class SystemOrchestrator
{
    public function __construct(
        private TagDispatcher $tagDispatcher,
    ) {
        // 必要なサービスクラスを依存注入で受け取る。
        // 今後、ChatService、ClaudeClient等の依存が追加される予定。
    }

    /**
     * 通常のチャットリクエストを処理するメインフロー。
     * /api/chat エンドポイント(ChatController)から呼ばれる。
     *
     * 実装予定の流れ(ステップ5):
     *   1. ClaudeClientでストリーミング開始
     *   2. ストリーム断片をTagDispatcherに渡す
     */
    public function handleChatRequest(): void
    {
        Log::info('[SystemOrchestrator] handleChatRequest called (skeleton)');
        // TODO: ステップ5で実装
    }

    /**
     * WordPressから検索結果が戻ってきた時の再開処理。
     * /api/chat/continue エンドポイント(ChatController)から呼ばれる。
     *
     * 実装予定の流れ(ステップ5):
     *   1. SearchHandlerに復元・検索実行を委譲
     *   2. 検索結果をもとに2回目のClaude呼び出し
     */
    public function handleSearchResults(): void
    {
        Log::info('[SystemOrchestrator] handleSearchResults called (skeleton)');
        // TODO: ステップ5で実装
    }
}
