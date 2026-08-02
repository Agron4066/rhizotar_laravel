<?php

namespace Tests\Feature\Http\Controllers;

use App\Services\ClaudeClient;
use App\Services\PromptBuilder;
use App\Services\PromptStructure;
use Tests\TestCase;

/**
 * ChatController の Feature テスト
 *
 * 主に検証する責務：
 *   1. stream() のバリデーション（必須パラメータ欠如で 422）
 *   2. stream() の正常系（200、Content-Type、PromptBuilder への引数渡し）
 *   3. continue() のバリデーション（必須パラメータ欠如で 422）
 *   4. continue() の正常系と例外（セッションなしで 404、正常で 200）
 *
 * ClaudeClient は外部 API を叩くため Mock 化する。
 * PromptBuilder は原則として実インスタンスを使うが、
 * 引数渡しの検証が必要なテストでのみ Mock 化する。
 */
class ChatControllerTest extends TestCase  // ←← 機能2 Step 6 で新設 ←←
{
    // =======================================================================
    // 第1グループ: stream() のバリデーション
    // =======================================================================

    public function test_stream_messageが欠けたら422(): void
    {
        $response = $this->postJson('/api/chat', [
            'session_id'               => 'test-session',
            'available_search_targets' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['message']);
    }

    public function test_stream_session_idが欠けたら422(): void
    {
        $response = $this->postJson('/api/chat', [
            'message'                  => 'テスト発言',
            'available_search_targets' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['session_id']);
    }

    public function test_stream_available_search_targetsが欠けたら422(): void
    {
        $response = $this->postJson('/api/chat', [
            'message'    => 'テスト発言',
            'session_id' => 'test-session',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['available_search_targets']);
    }

    // =======================================================================
    // 第2グループ: stream() の正常系
    // =======================================================================

    public function test_stream_正常リクエストで200が返る(): void
    {
        $this->mockClaudeClient();

        $response = $this->postJson('/api/chat', $this->validStreamPayload());

        $response->assertStatus(200);
    }

    public function test_stream_ContentTypeがtext_event_streamである(): void
    {
        $this->mockClaudeClient();

        $response = $this->postJson('/api/chat', $this->validStreamPayload());

        $this->assertStringContainsString(
            'text/event-stream',
            $response->headers->get('Content-Type')
        );
    }

    public function test_stream_buildFirstStagePromptにavailable_search_targetsが渡される(): void
    {
        $this->mockClaudeClient();

        $expectedTargets = [
            [
                'target_id' => 'property',
                'label'     => '物件',
                'post_type' => 'property',
                'fields'    => [],
            ],
        ];

        // PromptBuilder を Mock 化して引数を検証
        $mockBuilder = $this->mock(PromptBuilder::class);
        $mockBuilder->shouldReceive('buildFirstStagePrompt')
            ->once()
            ->with($expectedTargets)
            ->andReturn(new PromptStructure());

        $response = $this->postJson('/api/chat', [
            'message'                  => 'テスト発言',
            'session_id'               => 'test-session',
            'messages'                 => [],
            'available_search_targets' => $expectedTargets,
        ]);

        // ストリーミングのコールバックを実行して Mock の検証をトリガー
        $this->executeStreamCallback($response);
    }

    // =======================================================================
    // 第3グループ: continue() のバリデーション
    // =======================================================================

    public function test_continue_session_idが欠けたら422(): void
    {
        $response = $this->postJson('/api/chat/continue', [
            'search_results' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['session_id']);
    }

    public function test_continue_search_resultsが欠けたら422(): void
    {
        $response = $this->postJson('/api/chat/continue', [
            'session_id' => 'test-session',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['search_results']);
    }

    // =======================================================================
    // 第4グループ: continue() の正常系と例外
    // =======================================================================

    public function test_continue_セッションがキャッシュにない場合404が返る(): void
    {
        $response = $this->postJson('/api/chat/continue', [
            'session_id'     => 'non-existent-session',
            'search_results' => [],
        ]);

        $response->assertStatus(404);
    }

    public function test_continue_正常リクエストで200が返る(): void
    {
        $this->mockClaudeClient();

        // キャッシュにセッションデータを入れる（SearchHandler が参照する4キー構造）
        cache()->put('test-session-continue', [
            'messages'      => [['role' => 'user', 'content' => 'テスト']],
            'firstResponse' => 'かしこまりました。',
            'target'        => 'property',
            'conditions'    => ['madori' => '3LDK'],
        ], now()->addMinutes(10));

        $response = $this->postJson('/api/chat/continue', [
            'session_id'     => 'test-session-continue',
            'search_results' => [['title' => '物件A']],
        ]);

        $response->assertStatus(200);
    }

    // =======================================================================
    // ヘルパーメソッド
    // =======================================================================

    /**
     * ClaudeClient を Mock 化する。
     * streamMessage() が呼ばれても何もしない形にする。
     */
    private function mockClaudeClient(): void
    {
        $this->mock(ClaudeClient::class, function ($mock) {
            $mock->shouldReceive('streamMessage')->andReturnNull();
        });
    }

    /**
     * stream() の正常リクエスト用ペイロードを返す。
     */
    private function validStreamPayload(): array
    {
        return [
            'message'                  => 'テスト発言',
            'session_id'               => 'test-session',
            'messages'                 => [],
            'available_search_targets' => [],
        ];
    }

    /**
     * StreamedResponse のコールバックを実行する。
     *
     * Laravel のテストフレームワークでは postJson() が返す TestResponse は
     * StreamedResponse をラップしているが、コールバックはデフォルトでは実行されない。
     * Mock の shouldReceive 検証をトリガーするには、コールバックを明示的に実行する必要がある。
     * ob_start/ob_end_clean で echo 出力を抑制する。
     */
    private function executeStreamCallback($response): void
    {
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();
    }
}
