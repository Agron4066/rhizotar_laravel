<?php

namespace App\Services;

class SearchService
{
    /**
     * Claudeのレスポンスから<SEARCH target="...">タグを検出して
     * target と conditions JSON を抽出する
     *
     * 戻り値の構造：
     *   [
     *       'target' => 'property',
     *       'conditions' => ['madori' => '3LDK', 'price_max' => 50000000],
     *   ]
     *
     * 次の場合は null を返す：
     *   - <SEARCH target="..."> タグが見つからない
     *   - target 属性なしの <SEARCH> しか見つからない（判断Dの結論）
     *   - conditions JSON のパースに失敗する
     */
    public static function extractConditions(string $response): ?array
    {
        // <SEARCH target="..."> 形式のタグを検出
        // target 属性なしの <SEARCH> はマッチしないため、null が返る（判断Dの結論）
        if (!preg_match('/<SEARCH\s+target="([^"]+)">(.*?)<\/SEARCH>/s', $response, $matches)) {
            return null;
        }

        $target = $matches[1];
        $conditionsJson = trim($matches[2]);

        // conditions JSON をパース
        $conditions = json_decode($conditionsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // 連想配列ネスト形式で返す（判断Cの結論）
        return [
            'target' => $target,
            'conditions' => $conditions,
        ];
    }
}
