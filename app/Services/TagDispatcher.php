<?php

namespace App\Services;

use App\Services\Handlers\TagHandlerInterface;
use Illuminate\Support\Facades\Log;

/**
 * TagDispatcher
 *
 * Claudeの応答ストリームから検出されたタグを、
 * 対応するHandlerに仕分けるディスパッチャ。
 *
 * 役割は2つ:
 *   1. ストリーミング中のテキスト断片(チャンク)を受け取って蓄積し、
 *      タグの完成を判定する
 *   2. 完成したタグの種類に応じて、対応するHandlerを呼び出す
 *
 * 重要: このクラス自身はタグの意味を持たない。
 *   「<SEARCH>が来たらSearchHandlerに渡す」というマッピング情報
 *   を持つだけで、検索とは何かを知らない。
 *   タグが増えてもこのクラスは一切変更されない。
 *
 * 状態管理について:
 *   このクラスは内部バッファという状態を持つ。
 *   そのため Singleton ではなく bind() でリクエストごとに
 *   新しいインスタンスが生成される必要がある(サブステップ2-7で設定)。
 *
 * Phase 13 (最小版): 骨格のみ実装。
 *   - 検出ロジックは後続サブステップで実装
 *   - ディスパッチ機構は後続サブステップで実装
 */
class TagDispatcher
{
    /**
     * チャンクを蓄積する内部バッファ。
     * Claudeからの応答が細切れで届くため、ここに溜めて
     * タグの完成を判定する。
     */
    private string $buffer = '';

    /**
     * @param array<string, TagHandlerInterface> $handlers
     *   タグ名をキー、対応するHandlerを値とする連想配列。
     *   例: ['SEARCH' => SearchHandler, 'APPOINTMENT' => AppointmentHandler]
     *   ServiceProvider側で組み立てて渡される。
     *   骨格段階では空配列がデフォルト。
     */
    public function __construct(
        private array $handlers = []
    ) {
    }

    /**
     * Claudeからの応答チャンクを受け取る。
     *
     * 実装予定の流れ:
     *   1. チャンクを内部バッファに追加
     *   2. バッファ内にタグが完成していれば検出
     *   3. 完成したタグの種類に応じてHandlerを呼び出す
     *
     * @param string $chunk Claudeからの応答テキストの断片
     */
    public function feed(string $chunk): void
    {
        // 1. チャンクをバッファに追加
        $this->buffer .= $chunk;

        // 2. バッファから完成したタグを抽出(抽出したタグはバッファから除去される)
        $completedTags = $this->extractCompletedTags();

        // 3. TODO: 抽出されたタグを対応するHandlerに渡す
        foreach ($completedTags as $tag) {
            $this->dispatch($tag['name'], $tag['content']);
        }

        Log::info('[TagDispatcher] feed processed', [
            'chunk_length' => strlen($chunk),
            'buffer_length_after' => strlen($this->buffer),
            'detected_tags' => array_map(fn($t) => $t['name'], $completedTags),
        ]);
    }

    /**
     * 検出されたタグを、対応するHandlerに渡す。
     *
     * 該当するHandlerが登録されていない場合は警告ログを出して何もしない。
     * 例外を投げないのは、骨格段階ではHandlerが未登録なのが常態であり、
     * TagDispatcher単体の動作確認をHandler有無と独立させるため。
     *
     * Handlerが例外を投げた場合は、握り潰さず呼び出し元に伝播させる。
     * TagHandlerInterfaceの契約として「失敗時は例外を投げて呼び出し元に
     * 伝える」と定めているため、ここで捕捉してはならない。
     *
     * @param string $tagName    タグ名 (例: "SEARCH")
     * @param string $tagContent タグの中身 (例: "条件A")
     */
    private function dispatch(string $tagName, string $tagContent): void
    {
        if (!isset($this->handlers[$tagName])) {
            Log::warning('[TagDispatcher] no handler registered for tag', [
                'tag_name' => $tagName,
                'registered_tags' => array_keys($this->handlers),
            ]);
            return;
        }

        Log::info('[TagDispatcher] dispatching to handler', [
            'tag_name' => $tagName,
            'content_length' => strlen($tagContent),
        ]);

        $this->handlers[$tagName]->handle($tagContent);
    }

    /**
     * バッファから完成したタグを抽出して取り出す。
     * 抽出したタグはバッファから除去される。
     *
     * 検出パターン: <英大文字またはアンダースコア>...</同じタグ名>
     * 例: <SEARCH>条件A</SEARCH>, <APPOINTMENT>...</APPOINTMENT>
     *
     * バックリファレンス \1 により、開始タグと終了タグが
     * 同じ名前であることを保証している。
     * sフラグにより、タグ内に改行が含まれていてもマッチする。
     *
     * @return array<int, array{name: string, content: string}>
     */
    private function extractCompletedTags(): array
    {
        $extracted = [];

        $this->buffer = preg_replace_callback(
            '/<([A-Z_]+)>(.*?)<\/\1>/s',
            function (array $matches) use (&$extracted): string {
                $extracted[] = [
                    'name' => $matches[1],
                    'content' => $matches[2],
                ];
                return ''; // マッチ部分をバッファから除去
            },
            $this->buffer
        );

        return $extracted;
    }
    // ←← 追加ここまで ←←
}
