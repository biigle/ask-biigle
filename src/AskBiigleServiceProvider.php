<?php

namespace Biigle\Modules\AskBiigle;

use Biigle\Services\Modules;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AskBiigleServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @param Modules $modules
     * @param  Router  $router
     * @return  void
     */
    public function boot(Modules $modules, Router $router)
    {
        $this->loadViewsFrom(__DIR__ . "/resources/views", "ask-biigle");

        $router->group(
            [
                "namespace" => "Biigle\Modules\AskBiigle\Http\Controllers",
                "middleware" => "web",
            ],
            function ($router) {
                require __DIR__ . "/Http/routes.php";
            },
        );

        $modules->register("ask-biigle", [
            "viewMixins" => ["navbarItem"],
            "controllerMixins" => [
                //
            ],
            "apidoc" => [
                //__DIR__.'/Http/Controllers/Api/',
            ],
        ]);

        $this->publishes(
            [
                __DIR__ . "/public" => public_path("vendor/ask-biigle"),
            ],
            "public",
        );
    }

    /**
     * Register the service provider.
     *
     * @return  void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . "/config/ask-biigle.php", "ask-biigle");
    }
}
