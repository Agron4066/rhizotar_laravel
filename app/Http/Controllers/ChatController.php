<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\ClaudeClient;
use App\Services\PromptBuilder;
use App\Services\Handlers\SearchHandler;
use App\Services\Handlers\CustomerHandler;
use App\Services\Handlers\AnalysisHandler;
use App\Services\Handlers\AppointmentHandler;
use App\Services\Handlers\Exceptions\SessionNotFoundException;

class ChatController extends Controller
{

    // ←← Phase10 Step6で追加：コンストラクタインジェクション ←←
    // ←← Phase13 ステップ4で SearchHandler を追加 ←←
    // ←← 機能2 Step 6 で ClaudeClient を追加 ←←
    public function __construct(
        private PromptBuilder $promptBuilder,
        private SearchHandler $searchHandler,
        private ClaudeClient $claude,
        private CustomerHandler $customerHandler,
        private AnalysisHandler $analysisHandler,
        private AppointmentHandler $appointmentHandler,
    ) {
    }
    // ←← Phase10 Step6 追加ここまで ←←

    public function stream(Request $request): StreamedResponse
    {
        $request->validate([
            'message'                  => 'required|string|max:4000',
            'session_id'               => 'required|string',
            'messages'                 => 'array',
            'available_search_targets' => 'present|array',
            'temperature_criteria' => 'nullable|string',
            'identification_type'  => 'nullable|string|in:web,twitter,instagram',
            'identification_value' => 'nullable|string|max:255',
        ]);

        $message                = $request->input('message');
        $sessionId              = $request->input('session_id');
        $pastMessages           = $request->input('messages', []);
        $availableSearchTargets = $request->input('available_search_targets');
        $identificationType     = $request->input('identification_type');
        $identificationValue    = $request->input('identification_value');

        return response()->stream(function () use ($sessionId, $message, $pastMessages, $availableSearchTargets, $identificationType, $identificationValue) {

            // ←← Phase13 ステップ4で変更：SSE発火を共通クロージャに集約 ←←
            $sseCallback = function (array $payload) {
                echo "data: " . json_encode($payload) . "\n\n";
                ob_flush();
                flush();
            };

            $fullResponse = '';

            // ←← 機能2 Step 6 で改修 ここから ←←
            // PromptStructure の組み立て（判断A・M の責務分担に従う）
            $promptStructure = $this->promptBuilder->buildFirstStagePrompt($availableSearchTargets);
            $promptStructure->addStaticMessages($pastMessages);
            $promptStructure->addDynamicMessage(['role' => 'user', 'content' => $message]);
            $built = $promptStructure->build();
            // ←← 改修ここまで ←←

            $this->claude->streamMessage($built['messages'], function (string $chunk) use (&$fullResponse, $sseCallback) {
                $fullResponse .= $chunk;
                $sseCallback(['text' => $chunk]);
            }, $built['system']);

            // ←← Phase13 ステップ4で変更：<SEARCH>検出後の処理を SearchHandler に委譲 ←←
            $this->searchHandler->handleSearchTag(
                $fullResponse,
                $sessionId,
                $built['messages'],    // ←← 機能2 Step 6 で変更：PromptStructure から取り出した messages を渡す ←←
                $sseCallback,
            );

            // SearchHandlerが処理しなかった場合のみ done を出す（通常終了）
            if (!$this->searchHandler->wasProcessed()) {
                $donePayload = ['done' => true];
                $followUp = $this->customerHandler->extractFollowUp($fullResponse);
                if ($followUp !== null) {
                    $donePayload['follow_up'] = $followUp;
                }
                $donePayload['analysis'] = $this->analysisHandler->extractAnalysis($fullResponse);
                if ($this->appointmentHandler->extractAppointmentSignal($fullResponse)) {
                    $donePayload['appointment_request'] = true;
                }
                if ($identificationType !== null) {
                    $donePayload['identification_type']  = $identificationType;
                    $donePayload['identification_value'] = $identificationValue;
                }
                $sseCallback($donePayload);
            }

        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }



    // ←← Phase9 Step3で追加：検索結果を受け取りストリーミングを再開するエンドポイント ←←
    // ←← Phase13 ステップ4で SearchHandler に復元処理を委譲 ←←
    public function continue(Request $request): StreamedResponse
    {
        $request->validate([
            'session_id'     => 'required|string',
            'search_results' => 'present|array',
            'response_style' => 'nullable|string',
            'analysis_guide' => 'nullable|string',
            'identification_type'  => 'nullable|string|in:web,twitter,instagram',
            'identification_value' => 'nullable|string|max:255',
        ]);

        $sessionId     = $request->input('session_id');
        $searchResults = $request->input('search_results');
        $responseStyle = $request->input('response_style') ?? '';
        $analysisGuide = $request->input('analysis_guide') ?? '';
        $temperatureCriteria = $request->input('temperature_criteria') ?? '';
        $identificationType  = $request->input('identification_type');
        $identificationValue = $request->input('identification_value');

        // SearchHandlerに復元処理を委譲(セッションがなければ例外をキャッチして404を返す)
        try {
            $messages = $this->searchHandler->handleSearchResults($sessionId, $searchResults);
        } catch (SessionNotFoundException $e) {
            abort(404, $e->getMessage());
        }

        return response()->stream(function () use ($messages, $responseStyle, $analysisGuide, $temperatureCriteria, $identificationType, $identificationValue) {

            // ←← Phase13 ステップ4で変更:SSE発火を共通クロージャに集約 ←←
            $sseCallback = function (array $payload) {
                echo "data: " . json_encode($payload) . "\n\n";
                ob_flush();
                flush();
            };

            // PromptStructure の組み立て（判断L の責務分担に従う）
            $promptStructure = $this->promptBuilder->buildSecondStagePrompt($responseStyle, $analysisGuide, $temperatureCriteria);
            $promptStructure->addStaticMessages($messages);
            $built = $promptStructure->build();

            $fullResponse = '';

            // Claudeに2回目のストリーミング送信
            $this->claude->streamMessage($built['messages'], function (string $chunk) use (&$fullResponse, $sseCallback) {
                $fullResponse .= $chunk;
                $sseCallback(['text' => $chunk]);
            }, $built['system']);

            $donePayload = ['done' => true];
            $followUp = $this->customerHandler->extractFollowUp($fullResponse);
            if ($followUp !== null) {
                $donePayload['follow_up'] = $followUp;
            }
            $donePayload['analysis'] = $this->analysisHandler->extractAnalysis($fullResponse);
            if ($this->appointmentHandler->extractAppointmentSignal($fullResponse)) {
                $donePayload['appointment_request'] = true;
            }
            if ($identificationType !== null) {
                $donePayload['identification_type']  = $identificationType;
                $donePayload['identification_value'] = $identificationValue;
            }
            $sseCallback($donePayload);

        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // DM等の非ストリーミングチャネル用エンドポイント
    public function respond(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'message'                  => 'required|string|max:4000',
            'session_id'               => 'required|string',
            'messages'                 => 'array',
            'available_search_targets' => 'present|array',
            'temperature_criteria'     => 'nullable|string',
            'identification_type'      => 'nullable|string|in:web,twitter,instagram',
            'identification_value'     => 'nullable|string|max:255',
        ]);

        $message                = $request->input('message');
        $sessionId              = $request->input('session_id');
        $pastMessages           = $request->input('messages', []);
        $availableSearchTargets = $request->input('available_search_targets');
        $identificationType     = $request->input('identification_type');
        $identificationValue    = $request->input('identification_value');

        $promptStructure = $this->promptBuilder->buildFirstStagePrompt($availableSearchTargets);
        $promptStructure->addStaticMessages($pastMessages);
        $promptStructure->addDynamicMessage(['role' => 'user', 'content' => $message]);
        $built = $promptStructure->build();

        $fullResponse = $this->claude->sendMessage($built['messages'], $built['system']);

        $result = [
            'response_text' => $fullResponse,
        ];

        $followUp = $this->customerHandler->extractFollowUp($fullResponse);
        if ($followUp !== null) {
            $result['follow_up'] = $followUp;
        }

        $result['analysis'] = $this->analysisHandler->extractAnalysis($fullResponse);

        if ($this->appointmentHandler->extractAppointmentSignal($fullResponse)) {
            $result['appointment_request'] = true;
        }

        if ($identificationType !== null) {
            $result['identification_type']  = $identificationType;
            $result['identification_value'] = $identificationValue;
        }

        return response()->json($result);
    }

    // ←← LINE検索対応で追加　←←
    /**
     * 非ストリーミングチャネル用の第2段階エンドポイント。
     * continue() の同期版。キャッシュ復元ではなく messages を直接受け取る。
     */
    public function respondContinue(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'messages'             => 'required|array',
            'response_style'       => 'nullable|string',
            'analysis_guide'       => 'nullable|string',
            'temperature_criteria' => 'nullable|string',
        ]);

        $messages            = $request->input('messages');
        $responseStyle       = $request->input('response_style') ?? '';
        $analysisGuide       = $request->input('analysis_guide') ?? '';
        $temperatureCriteria = $request->input('temperature_criteria') ?? '';

        $promptStructure = $this->promptBuilder->buildSecondStagePrompt($responseStyle, $analysisGuide, $temperatureCriteria);
        $promptStructure->addStaticMessages($messages);
        $built = $promptStructure->build();

        $fullResponse = $this->claude->sendMessage($built['messages'], $built['system']);

        $result = [
            'response_text' => $fullResponse,
        ];

        $followUp = $this->customerHandler->extractFollowUp($fullResponse);
        if ($followUp !== null) {
            $result['follow_up'] = $followUp;
        }

        $result['analysis'] = $this->analysisHandler->extractAnalysis($fullResponse);

        if ($this->appointmentHandler->extractAppointmentSignal($fullResponse)) {
            $result['appointment_request'] = true;
        }

        return response()->json($result);
    }

}
