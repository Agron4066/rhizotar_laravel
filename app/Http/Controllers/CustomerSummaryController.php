<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\ClaudeClient;

class CustomerSummaryController extends Controller
{
    public function __construct(
        private ClaudeClient $claude,
    ) {
    }

    public function summarize(Request $request): JsonResponse
    {
        $request->validate([
            'sessions' => 'required|array|min:1',
        ]);

        $sessions = $request->input('sessions');

        // ── 全セッションの分析データをプロンプトに組み立て ──
        $sessionTexts = [];
        foreach ($sessions as $i => $session) {
            $num = $i + 1;
            $text = "【セッション{$num}】（{$session['created_at']}）\n";
            $text .= "要約: {$session['summary']}\n";
            $text .= "緊急度: {$session['temperature']}\n";
            $text .= "緊急度の判定根拠: {$session['temperature_reason']}\n";

            if (!empty($session['acquired_info'])) {
                $text .= "取得済み情報:\n";
                foreach ($session['acquired_info'] as $item) {
                    $text .= "- {$item}\n";
                }
            }

            if (!empty($session['missing_info'])) {
                $text .= "不足情報:\n";
                foreach ($session['missing_info'] as $item) {
                    $text .= "- {$item}\n";
                }
            }

            $text .= "次のアクション: " . ($session['next_action'] ?? '—') . "\n";
            $text .= "フォロー予定: " . ($session['follow_up_at'] ?? '—') . "\n";

            if (!empty($session['reference_titles'])) {
                $text .= "案内した法律知識:\n";
                foreach ($session['reference_titles'] as $title) {
                    $text .= "- {$title}\n";
                }
            }

            $sessionTexts[] = $text;
        }

        $allSessions = implode("\n---\n", $sessionTexts);

        $system = <<<PROMPT
あなたは法律事務所の事務局スタッフです。
同一の相談者が複数回にわたって行った相談セッションの分析結果が与えられます。
これらを統合して、弁護士が面談前に一目で状況を把握できる統合サマリーを作成してください。

以下のJSON形式で出力してください。JSONのみを出力し、他のテキストは含めないでください。

{
  "integrated_summary": "全セッションを通した相談内容の一言サマリー",
  "integrated_acquired_info": ["全セッションを通して取得できている事実のリスト（重複排除・表現統一）"],
  "truly_missing_info": ["全セッションを通してまだ確認できていない情報のリスト（他セッションで取得済みのものは除外）"],
  "latest_temperature": "最新の緊急度",
  "latest_temperature_reason": "最新の緊急度の判定根拠",
  "recommended_next_action": "全セッションを踏まえた次の一手の提案"
}
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $allSessions],
        ];

        $fullResponse = '';
        $this->claude->streamMessage($messages, function (string $chunk) use (&$fullResponse) {
            $fullResponse .= $chunk;
        }, $system);

        // JSON をパース
        $cleaned = trim($fullResponse);
        $cleaned = preg_replace('/^```json\s*/', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);

        $parsed = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'success' => false,
                'message' => 'Claude の応答を JSON としてパースできませんでした。',
                'raw'     => $fullResponse,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data'    => $parsed,
        ]);
    }
}
