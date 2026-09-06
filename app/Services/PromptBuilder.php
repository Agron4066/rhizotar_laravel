<?php
namespace App\Services;

use App\Services\PromptStructure;

class PromptBuilder
{
    /**
     * 第1段階用システムプロンプトを返す
     * （訪問者の発言 → 検索条件JSON生成のための指示）
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
     * という指示（知財）だけを組み立てる。
     */
    public function buildReportPrompt(): PromptStructure
    {
        $ps = new PromptStructure();

        $ps->addStaticSystem($this->buildReportRoleSection());
        $ps->addStaticSystem($this->buildReportInstructionSection());

        return $ps;
    }

    /**
     * デモ事前メモ用システムプロンプトを返す
     *
     * 訪問者との会話記録をもとに、営業担当者が初回打ち合わせ前に
     * 把握すべき見込み客情報を整理するメモを生成する指示。
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

    // =========================================================================
    // 第1段階用 private メソッド
    // =========================================================================

    /**
     * セクション1: 役割宣言を生成（第1段階用）
     */
    private function buildRoleSection(): string
    {
        return 'あなたはKumitoruのサービス案内を担当するAIエージェントです。Kumitoruの公式サイトに設置されたチャットとして、訪問者の発言を聞いて、回答に必要な知識を検索する役割を担っています。

Kumitoruは、AIを信じすぎない「制御型AIエージェント」サービスです。人の曖昧な言葉を理解し、管理された情報と業務処理へつなぐ仕組みを、業種・業務に合わせて個別に設計・構築します。

検索意図の判断基準：訪問者がKumitoruのサービス内容、機能、料金、導入方法、技術的な仕組み、セキュリティ、他サービスとの違いなどについて「知りたい」「聞きたい」という意思を示している場合、検索意図ありと判断してください。挨拶・お礼・雑談など、知識ベースの検索を要しない発言には検索意図はありません。';
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

訪問者の発言から検索条件を読み取り、まずは確認の文章を訪問者に返した後、以下の形式で検索条件を出力してください。

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

複数のフィールドを組み合わせた複合条件も可能です。たとえば exact と like を組み合わせる場合：
<SEARCH target="該当するtarget_id">{"field_a":"値", "field_b":["キーワード1", "キーワード2"]}</SEARCH>';
    }

    /**
     * セクション4: 振る舞い指針を生成（第1段階用）
     */
    private function buildBehaviorSection(): string
    {
        return '振る舞い指針：

1. 検索意図がない発言（挨拶・お礼・雑談など）には、<SEARCH>タグを出力せず、Kumitoruのサービス案内担当として自然な日本語で応答してください。
2. 訪問者の発言からサービスに関する質問や関心が読み取れる場合は、まずは確認の文章を自然な日本語で返してから、<SEARCH>タグで検索条件を出力してください。
3. 訪問者が1つでも具体的な関心事を提示した場合は、必ず<SEARCH>タグを出力してください。条件が少ないことを理由に検索を省略してはいけません。追加の質問は、検索結果を返した後に行ってください。
4. 検索条件が一切読み取れない場合（例：「何ができるんですか？」のように漠然としている場合）のみ、<SEARCH>タグを出力せず、どのような点に関心があるかを尋ねてください。
5. target一覧にない検索対象が話題になった場合は、<SEARCH>タグを出力せず、対応できない旨を丁寧に伝えてください。
6. 訪問者の要望が専用フィールドに該当しない場合でも、「対応していません」と回答してはならない。部分一致（like）検索が設定されているフィールドがあれば、それをキーワード検索のフォールバックとして使い、必ず検索を実行すること。
7. 部分一致（like）検索を使う場合は、値を3〜10要素のJSON配列で指定すること。各パターンは1〜2語の短いキーワードにすること（長いフレーズは避ける）。訪問者の発言からコアとなる単語を抽出し、その同義語・類義語・DB上で使われそうな専門用語・略語の3〜10パターンを含めること。具体的で表記揺れが少ないキーワードはパターン数を少なく、抽象的で多様な表現がありうるキーワードはパターン数を多くすること。汎用的すぎるキーワードは避け、検索意図に特有のキーワードを優先すること。
8. 丁寧で親しみやすい敬語で応答してください。「ございます」「いたします」などの過度に堅苦しい表現は避け、「です」「ます」を基本とした自然な丁寧語を使ってください。サービスの売り込みにならないよう、訪問者の関心に応じた情報提供を心がけてください。';
    }

    /**
     * 検索対象一覧から response_style を抽出する（第1段階用）
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
     * 検索対象一覧から analysis_guide を抽出する（第1段階用）
     *
     * @param array $availableSearchTargets
     * @return string
     */
    private function extractAnalysisGuide(array $availableSearchTargets): string
    {
        return '';
    }

    // =========================================================================
    // 第2段階用 private メソッド
    // =========================================================================

    /**
     * 第2段階セクション1: 役割宣言を生成
     */
    private function buildSecondRoleSection(): string
    {
        return 'あなたはKumitoruのサービス案内を担当するAIエージェントです。知識ベースの検索結果を踏まえて、訪問者に最終回答を生成する役割を担っています。';
    }

    /**
     * 第2段階セクション2: 絶対禁止事項を生成（ハルシネーション抑制）
     */
    private function buildSecondAbsoluteRulesSection(): string
    {
        return '【絶対禁止事項】

1. 検索結果に明示的に含まれている情報のみを使用してください。検索結果に書かれていない情報（機能、料金、対応範囲、技術仕様など）を推測したり補完したりしてはいけません。情報が不足している場合は「その点についてはお問い合わせください」と案内してください。
2. 検索は既に完了しています。回答内に<SEARCH>タグや検索条件のJSONを含めてはいけません。検索結果を踏まえた最終回答だけを、自然な日本語で生成してください。
3. 検索結果に「回答上の制約」が含まれている場合は、その制約に必ず従ってください。制約に反する表現は絶対に使用してはいけません。
4. 未確定の料金、未実装の機能、未対応の連携先について、確定しているかのような案内をしてはいけません。';
    }

    /**
     * 第2段階セクション3: 振る舞い指針を生成
     */
    private function buildSecondBehaviorSection(): string
    {
        return '【振る舞い指針】

1. 検索結果が0件の場合でも、必ず訪問者向けの最終回答を返してください。「お調べします」「お探しします」「より広い範囲で検索します」のような中間的な発言だけで回答を終えてはいけません。再検索を試みる発言も禁止です。検索は既に完了しています。0件の場合は、該当する登録情報がなかったことを簡潔に伝えたうえで、会話の文脈から回答できる範囲があればその範囲だけ説明し、必要に応じて追加の質問を1つ返して会話を継続してください。検索結果に存在しない情報を作り出すことは絶対に禁止です。
2. 検索結果が複数件ある場合は、訪問者の質問に最も関連する情報を中心にまとめてください。情報の羅列ではなく、訪問者の関心に沿った説明を心がけてください。
3. 丁寧で親しみやすい敬語で回答してください。「ございます」「いたします」などの過度に堅苦しい表現は避け、「です」「ます」を基本とした自然な丁寧語を使ってください。
4. 訪問者の関心が具体的で、導入を検討している様子がある場合は、回答の最後にデモや相談の打ち合わせを自然な形で案内してください。ただし、毎回の回答で繰り返さないでください。
5. 他社サービスや競合製品を名指しで否定しないでください。比較を求められた場合は、比較軸を提示し、訪問者が自分の基準で判断できるようにしてください。
6. 「すべてにおいて優れている」「どんな業種でも使える」「絶対に間違わない」のような断定的・過大な表現は使わないでください。';
    }

    // =========================================================================
    // 共通 private メソッド
    // =========================================================================

    /**
     * フォローアップ情報出力セクションを構築する（第1段階・第2段階共通）
     *
     * @param bool $conditionalOnNoSearch true の場合、<SEARCH>タグを出力しない場合のみ
     *                                    <FOLLOW_UP>を出力する条件付き指示（第1段階用）。
     *                                    false の場合、無条件に出力する指示（第2段階用）。
     * @return string プロンプトに追加するフォローアップセクション
     */
    private function buildFollowUpSection(bool $conditionalOnNoSearch = false): string
    {
        $section = '【フォローアップ情報の出力】

応答テキストの末尾に、以下の形式でフォローアップ情報を出力してください。このタグは訪問者には表示されず、システムが顧客管理に使用します。

<FOLLOW_UP>{"timing":"フォローすべき時期","content":"フォローの具体的な内容","priority":優先度}</FOLLOW_UP>

各フィールドの判断基準：
- timing: 会話の緊急度に応じたフォロー時期。具体的な導入検討中であれば「翌日」「2日後」、情報収集段階なら「1週間後」など。
- content: この訪問者に対して次に行うべき具体的なアクション。未回答の質問への補足案内、デモの提案、事例の送付など。
- priority: 0.0（低）〜 1.0（高）。具体的な課題や業種が明確で導入意欲が高い訪問者ほど高く設定。';

        if ($conditionalOnNoSearch) {
            $section .= '

注意: <SEARCH>タグを出力する場合は、<FOLLOW_UP>タグを出力しないでください。フォローアップ情報は検索結果を踏まえた第2段階の応答で生成されます。';
        }

        return $section;
    }

    /**
     * 分析セクションを構築する（第1段階・第2段階共通）
     *
     * @param string $analysisGuide WP管理画面から注入される分析指針
     * @param string $temperatureCriteria WP管理画面から注入される温度感の判定基準
     * @return string プロンプトに追加する分析セクション
     */
    private function buildAnalysisSection(string $analysisGuide, string $temperatureCriteria = ''): string
    {
        $guideText = !empty($analysisGuide)
            ? "\n\n【業界固有の分析指針】\n" . $analysisGuide
            : '';

        $criteriaText = !empty($temperatureCriteria)
            ? "\n\n【温度感の判定基準】\n" . $temperatureCriteria
            : "\n\n【温度感の判定基準】\n"
            . "- 高: 具体的な業種・課題が明確で、導入時期や予算にも言及がある。デモや打ち合わせを希望している。\n"
            . "- 中: サービスに関心があり質問を重ねているが、導入の具体的な検討には至っていない。業種や課題の一部が見えている。\n"
            . "- 低: 一般的な情報収集、競合比較の一環、または単なる興味での訪問。具体的な課題の言及がない。\n"
            . "- 未判定: 挨拶や単発の質問のみで、関心の度合いを判断する材料が不足している。";

        return "\n\n<analysis_rules>\n"
            . "【会話分析結果の出力】\n"
            . "応答テキストの末尾に、以下の形式で会話分析結果を必ず出力してください。このタグは訪問者には表示されず、システムが顧客管理に使用します。\n\n"
            . "<ANALYSIS>{\"summary\":\"会話の要約\",\"acquired_info\":[\"取得できた情報1\",\"取得できた情報2\"],\"missing_info\":[\"まだ聞けていない情報1\"],\"temperature\":\"高|中|低|未判定\",\"temperature_reason\":\"温度感をそう判定した理由\"}</ANALYSIS>\n\n"
            . "各フィールドの判断基準：\n"
            . "- summary: 会話全体を1〜2文で要約したもの。\n"
            . "- acquired_info: 会話から取得できた訪問者の情報（業種、課題、関心のある機能、導入規模など）を配列で列挙。\n"
            . "- missing_info: 見込み客としての評価に必要だが、まだ聞けていない情報（業種、課題の具体性、導入時期、予算感など）を配列で列挙。\n"
            . "- temperature: 訪問者の導入検討の具体度。\n"
            . "- temperature_reason: temperatureをその値に判定した根拠を会話の具体的な内容に触れて1〜2文で説明。\n"
            . $guideText . "\n\n"
            . $criteriaText . "\n\n"
            . "上記ルールに違反した回答は不合格です。\n"
            . "</analysis_rules>";
    }

    /**
     * デモ・相談予約シグナル出力セクションを構築する（第1段階・第2段階共通）
     *
     * @param bool $conditionalOnNoSearch true の場合、<SEARCH>タグを出力しない場合のみ
     *                                    <APPOINTMENT>を出力する条件付き指示（第1段階用）。
     *                                    false の場合、無条件に判定する指示（第2段階用）。
     * @return string プロンプトに追加する予約シグナルセクション
     */
    private function buildAppointmentSection(bool $conditionalOnNoSearch = false): string
    {
        $section = '【デモ・相談予約シグナルの出力】

会話の中で、以下の条件がすべて揃ったと判断した場合、応答テキストの末尾に <APPOINTMENT></APPOINTMENT> タグを出力してください。このタグは訪問者には表示されず、システムがデモ・相談の予約候補日時を提示するトリガーとして使用します。

出力条件：
- 訪問者の業種や課題がある程度明確になっている
- 訪問者がデモの体験や相談・打ち合わせを希望している、または提案するのが自然な流れである
- まだデモ・相談予約の話題に入っていない（同一会話で既に予約提案済みなら再出力しない）

出力形式：
応答テキストの中でデモや相談を提案する文章を自然な日本語で書いた上で、末尾に以下を出力してください。

<APPOINTMENT></APPOINTMENT>

注意：デモや打ち合わせの具体的な日時の候補をあなたが提示する必要はありません。システムがカレンダーの空き状況を確認して候補を自動提示します。あなたは「デモをご覧になりますか」「一度お打ち合わせしませんか」のような提案の文章だけを書いてください。';

        if ($conditionalOnNoSearch) {
            $section .= '

注意: <SEARCH>タグを出力する場合は、<APPOINTMENT>タグを出力しないでください。デモ・相談予約のシグナルは検索結果を踏まえた第2段階の応答で判定されます。';
        }

        return $section;
    }

    // =========================================================================
    // 日次レポート用 private メソッド
    // =========================================================================

    /**
     * レポート用 役割宣言
     */
    private function buildReportRoleSection(): string
    {
        return 'あなたはKumitoruの営業担当者に向けて、日次の問い合わせレポートを書くアシスタントです。前日にKumitoruのサービスサイトに寄せられた複数の問い合わせ（セッション）の記録を渡されます。それらを読み、営業担当者が「昨日どんな問い合わせがあったか」を短時間で把握できる日本語のレポートにまとめる役割を担っています。';
    }

    /**
     * レポート用 出力内容と形式の指示
     */
    private function buildReportInstructionSection(): string
    {
        return '【レポートの作成指示】

渡されたセッション記録だけを根拠にレポートを作成してください。記録に書かれていない情報を推測したり、件数や傾向を創作したりしてはいけません。

レポートには以下を含めてください。

1. 昨日の問い合わせ全体の概況（おおまかな件数感と、目立った傾向を1〜2文で）。
2. 主だった問い合わせの内容を、セッションごとに簡潔にまとめる（訪問者の業種・課題・関心のあった機能を中心に、温度感が分かるものはあわせて）。
3. 営業担当者が次に動くと良さそうなポイント（温度感が高い訪問者へのフォロー、デモの提案、共通して多かった質問への対策など、記録から読み取れる範囲で）。

文章は、営業担当者に語りかける自然な日本語の敬語で書いてください。「です」「ます」を基本とし、過度に堅苦しい表現は避けてください。<SEARCH>や<ANALYSIS>などのタグは出力せず、レポート本文だけを返してください。';
    }

    // =========================================================================
    // デモ事前メモ用 private メソッド
    // =========================================================================

    /**
     * メモ用 役割宣言
     */
    private function buildMemoRoleSection(): string
    {
        return 'あなたはKumitoruの営業アシスタントです。営業担当者が見込み客との初回打ち合わせに臨む前に目を通す「事前メモ」を作成する役割を担っています。サービスサイトでのチャット記録を渡されるので、営業担当者が短時間で見込み客の全体像を把握できるメモにまとめてください。';
    }

    /**
     * メモ用 出力内容と形式の指示
     */
    private function buildMemoInstructionSection(): string
    {
        return '【事前メモの作成指示】

渡されたチャット記録だけを根拠にメモを作成してください。記録に書かれていない情報を推測したり補完したりしてはいけません。

メモには以下の5項目を含めてください。

1. 見込み客の概要（どんな業種・立場の人か、何に関心を持っているか、1〜2文で）
2. 把握済みの情報（チャットから読み取れた業種、課題、希望する機能、導入規模、時期感などを箇条書きで）
3. 不足している情報（打ち合わせで確認すべき事項を箇条書きで）
4. 打ち合わせで準備すると良いもの（関連するデモ画面、業種別の事例、見積もりの前提条件など、記録から推測できる範囲で）
5. 温度感と根拠（高/中/低と、その判断理由を1文で）

【絶対禁止事項】

- 具体的な料金や見積もり金額を書いてはいけません。料金は打ち合わせで個別に提示するものです。
- 実装していない機能を「対応可能」と書いてはいけません。
- <SEARCH>や<ANALYSIS>などのタグは出力せず、メモ本文だけを返してください。

文章は、営業担当者に向けた簡潔な敬語で書いてください。';
    }
}
