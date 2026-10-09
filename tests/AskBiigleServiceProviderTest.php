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

        $view = view('ask-biigle::navbarHelpItemTop')->render();

        // Without a privacy notice there is nothing to confirm.
        $this->assertStringNotContainsString('askBiigle.privacyUrl', $view);
        $this->assertStringNotContainsString('askBiigle.confirmedPrivacy', $view);
    }

    public function testViewMixinWithPrivacyNotice()
    {
        $user = UserTest::create();
        $this->be($user);
        $this->withoutVite();
        $this->withPrivacyNotice(function () use ($user) {
            $view = view('ask-biigle::navbarHelpItemTop')->render();
            $this->assertStringContainsString("biigle.\$declare('askBiigle.privacyUrl', ".json_encode(route('privacy')).')', $view);
            $this->assertStringContainsString("biigle.\$declare('askBiigle.confirmedPrivacy', false)", $view);

            $user->setSettings(['ask_biigle_confirmed_privacy' => true]);
            $user->save();

            $view = view('ask-biigle::navbarHelpItemTop')->render();
            $this->assertStringContainsString("biigle.\$declare('askBiigle.confirmedPrivacy', true)", $view);
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
