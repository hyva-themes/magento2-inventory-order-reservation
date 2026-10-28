<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Composer\InstalledVersions;
use Hyva\InventoryOrderReservation\Model\PackageVersion;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * The version is what Composer installed, read from its runtime data.
 */
class PackageVersionTest extends TestCase
{
    protected function tearDown(): void
    {
        // back to the vendor directory's own installed.php
        InstalledVersions::reload(null);
    }

    public function test_a_tagged_install_reports_its_tag(): void
    {
        $this->installed('1.0.2', '1.0.2.0');

        self::assertSame('1.0.2', (new PackageVersion())->get());
    }

    public function test_a_branch_install_reports_its_branch(): void
    {
        $this->installed('dev-main', 'dev-main');

        self::assertSame('dev-main', (new PackageVersion())->get());
    }

    public function test_a_module_outside_composer_reports_unknown(): void
    {
        InstalledVersions::reload(['root' => $this->root(), 'versions' => []]);

        self::assertSame(PackageVersion::UNKNOWN, (new PackageVersion())->get());
    }

    private function installed(string $prettyVersion, string $version): void
    {
        InstalledVersions::reload([
            'root' => $this->root(),
            'versions' => [
                PackageVersion::PACKAGE => [
                    'pretty_version' => $prettyVersion,
                    'version' => $version,
                    'reference' => null,
                    'type' => 'magento2-module',
                    'install_path' => __DIR__,
                    'aliases' => [],
                    'dev_requirement' => false,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function root(): array
    {
        return [
            'name' => 'test/root',
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => null,
            'type' => 'project',
            'install_path' => __DIR__,
            'aliases' => [],
            'dev' => false,
        ];
    }
}
