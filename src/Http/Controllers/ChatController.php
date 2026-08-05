<?php

namespace Biigle\Modules\AskBiigle\Http\Controllers;

use Biigle\Http\Controllers\Views\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    /**
     * Forward a user message to the configured OpenAI-compatible backend.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function chat(Request $request)
    {
        $validated = $this->validate($request, [
            'message' => 'required|string',
            'history' => 'sometimes|array|max:20',
            'history.*.role' => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
        ]);

        $apiKey = config('ask-biigle.llm_api_key');
        $model = config('ask-biigle.llm_algorithm');
        $apiUrl = config('ask-biigle.llm_api_url');
        if (empty($apiKey) || empty($model) || empty($apiUrl)) {
            throw ValidationException::withMessages([
                'message' => ['AskBiigle is not configured. Please set ASK_BIIGLE_LLM_API_URL, ASK_BIIGLE_LLM_API_KEY and ASK_BIIGLE_LLM_ALGORITHM.'],
            ]);
        }

        $messages = [
            [
                'role' => 'system',
                'content' => trim(config('ask-biigle.llm_system_prompt')."\n\nUse Markdown for formatting. Do not output retrieval references, source dumps, or markers like [RREF1]."),
            ],
        ];

        foreach (Arr::get($validated, 'history', []) as $item) {
            $messages[] = [
                'role' => $item['role'],
                'content' => $item['content'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $validated['message'],
        ];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'enable-tools' => (bool) config('ask-biigle.llm_enable_tools'),
            'temperature' => (float) config('ask-biigle.llm_temperature'),
            'top_p' => (float) config('ask-biigle.llm_top_p'),
        ];

        $arcanaId = config('ask-biigle.llm_arcana_id');
        if (!empty($arcanaId)) {
            $payload['arcana'] = ['id' => $arcanaId];
        }

        $maxTries = 3;
        $timeout = (int) config('ask-biigle.llm_timeout', 120);
        $response = null;

        for ($attempt = 1; $attempt <= $maxTries; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->timeout($timeout)
                    ->withToken($apiKey)
                    ->withHeaders([
                        'inference-service' => config('ask-biigle.llm_inference_service'),
                    ])
                    ->post($apiUrl, $payload);

                if ($response->successful()) {
                    break;
                }

                $details = $response->json();
                $errMsg = data_get($details, 'message') ?: data_get($details, 'details.message');

                if ($attempt < $maxTries && ($response->status() >= 500 || $errMsg === 'ReadTimeout')) {
                    usleep(1000000); // 1s delay before retry
                    continue;
                }
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($attempt < $maxTries) {
                    usleep(1000000);
                    continue;
                }

                return response()->json([
                    'message' => 'The AI service connection timed out. Please click Retry to try again.',
                    'details' => [
                        'type' => 'error',
                        'status' => 504,
                        'message' => 'ReadTimeout',
                    ],
                ], 504);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'AskBiigle request failed: '.$e->getMessage(),
                    'details' => [
                        'type' => 'error',
                        'status' => 500,
                        'message' => $e->getMessage(),
                    ],
                ], 500);
            }
        }

        if (!$response || !$response->successful()) {
            $details = $response ? $response->json() : null;
            $errMsg = data_get($details, 'message') ?: data_get($details, 'details.message');

            $message = 'AskBiigle request failed.';
            if ($errMsg === 'ReadTimeout') {
                $message = 'The upstream AI service (AcademicCloud) encountered a timeout. Please click Retry to try again.';
            } elseif ($errMsg) {
                $message = 'AskBiigle request failed: '.$errMsg;
            }

            return response()->json([
                'message' => $message,
                'details' => $details,
            ], $response ? ($response->status() >= 400 && $response->status() < 600 ? $response->status() : 500) : 500);
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (is_array($content)) {
            $content = collect($content)
                ->where('type', 'text')
                ->pluck('text')
                ->implode("\n");
        }

        $rawContent = $this->normalizeNewlines((string) ($content ?? ''));

        return response()->json([
            'assistant' => $this->cleanAssistantContent($rawContent),
            'sources' => $this->extractSources($rawContent),
        ]);
    }

    /**
     * Unescape newlines of responses that arrive escaped from the upstream service.
     *
     * Some responses contain literal "\n" sequences instead of actual newlines. Content
     * that has actual newlines is left untouched, as literal "\n" sequences may be part
     * of the content there (e.g. in a code block).
     *
     * @param string $content
     * @return string
     */
    protected function normalizeNewlines($content)
    {
        if (!str_contains($content, "\n") && str_contains($content, '\n')) {
            return str_replace('\n', "\n", $content);
        }

        return $content;
    }

    /**
     * Remove retrieval/source artifacts from the assistant response.
     *
     * @param string $content
     * @return string
     */
    protected function cleanAssistantContent($content)
    {
        $cleaned = str_replace("\r\n", "\n", $content);
        $cleaned = preg_replace('/\n?-{3,}\s*References?:[\s\S]*$/i', '', $cleaned);
        $cleaned = preg_replace('/\s*References?:\s*\[[A-Z]*REF\d+\][\s\S]*$/i', '', $cleaned);
        $cleaned = preg_replace('/\s*\[(?:RREF|REF)\d+\]/i', '', $cleaned);
        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned);

        return trim($cleaned);
    }

    /**
     * Extract retrieval sources from a reference section.
     *
     * @param string $content
     * @return array
     */
    protected function extractSources($content)
    {
        $normalized = str_replace("\r\n", "\n", $content);
        if (!preg_match('/References?:\s*(\[(?:RREF|REF)\d+\][\s\S]*)$/i', $normalized, $matches)) {
            return [];
        }

        $referenceSection = $matches[1];
        preg_match_all('/\[((?:RREF|REF)\d+)\]\s*([\s\S]*?)(?=\[(?:RREF|REF)\d+\]|$)/i', $referenceSection, $chunks, PREG_SET_ORDER);

        $sources = [];
        foreach ($chunks as $chunk) {
            $id = strtoupper($chunk[1]);
            $entry = trim($chunk[2]);
            if ($entry === '') {
                continue;
            }

            $title = 'Source';
            $score = null;
            $snippet = $entry;

            if (preg_match('/^([^\n(]+?)\s*\(([\d.]+)\)\s*([\s\S]*)$/', $entry, $parts)) {
                $title = trim($parts[1]);
                $score = (float) $parts[2];
                $snippet = trim($parts[3]);
            }

            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($snippet)));
            if (strlen($snippet) > 220) {
                $snippet = substr($snippet, 0, 220).'...';
            }

            $sources[] = [
                'id' => $id,
                'title' => $title,
                'score' => $score,
                'snippet' => $snippet,
            ];

            if (count($sources) >= 10) {
                break;
            }
        }

        return $sources;
    }
}
