<?php namespace Dozensoftware\Listcraft;

use Illuminate\Support\ServiceProvider;

class ListcraftServiceProvider extends ServiceProvider {

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $viewPath = __DIR__.'/../../views';
        $this->loadViewsFrom($viewPath, 'listcraft');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {   
        $this->registerConsoleCommands();
    }

    /**
     * Register the package console commands.
     *
     * @return void
     */
    protected function registerConsoleCommands()
    {
        $this->registerListcraftAttach();
        $this->commands([
            'listcraft.attach'
        ]);
    }

    /**
     * Register the listcraft command with the container.
     * 
     * @return void
     */
    protected function registerListcraftAttach()
    {
        $this->app->singleton('listcraft.attach', function($app)
        {
            return new Commands\AttachCommand;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array();
    }

}