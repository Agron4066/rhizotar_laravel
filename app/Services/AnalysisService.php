<?php

namespace App\Services;

class AnalysisService
{
    /**
     * 会話履歴を分析し、構造化データを返す
     *
     * 採用方針は案A：Claude のメイン応答に <ANALYSIS> タグを混ぜる。
     * 分析の知能は PromptBuilder のプロンプト側にあり、このメソッドは
     * 生成されたタグから JSON を抽出・検証・整形する役に徹する。
     *
     * 戻り値のスキーマ（業界中立・汎用4キー）：
     *   [
     *       'summary'       => '会話の要約',
     *       'acquired_info' => ['取得できた情報1', '取得できた情報2'],
     *       'missing_info'  => ['まだ聞けていない情報1'],
     *       'temperature'   => '高|中|低|未判定',
     *   ]
     *
     * タグ未検出・JSONパース失敗時は、スキーマを保ったフォールバック配列を返す
     * （戻り値の契約は不変。WP側・ChatController は影響を受けない）。
     */
    public function analyzeConversation(string $fullResponse): array
    {
        $fallback = [
            'summary'       => '',
            'acquired_info' => [],
            'missing_info'  => [],
            'temperature'   => '未判定',
            'temperature_reason' => '',
        ];

        // <ANALYSIS>{JSON}</ANALYSIS> を検出
        if (!preg_match('/<ANALYSIS>(.*?)<\/ANALYSIS>/s', $fullResponse, $matches)) {
            return $fallback;
        }

        $decoded = json_decode(trim($matches[1]), true);
        if (!is_array($decoded)) {
            return $fallback;
        }

        // 4キーのバリデーション・整形
        $summary = isset($decoded['summary']) && is_string($decoded['summary'])
            ? $decoded['summary']
            : '';

        $acquiredInfo = isset($decoded['acquired_info']) && is_array($decoded['acquired_info'])
            ? array_values(array_filter($decoded['acquired_info'], 'is_string'))
            : [];

        $missingInfo = isset($decoded['missing_info']) && is_array($decoded['missing_info'])
            ? array_values(array_filter($decoded['missing_info'], 'is_string'))
            : [];

        $allowedTemperatures = ['高', '中', '低', '未判定'];
        $temperature = isset($decoded['temperature']) && in_array($decoded['temperature'], $allowedTemperatures, true)
            ? $decoded['temperature']
            : '未判定';

        $temperatureReason = isset($decoded['temperature_reason']) && is_string($decoded['temperature_reason'])
            ? $decoded['temperature_reason']
            : '';

        return [
            'summary'       => $summary,
            'acquired_info' => $acquiredInfo,
            'missing_info'  => $missingInfo,
            'temperature'   => $temperature,
            'temperature_reason' => $temperatureReason,
        ];
    }
}
