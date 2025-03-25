<?php

namespace Biigle\Modules\MagicSam;

use Biigle\Modules\MagicSam\Console\Commands\PruneOldEmbeddings;
use Biigle\Services\Modules;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class MagicSamServiceProvider extends ServiceProvider
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
        $this->loadViewsFrom(__DIR__.'/resources/views', 'magic-sam');

        $router->group([
            'namespace' => 'Biigle\Modules\MagicSam\Http\Controllers',
            'middleware' => 'web',
        ], function ($router) {
            require __DIR__.'/Http/routes.php';
        });

        $modules->register('magic-sam', [
            'viewMixins' => [
                'annotationsManualSidebarSettings',
                'annotationsSettingsTab',
                'imageAnnotationPolygonTools',
                'manualAnnotationShortcutsPolygon',
                'manualCreatingPolygons',
            ],
            'controllerMixins' => [
                //
            ],
            'apidoc' => [
               __DIR__.'/Http/Controllers/',
            ],
        ]);

        $this->publishes([
            __DIR__.'/public' => public_path('vendor/magic-sam'),
        ], 'public');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneOldEmbeddings::class,
            ]);

            $this->app->booted(function () {
                $schedule = app(Schedule::class);
                $schedule->command(PruneOldEmbeddings::class)
                    ->daily()
                    ->onOneServer();
            });
        }
    }

    /**
    * Register the service provider.
    *
    * @return  void
    */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/magic_sam.php', 'magic_sam');
    }
}
