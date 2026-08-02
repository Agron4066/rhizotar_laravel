<?php

namespace Tests\Unit;

use App\Services\PromptStructure;
use PHPUnit\Framework\TestCase;

/**
 * PromptStructure クラスの単体テスト
 *
 * 主に検証する責務：
 *   1. 組み立て順保証（static が先・dynamic が後の層間順序、層内では投入順を維持）
 *   2. build() の戻り値構造（['system' => string, 'messages' => array]）
 *   3. 便利投入メソッド（addStaticMessages / addDynamicMessages）の挙動
 */
class PromptStructureTest extends TestCase
{
    public function test_組み立て順_systemは静的が先動的が後に並ぶ(): void
    {
        $ps = new PromptStructure();

        // わざと「動的を先に、静的を後に」投入する
        $ps->addDynamicSystem('動的1');
        $ps->addDynamicSystem('動的2');
        $ps->addStaticSystem('静的1');
        $ps->addStaticSystem('静的2');

        $result = $ps->build();

        // 投入順と関係なく、静的が先・動的が後で並ぶ
        // 区切りは "\n\n"（空行）
        $expected = "静的1\n\n静的2\n\n動的1\n\n動的2";
        $this->assertSame($expected, $result['system']);
    }

    public function test_組み立て順_messagesは静的が先動的が後に並ぶ(): void
    {
        $ps = new PromptStructure();

        // わざと「動的を先に、静的を後に」投入する
        $ps->addDynamicMessage(['role' => 'user', 'content' => '動的1']);
        $ps->addDynamicMessage(['role' => 'assistant', 'content' => '動的2']);
        $ps->addStaticMessage(['role' => 'user', 'content' => '静的1']);
        $ps->addStaticMessage(['role' => 'assistant', 'content' => '静的2']);

        $result = $ps->build();

        // 投入順と関係なく、静的が先・動的が後で並ぶ
        $expected = [
            ['role' => 'user',      'content' => '静的1'],
            ['role' => 'assistant', 'content' => '静的2'],
            ['role' => 'user',      'content' => '動的1'],
            ['role' => 'assistant', 'content' => '動的2'],
        ];
        $this->assertSame($expected, $result['messages']);
    }

    public function test_組み立て順_投入順がadd交互でも層間順序は保たれる(): void
    {
        $ps = new PromptStructure();

        // static と dynamic を交互に投入する(極端なケース)
        $ps->addDynamicSystem('動的A');
        $ps->addStaticSystem('静的A');
        $ps->addDynamicSystem('動的B');
        $ps->addStaticSystem('静的B');
        $ps->addDynamicSystem('動的C');
        $ps->addStaticSystem('静的C');

        $result = $ps->build();

        // 投入が交互であっても、層間順序(static 先・dynamic 後)は保たれ、
        // 層内順序(投入順)も保たれる
        $expected = "静的A\n\n静的B\n\n静的C\n\n動的A\n\n動的B\n\n動的C";
        $this->assertSame($expected, $result['system']);
    }

    public function test_buildの戻り値はsystemとmessagesの2キーを持つ連想配列(): void
    {
        $ps = new PromptStructure();
        $ps->addStaticSystem('テスト');
        $ps->addStaticMessage(['role' => 'user', 'content' => 'こんにちは']);

        $result = $ps->build();

        // 戻り値は array 型
        $this->assertIsArray($result);

        // 'system' と 'messages' の2キーを持つ
        $this->assertArrayHasKey('system', $result);
        $this->assertArrayHasKey('messages', $result);

        // 余計なキーは含まない
        $this->assertCount(2, $result);
    }

    public function test_buildのsystem部分は文字列型でnn区切りで連結される(): void
    {
        $ps = new PromptStructure();
        $ps->addStaticSystem('セクション1');
        $ps->addStaticSystem('セクション2');
        $ps->addStaticSystem('セクション3');

        $result = $ps->build();

        // system は文字列型
        $this->assertIsString($result['system']);

        // "\n\n"(改行2つ)で連結されている
        $this->assertSame("セクション1\n\nセクション2\n\nセクション3", $result['system']);
    }

    public function test_buildのmessages部分は配列型で連想配列の並びを保つ(): void
    {
        $ps = new PromptStructure();
        $ps->addStaticMessage(['role' => 'user', 'content' => '質問1']);
        $ps->addStaticMessage(['role' => 'assistant', 'content' => '回答1']);
        $ps->addStaticMessage(['role' => 'user', 'content' => '質問2']);

        $result = $ps->build();

        // messages は配列型
        $this->assertIsArray($result['messages']);

        // 投入順を保った数値添字配列で返る
        $expected = [
            ['role' => 'user',      'content' => '質問1'],
            ['role' => 'assistant', 'content' => '回答1'],
            ['role' => 'user',      'content' => '質問2'],
        ];
        $this->assertSame($expected, $result['messages']);
    }

    public function test_空のPromptStructureをbuildしても例外を投げない(): void
    {
        $ps = new PromptStructure();

        // 何も投入せずにいきなり build() を呼ぶ
        $result = $ps->build();

        // 例外は投げず、空文字列と空配列を返す
        $this->assertSame('', $result['system']);
        $this->assertSame([], $result['messages']);
    }

    public function test_addStaticMessagesは複数要素を順序通り投入する(): void
    {
        $ps = new PromptStructure();

        // 配列を丸ごと投入
        $messagesToAdd = [
            ['role' => 'user',      'content' => '一括1'],
            ['role' => 'assistant', 'content' => '一括2'],
            ['role' => 'user',      'content' => '一括3'],
        ];
        $ps->addStaticMessages($messagesToAdd);

        $result = $ps->build();

        // 投入順がそのまま保たれて、static 領域に積まれている
        $this->assertSame($messagesToAdd, $result['messages']);
    }

    public function test_addDynamicMessagesは複数要素を順序通り投入する(): void
    {
        $ps = new PromptStructure();

        // 静的を1件だけ投入してから、動的を一括投入する
        $ps->addStaticMessage(['role' => 'user', 'content' => '先行静的']);

        $dynamicToAdd = [
            ['role' => 'assistant', 'content' => '一括動的1'],
            ['role' => 'user',      'content' => '一括動的2'],
        ];
        $ps->addDynamicMessages($dynamicToAdd);

        $result = $ps->build();

        // static の後に dynamic が、それぞれ順序通りに並ぶ
        $expected = [
            ['role' => 'user',      'content' => '先行静的'],
            ['role' => 'assistant', 'content' => '一括動的1'],
            ['role' => 'user',      'content' => '一括動的2'],
        ];
        $this->assertSame($expected, $result['messages']);
    }
}
