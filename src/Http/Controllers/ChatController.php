<?php

namespace Biigle\Modules\BIIGLEBot\Http\Controllers;

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

        $apiKey = config('biiglebot.llm_api_key');
        $model = config('biiglebot.llm_algorithm');
        $apiUrl = config('biiglebot.llm_api_url');
        if (empty($apiKey) || empty($model) || empty($apiUrl)) {
            throw ValidationException::withMessages([
                'message' => ['BIIGLEBot is not configured. Please set BIIGLEBOT_LLM_API_URL, BIIGLEBOT_LLM_API_KEY and BIIGLEBOT_LLM_ALGORITHM.'],
            ]);
        }

        $messages = [
            [
                'role' => 'system',
                'content' => trim(config('biiglebot.llm_system_prompt')."\n\nUse Markdown for formatting. Do not output retrieval references, source dumps, or markers like [RREF1]."),
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
            'enable-tools' => (bool) config('biiglebot.llm_enable_tools'),
            'temperature' => (float) config('biiglebot.llm_temperature'),
            'top_p' => (float) config('biiglebot.llm_top_p'),
        ];

        $arcanaId = config('biiglebot.llm_arcana_id');
        if (!empty($arcanaId)) {
            $payload['arcana'] = ['id' => $arcanaId];
        }

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->withHeaders([
                'inference-service' => config('biiglebot.llm_inference_service'),
            ])
            ->post($apiUrl, $payload);

        if (!$response->successful()) {
            return response()->json([
                'message' => 'BIIGLEBot request failed.',
                'details' => $response->json(),
            ], $response->status());
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (is_array($content)) {
            $content = collect($content)
                ->where('type', 'text')
                ->pluck('text')
                ->implode("\n");
        }

        $rawContent = (string) ($content ?? '');

        return response()->json([
            'assistant' => $this->cleanAssistantContent($rawContent),
            'sources' => $this->extractSources($rawContent),
        ]);
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
