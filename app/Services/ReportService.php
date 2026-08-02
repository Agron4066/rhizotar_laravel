<?php

namespace App\Services;

/**
 * 日次レポート生成サービス（機能4・第1回デモ）
 *
 * WP側（report-aggregator）から渡された「前日のセッション群」をもとに、
 * 「昨日こんな問い合わせがありました」という運営担当者向けの日次レポートを
 * Claude に生成させて返すオーケストレーション層。
 *
 * 責務はあくまで「組み立てて Claude を呼び、全文を返す」ことに限定する。
 * - 要約の指示（知財）は PromptBuilder::buildReportPrompt() が持つ。
 * - 事実データ（会話本文・分析結果）は引数で渡される。DBは見ない。
 *   ハルシネーション抑制は、渡された記録だけを根拠にさせるプロンプト側で担保する。
 */
class ReportService
{
    public function __construct(
        private PromptBuilder $promptBuilder,
        private ClaudeClient $claude,
    ) {
    }

    /**
     * 前日のセッション群から日次レポート本文を生成して返す
     *
     * @param array  $sessions セッション記録の配列。各要素は次のキーを想定（いずれも任意）：
     *                         - session_id: string
     *                         - analysis:   array（機能3の分析結果。summary / temperature /
     *                                              acquired_info[] / missing_info[]）
     *                         - messages:   array（機能0の会話本文。[['role','content'], ...]）
     * @param string $date     対象日（WP側から渡される表記をそのまま使う）
     * @return string レポート本文（タグなしの自然文）
     */
    public function generate(array $sessions, string $date): string
    {
        // 問い合わせが0件のときは Claude を呼ばず固定文を返す（無駄なAPI呼び出しを避ける）
        if (empty($sessions)) {
            return $date . ' の問い合わせはありませんでした。';
        }

        $promptStructure = $this->promptBuilder->buildReportPrompt();
        $promptStructure->addDynamicMessage([
            'role'    => 'user',
            'content' => $this->buildUserContent($sessions, $date),
        ]);
        $built = $promptStructure->build();

        // streamMessage は戻り値を持たないため、チャンクをコールバックで溜めて全文化する
        // （ChatController の $fullResponse と同じ方式）
        $report = '';
        $this->claude->streamMessage(
            $built['messages'],
            function (string $chunk) use (&$report) {
                $report .= $chunk;
            },
            $built['system'],
        );

        return $report;
    }

    /**
     * セッション群を、Claude に渡すユーザーメッセージ用のテキストに整形する
     *
     * 渡された配列のキーが一部欠けていても落ちないよう、防御的に読む。
     *
     * @param array  $sessions
     * @param string $date
     * @return string
     */
    private function buildUserContent(array $sessions, string $date): string
    {
        $lines = [];
        $lines[] = '対象日: ' . $date;
        $lines[] = '問い合わせ件数: ' . count($sessions) . '件';
        $lines[] = '';
        $lines[] = '以下は各問い合わせ（セッション）の記録です。これらの記録だけを根拠にレポートを作成してください。';

        foreach (array_values($sessions) as $i => $session) {
            $num = $i + 1;
            $lines[] = '';
            $lines[] = '----- セッション' . $num . ' -----';

            if (!empty($session['session_id'])) {
                $lines[] = 'session_id: ' . $session['session_id'];
            }

            // 機能3の分析結果（あれば）
            if (!empty($session['analysis']) && is_array($session['analysis'])) {
                $analysis = $session['analysis'];

                if (!empty($analysis['summary'])) {
                    $lines[] = '要約: ' . $analysis['summary'];
                }
                if (!empty($analysis['temperature'])) {
                    $lines[] = '温度感: ' . $analysis['temperature'];
                }
                if (!empty($analysis['acquired_info']) && is_array($analysis['acquired_info'])) {
                    $lines[] = '取得できた情報: ' . implode('、', $analysis['acquired_info']);
                }
                if (!empty($analysis['missing_info']) && is_array($analysis['missing_info'])) {
                    $lines[] = 'まだ聞けていない情報: ' . implode('、', $analysis['missing_info']);
                }
            }

            // 会話本文（あれば）
            if (!empty($session['messages']) && is_array($session['messages'])) {
                $lines[] = '会話:';
                foreach ($session['messages'] as $msg) {
                    $role    = ($msg['role'] ?? '') === 'assistant' ? 'コンシェルジュ' : 'お客様';
                    $content = $msg['content'] ?? '';
                    $lines[] = '  ' . $role . ': ' . $content;
                }
            }
        }

        return implode("\n", $lines);
    }
}
