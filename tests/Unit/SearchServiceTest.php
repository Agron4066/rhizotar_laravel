<?php

namespace Tests\Unit;

use App\Services\SearchService;
use PHPUnit\Framework\TestCase;

/**
 * SearchService クラスの単体テスト
 *
 * 主に検証する責務：
 *   1. 基本動作の正常系（target属性付きSEARCHから target と conditions の抽出、ネスト構造）
 *   2. null返却の各パターン（タグなし、target属性なし、target空、JSON壊れ、空オブジェクト）
 *   3. 複合条件・特殊なケース（range形式、in形式、複数タグ）
 */
class SearchServiceTest extends TestCase // ←← 機能2 Step 4 で全件書き直し ←←
{
    // =======================================================================
    // 第1グループ: 基本動作の正常系
    // =======================================================================

    public function test_target属性付きSEARCHからtargetとconditionsを抽出できる(): void
    {
        $response = 'かしこまりました。条件に合う物件を探しますね。<SEARCH target="property">{"madori":"3LDK","price_max":50000000}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertIsArray($result);
        $this->assertSame('property', $result['target']);
        $this->assertSame('3LDK', $result['conditions']['madori']);
        $this->assertSame(50000000, $result['conditions']['price_max']);
    }

    public function test_戻り値はtargetとconditionsの2キーを持つ連想配列(): void
    {
        $response = '<SEARCH target="property">{"madori":"3LDK"}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('target', $result);
        $this->assertArrayHasKey('conditions', $result);
        $this->assertCount(2, $result);
    }

    public function test_target_idはconditionsに紛れ込まない(): void
    {
        // target_id を conditions と並列に保持する構造であり、
        // conditions の中に target キーが混入していないことを担保する
        $response = '<SEARCH target="property">{"madori":"3LDK","price_max":50000000}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertArrayNotHasKey('target', $result['conditions']);
        $this->assertArrayNotHasKey('property', $result['conditions']);
    }

    // =======================================================================
    // 第2グループ: null返却の各パターン
    // =======================================================================

    public function test_SEARCHタグがない場合はnullを返す(): void
    {
        $response = 'はい、承知しました！何かご質問はありますか？';

        $result = SearchService::extractConditions($response);

        $this->assertNull($result);
    }

    public function test_target属性なしの旧形式SEARCHはnullを返す(): void
    {
        // 判断Dの遵守：target属性なしの<SEARCH>はエラー扱い（null返却）
        // これは要請（規約を型として強制する）の核心テスト
        $response = 'かしこまりました。<SEARCH>{"madori":"3LDK"}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertNull($result);
    }

    public function test_target属性が空文字列の場合はnullを返す(): void
    {
        // <SEARCH target=""> は target_id が取り出せないため null を返す
        $response = '<SEARCH target="">{"madori":"3LDK"}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertNull($result);
    }

    public function test_JSONが壊れている場合はnullを返す(): void
    {
        $response = '<SEARCH target="property">これはJSONではない</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertNull($result);
    }

    public function test_conditionsが空オブジェクトの場合は空配列のconditionsを返す(): void
    {
        // 空の {} は有効な JSON で、空配列としてパースされる
        // 「条件なしで target だけ指定」という SEARCH も有効として扱う
        $response = '<SEARCH target="property">{}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertNotNull($result);
        $this->assertSame('property', $result['target']);
        $this->assertSame([], $result['conditions']);
    }

    // =======================================================================
    // 第3グループ: 複合条件・特殊なケース
    // =======================================================================

    public function test_range形式の複合条件を正しくパースする(): void
    {
        // range検索方式は field_min / field_max の形で表現される
        $response = '<SEARCH target="property">{"price_min":30000000,"price_max":50000000,"built_year_min":2010}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertSame('property', $result['target']);
        $this->assertSame(30000000, $result['conditions']['price_min']);
        $this->assertSame(50000000, $result['conditions']['price_max']);
        $this->assertSame(2010, $result['conditions']['built_year_min']);
    }

    public function test_in検索の配列形式を正しくパースする(): void
    {
        // in検索方式は配列形式で表現される
        $response = '<SEARCH target="property">{"area":["新宿区","渋谷区","港区"]}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertSame('property', $result['target']);
        $this->assertSame(['新宿区', '渋谷区', '港区'], $result['conditions']['area']);
    }

    public function test_複数のSEARCHタグが混在する場合は最初の1個のみ抽出する(): void
    {
        // 機能2 では「Claudeが1回の応答で複数のSEARCHタグを出すケース」は想定しないが、
        // 万一発生した場合は最初の1個だけを取り出す（preg_matchの非貪欲マッチの挙動）
        $response = '<SEARCH target="property">{"madori":"3LDK"}</SEARCH>後続文章<SEARCH target="analytics">{"hit_count_min":10}</SEARCH>';

        $result = SearchService::extractConditions($response);

        $this->assertSame('property', $result['target']);
        $this->assertSame('3LDK', $result['conditions']['madori']);
        // analytics target は取り出されない
        $this->assertArrayNotHasKey('hit_count_min', $result['conditions']);
    }
}
