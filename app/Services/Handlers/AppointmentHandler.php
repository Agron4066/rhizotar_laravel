<?php

namespace App\Services\Handlers;

use Illuminate\Support\Facades\Log;

/**
 * AppointmentHandler
 *
 * <APPOINTMENT>タグが検出されたときの処理を担当するHandler。
 *
 * SearchHandler とは異なり、中断・再開パターンは使わない。
 * Claudeの応答はそのまま表示し、done ペイロードに
 * appointment_request: true を追加するだけのシグナル型Handler。
 *
 * SearchHandler と同じ「保守的移行」のアプローチで、
 * ChatController から extractAppointmentSignal() が直接呼ばれる。
 * Phase 14 で TagDispatcher 経由に切り替える際に handle() を実装する。
 *
 * 役割:
 *   1. Claudeの応答全文から <APPOINTMENT> タグの有無を判定
 *   2. 検出結果を boolean で返す（SSE発火は ChatController 側の責務）
 *
 * フェーズ6 Step 3-1 で新設。
 */
// ←← フェーズ6 Step3-1: AppointmentHandler 新設 ←←
class AppointmentHandler implements TagHandlerInterface
{
    public function __construct()
    {
    }

    /**
     * TagHandlerInterface契約の実装。
     * Phase 14 で TagDispatcher 経由で呼ばれるようになる予定。
     *
     * 現時点では未使用（ChatControllerから extractAppointmentSignal() が直接呼ばれる）。
     *
     * @param string $tagContent <APPOINTMENT>タグの中身
     */
    public function handle(string $tagContent): void
    {
        Log::info('[AppointmentHandler] handle() called (skeleton, not yet implemented)', [
            'content_length' => strlen($tagContent),
        ]);
        // TODO: Phase 14 で TagDispatcher 経由のフローを実装する
    }

    /**
     * Claudeの応答全文から <APPOINTMENT> タグの有無を判定する。
     * ChatController::stream() / continue() から呼ばれる。
     *
     * タグの中身は現時点では使わない（存在判定のみ）。
     * 将来、タグ内に希望日時のヒント等を含める拡張に備えて、
     * 中身を取り出す構造にはしておく。
     *
     * @param string $fullResponse Claudeの応答全文
     * @return bool <APPOINTMENT>タグが検出されたら true
     */
    public function extractAppointmentSignal(string $fullResponse): bool
    {
        $found = (bool) preg_match('/<APPOINTMENT>(.*?)<\/APPOINTMENT>/s', $fullResponse);

        Log::info('[AppointmentHandler] extractAppointmentSignal', [
            'found' => $found,
        ]);

        return $found;
    }
}
// ←← フェーズ6 Step3-1 ここまで ←←
