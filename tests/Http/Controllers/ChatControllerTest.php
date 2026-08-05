<?php

namespace Biigle\Tests\Modules\AskBiigle\Http\Controllers;

use Biigle\Tests\UserTest;
use Illuminate\Support\Facades\Http;
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
            'https://chat-ai.academiccloud.de/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "Answer text.\n\nReferences:\n[RREF1] source-a.md (0.999) Example source snippet."]],
                ],
            ], 200),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', [
            'message' => 'Hello',
            'history' => [
                ['role' => 'assistant', 'content' => 'Previous answer.'],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'assistant',
                'sources',
            ]);

        $this->assertIsString($response->json('assistant'));
        $this->assertIsArray($response->json('sources'));
    }

    public function testUpstreamFailure()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => Http::response([
                'error' => ['message' => 'upstream failure'],
            ], 500),
        ]);

        $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello'])
            ->assertStatus(500)
            ->assertJsonStructure(['message']);
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
            'https://chat-ai.academiccloud.de/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "Result with marker [RREF1].\n\nReferences:\n[RREF1] 22.html.md (0.521) First source text.\n[RREF2] 9.html.md (0.523) Second source text."]],
                ],
            ], 200),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello']);
        $response->assertStatus(200);

        $assistant = $response->json('assistant');
        $this->assertStringNotContainsString('[RREF1]', $assistant);
        $this->assertStringNotContainsString('References:', $assistant);

        $sources = $response->json('sources');
        $this->assertCount(2, $sources);
        $this->assertSame('RREF1', $sources[0]['id']);
    }

    public function testEscapedNewlines()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => Http::response([
                'choices' => [
                    // Single quotes, so the content contains literal "\n" sequences.
                    ['message' => ['content' => 'First line.\nSecond line.\n\nReferences:\n[RREF1] 22.html.md (0.521) First source text.']],
                ],
            ], 200),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello']);
        $response->assertStatus(200);

        $this->assertSame("First line.\nSecond line.", $response->json('assistant'));
        $this->assertCount(1, $response->json('sources'));
    }

    public function testEscapedNewlinesKeepsRealNewlines()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->configureBot();

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "Use the pattern `a\\nb` here.\n\nDone."]],
                ],
            ], 200),
        ]);

        $response = $this->json('POST', 'ask-biigle/chat', ['message' => 'Hello']);
        $response->assertStatus(200);

        $this->assertSame("Use the pattern `a\\nb` here.\n\nDone.", $response->json('assistant'));
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
