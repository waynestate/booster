<?php
namespace App;

class App
{
    private \Slim\App $app;

    public function __construct(array $config = [])
    {
        $container = new \Pimple\Container(require_once CONFIG_PATH . 'core.php');
        $container['config'] = $config;
        $this->app = \Slim\Factory\AppFactory::create(null, new \Pimple\Psr11\Container($container));

        foreach (require_once CONFIG_PATH . 'middleware.php' as $callback) {
            $this->app->add($callback($this));
        }
        foreach (require_once CONFIG_PATH . 'routes.php' as $callback) {
            $callback($this);
        }
    }

    public function __call(string $name, array $args)
    {
        return $this->app->$name(...$args);
    }

    public function getContainer()
    {
        return $this->app->getContainer();
    }

    public function run(): void
    {
        $this->app->run();
    }
}
