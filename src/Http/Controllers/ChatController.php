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
            'history' => 'sometimes|array',
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
                'content' => config('biiglebot.llm_system_prompt'),
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

        return response()->json([
            'assistant' => (string) ($content ?? ''),
        ]);
    }
}
