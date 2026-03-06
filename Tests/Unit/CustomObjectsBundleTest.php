<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit;

use MauticPlugin\CustomObjectsBundle\CustomObjectsBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class CustomObjectsBundleTest extends TestCase
{
    public function testBundleBuildsWithoutError(): void
    {
        $bundle    = new CustomObjectsBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);
        $this->assertTrue(true); // No fatal error thrown
    }
}
