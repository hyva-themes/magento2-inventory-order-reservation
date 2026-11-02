<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservation\Test\Unit\Model;

use Hyva\InventoryOrderReservation\Model\CapabilitiesService;
use Hyva\InventoryOrderReservation\Model\Data\Capabilities;
use Hyva\InventoryOrderReservation\Model\Data\CapabilitiesFactory;
use Hyva\InventoryOrderReservation\Model\InventoryPresence;
use Hyva\InventoryOrderReservation\Model\PackageVersion;
use Hyva\InventoryOrderReservation\Model\Readiness;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * `GET /V1/inventory/order-reservation` answers, beside `ready` and the
 * sentence in `problem`, a stable `problem_code` a client keys on:
 * `inventory_modules_missing`, `handler_missing` or
 * `website_without_stock`, and none when the store is ready. The
 * readiness is asked for the request's website.
 */
class CapabilitiesServiceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function problems(): array
    {
        return [
            'an Inventory module disabled' => [
                Readiness::PROBLEM_INVENTORY_MODULES_MISSING,
                'Magento Inventory is enabled, but these of its modules are disabled or missing: Magento_InventoryReservations.',
            ],
            'the handler package missing' => [
                Readiness::PROBLEM_HANDLER_MISSING,
                'Magento Inventory is enabled, but Hyva_InventoryOrderReservationMsi, which places the reservation, is not installed.',
            ],
            'the website sells from no stock' => [
                Readiness::PROBLEM_WEBSITE_WITHOUT_STOCK,
                'Website "base" is assigned to no stock.',
            ],
        ];
    }

    #[DataProvider('problems')]
    public function test_a_store_that_is_not_ready_answers_the_code_beside_the_sentence(string $code, string $problem): void
    {
        $capabilities = $this->capabilities(['code' => $code, 'problem' => $problem]);

        self::assertFalse($capabilities->isReady());
        self::assertSame($problem, $capabilities->getProblem());
        self::assertSame($code, $capabilities->getProblemCode());
    }

    public function test_a_ready_store_answers_neither(): void
    {
        $capabilities = $this->capabilities(null);

        self::assertTrue($capabilities->isReady());
        self::assertNull($capabilities->getProblem());
        self::assertNull($capabilities->getProblemCode());
        self::assertSame('reserve_stock', $capabilities->getAttribute());
        self::assertSame('0.1.1', $capabilities->getVersion());
        self::assertSame('inventory', $capabilities->getMode());
    }

    /**
     * @param array{code: string, problem: string}|null $diagnosis
     */
    private function capabilities(?array $diagnosis): Capabilities
    {
        $readiness = $this->createMock(Readiness::class);
        $readiness->expects(self::once())->method('diagnosis')->with(4)->willReturn($diagnosis);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn('4');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $presence = $this->createStub(InventoryPresence::class);
        $presence->method('mode')->willReturn('inventory');

        $version = $this->createStub(PackageVersion::class);
        $version->method('get')->willReturn('0.1.1');

        $factory = $this->createMock(CapabilitiesFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data): Capabilities => new Capabilities(...$data)
        );

        $capabilities = (new CapabilitiesService($factory, $presence, $readiness, $storeManager, $version))->get();
        self::assertInstanceOf(Capabilities::class, $capabilities);

        return $capabilities;
    }
}
