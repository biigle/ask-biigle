<?php

namespace Biigle\Tests\Modules\Module\Http\Controllers;

use Biigle\Tests\UserTest;
use Illuminate\Support\Facades\Http;
use TestCase;

class QuotesControllerTest extends TestCase
{
    public function testRoute()
    {
        $user = UserTest::create();

        // Redirect to login page.
        $this->get('quotes')->assertStatus(302);

        $this->be($user);
        $this->get('quotes')->assertStatus(200);
    }

    public function testQuoteProvider()
    {
        $user = UserTest::create();

        // Redirect to login page.
        $this->get('quotes/new')->assertStatus(302);

        $this->be($user);
        $this->get('quotes/new')->assertStatus(200);
    }

    public function testChatRoute()
    {
        // Redirect to login page.
        $this->json('POST', 'biiglebot/chat', ['message' => 'Hello'])->assertStatus(302);
    }

    public function testChatProvider()
    {
        $user = UserTest::create();
        $this->be($user);

        config([
            'biiglebot.llm_api_url' => 'https://chat-ai.academiccloud.de/v1/chat/completions',
            'biiglebot.llm_api_key' => 'test-key',
            'biiglebot.llm_algorithm' => 'test-model',
            'biiglebot.llm_inference_service' => 'saia-openai-gateway',
            'biiglebot.llm_arcana_id' => 'arcana-123',
            'biiglebot.llm_enable_tools' => true,
            'biiglebot.llm_temperature' => 0.3,
            'biiglebot.llm_top_p' => 0.9,
            'biiglebot.llm_system_prompt' => 'You are an assistant.',
        ]);

        Http::fake([
            'https://chat-ai.academiccloud.de/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Hi from BIIGLEBot.']],
                ],
            ], 200),
        ]);

        $response = $this->json('POST', 'biiglebot/chat', [
            'message' => 'Hello',
            'history' => [
                [
                    'role' => 'assistant',
                    'content' => 'Previous answer.',
                ],
            ],
        ]);

        $response->assertStatus(200)->assertJson([
            'assistant' => 'Hi from BIIGLEBot.',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://chat-ai.academiccloud.de/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->hasHeader('inference-service', 'saia-openai-gateway')
                && $data['model'] === 'test-model'
                && $data['enable-tools'] === true
                && $data['arcana']['id'] === 'arcana-123'
                && $data['messages'][0]['role'] === 'system'
                && $data['messages'][2]['role'] === 'user'
                && $data['messages'][2]['content'] === 'Hello';
        });
    }
}
