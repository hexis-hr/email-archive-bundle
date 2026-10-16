<?php

namespace Hexis\EmailArchiveBundle\Tests\Integration\Kernel;

use Hexis\EmailArchiveBundle\EmailArchiveBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Boots the bundle the way a host application does, with Mailer on the null transport.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new EmailArchiveBundle();
    }

    /**
     * Outside the checkout: Symfony 7.4+ debug kernels write config/reference.php into the project dir.
     */
    public function getProjectDir(): string
    {
        return $this->varDir();
    }

    public function getCacheDir(): string
    {
        return $this->varDir() . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->varDir() . '/log';
    }

    public function archiveRoot(): string
    {
        return $this->varDir() . '/archive';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'email-archive-bundle-test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'mailer' => ['dsn' => 'null://null', 'message_bus' => false],
        ]);

        $container->services()->set('logger', NullLogger::class);
        $container->services()->alias('test.mailer', MailerInterface::class)->public();

        $container->extension('email_archive', [
            'archive_root' => $this->archiveRoot(),
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    private function varDir(): string
    {
        // Per checkout and Symfony version, so a container compiled for another install is never reused.
        return sys_get_temp_dir() . '/hexis-email-archive-bundle-' . md5(__DIR__) . '/' . self::VERSION;
    }
}
