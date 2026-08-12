<?php

namespace Biigle\Tests\Modules\AskBiigle\Http\Controllers;

use Biigle\Tests\UserTest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use TestCase;

class ChatControllerTest extends TestCase
{
    public function testUnauthenticatedRequest()
    {
        $response = $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello']);

        $this->assertTrue(in_array($response->getStatusCode(), [302, 401]));
    }

    public function testMissingConfiguration()
    {
        $user = UserTest::create();
        $this->be($user);

        config([
            'ask-biigle.llm_api_url' => 'https://chat-ai.academiccloud.de/v1/chat/completions',
            'ask-biigle.llm_api_key' => '',
            'ask-biigle.llm_algorithm' => '',
        ]);

        $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello'])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'AskBiigle is not configured. Please set ASK_BIIGLE_LLM_API_URL, ASK_BIIGLE_LLM_API_KEY and ASK_BIIGLE_LLM_ALGORITHM.',
            ]);
    }

    public function testSuccessfulResponse()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => $this->fakeStream([
                "Answer text.\n\nReferences:\n",
                '[RREF1] source-a.md (0.999) Example source snippet.',
            ]),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', [
            'message' => 'Hello',
            'history' => [
                ['role' => 'assistant', 'content' => 'Previous answer.'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $done = $this->doneEvent($response->streamedContent());

        $this->assertIsString($done['assistant']);
        $this->assertIsArray($done['sources']);

        // The upstream response is always requested as a stream.
        Http::assertSent(fn ($request) => $request['stream'] === true);
    }

    public function testUpstreamFailure()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();
        Sleep::fake();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => Http::response([
                'error' => ['message' => 'upstream failure'],
            ], 500),
        ]);

        $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello'])
            ->assertStatus(500)
            ->assertJsonStructure(['message']);

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function testHistoryTooLong()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        $history = [];
        for ($i = 0; $i < 21; $i++) {
            $history[] = [
                'role' => 'user',
                'content' => "Message {$i}",
            ];
        }

        $this->json('POST', 'ask-biigle/chat', [
            'message' => 'Hello',
            'history' => $history,
        ])->assertStatus(422);
    }

    public function testRagCleaningViaEndpoint()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => $this->fakeStream([
                "Result with marker [RREF1].\n\nReferences:\n",
                "[RREF1] 22.html.md (0.521) First source text.\n",
                '[RREF2] 9.html.md (0.523) Second source text.',
            ]),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello']);
        $response->assertStatus(200);

        $done = $this->doneEvent($response->streamedContent());

        $this->assertStringNotContainsString('[RREF1]', $done['assistant']);
        $this->assertStringNotContainsString('References:', $done['assistant']);

        $this->assertCount(2, $done['sources']);
        $this->assertSame('RREF1', $done['sources'][0]['id']);
    }

    public function testEscapedNewlines()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            // Single quotes, so the content contains literal "\n" sequences.
            'https://chat-ai.academiccloud.de/*' => $this->fakeStream([
                'First line.\nSecond line.\n\nReferences:\n[RREF1] 22.html.md (0.521) First source text.',
            ]),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello']);
        $response->assertStatus(200);

        $done = $this->doneEvent($response->streamedContent());

        $this->assertSame("First line.\nSecond line.", $done['assistant']);
        $this->assertCount(1, $done['sources']);
    }

    public function testEscapedNewlinesKeepsRealNewlines()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => $this->fakeStream([
                "Use the pattern `a\\nb` here.\n\nDone.",
            ]),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello']);
        $response->assertStatus(200);

        $done = $this->doneEvent($response->streamedContent());

        $this->assertSame("Use the pattern `a\\nb` here.\n\nDone.", $done['assistant']);
    }

    public function testStreamedResponse()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => $this->fakeStream([
                'Hello ',
                "world [RREF1] [RREF2].\n",
                "\nReferences:\n[RREF1] doc.md (0.95) Snippet text.\n",
                '[RREF2] other.md (0.51) Second snippet.',
            ]),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello']);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        // Tokens are collected and sent line by line instead of one by one.
        $this->assertStringContainsString('data: {"type":"delta","content":"Hello world [RREF1] [RREF2].\n"}', $content);
        $this->assertStringContainsString('"type":"done"', $content);
        // Every marker is stripped, not just the first one.
        $this->assertStringContainsString('"assistant":"Hello world."', $content);
        $this->assertStringContainsString('"id":"RREF1"', $content);
        $this->assertStringContainsString('"id":"RREF2"', $content);
        $this->assertStringEndsWith("data: [DONE]\n\n", $content);
        $this->assertSame(3, substr_count($content, '"type":"delta"'));
    }

    public function testStreamedResponseUpstreamFailure()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => Http::response([
                'error' => ['message' => 'upstream failure'],
            ], 400),
        ]);

        // Failures that happen before the first token are reported with a
        // proper status code instead of an event stream.
        $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello'])
            ->assertStatus(400)
            ->assertJsonStructure(['message']);
    }

    /**
     * Build a fake upstream event stream from the given content chunks.
     */
    protected function fakeStream(array $deltas)
    {
        $payload = '';
        foreach ($deltas as $delta) {
            $payload .= 'data: '.json_encode([
                'choices' => [['delta' => ['content' => $delta]]],
            ])."\n\n";
        }

        return Http::response($payload."data: [DONE]\n\n", 200, [
            'Content-Type' => 'text/event-stream',
        ]);
    }

    /**
     * Extract the "done" event of a streamed response.
     */
    protected function doneEvent($content)
    {
        foreach (explode("\n", $content) as $line) {
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }

            $event = json_decode(substr($line, 6), true);
            if (is_array($event) && ($event['type'] ?? null) === 'done') {
                return $event;
            }
        }

        $this->fail('The response did not contain a "done" event.');
    }

    protected function configureBot()
    {
        config([
            'ask-biigle.llm_api_url' => 'https://chat-ai.academiccloud.de/v1/chat/completions',
            'ask-biigle.llm_api_key' => 'test-key',
            'ask-biigle.llm_algorithm' => 'test-model',
            'ask-biigle.llm_inference_service' => 'saia-openai-gateway',
            'ask-biigle.llm_arcana_id' => 'arcana-123',
            'ask-biigle.llm_enable_tools' => true,
            'ask-biigle.llm_temperature' => 0.3,
            'ask-biigle.llm_top_p' => 0.9,
            'ask-biigle.llm_system_prompt' => 'You are an assistant.',
        ]);
    }
}
