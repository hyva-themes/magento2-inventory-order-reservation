# Stock for orders created through Magento's order API

`Hyva_InventoryOrderReservation` gives an order created through `POST /V1/orders` the stock side of a placed order, on request.

## The gap it closes

`POST /V1/orders` is the order repository's save. Magento treats it as an import and update path, not a placement, so none of the placement lifecycle runs: with Magento Inventory, no `order_placed` reservation (that is placed by a plugin on `OrderManagementInterface::place`, which the cart flow calls and which has no REST route); without Inventory, no legacy stock deduction (it runs on quote submit alone). An integration that creates orders this way and ships them (a point of sale, an ERP booking counter sales, a marketplace connector) leaves the website's salable quantity overstated by what it sold: the shipment deducts the source and adds its compensating reservation, and the placement that should balance it never happened. On record at [magento/inventory#3185](https://github.com/magento/inventory/issues/3185) since 2020, confirmed by Adobe twice.

## What it does

One boolean extension attribute on the order, `reserve_stock`, absent by default. `POST /V1/orders` behaves exactly as before for every caller that does not send it. When a new order carries it:

- on a store with Magento Inventory, the module places the `order_placed` reservation for the order's lines through `PlaceReservationsForSalesEventInterface`, as the cart flow's placement does, so the shipment's compensation balances it and cancellation and credit memos keep balancing it;
- on a store without Inventory, the module registers the sale on the legacy stock items (`StockManagement::registerProductsSale`, what the quote submit does).

```http
POST /rest/V1/orders
{"entity": {"...": "...", "extension_attributes": {"reserve_stock": true}}}
```

A client asks first whether the store takes the attribute (an extension attribute no module declares is refused by input validation):

```http
GET /rest/V1/inventory/order-reservation
{"version": "1.0.0", "mode": "inventory", "attribute": "reserve_stock"}
```

The route needs the ACL resource `Hyva_InventoryOrderReservation::capabilities`.

## How it stays exactly once

The obligation is written with the order, in the order's own save: one row per sku with the quantity ordered at creation, the product type, the website and its stock, and the store's mode (`hyva_inventory_order_stock_registration`). Later edits to the order never change it. Registration runs after the order's transaction has committed, under Magento's own order lock (`OrderMutexInterface`: a transaction with `SELECT … FOR UPDATE` on the order row): the inventory write and the completion marker are in that one transaction, so a failure anywhere rolls both back and the obligation stays owed, and two runners for one order are serialized. That holds when the sales tables and the inventory tables share one database connection, which is every store since Magento removed the split database; the module checks it and refuses the step otherwise.

Recovery has three triggers: the order's next save (an invoice or shipment brings one within seconds for most integrations), a cron every five minutes for obligations owed longer than two minutes, and `bin/magento inventory:order-reservation:run`. `bin/magento inventory:order-reservation:list` names what is owed and why the last attempt failed. Orders owed longer than an hour raise an admin system message.

## Magento's repair tools

A manual compensation that names an order this module has an obligation for reaches a coordinated service, whichever code appends it (Magento's `inventory:reservation:create-compensations`, a store's own integration): under the order's lock, the order's owed registration completes first, then the request is read against what the order still owes by Magento's own rule (ordered less canceled, refunded and shipped for an order in a non-final state; zero for a complete, closed or canceled one). Already balanced: nothing is written, and the request is refused as such. The right amount, or part of it toward balance: written. The wrong direction, more than remains, or a sku the order owes nothing on: refused with the current discrepancy. A refusal reaches the caller as `CouldNotSaveException`, so Magento's command prints it as an error and exits non-zero, as for any refused row; a failure under the order's lock (the registration, the write, the settlement of the sku's obligation) rolls the transaction back and reaches the caller the same way, nothing written; for a written row the command prints its own line, and the module adds a note under Magento's output where there is one: the remainder still owed after a partial compensation, a registration that completed first, and the "Decrease Stock When Order is Placed" setting being off, under which Magento's own filter drops every row after its loop has printed it as compensated. A row dropped for a product whose stock is not managed stays Magento's silent case: its filter wraps every other plugin on the writer, so nothing else sees the row. A compensation that balances a sku of an order the registration gave up on settles that sku's obligation; the order's other skus keep theirs until each is balanced. `inventory:reservation:list-inconsistencies` leaves orders this module still owes out of its listing; they are the module's to register. Reservation history that Magento's cleanup deletes is never read to decide whether registration ran; the module's own record is. The coordination sits on Inventory's reservation writer, `AppendReservationsInterface`; a row written straight into the reservation table bypasses it.

## Installing

```sh
composer require hyva-themes/magento2-inventory-order-reservation
bin/magento setup:upgrade
```

No dependency on Magento Inventory: the module installs on a store without it and takes the legacy path there. No dependency on any other Hyvä package.

## Without Magento Inventory

On a store without Inventory the module registers the sale on the legacy stock item, as the checkout does (`StockManagement::registerProductsSale`). Everything that follows is Magento's own legacy behavior: a cancellation returns the unit only with "Set Items' Status to be In Stock When Order is Cancelled" on (`cataloginventory/options/can_back_in_stock`, what `CancelOrderItemObserver` reads; on by default), a credit memo returns it only when asked to, a shipment moves nothing. A registration that runs after one of those events ends in the same place as a checkout-placed order would.

A line the stock item cannot cover (backorders off, the register sold the last units anyway) makes `registerProductsSale` refuse the whole sale, as it refuses the storefront checkout and not an admin order. The goods left the shop, so the module then deducts the line the way an admin order's is deducted, past zero, and the item reads out of stock. With Inventory the placement never refuses for quantity, so the two modes end in the same place.

## Working on the module

The module installs on a Magento with or without Inventory, and `setup:di:compile` reflects the constructor of every class on either. So no constructor names an Inventory type: Inventory services are fetched through `Model\OptionalInventory` at run time, and a class that needs one asks for it where it runs. Plugins on Inventory types are declared in `etc/di.xml` only; Magento builds them where the type exists.

Unit tests (`phpunit.xml.dist`) run against a borrowed Magento vendor: `MAGENTO_VENDOR_PATH=/path/to/magento/vendor/autoload.php php phpunit-10.phar`. Integration tests (`phpunit.integration.xml.dist`) run inside a development store, booted through `app/bootstrap.php`: `MAGENTO_ROOT=/var/www/html php phpunit-10.phar -c phpunit.integration.xml.dist` from the module's directory under the store. They place orders for one sku (`bed-walnut-cane` in the Koti sample data) and put its stock back after every test.

## License

This package is dual-licensed under the Open Software License (OSL 3.0) and the Academic Free License (AFL 3.0). You may choose either license.

- Copyright © 2026-present Hyvä Themes. All rights reserved.
- OSL 3.0 text: `LICENSE.txt` or http://opensource.org/licenses/osl-3.0.php.
- AFL 3.0 text: `LICENSE_AFL.txt` or http://opensource.org/licenses/afl-3.0.php.
