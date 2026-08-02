<?php
namespace App\Services;

use App\Services\PromptStructure;

class PromptBuilder
{
    /**
     * 第1段階用システムプロンプトを返す
     * （ユーザー質問 → 検索条件JSON生成のための指示）
     *
     * @param array $availableSearchTargets WP管理画面で設計された target 一覧（JSON Schema 準拠）
     */
    public function buildFirstStagePrompt(array $availableSearchTargets): PromptStructure
    {
        $ps = new PromptStructure();

        $ps->addStaticSystem($this->buildRoleSection());
        $responseStyle = $this->extractResponseStyle($availableSearchTargets);
        if (!empty($responseStyle)) {
            $ps->addStaticSystem($this->buildResponseStyleSection($responseStyle));
        }

        $ps->addStaticSystem($this->buildTargetsSection($availableSearchTargets));
        $ps->addStaticSystem($this->buildOutputFormatSection());
        $ps->addStaticSystem($this->buildBehaviorSection());
        $ps->addStaticSystem($this->buildFollowUpSection(true));
        $analysisGuide = $this->extractAnalysisGuide($availableSearchTargets);
        $ps->addStaticSystem($this->buildAnalysisSection($analysisGuide));
        $ps->addStaticSystem($this->buildAppointmentSection(true));

        return $ps;
    }

    /**
     * 第2段階用システムプロンプトを返す
     * （検索結果 → 最終回答生成のための指示。ハルシネーション抑制）
     */
    public function buildSecondStagePrompt(string $responseStyle = '', string $analysisGuide = '', string $temperatureCriteria = ''): PromptStructure
    {
        $ps = new PromptStructure();

        $ps->addStaticSystem($this->buildSecondRoleSection());

        if (!empty($responseStyle)) {
            $ps->addStaticSystem($this->buildResponseStyleSection($responseStyle));
        }

        $ps->addStaticSystem($this->buildSecondAbsoluteRulesSection());
        $ps->addStaticSystem($this->buildSecondBehaviorSection());
        $ps->addStaticSystem($this->buildFollowUpSection(false));
        $ps->addStaticSystem($this->buildAnalysisSection($analysisGuide, $temperatureCriteria));
        $ps->addStaticSystem($this->buildAppointmentSection(false));

        return $ps;
    }

    /**
     * 日次レポート用システムプロンプトを返す
     * （前日のセッション群 → 「昨日こんな問い合わせがありました」要約の生成指示）
     *
     * セッションの実データ（会話本文・分析結果）はこのプロンプトには含めない。
     * ReportService がユーザーメッセージとして渡す。ここでは「どう要約するか」
     * という指示（知財）だけを組み立てる。第1段階・第2段階と同じく
     * PromptStructure を返し、ReportService から呼び出される。
     */
    public function buildReportPrompt(): PromptStructure
    {
        $ps = new PromptStructure();

        $ps->addStaticSystem($this->buildReportRoleSection());
        $ps->addStaticSystem($this->buildReportInstructionSection());

        return $ps;
    }

    /**
     * セクション1: 役割宣言を生成（第1段階用）
     */
    private function buildRoleSection(): string
    {
        return 'あなたはコンシェルジュです。ユーザーの発言を聞いて、検索したい意図があるかどうかを判断し、検索意図がある場合は検索条件を生成する役割を担っています。

検索意図の判断基準：ユーザーが何かを「探している」「知りたい」「調べたい」という意思を示している場合、検索意図ありと判断してください。挨拶・お礼・雑談など、検索を要しない発言には検索意図はありません。';
    }

    /**
     * セクション2: 利用可能な検索対象 (target) 一覧を動的生成
     *
     * @param array $availableSearchTargets
     */
    private function buildTargetsSection(array $availableSearchTargets): string
    {
        if (empty($availableSearchTargets)) {
            return '利用可能な検索対象（target）一覧：

現在、利用可能な検索対象はありません。すべての発言には検索意図なしとして応答してください。';
        }

        $sectionParts = ['利用可能な検索対象（target）一覧：'];

        foreach ($availableSearchTargets as $target) {
            $sectionParts[] = $this->formatTarget($target);
        }

        return implode("\n\n", $sectionParts);
    }

    /**
     * 1つの target をプロンプト用文字列に整形
     *
     * @param array $target
     */
    private function formatTarget(array $target): string
    {
        $lines = [];
        $lines[] = '【target_id: ' . ($target['target_id'] ?? '') . '】';

        if (isset($target['label'])) {
            $lines[] = '- 表示名: ' . $target['label'];
        }

        if (isset($target['description'])) {
            $lines[] = '- 説明: ' . $target['description'];
        }

        if (!empty($target['fields']) && is_array($target['fields'])) {
            $lines[] = '- 検索可能なフィールド:';
            foreach ($target['fields'] as $field) {
                $lines[] = $this->formatField($field);
            }
        }

        if (!empty($target['few_shot_examples']) && is_array($target['few_shot_examples'])) {
            $lines[] = '';
            $lines[] = '- 入出力例:';
            foreach ($target['few_shot_examples'] as $i => $example) {
                $num = $i + 1;
                $lines[] = '  例' . $num . ':';
                $lines[] = '    ユーザー: ' . ($example['user'] ?? '');
                $lines[] = '    出力: ' . ($example['output'] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 1つの field をプロンプト用文字列に整形
     *
     * @param array $field
     */
    private function formatField(array $field): string
    {
        $fieldName = $field['field_name'] ?? '';
        $fieldType = $field['field_type'] ?? '';
        $searchMethod = $field['search_method'] ?? '';

        $headParts = [];
        $headParts[] = '型: ' . $fieldType;
        $headParts[] = '検索方式: ' . $searchMethod;

        if (!empty($field['options']) && is_array($field['options'])) {
            $optionLabels = array_map(function ($opt) {
                if (is_array($opt)) {
                    $value = $opt['value'] ?? '';
                    $label = $opt['label'] ?? '';
                    return ($label !== '' && $label !== $value) ? $value . '（' . $label . '）' : $value;
                }
                return $opt;
            }, $field['options']);
            $headParts[] = '選択肢（conditions JSONにはvalueを使用）: [' . implode(', ', $optionLabels) . ']';
        }

        $head = '  - ' . $fieldName . ' (' . implode('、', $headParts) . ')';

        $detailLines = [];
        if (isset($field['label'])) {
            $detailLines[] = '    ' . $field['label'];
        }
        if (isset($field['description'])) {
            $detailLines[] = '    ' . $field['description'];
        }

        if (empty($detailLines)) {
            return $head;
        }

        return $head . "\n" . implode("\n", $detailLines);
    }

    /**
     * セクション3: 出力フォーマット指示と検索方式の意味を生成（第1段階用）
     */
    private function buildOutputFormatSection(): string
    {
        return '検索意図がある場合の出力フォーマット：

ユーザーの発言から検索条件を読み取り、まずは確認の文章をユーザーに返した後、以下の形式で検索条件を出力してください。

<SEARCH target="該当するtarget_id">{"フィールド名":"値", ...}</SEARCH>

' . $this->formatSearchMethodsExplanation();
    }

    /**
     * 検索方式の意味を説明する文字列を生成
     */
    private function formatSearchMethodsExplanation(): string
    {
        return '検索方式（search_method）ごとのconditions JSONの書き方：

- exact: 完全一致。指定した値とフィールド値が完全に一致する場合にヒット。conditions JSONでは {"field_name": "値"} の形。
- range: 範囲検索。最小値・最大値の片方または両方を指定。conditions JSONでは {"field_name_min": 値, "field_name_max": 値} の形（片方のみの指定も可）。
- like: 部分一致。指定文字列がフィールド値に含まれる場合にヒット。conditions JSONでは {"field_name": "値"} の形。
- in: 複数選択。複数の候補値のいずれかにヒット。conditions JSONでは {"field_name": ["値1", "値2"]} の形。

複数のフィールドを組み合わせた複合条件も可能です。たとえば exact と range を組み合わせる場合：
<SEARCH target="該当するtarget_id">{"field_a":"値", "field_b_min":数値, "field_b_max":数値}</SEARCH>';
    }

    /**
     * セクション4: 振る舞い指針を生成（第1段階用）
     */
    private function buildBehaviorSection(): string
    {
        return '振る舞い指針：

1. 検索意図がない発言（挨拶・お礼・雑談など）には、<SEARCH>タグを出力せず、コンシェルジュとして自然な日本語で応答してください。
2. ユーザーの発言から検索条件が読み取れる場合は、まずは確認の文章を自然な日本語で返してから、<SEARCH>タグで検索条件を出力してください。
3. ユーザーが1つでも具体的な検索条件を提示した場合は、必ず<SEARCH>タグを出力してください。条件が少ないことを理由に検索を省略してはいけません。追加条件の確認は、検索結果を返した後に行ってください。
4. 検索条件が一切読み取れない場合（例：「何かおすすめはありますか？」のように具体的な条件がない場合）のみ、<SEARCH>タグを出力せず、希望条件を尋ねてください。
5. target一覧にない検索対象が話題になった場合は、<SEARCH>タグを出力せず、対応できない旨を丁寧に伝えてください。
6. ユーザーの要望が専用フィールドに該当しない場合でも、「対応していません」と回答してはならない。部分一致（like）検索が設定されているフィールドがあれば、それをキーワード検索のフォールバックとして使い、必ず検索を実行すること。
7. 部分一致（like）検索を使う場合は、値を3〜10要素のJSON配列で指定すること各パターンは1〜2語の短いキーワードにすること（長いフレーズは避ける）。ユーザーの発言からコアとなる単語を抽出し、その同義語・類義語・DB上で使われそうな専門用語・略語の3〜10パターンを含めること。具体的で表記揺れが少ないキーワードはパターン数を少なく、抽象的で多様な表現がありうるキーワードはパターン数を多くすること。汎用的すぎるキーワードは避け、検索意図に特有のキーワードを優先すること。
8. コンシェルジュとして、丁寧で親しみやすい敬語で応答してください。「ございます」「いたします」などの過度に堅苦しい表現は避け、「です」「ます」を基本とした自然な丁寧語を使ってください。';
    }

    /**
     * 検索対象一覧から response_style を抽出する（第1段階用）
     *
     * 複数の検索対象にそれぞれ回答スタイルが設定されている場合は
     * 改行で連結して1つの文字列にまとめる。
     *
     * @param array $availableSearchTargets WP管理画面で設計された target 一覧
     * @return string 回答スタイルが未設定の場合は空文字列
     */
    private function extractResponseStyle(array $availableSearchTargets): string
    {
        $styles = [];
        foreach ($availableSearchTargets as $target) {
            if (!empty($target['response_style'])) {
                $styles[] = $target['response_style'];
            }
        }
        return implode("\n", $styles);
    }

    /**
     * 回答スタイルセクションを構築する（第1段階・第2段階共通）
     *
     * WP管理画面の「この検索対象での回答スタイル」に入力された
     * フォーマット・トーンのルールをプロンプトに注入する。
     *
     * XMLタグ <response_rules> と強い前置き・後書きで囲むことで
     * Claudeの遵守率を高める。配置はロール宣言の直後（絶対禁止事項の前）。
     *
     * @param string $responseStyle 回答スタイルのルール文字列
     * @return string プロンプトに追加する回答スタイルセクション
     */
    private function buildResponseStyleSection(string $responseStyle): string
    {
        return "\n\n<response_rules>\n"
            . "【回答スタイルの厳守事項】\n"
            . "以下のルールはこの回答において必ず守ってください。例外は認めません。\n\n"
            . $responseStyle . "\n\n"
            . "上記ルールに違反した回答は不合格です。\n"
            . "</response_rules>";
    }

    /**
     * 第2段階セクション1: 役割宣言を生成
     */
    private function buildSecondRoleSection(): string
    {
        return 'あなたはコンシェルジュです。検索結果を踏まえてユーザーに最終回答を生成する役割を担っています。';
    }

    /**
     * 第2段階セクション2: 絶対禁止事項を生成（ハルシネーション抑制）
     */
    private function buildSecondAbsoluteRulesSection(): string
    {
        return '【絶対禁止事項】

1. 検索結果に明示的に含まれている情報のみを使用してください。検索結果に書かれていない情報（検索結果に明示されていないあらゆる属性情報）を推測したり補完したりしてはいけません。情報が不足している場合は「その情報は検索結果に含まれていません」と明示してください。
2. 検索は既に完了しています。回答内に<SEARCH>タグや検索条件のJSONを含めてはいけません。検索結果を踏まえた最終回答だけを、自然な日本語で生成してください。';
    }

    /**
     * 第2段階セクション3: 振る舞い指針を生成
     */
    private function buildSecondBehaviorSection(): string
    {
        return '【振る舞い指針】

1. 検索結果が0件の場合は、その旨をユーザーに伝え、検索条件を変更するよう提案してください。具体的な代替の検索結果を勝手に提示してはいけません。検索結果に存在しない項目を作り出すことは絶対に禁止です。
2. 検索結果が多数ある場合は、最大10件まで紹介してください。それ以上ある場合は「他にも〇件の検索結果があります。条件を絞り込みますか？」とユーザーに確認してください。紹介する10件は検索結果の上位から順に選んでください。
3. コンシェルジュとして、丁寧で親しみやすい敬語で回答してください。「ございます」「いたします」などの過度に堅苦しい表現は避け、「です」「ます」を基本とした自然な丁寧語を使ってください。';
    }

    /**
     * フォローアップ情報出力セクションを構築する（第1段階・第2段階共通）
     *
     * Claudeに <FOLLOW_UP> タグでフォローアップ情報を出力させる指示。
     * CustomerHandler が応答全文からこのタグを検出・抽出し、
     * done イベントの follow_up フィールドとして WP 側に返す。
     *
     * @param bool $conditionalOnNoSearch true の場合、<SEARCH>タグを出力しない場合のみ
     *                                    <FOLLOW_UP>を出力する条件付き指示（第1段階用）。
     *                                    false の場合、無条件に出力する指示（第2段階用）。
     * @return string プロンプトに追加するフォローアップセクション
     */
    private function buildFollowUpSection(bool $conditionalOnNoSearch = false): string
    {
        $section = '【フォローアップ情報の出力】

応答テキストの末尾に、以下の形式でフォローアップ情報を出力してください。このタグはユーザーには表示されず、システムが顧客管理に使用します。

<FOLLOW_UP>{"timing":"フォローすべき時期","content":"フォローの具体的な内容","priority":優先度}</FOLLOW_UP>

各フィールドの判断基準：
- timing: 会話の緊急度に応じたフォロー時期。具体的な希望がある場合は「翌日」「2日後」、特に急ぎでなければ「1週間後」など。
- content: このユーザーに対して次に行うべき具体的なアクション。会話で残った未確認事項の確認、追加情報の案内など。
- priority: 0.0（低）〜 1.0（高）。緊急性が高い、または具体的な対応が必要なユーザーほど高く設定。';

        if ($conditionalOnNoSearch) {
            $section .= '

注意: <SEARCH>タグを出力する場合は、<FOLLOW_UP>タグを出力しないでください。フォローアップ情報は検索結果を踏まえた第2段階の応答で生成されます。';
        }

        return $section;
    }

    /**
     * 分析セクションを構築する（第1段階・第2段階共通）
     *
     * Claudeに <ANALYSIS> タグで会話分析結果を出力させる指示。
     * AnalysisHandler が応答全文からこのタグを検出・抽出し、
     * done イベントの analysis フィールドとして WP 側に返す。
     *
     * response_rules と同じ強い位置づけで囲み、遵守率を確保する。
     *
     * @param string $analysisGuide WP管理画面から注入される業界固有の分析指針
     * @return string プロンプトに追加する分析セクション
     */
    private function buildAnalysisSection(string $analysisGuide, string $temperatureCriteria = ''): string
    {
        $guideText = !empty($analysisGuide)
            ? "\n\n【業界固有の分析指針】\n" . $analysisGuide
            : '';

        $criteriaText = !empty($temperatureCriteria)
            ? "\n\n【温度感の判定基準】\n" . $temperatureCriteria
            : '';

        return "\n\n<analysis_rules>\n"
            . "【会話分析結果の出力】\n"
            . "応答テキストの末尾に、以下の形式で会話分析結果を必ず出力してください。このタグはユーザーには表示されず、システムが顧客管理に使用します。\n\n"
            . "<ANALYSIS>{\"summary\":\"会話の要約\",\"acquired_info\":[\"取得できた情報1\",\"取得できた情報2\"],\"missing_info\":[\"まだ聞けていない情報1\"],\"temperature\":\"高|中|低|未判定\",\"temperature_reason\":\"温度感をそう判定した理由\"}</ANALYSIS>\n\n"
            . "各フィールドの判断基準：\n"
            . "- summary: 会話全体を1〜2文で要約したもの。\n"
            . "- acquired_info: 会話から取得できたユーザーの情報（希望条件・状況など）を配列で列挙。\n"
            . "- missing_info: ユーザーの意思決定に必要だが、まだ聞けていない情報を配列で列挙。\n"
            . "- temperature: ユーザーの相談の緊急度・具体性。具体的な条件が揃い早期の対応が必要なら「高」、関心はあるが検討中なら「中」、様子見・情報収集段階なら「低」、判断できない場合は「未判定\"。\n"
            . $guideText . "\n\n"
            . $criteriaText . "\n\n"
            . "上記ルールに違反した回答は不合格です。\n"
            . "</analysis_rules>";
    }

    /**
     * 検索対象一覧から analysis_guide を抽出する（第1段階用）
     *
     * 将来 target 単位の analysis_guide に対応する場合は
     * concierge_get_analysis_guide() と同じ優先順位ロジックをここに追加する。
     * 現時点ではサイト全体の単一設定のみ対応のため、空文字列を返す。
     * （サイト全体の analysis_guide は ChatController 経由で渡される）
     *
     * @param array $availableSearchTargets
     * @return string
     */
    private function extractAnalysisGuide(array $availableSearchTargets): string
    {
        return '';
    }

    /**
     * レポートセクション1: 役割宣言を生成（日次レポート用）
     */
    private function buildReportRoleSection(): string
    {
        return 'あなたはコンシェルジュの運営担当者に向けて、日次の問い合わせレポートを書くアシスタントです。前日に寄せられた複数の問い合わせ（セッション）の記録を渡されます。それらを読み、担当者が「昨日どんな問い合わせがあったか」を短時間で把握できる日本語のレポートにまとめる役割を担っています。';
    }

    /**
     * レポートセクション2: 出力内容と形式の指示を生成（日次レポート用）
     *
     * 渡されたセッション記録のみを根拠に要約させ、記録にない情報の
     * 推測・補完を禁止する（第2段階の絶対禁止事項と同じ思想）。
     */
    private function buildReportInstructionSection(): string
    {
        return '【レポートの作成指示】

渡されたセッション記録だけを根拠にレポートを作成してください。記録に書かれていない情報を推測したり、件数や傾向を創作したりしてはいけません。

レポートには以下を含めてください。

1. 昨日の問い合わせ全体の概況（おおまかな件数感と、目立った傾向を1〜2文で）。
2. 主だった問い合わせの内容を、セッションごとに簡潔にまとめる（語られた希望条件や関心事を中心に、温度感が分かるものはあわせて）。
3. 担当者が次に動くと良さそうなポイント（フォロー優先度が高そうな問い合わせなど、記録から読み取れる範囲で）。

文章は、運営担当者に語りかける自然な日本語の敬語で書いてください。「です」「ます」を基本とし、過度に堅苦しい表現は避けてください。<SEARCH>や<ANALYSIS>などのタグは出力せず、レポート本文だけを返してください。';
    }

    /**
     * 予約シグナル出力セクションを構築する（第1段階・第2段階共通）
     *
     * Claudeに <APPOINTMENT> タグで面談予約のシグナルを出力させる指示。
     * AppointmentHandler が応答全文からこのタグを検出し、
     * done イベントの appointment_request フィールドとして WP 側に返す。
     *
     * @param bool $conditionalOnNoSearch true の場合、<SEARCH>タグを出力しない場合のみ
     *                                    <APPOINTMENT>を出力する条件付き指示（第1段階用）。
     *                                    false の場合、無条件に判定する指示（第2段階用）。
     * @return string プロンプトに追加する予約シグナルセクション
     */
    private function buildAppointmentSection(bool $conditionalOnNoSearch = false): string
    {
        $section = '【面談予約シグナルの出力】

会話の中で、以下の条件がすべて揃ったと判断した場合、応答テキストの末尾に <APPOINTMENT></APPOINTMENT> タグを出力してください。このタグはユーザーには表示されず、システムが面談予約の候補日時を提示するトリガーとして使用します。

出力条件：
- ユーザーの相談内容がある程度明確になっている（分野・論点が把握できている）
- ユーザーが弁護士との面談・相談を希望している、または面談を提案するのが自然な流れである
- まだ面談予約の話題に入っていない（同一会話で既に予約提案済みなら再出力しない）

出力形式：
応答テキストの中で面談を提案する文章を自然な日本語で書いた上で、末尾に以下を出力してください。

<APPOINTMENT></APPOINTMENT>

注意：面談の具体的な日時の候補をあなたが提示する必要はありません。システムがカレンダーの空き状況を確認して候補を自動提示します。あなたは「面談のご予約をお取りしましょうか」のような提案の文章だけを書いてください。';

        if ($conditionalOnNoSearch) {
            $section .= '

注意: <SEARCH>タグを出力する場合は、<APPOINTMENT>タグを出力しないでください。面談予約のシグナルは検索結果を踏まえた第2段階の応答で判定されます。';
        }

        return $section;
    }

    // ←← フェーズ6 Step6-1: 面談事前メモ用プロンプトを追加 ←←

    /**
     * 面談事前メモ用システムプロンプトを返す
     *
     * 承認時に WP から渡されるセッションデータ（会話本文・分析結果・カルテ情報）を
     * もとに、弁護士が面談前に把握すべき要点を整理するメモを生成する指示。
     * buildReportPrompt() と同型で PromptStructure を返す。
     *
     * セッションの実データはこのプロンプトには含めない。
     * AppointmentService がユーザーメッセージとして渡す。
     */
    public function buildAppointmentMemoPrompt(): PromptStructure
    {
        $ps = new PromptStructure();

        $ps->addStaticSystem($this->buildMemoRoleSection());
        $ps->addStaticSystem($this->buildMemoInstructionSection());

        return $ps;
    }

    /**
     * メモ用 役割宣言
     */
    private function buildMemoRoleSection(): string
    {
        return 'あなたは法律事務所のアシスタントです。弁護士が初回面談に臨む前に目を通す「事前メモ」を作成する役割を担っています。AIチャットでの相談記録を渡されるので、弁護士が短時間で相談の全体像を把握できるメモにまとめてください。';
    }

    /**
     * メモ用 出力内容と形式の指示
     */
    private function buildMemoInstructionSection(): string
    {
        return '【事前メモの作成指示】

渡された相談記録だけを根拠にメモを作成してください。記録に書かれていない情報を推測したり補完したりしてはいけません。

メモには以下の5項目を含めてください。

1. 相談概要（どんな相談か、1〜2文で）
2. 把握済みの事実（相談者から聞き取れている情報を箇条書きで）
3. 不足している情報（面談で確認すべき事項を箇条書きで）
4. 相談者に持参を依頼すべき資料（契約書・通知書・証拠写真など、記録から推測できる範囲で）
5. 緊急度と根拠（高/中/低と、その判断理由を1文で）

【絶対禁止事項】

- 法的見立て（受任可否・勝算・請求額の見通し等）は一切書かないでください。法的判断は弁護士が面談で行うものです。
- <SEARCH>や<ANALYSIS>などのタグは出力せず、メモ本文だけを返してください。

文章は、弁護士に向けた簡潔な敬語で書いてください。';
    }

}


