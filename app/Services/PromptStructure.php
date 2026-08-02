<?php

namespace App\Services;

/**
 * PromptStructure：LLM への送信内容を2層構造で保持する共通データ構造クラス
 *
 * 第2世代システムの全機能横断で使用される共通基盤。
 * システムプロンプト本文（system）と messages 配列の2系統を、
 * それぞれ静的部分（static）と動的部分（dynamic）の2層に分けて保持する。
 *
 * 静的部分:リクエストをまたいで変わらない領域。LLMキャッシュの対象。
 * 動的部分：リクエストごとに変わる領域。最新のユーザー発言などが入る。
 *
 * build() で取り出す際、内部で「静的部分が先、動的部分が後」の組み立て順を保証する。
 */
class PromptStructure
{
    /**
     * システムプロンプト本文の静的部分を蓄積する配列。各要素は文字列。
     *
     * @var string[]
     */
    private array $staticSystem = [];

    /**
     * システムプロンプト本文の動的部分を蓄積する配列。各要素は文字列。
     *
     * @var string[]
     */
    private array $dynamicSystem = [];

    /**
     * messages 配列の静的要素を蓄積する配列。
     * 各要素は ['role' => ..., 'content' => ...] の連想配列。
     *
     * @var array<int, array{role: string, content: string}>
     */
    private array $staticMessages = [];

    /**
     * messages 配列の動的要素を蓄積する配列。
     * 各要素は ['role' => ..., 'content' => ...] の連想配列。
     *
     * @var array<int, array{role: string, content: string}>
     */
    private array $dynamicMessages = [];

    /**
     * システムプロンプト本文の静的部分に文字列を追記する。
     *
     * 投入順がそのまま組み立て順になる（先に投入したものが先に並ぶ）。
     * build() の戻り値の system 文字列内では、ここで投入された全文字列が
     * dynamicSystem よりも先に並ぶ。
     */
    public function addStaticSystem(string $content): void
    {
        $this->staticSystem[] = $content;
    }

    /**
     * システムプロンプト本文の動的部分に文字列を追記する。
     *
     * 投入順がそのまま組み立て順になる（先に投入したものが先に並ぶ）。
     * build() の戻り値の system 文字列内では、ここで投入された全文字列は
     * staticSystem の後に並ぶ。
     */
    public function addDynamicSystem(string $content): void
    {
        $this->dynamicSystem[] = $content;
    }

    /**
     * messages 配列の静的要素を追記する。
     *
     * 引数の連想配列は ['role' => 'user'|'assistant', 'content' => '...'] の形を想定。
     * 投入順がそのまま組み立て順になる（先に投入したものが先に並ぶ）。
     * build() の戻り値の messages 配列では、ここで投入された全要素が
     * dynamicMessages よりも先に並ぶ。
     *
     * @param array{role: string, content: string} $message
     */
    public function addStaticMessage(array $message): void
    {
        $this->staticMessages[] = $message;
    }

    /**
     * messages 配列の動的要素を追記する。
     *
     * 引数の連想配列は ['role' => 'user'|'assistant', 'content' => '...'] の形を想定。
     * 投入順がそのまま組み立て順になる（先に投入したものが先に並ぶ）。
     * build() の戻り値の messages 配列では、ここで投入された全要素は
     * staticMessages の後に並ぶ。
     *
     * @param array{role: string, content: string} $message
     */
    public function addDynamicMessage(array $message): void
    {
        $this->dynamicMessages[] = $message;
    }

    /**
     * messages 配列の静的要素を複数まとめて追記する。
     *
     * 引数の各要素は ['role' => ..., 'content' => ...] の連想配列。
     * 内部で addStaticMessage() を順次呼び出すため、引数配列の順序が
     * そのまま組み立て順として保たれる。
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function addStaticMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->addStaticMessage($message);
        }
    }

    /**
     * messages 配列の動的要素を複数まとめて追記する。
     *
     * 引数の各要素は ['role' => ..., 'content' => ...] の連想配列。
     * 内部で addDynamicMessage() を順次呼び出すため、引数配列の順序が
     * そのまま組み立て順として保たれる。
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function addDynamicMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->addDynamicMessage($message);
        }
    }

    /**
     * 蓄積された内容を、LLM 送信用の構造に組み立てて返す。
     *
     * 組み立て順の保証：
     *   - system 文字列内では、staticSystem の全要素が dynamicSystem の全要素よりも先に並ぶ。
     *   - messages 配列内では、staticMessages の全要素が dynamicMessages の全要素よりも先に並ぶ。
     *   - 各層の内部では、addXxx() メソッドへの投入順がそのまま保たれる。
     *
     * 投入側のメソッド呼び出し順序（addStatic と addDynamic を交互に呼ぶ等）に
     * 関わらず、必ず「静的が先・動的が後」の順序で並ぶ。これが本クラスの中核責務。
     *
     * 戻り値の構造：
     *   [
     *       'system'   => string,  // システムプロンプト本文（連結された1本の文字列）
     *       'messages' => array,   // messages 配列（連結された連想配列のリスト）
     *   ]
     *
     * @return array{system: string, messages: array<int, array{role: string, content: string}>}
     */
    public function build(): array
    {
        $systemParts = array_merge($this->staticSystem, $this->dynamicSystem);
        $messages    = array_merge($this->staticMessages, $this->dynamicMessages);

        return [
            'system'   => implode("\n\n", $systemParts),
            'messages' => $messages,
        ];
    }
}
