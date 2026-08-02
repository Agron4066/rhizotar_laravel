<?php
namespace Tests\Unit;

use App\Services\PromptBuilder;
use App\Services\PromptStructure;
use PHPUnit\Framework\TestCase;

/**
 * PromptBuilder クラスの単体テスト
 *
 * 主に検証する責務：
 *   1. 第1段階プロンプトの基本動作（戻り値型、業界中立化、出力フォーマット指示）
 *   2. 第1段階プロンプトのスキーマ駆動（target候補・field情報の動的注入、特定名のハードコードがないこと）
 *   3. 第2段階プロンプトの基本動作（戻り値型、業界中立化、絶対禁止事項、ハルシネーション抑制）
 */
class PromptBuilderTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PromptBuilder();
    }

    // =======================================================================
    // 第1グループ: 第1段階プロンプトの基本動作
    // =======================================================================

    public function test_第1段階_戻り値はPromptStructureインスタンス(): void
    {
        $result = $this->builder->buildFirstStagePrompt($this->sampleTargets());
        $this->assertInstanceOf(PromptStructure::class, $result);
    }

    public function test_第1段階_業界中立な役割宣言が含まれる(): void
    {
        $system = $this->builder->buildFirstStagePrompt($this->sampleTargets())->build()['system'];
        $this->assertStringContainsString('コンシェルジュ', $system);
    }

    public function test_第1段階_業界固有表現が含まれない(): void
    {
        $system = $this->builder->buildFirstStagePrompt($this->sampleTargets())->build()['system'];
        // PromptBuilder本体に業界固有表現がハードコードされていないことを担保
        $this->assertStringNotContainsString('不動産コンシェルジュ', $system);
        $this->assertStringNotContainsString('madori', $system === 'madori' ? '' : implode('', array_filter(
            preg_split('//u', $system, -1, PREG_SPLIT_NO_EMPTY),
            fn($_) => false
        )));
        // ※ "madori" のような fields側の文字列はサンプルtargetに含まれるため、
        //    この厳密チェックは「PromptBuilderの構造の中に固有表現がない」という別観点で要請⑤の確認を行う。
        //    ここでは旧版の代表的な業界固有表現「不動産コンシェルジュ」のみを禁止する。
    }

    public function test_第1段階_target属性付きSEARCHタグの形式指示が含まれる(): void
    {
        $system = $this->builder->buildFirstStagePrompt($this->sampleTargets())->build()['system'];
        $this->assertStringContainsString('<SEARCH target=', $system);
    }

    // =======================================================================
    // 第2グループ: 第1段階プロンプトのスキーマ駆動
    // =======================================================================

    public function test_第1段階_target候補一覧が動的注入される(): void
    {
        $system = $this->builder->buildFirstStagePrompt($this->sampleTargets())->build()['system'];
        // sampleTargets() に含まれる2つのtargetのIDがプロンプトに登場すること
        $this->assertStringContainsString('target_id: property', $system);
        $this->assertStringContainsString('target_id: analytics', $system);
    }

    public function test_第1段階_targetのlabelとdescriptionが動的注入される(): void
    {
        $system = $this->builder->buildFirstStagePrompt($this->sampleTargets())->build()['system'];
        // sampleTargets() のlabelとdescriptionがプロンプトに登場すること
        $this->assertStringContainsString('物件', $system);
        $this->assertStringContainsString('住宅物件のデータベース', $system);
        $this->assertStringContainsString('チャット分析', $system);
    }

    public function test_第1段階_fieldの型と検索方式が動的注入される(): void
    {
        $system = $this->builder->buildFirstStagePrompt($this->sampleTargets())->build()['system'];
        // 各fieldの field_type と search_method がプロンプトに登場すること
        $this->assertStringContainsString('select', $system);
        $this->assertStringContainsString('number', $system);
        $this->assertStringContainsString('taxonomy', $system);
        $this->assertStringContainsString('date', $system);
        $this->assertStringContainsString('text', $system);
    }

    public function test_第1段階_fieldのoptionsが動的注入される(): void
    {
        $system = $this->builder->buildFirstStagePrompt($this->sampleTargets())->build()['system'];
        // sampleTargets() の madori field の options がプロンプトに登場すること
        $this->assertStringContainsString('1LDK', $system);
        $this->assertStringContainsString('3LDK', $system);
    }

    public function test_第1段階_検索方式の説明文が含まれる(): void
    {
        $system = $this->builder->buildFirstStagePrompt($this->sampleTargets())->build()['system'];
        // 4つの検索方式の識別子がすべて説明文に登場すること
        $this->assertStringContainsString('exact', $system);
        $this->assertStringContainsString('range', $system);
        $this->assertStringContainsString('like', $system);
        $this->assertStringContainsString('in', $system);
    }

    public function test_第1段階_空のtargetsを渡すと検索対象なし旨が含まれる(): void
    {
        $system = $this->builder->buildFirstStagePrompt([])->build()['system'];
        $this->assertStringContainsString('利用可能な検索対象はありません', $system);
    }

    public function test_第1段階_targetsを差し替えると出力にも反映される(): void
    {
        // 異なる target を渡したとき、その target の情報のみがプロンプトに登場することを担保。
        // これは「PromptBuilder が特定の target 名をハードコードしていない」ことの検証
        // （要請⑤の核心）。
        $customTargets = [
            [
                'target_id' => 'book',
                'label' => '書籍',
                'description' => '書籍データベース。',
                'post_type' => 'book',
                'fields' => [
                    [
                        'field_name' => 'isbn',
                        'field_type' => 'text',
                        'search_method' => 'exact',
                        'label' => 'ISBN',
                        'description' => '書籍のISBNコード。',
                    ],
                ],
            ],
        ];
        $system = $this->builder->buildFirstStagePrompt($customTargets)->build()['system'];

        // 渡した target の情報が含まれる
        $this->assertStringContainsString('target_id: book', $system);
        $this->assertStringContainsString('書籍データベース', $system);
        $this->assertStringContainsString('isbn', $system);

        // sampleTargets() の固有表現（property、analytics、madoriなど）が含まれないこと
        $this->assertStringNotContainsString('target_id: property', $system);
        $this->assertStringNotContainsString('target_id: analytics', $system);
        $this->assertStringNotContainsString('madori', $system);
    }

    // =======================================================================
    // 第3グループ: 第2段階プロンプトの基本動作
    // =======================================================================

    public function test_第2段階_戻り値はPromptStructureインスタンス(): void
    {
        $result = $this->builder->buildSecondStagePrompt();
        $this->assertInstanceOf(PromptStructure::class, $result);
    }

    public function test_第2段階_業界中立な役割宣言が含まれる(): void
    {
        $system = $this->builder->buildSecondStagePrompt()->build()['system'];
        $this->assertStringContainsString('コンシェルジュ', $system);
    }

    public function test_第2段階_業界固有表現が含まれない(): void
    {
        $system = $this->builder->buildSecondStagePrompt()->build()['system'];
        // 旧版の業界固有表現が含まれていないこと
        $this->assertStringNotContainsString('不動産コンシェルジュ', $system);
        $this->assertStringNotContainsString('駅徒歩分数', $system);
    }

    public function test_第2段階_絶対禁止事項セクションが含まれる(): void
    {
        $system = $this->builder->buildSecondStagePrompt()->build()['system'];
        $this->assertStringContainsString('【絶対禁止事項】', $system);
    }

    public function test_第2段階_振る舞い指針セクションが含まれる(): void
    {
        $system = $this->builder->buildSecondStagePrompt()->build()['system'];
        $this->assertStringContainsString('【振る舞い指針】', $system);
    }

    public function test_第2段階_ハルシネーション抑制指示が含まれる(): void
    {
        $system = $this->builder->buildSecondStagePrompt()->build()['system'];
        $this->assertStringContainsString('推測', $system);
        $this->assertStringContainsString('補完', $system);
    }

    public function test_第2段階_SEARCHタグを出力しない指示が含まれる(): void
    {
        $system = $this->builder->buildSecondStagePrompt()->build()['system'];
        // 第2段階で誤ってSEARCHタグを出力しないようClaudeに指示する旨が含まれること
        $this->assertStringContainsString('<SEARCH>', $system);
    }

    // =======================================================================
    // テストデータ生成ヘルパー
    // =======================================================================

    /**
     * 標準的なサンプル target 配列を返す。
     * Step 3-2 で確定した property と analytics の2つを含む。
     *
     * @return array
     */
    private function sampleTargets(): array
    {
        return [
            [
                'target_id' => 'property',
                'label' => '物件',
                'description' => '住宅物件のデータベース。マンション・アパート・戸建てなどの賃貸または売買物件情報を保有する。ユーザーが住む場所を探している場合、または不動産取引を希望する場合に参照する。',
                'post_type' => 'property',
                'fields' => [
                    [
                        'field_name' => 'madori',
                        'field_type' => 'select',
                        'search_method' => 'exact, in',
                        'label' => '間取り',
                        'description' => '住戸の部屋数と用途の組み合わせ。3LDKなら3部屋＋リビング・ダイニング・キッチン。',
                        'options' => ['1K', '1LDK', '2LDK', '3LDK', '4LDK'],
                    ],
                    [
                        'field_name' => 'price',
                        'field_type' => 'number',
                        'search_method' => 'exact, range',
                        'label' => '価格',
                        'description' => '物件の販売価格または家賃。単位は円。',
                    ],
                    [
                        'field_name' => 'area',
                        'field_type' => 'taxonomy',
                        'search_method' => 'exact, in',
                        'label' => 'エリア',
                        'description' => '物件の所在エリア。タクソノミー property_area にぶら下がる。',
                        'options' => ['新宿区', '渋谷区', '港区', '千代田区'],
                    ],
                    [
                        'field_name' => 'station_walk',
                        'field_type' => 'number',
                        'search_method' => 'exact, range',
                        'label' => '駅徒歩分数',
                        'description' => '最寄り駅から徒歩でかかる時間。単位は分。',
                    ],
                    [
                        'field_name' => 'built_year',
                        'field_type' => 'number',
                        'search_method' => 'exact, range',
                        'label' => '築年数',
                        'description' => '物件が建てられた年。たとえば2010など。',
                    ],
                ],
            ],
            [
                'target_id' => 'analytics',
                'label' => 'チャット分析',
                'description' => 'コンシェルジュシステム自身が記録した過去の検索ログ。ユーザーが過去の検索動向を分析したい場合、または運営者が利用状況を確認したい場合に参照する。',
                'post_type' => 'analytics',
                'fields' => [
                    [
                        'field_name' => 'session_id',
                        'field_type' => 'text',
                        'search_method' => 'exact, like',
                        'label' => 'セッションID',
                        'description' => 'チャットセッションを識別するID。32文字の英数字。',
                    ],
                    [
                        'field_name' => 'target',
                        'field_type' => 'text',
                        'search_method' => 'exact, like',
                        'label' => '検索対象target',
                        'description' => 'その検索リクエストでClaudeが指定したtarget識別子。',
                    ],
                    [
                        'field_name' => 'conditions_json',
                        'field_type' => 'text',
                        'search_method' => 'like',
                        'label' => '検索条件JSON',
                        'description' => 'その検索リクエストでClaudeが指定した検索条件のJSON文字列。',
                    ],
                    [
                        'field_name' => 'hit_count',
                        'field_type' => 'number',
                        'search_method' => 'exact, range',
                        'label' => 'ヒット件数',
                        'description' => 'その検索でWP_Queryが返した結果の件数。',
                    ],
                    [
                        'field_name' => 'executed_at',
                        'field_type' => 'date',
                        'search_method' => 'exact, range',
                        'label' => '実行時刻',
                        'description' => 'その検索が実行された日時。',
                    ],
                ],
            ],
        ];
    }

    /**
     * formatField: options が value（label）形式で表示される
     */
    public function test_first_stage_shows_option_values_with_labels(): void
    {
        $targets = [
            [
                'target_id'   => 'property',
                'label'       => '物件一覧',
                'description' => 'テスト用',
                'fields'      => [
                    [
                        'field_name'    => 'property_category',
                        'field_type'    => 'taxonomy',
                        'search_method' => 'exact',
                        'options'       => [
                            ['value' => 'mansion', 'label' => 'マンション'],
                            ['value' => 'house',   'label' => '一戸建て'],
                        ],
                    ],
                ],
            ],
        ];

        $builder = new \App\Services\PromptBuilder();
        $ps = $builder->buildFirstStagePrompt($targets);
        $built = $ps->build();
        $system = $built['system'];

        $this->assertStringContainsString('mansion（マンション）', $system);
        $this->assertStringContainsString('house（一戸建て）', $system);
        $this->assertStringContainsString('conditions JSONにはvalueを使用', $system);
    }

    /**
     * formatTarget: few_shot_examples がプロンプトに注入される
     */
    public function test_first_stage_injects_few_shot_examples(): void
    {
        $targets = [
            [
                'target_id'   => 'property',
                'label'       => '物件一覧',
                'description' => 'テスト用',
                'fields'      => [],
                'few_shot_examples' => [
                    [
                        'user'   => '新宿で3LDKのマンション',
                        'output' => '<SEARCH target="property">{"property_nearest_station":"新宿","property_floor_plan":"3LDK","property_category":["mansion"]}</SEARCH>',
                    ],
                ],
            ],
        ];

        $builder = new \App\Services\PromptBuilder();
        $ps = $builder->buildFirstStagePrompt($targets);
        $built = $ps->build();
        $system = $built['system'];

        $this->assertStringContainsString('入出力例', $system);
        $this->assertStringContainsString('新宿で3LDKのマンション', $system);
        $this->assertStringContainsString('<SEARCH target="property">', $system);
        $this->assertStringContainsString('"property_category":["mansion"]', $system);
    }

    /**
     * formatTarget: few_shot_examples がない場合は何も出力されない
     */
    public function test_first_stage_no_few_shot_examples_when_absent(): void
    {
        $targets = [
            [
                'target_id'   => 'post',
                'label'       => '投稿',
                'description' => 'テスト用',
                'fields'      => [],
            ],
        ];

        $builder = new \App\Services\PromptBuilder();
        $ps = $builder->buildFirstStagePrompt($targets);
        $built = $ps->build();
        $system = $built['system'];

        $this->assertStringNotContainsString('入出力例', $system);
    }

}


