<?php

namespace Biigle\Tests\Modules\AskBiigle;

use Biigle\Facades\Modules;
use Biigle\Modules\AskBiigle\AskBiigleServiceProvider;
use Biigle\Tests\UserTest;
use Illuminate\Support\Facades\Route;
use TestCase;

class AskBiigleServiceProviderTest extends TestCase
{
    public function testServiceProvider()
    {
        $this->assertTrue(class_exists(AskBiigleServiceProvider::class));
    }

    public function testConfig()
    {
        // Only the API key has to be configured, everything else has a default that
        // works with the Chat AI service of the GWDG.
        $this->assertNotEmpty(config('ask-biigle.llm_api_url'));
        $this->assertNotEmpty(config('ask-biigle.llm_algorithm'));
        $this->assertNotEmpty(config('ask-biigle.llm_arcana_id'));
        $this->assertNotEmpty(config('ask-biigle.llm_system_prompt'));
    }

    public function testRoute()
    {
        $this->assertTrue(Route::has('ask-biigle.chat'));
    }

    public function testViewMixin()
    {
        $this->assertArrayHasKey('ask-biigle', Modules::getViewMixins('navbarHelpItemTop'));
    }

    public function testViewMixinContent()
    {
        $user = UserTest::create();
        $this->be($user);
        // The assets of the module are not published in the test environment.
        $this->withoutVite();

        $view = view('ask-biigle::navbarHelpItemTop')->render();

        // The button dispatches the event that opens the chat.
        $this->assertStringContainsString('ask-biigle:open', $view);
        // The user ID scopes the stored conversation to the current user.
        $this->assertStringContainsString('data-user-id="'.$user->id.'"', $view);
    }
}
