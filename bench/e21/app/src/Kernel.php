<?php
declare(strict_types=1);

namespace App;

use Ignis\Symfony\FiberRequestStack;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        if (!getenv('IGNIS_NO_SCOPE')) {
            yield new \Ignis\Symfony\IgnisBundle();
        }
        yield new \Doctrine\Bundle\DoctrineBundle\DoctrineBundle();
        if (!getenv('IGNIS_NO_DOCTRINE_SCOPE')) {
            yield new \Ignis\Doctrine\IgnisDoctrineBundle();
        }
    }

    protected function configureContainer(ContainerConfigurator $c, LoaderInterface $loader): void
    {
        $c->extension('framework', [
            'secret' => 'probe',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => false,
            'router' => ['utf8' => true],
        ]);

        $c->extension('security', [
            'password_hashers' => [InMemoryUser::class => ['algorithm' => 'plaintext']],
            'providers' => ['inmem' => ['memory' => ['users' => [
                'alice' => ['password' => 'alicepw', 'roles' => ['ROLE_USER']],
                'bob'   => ['password' => 'bobpw',   'roles' => ['ROLE_USER', 'ROLE_ADMIN']],
            ]]]],
            'firewalls' => ['main' => [
                'pattern'    => '^/',
                'stateless'  => true,
                'provider'   => 'inmem',
                'http_basic' => true,
            ]],
            'access_control' => [['path' => '^/whoami', 'roles' => 'ROLE_USER']],
        ]);

        $c->extension('doctrine', [
            'dbal' => getenv('DATABASE_URL')
                ? ['url' => getenv('DATABASE_URL')]                                      // E24 runs the same app on PostgreSQL
                : ['driver' => 'pdo_sqlite', 'path' => '%kernel.project_dir%/var/probe.sqlite'],
            'orm'  => [
                'mappings' => ['App' => ['type' => 'attribute', 'dir' => '%kernel.project_dir%/src/Entity', 'prefix' => 'App\\Entity', 'is_bundle' => false]],
            ],
        ]);

        $s = $c->services();
        $s->defaults()->autowire()->autoconfigure();
        if (!getenv('IGNIS_NO_SCOPE')) {
            $s->set('request_stack', FiberRequestStack::class);
        }
        $s->load('App\\', '../src/')->exclude('../src/Kernel.php');
        $s->get(\App\Controller\WhoAmI::class)->tag('controller.service_arguments');
        $s->get(\App\Controller\EmProbe::class)->tag('controller.service_arguments');
        $s->get(\App\Controller\PgProbe::class)->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('whoami', '/whoami')->controller([\App\Controller\WhoAmI::class, '__invoke']);
        $routes->add('em', '/em')->controller([\App\Controller\EmProbe::class, '__invoke']);
        $routes->add('pg', '/pg')->controller([\App\Controller\PgProbe::class, '__invoke']);
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__).'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__).'/var/log';
    }
}
