<?php

namespace Biigle\Modules\AskBiigle\Http\Controllers;

use Biigle\Http\Controllers\Views\Controller;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChatController extends Controller
{
    /**
     * Number of attempts for the upstream request.
     *
     * @var int
     */
    const MAX_TRIES = 3;

    /**
     * Forward a user message to the configured OpenAI-compatible backend.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     * @throws ValidationException
     * @throws RuntimeException
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

        // Guzzle only streams the response body if it uses the PHP stream handler,
        // which in turn requires allow_url_fopen. Its default cURL handler buffers the
        // whole body before it returns, which would defeat the purpose of this
        // endpoint. Also, the timeout of the cURL handler applies to the whole transfer
        // instead of a single read, so long answers would be truncated.
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('AskBiigle cannot stream the chat response because allow_url_fopen is disabled.');
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
            'stream' => true,
        ];

        $arcanaId = config('ask-biigle.llm_arcana_id');
        if (!empty($arcanaId)) {
            $payload['arcana'] = ['id' => $arcanaId];
        }

        $timeout = (int) config('ask-biigle.llm_timeout', 120);

        try {
            $response = $this->sendUpstreamRequest($apiUrl, $apiKey, $payload, $timeout);
        } catch (ConnectionException $e) {
            Log::warning('AskBiigle upstream connection failed: '.$e->getMessage());

            return response()->json([
                'message' => 'The AI service connection timed out. Please click Retry to try again.',
                'details' => [
                    'type' => 'error',
                    'status' => 504,
                    'message' => 'ReadTimeout',
                ],
            ], 504);
        } catch (\Throwable $e) {
            Log::error('AskBiigle request failed: '.$e->getMessage());

            return response()->json([
                'message' => 'AskBiigle request failed. Please click Retry to try again.',
                'details' => [
                    'type' => 'error',
                    'status' => 500,
                ],
            ], 500);
        }

        if (!$response->successful()) {
            return $this->upstreamErrorResponse($response);
        }

        return $this->streamAssistantResponse($response);
    }

    /**
     * Perform the upstream request, retrying transient failures.
     *
     * The request is made before any response is returned to the browser so
     * that failures which occur before the first token can still be reported
     * with a proper HTTP status code instead of a half-open event stream.
     *
     * @param string $apiUrl
     * @param string $apiKey
     * @param array $payload
     * @param int $timeout
     * @return ClientResponse
     * @throws ConnectionException
     */
    protected function sendUpstreamRequest($apiUrl, $apiKey, array $payload, $timeout)
    {
        $response = null;

        for ($attempt = 1; $attempt <= self::MAX_TRIES; $attempt++) {
            try {
                $response = $this->newUpstreamRequest($apiKey, $timeout)
                    ->post($apiUrl, $payload);
            } catch (ConnectionException $e) {
                if ($attempt < self::MAX_TRIES) {
                    Sleep::for(1)->second();
                    continue;
                }

                throw $e;
            }

            if ($response->successful() || !$this->shouldRetry($response)) {
                return $response;
            }

            if ($attempt < self::MAX_TRIES) {
                Sleep::for(1)->second();
            }
        }

        return $response;
    }

    /**
     * Configure a request to the upstream chat completion endpoint.
     *
     * @param string $apiKey
     * @param int $timeout
     * @return PendingRequest
     */
    protected function newUpstreamRequest($apiKey, $timeout)
    {
        return Http::timeout($timeout)
            ->withToken($apiKey)
            ->withHeaders([
                'inference-service' => config('ask-biigle.llm_inference_service'),
                'Accept' => 'text/event-stream',
            ])
            ->withOptions(['stream' => true])
            ->setHandler(new StreamHandler);
    }

    /**
     * Determine if an unsuccessful upstream response should be retried.
     *
     * @param ClientResponse $response
     * @return bool
     */
    protected function shouldRetry(ClientResponse $response)
    {
        if ($response->status() >= 500) {
            return true;
        }

        return $this->upstreamErrorMessage($response) === 'ReadTimeout';
    }

    /**
     * Build the response for an unsuccessful upstream request.
     *
     * @param ClientResponse $response
     * @return \Illuminate\Http\JsonResponse
     */
    protected function upstreamErrorResponse(ClientResponse $response)
    {
        $errMsg = $this->upstreamErrorMessage($response);

        $message = 'AskBiigle request failed.';
        if ($errMsg === 'ReadTimeout') {
            $message = 'The upstream AI service (AcademicCloud) encountered a timeout. Please click Retry to try again.';
        } elseif ($errMsg) {
            $message = 'AskBiigle request failed: '.$errMsg;
        }

        $status = $response->status();
        if ($status < 400 || $status >= 600) {
            $status = 500;
        }

        return response()->json([
            'message' => $message,
            'details' => $response->json(),
        ], $status);
    }

    /**
     * Extract the error message of an unsuccessful upstream response.
     *
     * @param ClientResponse $response
     * @return string|null
     */
    protected function upstreamErrorMessage(ClientResponse $response)
    {
        $details = $response->json();

        $message = data_get($details, 'message')
            ?: data_get($details, 'details.message')
            ?: data_get($details, 'error.message');

        return is_string($message) ? $message : null;
    }

    /**
     * Relay the upstream event stream to the browser.
     *
     * Upstream chunks are normalized so the browser only has to handle the
     * events of this endpoint. Tokens are collected and sent line by line, as one
     * event per token would add a lot of overhead. The complete answer is cleaned
     * once the stream is finished, which is also where the retrieval sources are
     * extracted.
     *
     * @param ClientResponse $response
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function streamAssistantResponse(ClientResponse $response)
    {
        return response()->stream(function () use ($response) {
            // Send each frame as soon as it is produced. Output buffers that
            // belong to the caller (e.g. the test harness) are flushed but must
            // not be closed here.
            ob_implicit_flush(true);

            $body = $response->toPsrResponse()->getBody();
            $content = '';
            $pending = '';

            try {
                while (!$body->eof() && !connection_aborted()) {
                    $line = rtrim(Utils::readLine($body), "\r\n");
                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }

                    $delta = $this->extractDelta(json_decode($data, true));
                    if ($delta === '') {
                        continue;
                    }

                    $content .= $delta;
                    $pending .= $delta;

                    // Send everything up to the last complete line and keep the
                    // rest until the line is finished.
                    $lineBreak = strrpos($pending, "\n");
                    if ($lineBreak !== false) {
                        $this->sendEvent([
                            'type' => 'delta',
                            'content' => substr($pending, 0, $lineBreak + 1),
                        ]);
                        $pending = substr($pending, $lineBreak + 1);
                    }
                }

                if ($pending !== '') {
                    $this->sendEvent(['type' => 'delta', 'content' => $pending]);
                }

                $content = $this->normalizeNewlines($content);

                $this->sendEvent([
                    'type' => 'done',
                    'assistant' => $this->cleanAssistantContent($content),
                    'sources' => $this->extractSources($content),
                ]);
            } catch (\Throwable $e) {
                Log::error('AskBiigle stream failed: '.$e->getMessage());
                $this->sendEvent([
                    'type' => 'error',
                    'message' => 'The AI service encountered an issue. Please click Retry to try again.',
                ]);
            } finally {
                $body->close();
            }

            echo "data: [DONE]\n\n";
            $this->flushOutput();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Send a single server sent event.
     *
     * @param array $event
     */
    protected function sendEvent(array $event)
    {
        echo 'data: '.json_encode($event)."\n\n";
        $this->flushOutput();
    }

    /**
     * Push the buffered output to the browser.
     */
    protected function flushOutput()
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    /**
     * Extract the new content of a single upstream chunk.
     *
     * @param mixed $event
     * @return string
     */
    protected function extractDelta($event)
    {
        if (!is_array($event)) {
            return '';
        }

        $content = data_get($event, 'choices.0.delta.content');
        if (is_null($content)) {
            $content = data_get($event, 'choices.0.message.content');
        }

        return $this->flattenContent($content);
    }

    /**
     * Reduce structured message content to plain text.
     *
     * @param mixed $content
     * @return string
     */
    protected function flattenContent($content)
    {
        if (is_array($content)) {
            $content = collect($content)
                ->where('type', 'text')
                ->pluck('text')
                ->implode("\n");
        }

        return (string) ($content ?? '');
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
