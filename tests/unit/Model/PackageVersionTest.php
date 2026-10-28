<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\InstalledPackages;
use Hyva\InventoryOrderReservation\Model\PackageVersion;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * The version is what Composer installed this package at, or "unknown".
 * Composer's record is stubbed: the borrowed vendor directory may hold
 * the package itself, and its own data would decide the result.
 */
class PackageVersionTest extends TestCase
{
    public function test_a_tagged_install_reports_its_tag(): void
    {
        self::assertSame('1.0.2', $this->version('1.0.2')->get());
    }

    public function test_a_branch_install_reports_its_branch(): void
    {
        self::assertSame('dev-main', $this->version('dev-main')->get());
    }

    public function test_a_module_outside_composer_reports_unknown(): void
    {
        self::assertSame(PackageVersion::UNKNOWN, $this->version(null)->get());
    }

    public function test_the_record_answers_for_installed_packages_and_null_for_others(): void
    {
        $installed = new InstalledPackages();

        // every borrowed Magento vendor directory holds the framework
        self::assertNotEmpty($installed->prettyVersion('magento/framework'));
        self::assertNull($installed->prettyVersion('hyva-themes/no-such-package'));
    }

    private function version(?string $installedAt): PackageVersion
    {
        $packages = $this->createMock(InstalledPackages::class);
        $packages->method('prettyVersion')->with(PackageVersion::PACKAGE)->willReturn($installedAt);

        return new PackageVersion($packages);
    }
}
