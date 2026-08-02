<?php

namespace App\Services;

/**
 * 面談事前メモ生成サービス（フェーズ6 Step 6-2）
 *
 * WP側（appointment-aggregator）から渡されたセッションデータをもとに、
 * 弁護士が面談前に把握すべき要点をまとめた事前メモを
 * Claude に生成させて返すオーケストレーション層。
 *
 * ReportService と同型の責務配分：
 * - メモの指示（知財）は PromptBuilder::buildAppointmentMemoPrompt() が持つ。
 * - 事実データ（会話本文・分析結果）は引数で渡される。DBは見ない。
 */
// ←← フェーズ6 Step6-2: AppointmentService 新設 ←←
class AppointmentService
{
    public function __construct(
        private PromptBuilder $promptBuilder,
        private ClaudeClient $claude,
    ) {
    }

    /**
     * セッションデータから面談事前メモを生成して返す
     *
     * @param array $sessionData セッション記録。次のキーを想定（いずれも任意）：
     *                           - session_id: string
     *                           - analysis:   array（機能3の分析結果）
     *                           - messages:   array（会話本文 [['role','content'], ...]）
     *                           - karte:      array（カルテ情報 conditions 等）
     * @return string 事前メモ本文（タグなしの自然文）
     */
    public function generateMemo(array $sessionData): string
    {
        if ( empty($sessionData['messages']) && empty($sessionData['analysis']) ) {
            return '相談記録が不足しているため、事前メモを生成できませんでした。';
        }

        $promptStructure = $this->promptBuilder->buildAppointmentMemoPrompt();
        $promptStructure->addDynamicMessage([
            'role'    => 'user',
            'content' => $this->buildUserContent($sessionData),
        ]);
        $built = $promptStructure->build();

        $memo = '';
        $this->claude->streamMessage(
            $built['messages'],
            function (string $chunk) use (&$memo) {
                $memo .= $chunk;
            },
            $built['system'],
        );

        return $memo;
    }

    /**
     * セッションデータを、Claude に渡すユーザーメッセージ用のテキストに整形する
     *
     * @param array $sessionData
     * @return string
     */
    private function buildUserContent(array $sessionData): string
    {
        $lines = [];
        $lines[] = '以下は、面談予約を入れた相談者とのAIチャット記録です。この記録だけを根拠に事前メモを作成してください。';
        $lines[] = '';

        if ( ! empty($sessionData['session_id']) ) {
            $lines[] = 'セッションID: ' . $sessionData['session_id'];
        }

        // カルテ情報（あれば）
        if ( ! empty($sessionData['karte']) && is_array($sessionData['karte']) ) {
            $lines[] = '';
            $lines[] = '【カルテ情報】';
            foreach ($sessionData['karte'] as $key => $value) {
                if ( is_array($value) ) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                $lines[] = '  ' . $key . ': ' . $value;
            }
        }

        // 分析結果（あれば）
        if ( ! empty($sessionData['analysis']) && is_array($sessionData['analysis']) ) {
            $analysis = $sessionData['analysis'];
            $lines[] = '';
            $lines[] = '【AI分析結果】';

            if ( ! empty($analysis['summary']) ) {
                $lines[] = '  要約: ' . $analysis['summary'];
            }
            if ( ! empty($analysis['temperature']) ) {
                $lines[] = '  温度感: ' . $analysis['temperature'];
            }
            if ( ! empty($analysis['acquired_info']) && is_array($analysis['acquired_info']) ) {
                $lines[] = '  取得できた情報: ' . implode('、', $analysis['acquired_info']);
            }
            if ( ! empty($analysis['missing_info']) && is_array($analysis['missing_info']) ) {
                $lines[] = '  まだ聞けていない情報: ' . implode('、', $analysis['missing_info']);
            }
        }

        // 会話本文（あれば）
        if ( ! empty($sessionData['messages']) && is_array($sessionData['messages']) ) {
            $lines[] = '';
            $lines[] = '【会話記録】';
            foreach ($sessionData['messages'] as $msg) {
                $role    = ($msg['role'] ?? '') === 'assistant' ? 'コンシェルジュ' : '相談者';
                $content = $msg['content'] ?? '';
                $lines[] = '  ' . $role . ': ' . $content;
            }
        }

        return implode("\n", $lines);
    }
}
// ←← フェーズ6 Step6-2 ここまで ←←
