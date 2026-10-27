# Stock for orders created through Magento's order API

`Hyva_InventoryOrderReservation` gives an order created through `POST /V1/orders` the stock side of a placed order, on request.

## The gap it closes

`POST /V1/orders` is the order repository's save. Magento treats it as an import, not as placing an order, so nothing of the placement lifecycle runs:

- with Magento Inventory (MSI), no `order_placed` reservation is written. That reservation comes from a plugin on `OrderManagementInterface::place`, which the cart flow calls and which has no REST route;
- without MSI, the legacy stock deduction does not run either. It runs on quote submit only.

So an integration that creates orders this way and ships them (a point of sale, an ERP booking counter sales, a marketplace connector) leaves the website's salable quantity too high by what it sold. The shipment deducts the source and adds its compensating reservation, but the placement that should balance it never happened. Magento has had this on record since 2020 as [magento/inventory#3185](https://github.com/magento/inventory/issues/3185), confirmed by Adobe twice.

## What it does

The module adds one boolean extension attribute to the order, `reserve_stock`. It is absent by default, and `POST /V1/orders` behaves exactly as before for callers that do not send it.

```http
POST /rest/V1/orders
{"entity": {"...": "...", "extension_attributes": {"reserve_stock": true}}}
```

When a new order carries the attribute, the module writes the stock side of the order:

- on a store with MSI, the companion package `hyva-themes/magento2-inventory-order-reservation-msi` places the `order_placed` reservation, one row per sku, for what the order still owes. A shipment, a cancellation or a manual compensation that happened earlier is already on the rows and is not placed again;
- on a store without MSI, the module registers the sale on the legacy stock items, through `StockManagement::registerProductsSale`, the same call the quote submit makes.

### Ask the store first

An extension attribute no module declares is refused by Magento's input validation, so a client should ask whether the store takes it before posting:

```http
GET /rest/V1/inventory/order-reservation
{"version": "1.0.0", "mode": "inventory", "attribute": "reserve_stock", "ready": true, "problem": null}
```

- `mode` is `inventory` when `Magento_InventorySales` is enabled, `legacy` otherwise.
- `ready` says whether registration actually runs for the website you asked on: the handler for the mode is installed (with MSI, that is the companion package), MSI's modules are enabled, and the website sells from a stock.
- `problem` names what is missing when `ready` is false.

The route needs the ACL resource `Hyva_InventoryOrderReservation::capabilities`.

## How it stays exactly once

### The record

The order's save writes one row per sku into `hyva_inventory_order_stock_registration`: the quantity ordered at that moment, the product type, the website and the stock it sells from, and the store's mode. The stock is read with one query from `inventory_stock_sales_channel`, the same table Magento's own website-to-stock lookup reads, so the save constructs nothing from MSI. Later edits to the order never change these rows.

### The registration

Registration runs after the order's transaction has committed, under Magento's own order lock (`OrderMutexInterface`, a transaction with `SELECT … FOR UPDATE` on the order row). The stock write and the state of each row land in that one transaction. If anything fails, all of it rolls back and the order stays owed. Two runners for the same order are serialized by the lock; the second one finds nothing left to do.

Each sku's row ends in one of three states:

- **done by registration**: the stock was written;
- **done as balanced**: the rows already said what the order owes, nothing had to be written;
- **refused**: something a placement cannot fix, such as more reserved than the order owes, or a remainder on another stock after the website was moved. The reason is stored on the row. A refusal counts no attempt; a manual compensation repairs it, and `inventory:order-reservation:run <id>` tries the row again.

The order is complete when every row is done. The listing, the admin message and the run command name refused rows with their reason.

This all relies on the sales tables and the inventory tables sharing one database connection, which is every store since Magento removed the split database. The module checks it and refuses the step otherwise.

### Recovery

If a registration fails, three things try again:

- the order's next save. An invoice or a shipment brings one within seconds for most integrations;
- a cron job every five minutes, for orders owed longer than two minutes;
- `bin/magento inventory:order-reservation:run`, by hand.

`bin/magento inventory:order-reservation:list` shows what is owed and why the last attempt failed. Orders owed longer than an hour raise an admin system message.

A missing handler package, or an MSI module that is disabled, is an installation problem and not the order's fault. Orders sent with the attribute are still accepted and stay owed, with no attempt counted. The admin message, the listing and the capabilities route name the problem right away. Once it is fixed, the next registration completes them with whatever still remains. Refusing sales because a server package is missing would be the worse outcome.

## Magento's repair tools

With MSI, the companion package hooks into `inventory:reservation:create-compensations` and `inventory:reservation:list-inconsistencies`, so a manual compensation for an order this module knows about is checked against what the order really still owes, and the listing leaves out skus this module still has to register. The companion's README describes the rules.

## Installing

```sh
composer require hyva-themes/magento2-inventory-order-reservation
bin/magento setup:upgrade
```

On a store with MSI, install the companion package as well. It holds everything that names an MSI interface and is sequenced on the MSI modules:

```sh
composer require hyva-themes/magento2-inventory-order-reservation-msi
```

This package itself has no dependency on MSI, and none on any other Hyvä package. It installs on a store without MSI and takes the legacy path there.

## Without Magento Inventory

On a store without MSI the module registers the sale on the legacy stock item, the way the checkout does. From there on everything is Magento's own legacy behaviour:

- a cancellation returns the unit only when "Set Items' Status to be In Stock When Order is Cancelled" is on (`cataloginventory/options/can_back_in_stock`, what `CancelOrderItemObserver` reads; on by default);
- a credit memo returns it only when asked to;
- a shipment moves nothing.

A registration that runs after one of those events ends in the same place a checkout-placed order would.

One special case: a line the stock item cannot cover (backorders off, and the register sold the last units anyway) makes `registerProductsSale` refuse the whole sale, as it refuses a storefront checkout but not an admin order. The goods have left the shop, so the module deducts the line the way an admin order's is deducted, past zero, and the item reads out of stock. With MSI a placement never refuses for quantity, so both modes end in the same place.

## Working on the module

This package installs on a Magento with or without MSI, and `setup:di:compile` reflects every constructor on either. That is why no class here names an MSI type. The mode comes from the module list (`Model\InventoryPresence`), the website's stock from one table, and everything that needs an MSI interface lives in the companion package. The handlers sit in a lazy map (`Magento\Framework\ObjectManager\TMap`) keyed by mode; the companion adds its handler to that map in its own `di.xml`.

Unit tests run against a borrowed Magento vendor directory:

```sh
MAGENTO_VENDOR_PATH=/path/to/magento/vendor/autoload.php php phpunit-10.phar
```

Integration tests run inside a development store, booted through `app/bootstrap.php`. They place orders for one sku (`bed-walnut-cane` in the Koti sample data) and put its stock back after every test. Run them from the module directory under the store:

```sh
MAGENTO_ROOT=/var/www/html php phpunit-10.phar -c phpunit.integration.xml.dist
```

## License

This package is dual-licensed under the Open Software License (OSL 3.0) and the Academic Free License (AFL 3.0). You may choose either license.

- Copyright © 2026-present Hyvä Themes. All rights reserved.
- OSL 3.0 text: `LICENSE.txt` or http://opensource.org/licenses/osl-3.0.php.
- AFL 3.0 text: `LICENSE_AFL.txt` or http://opensource.org/licenses/afl-3.0.php.
