<?php

namespace Tests\Unit\Handlers;

use App\Services\Handlers\Exceptions\SessionNotFoundException;
use App\Services\Handlers\SearchHandler;
use Tests\TestCase;

/**
 * SearchHandler クラスの単体テスト
 *
 * 主に検証する責務：
 *   1. handleSearchTag の正常系（target+conditions のキャッシュ保存、SSE 平坦化ペイロード、processed フラグ）
 *   2. handleSearchTag の null 返却時の挙動（target属性なしの SEARCH では検索が走らない）
 *   3. handleSearchResults の整形仕様（判断F の実装：0件特別扱い・件数明示・日本語文字化けなし）
 *   4. wasProcessed の挙動（初期 false、成功で true、early return で false）
 *   5. target 非依存性（異なる target でも整形結果が変わらない）
 *
 * Laravel TestCase（Tests\TestCase）を継承する理由：
 *   SearchHandler は Cache ファサード（cache()->put・cache()->get）に依存している。
 *   Laravel TestCase ではテスト環境で array driver の Cache が使われ、各テストごとに
 *   自動でクリアされる。Mock を使うより、実 Cache で「キャッシュデータの構造が
 *   正しいこと」を直接検証できるため、こちらを採用する。
 */
class SearchHandlerTest extends TestCase // ←← 機能2 Step 5 で新設 ←←
{
    private SearchHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new SearchHandler();
    }

    // =======================================================================
    // 第1グループ: handleSearchTag の正常系
    // =======================================================================

    public function test_handleSearchTag_targetとconditionsをキャッシュに保存する(): void
    {
        $sessionId = 'test-session-001';
        $messages = [['role' => 'user', 'content' => '3LDKの物件を探して']];
        $fullResponse = 'かしこまりました。<SEARCH target="property">{"madori":"3LDK"}</SEARCH>';

        $this->handler->handleSearchTag(
            $fullResponse,
            $sessionId,
            $messages,
            function ($payload) {} // 検証しない空コールバック
        );

        $cached = cache()->get($sessionId);
        $this->assertNotNull($cached);
        $this->assertSame('property', $cached['target']);
        $this->assertSame(['madori' => '3LDK'], $cached['conditions']);
        $this->assertSame($messages, $cached['messages']);
        $this->assertSame($fullResponse, $cached['firstResponse']);
    }

    public function test_handleSearchTag_SSEコールバックが平坦化されたペイロードで呼ばれる(): void
    {
        $sessionId = 'test-session-002';
        $fullResponse = '<SEARCH target="property">{"madori":"3LDK","price_max":50000000}</SEARCH>';

        $captured = null;
        $this->handler->handleSearchTag(
            $fullResponse,
            $sessionId,
            [],
            function ($payload) use (&$captured) {
                $captured = $payload;
            }
        );

        $this->assertNotNull($captured);
        $this->assertTrue($captured['search_request']);
        $this->assertSame('property', $captured['target']);
        $this->assertSame(['madori' => '3LDK', 'price_max' => 50000000], $captured['conditions']);
        $this->assertSame($sessionId, $captured['session_id']);
        // target と conditions が並列のキーで配置されている（conditions の中に target が紛れ込まない）
        $this->assertArrayNotHasKey('target', $captured['conditions']);
    }

    public function test_handleSearchTag_キャッシュデータは4キーを持つ(): void
    {
        $sessionId = 'test-session-003';
        $this->handler->handleSearchTag(
            '<SEARCH target="property">{"madori":"3LDK"}</SEARCH>',
            $sessionId,
            [],
            function ($payload) {}
        );

        $cached = cache()->get($sessionId);
        $this->assertCount(4, $cached);
        $this->assertArrayHasKey('messages', $cached);
        $this->assertArrayHasKey('firstResponse', $cached);
        $this->assertArrayHasKey('target', $cached);
        $this->assertArrayHasKey('conditions', $cached);
        // 判断E の Notion 記述で予防的に書いた results キーは含めない
        $this->assertArrayNotHasKey('results', $cached);
    }

    public function test_handleSearchTag_成功時にprocessedフラグがtrueになる(): void
    {
        $this->assertFalse($this->handler->wasProcessed()); // 初期状態

        $this->handler->handleSearchTag(
            '<SEARCH target="property">{"madori":"3LDK"}</SEARCH>',
            'session-id',
            [],
            function ($payload) {}
        );

        $this->assertTrue($this->handler->wasProcessed());
    }

    // =======================================================================
    // 第2グループ: handleSearchTag の null 返却時の挙動（判断Dの遵守）
    // =======================================================================

    public function test_handleSearchTag_target属性なしのSEARCHではSSEコールバックが呼ばれない(): void
    {
        // 判断Dの遵守：target属性なしの旧形式SEARCHでは検索を起動しない
        $callbackCalled = false;
        $this->handler->handleSearchTag(
            'かしこまりました。<SEARCH>{"madori":"3LDK"}</SEARCH>', // target属性なし
            'session-id',
            [],
            function ($payload) use (&$callbackCalled) {
                $callbackCalled = true;
            }
        );

        $this->assertFalse($callbackCalled);
    }

    public function test_handleSearchTag_target属性なしのSEARCHではキャッシュに保存されない(): void
    {
        $sessionId = 'test-session-no-target';
        $this->handler->handleSearchTag(
            '<SEARCH>{"madori":"3LDK"}</SEARCH>',
            $sessionId,
            [],
            function ($payload) {}
        );

        $this->assertNull(cache()->get($sessionId));
    }

    public function test_handleSearchTag_target属性なしのSEARCHではprocessedフラグがfalseのまま(): void
    {
        $this->handler->handleSearchTag(
            '<SEARCH>{"madori":"3LDK"}</SEARCH>',
            'session-id',
            [],
            function ($payload) {}
        );

        $this->assertFalse($this->handler->wasProcessed());
    }

    // =======================================================================
    // 第3グループ: handleSearchResults の整形仕様（判断Fの実装）
    // =======================================================================

    public function test_handleSearchResults_セッションがなければSessionNotFoundExceptionを投げる(): void
    {
        $this->expectException(SessionNotFoundException::class);

        $this->handler->handleSearchResults('non-existent-session', []);
    }

    public function test_handleSearchResults_messagesにassistant応答とuser検索結果が順に追加される(): void
    {
        $sessionId = 'test-session-restore';
        $this->putSampleSessionToCache($sessionId);

        $searchResults = [['title' => '物件A', 'price' => 30000000]];
        $messages = $this->handler->handleSearchResults($sessionId, $searchResults);

        // 末尾2要素が assistant → user の順に追加されている
        $count = count($messages);
        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertSame('assistant', $messages[$count - 2]['role']);
        $this->assertSame('user', $messages[$count - 1]['role']);
    }

    public function test_handleSearchResults_検索結果が0件の場合は特別な文言が含まれる(): void
    {
        $sessionId = 'test-session-empty';
        $this->putSampleSessionToCache($sessionId);

        $messages = $this->handler->handleSearchResults($sessionId, []);

        $userContent = end($messages)['content'];
        $this->assertStringContainsString('検索結果：0件', $userContent);
        $this->assertStringContainsString('該当する項目が見つかりませんでした', $userContent);
    }

    public function test_handleSearchResults_検索結果が1件以上の場合は冒頭に件数が明示される(): void
    {
        $sessionId = 'test-session-results';
        $this->putSampleSessionToCache($sessionId);

        $searchResults = [
            ['title' => '物件A'],
            ['title' => '物件B'],
            ['title' => '物件C'],
        ];
        $messages = $this->handler->handleSearchResults($sessionId, $searchResults);

        $userContent = end($messages)['content'];
        // 件数が明示されている
        $this->assertStringContainsString('3件', $userContent);
        // 件数が冒頭に来ている
        $this->assertSame(0, strpos($userContent, '検索結果'));
    }

    public function test_handleSearchResults_日本語が文字化けせずに整形される(): void
    {
        // JSON_UNESCAPED_UNICODE フラグの動作を担保する
        $sessionId = 'test-session-japanese';
        $this->putSampleSessionToCache($sessionId);

        $searchResults = [['title' => '東京の物件', 'area' => '新宿区']];
        $messages = $this->handler->handleSearchResults($sessionId, $searchResults);

        $userContent = end($messages)['content'];
        // 日本語が直接含まれる
        $this->assertStringContainsString('東京の物件', $userContent);
        $this->assertStringContainsString('新宿区', $userContent);
        // \u エスケープされていない
        $this->assertStringNotContainsString('\\u', $userContent);
    }

    // =======================================================================
    // 第4グループ: wasProcessed の挙動
    // =======================================================================

    public function test_wasProcessed_初期状態でfalseを返す(): void
    {
        $this->assertFalse($this->handler->wasProcessed());
    }

    public function test_wasProcessed_handleSearchTag成功後にtrueを返す(): void
    {
        $this->handler->handleSearchTag(
            '<SEARCH target="property">{"madori":"3LDK"}</SEARCH>',
            'session-id',
            [],
            function ($payload) {}
        );

        $this->assertTrue($this->handler->wasProcessed());
    }

    public function test_wasProcessed_handleSearchTagがearly_returnした場合はfalseのまま(): void
    {
        $this->handler->handleSearchTag(
            'タグなしのレスポンス', // SEARCHタグそのものがない
            'session-id',
            [],
            function ($payload) {}
        );

        $this->assertFalse($this->handler->wasProcessed());
    }

// =======================================================================
// 第5グループ: target 非依存性の確認
// =======================================================================

public function test_target非依存_異なるtargetでも1件以上の整形結果は同じ(): void
{
    // 整形処理に target に依存した分岐がないことを構造的に担保する
    $searchResults = [['name' => 'item1'], ['name' => 'item2']];

    $this->putSampleSessionToCache('session-property', 'property');
    $messagesProperty = $this->handler->handleSearchResults('session-property', $searchResults);
    $contentProperty = end($messagesProperty)['content'];

    $this->putSampleSessionToCache('session-book', 'book');
    $messagesBook = $this->handler->handleSearchResults('session-book', $searchResults);
    $contentBook = end($messagesBook)['content'];

    // 整形結果（user メッセージのcontent）が target に依存しない
    $this->assertSame($contentProperty, $contentBook);
}

public function test_target非依存_異なるtargetでも0件の整形結果は同じ(): void
{
    $this->putSampleSessionToCache('session-property-empty', 'property');
    $messagesProperty = $this->handler->handleSearchResults('session-property-empty', []);
    $contentProperty = end($messagesProperty)['content'];

    $this->putSampleSessionToCache('session-analytics-empty', 'analytics');
    $messagesAnalytics = $this->handler->handleSearchResults('session-analytics-empty', []);
    $contentAnalytics = end($messagesAnalytics)['content'];

    // 0件の整形も target に依存しない
    $this->assertSame($contentProperty, $contentAnalytics);
}

// =======================================================================
// テストデータ生成ヘルパー
// =======================================================================

/**
 * 標準的なサンプルセッションをキャッシュに保存する。
 * handleSearchResults 系テストで「キャッシュに保存された状態」を再現する。
 *
 * @param string $sessionId キャッシュキー
 * @param string $target 保存する target_id（デフォルトは property）
 */
private function putSampleSessionToCache(string $sessionId, string $target = 'property'): void
{
    cache()->put($sessionId, [
        'messages' => [
            ['role' => 'user', 'content' => 'サンプル発言'],
        ],
        'firstResponse' => 'かしこまりました。検索しますね。',
        'target' => $target,
        'conditions' => ['sample' => 'value'],
    ], now()->addMinutes(10));
}
}
