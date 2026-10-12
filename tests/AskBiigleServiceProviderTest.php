<?php

namespace Biigle\Tests\Modules\AskBiigle;

use Biigle\Facades\Modules;
use Biigle\Modules\AskBiigle\AskBiigleServiceProvider;
use Biigle\Tests\UserTest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
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

    public function testViewMixinWithoutPrivacyNotice()
    {
        $this->be(UserTest::create());
        $this->withoutVite();

        $scripts = $this->renderScripts();

        // Without a privacy notice there is nothing to confirm.
        $this->assertStringContainsString('askBiigle.adminEmail', $scripts);
        $this->assertStringNotContainsString('askBiigle.privacyUrl', $scripts);
        $this->assertStringNotContainsString('askBiigle.confirmedPrivacy', $scripts);
    }

    public function testViewMixinWithPrivacyNotice()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->withoutVite();
        $this->withPrivacyNotice(function () use ($user) {
            $scripts = $this->renderScripts();
            $this->assertStringContainsString("biigle.\$declare('askBiigle.privacyUrl', ".json_encode(route('privacy')).')', $scripts);
            $this->assertStringContainsString("biigle.\$declare('askBiigle.confirmedPrivacy', false)", $scripts);

            $user->setSettings(['ask_biigle_confirmed_privacy' => true]);
            $user->save();

            $scripts = $this->renderScripts();
            $this->assertStringContainsString("biigle.\$declare('askBiigle.confirmedPrivacy', true)", $scripts);
        });
    }

    public function testConfirmPrivacySetting()
    {
        $user = UserTest::create();
        $this->be($user);

        $this->putJson('api/v1/users/my/settings', ['ask_biigle_confirmed_privacy' => 'abc'])
            ->assertStatus(422);

        $this->putJson('api/v1/users/my/settings', ['ask_biigle_confirmed_privacy' => true])
            ->assertStatus(200);

        $this->assertTrue($user->fresh()->getSettings('ask_biigle_confirmed_privacy'));
    }

    /**
     * Render the view mixin and return what it pushed to the scripts stack.
     */
    protected function renderScripts()
    {
        // The declarations are pushed once per page, so start with a fresh page.
        View::flushState();
        view('ask-biigle::navbarHelpItemTop')->render();

        return View::yieldPushContent('scripts');
    }

    /**
     * Run the callback while the instance has a privacy notice.
     */
    protected function withPrivacyNotice(callable $callback)
    {
        $path = sys_get_temp_dir().'/ask-biigle-test-views-'.uniqid();
        File::makeDirectory($path);
        File::put("{$path}/privacy.blade.php", 'Privacy notice');
        View::addLocation($path);

        try {
            $callback();
        } finally {
            File::deleteDirectory($path);
        }
    }
}
