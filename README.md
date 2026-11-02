# Stock handling for orders created through Magento's order API

Orders created through `POST /V1/orders` don't touch stock the way orders placed through the checkout do. `Hyva_InventoryOrderReservation` closes that gap for API clients that ask for it: it reserves or deducts the stock for such an order, as if the order had been placed.

## The problem

`POST /V1/orders` calls the order repository's `save()` method, and so does `PUT /V1/orders/create`. Everything below applies to both routes. Magento treats this as importing an order, not as placing one, so none of the steps that normally run when an order is placed take place:

- With Magento Inventory (MSI), no `order_placed` reservation is written. That reservation is created by a plugin on `OrderManagementInterface::place()`. The checkout calls that method, but it has no REST route.
- Without MSI, the legacy stock deduction doesn't run either. It only runs when a quote is submitted.

As a result, an integration that creates orders this way and then ships them (a point of sale, an ERP that books counter sales, a marketplace connector) leaves the website's salable quantity too high by the amount it sold. The shipment deducts the quantity from the source and adds a compensating reservation, but the placement reservation that the compensation is meant to balance was never written.

Magento has known about this since 2020: [magento/inventory#3185](https://github.com/magento/inventory/issues/3185). Adobe has confirmed the issue twice.

## What the module does

The module adds one boolean extension attribute to the order: `reserve_stock`. It isn't set by default, so `POST /V1/orders` behaves exactly as before for clients that don't send it.

```http
POST /rest/V1/orders
{"entity": {"...": "...", "extension_attributes": {"reserve_stock": true}}}
```

When a new order has `reserve_stock` set, the module writes the stock side of the order:

- On a store with MSI, the companion package `hyva-themes/magento2-inventory-order-reservation-msi` places the `order_placed` reservation, one row per SKU, for the quantity the order still owes. A shipment, cancellation or manual compensation that happened earlier is already reflected in the reservations and isn't placed a second time.
- On a store without MSI, the module registers the sale on the legacy stock items by calling `StockManagement::registerProductsSale()`, the same method the checkout calls when a quote is submitted.

### Check that the store supports it

Magento's input validation rejects extension attributes that no module declares. A client should therefore check whether the store accepts `reserve_stock` before sending it:

```http
GET /rest/V1/inventory/order-reservation
{"version": "0.1.0", "mode": "inventory", "attribute": "reserve_stock", "ready": true}
```

- `version` is the package version Composer installed, for example `0.1.2`, or `dev-main` for a branch install. Outside Composer it's `unknown`.
- `mode` is `inventory` when `Magento_InventorySales` is enabled, and `legacy` otherwise.
- `ready` tells you whether stock registration actually works for the website of the store view in the request URL (`/rest/<store_code>/V1/...`). For that, the handler for the mode must be installed (with MSI, that's the companion package). With MSI, the MSI modules must also be enabled, and the website must be assigned to a stock. Under `/rest/all/V1/...` the website is the admin website, which has no stock, so with MSI `ready` is `false` there.
- `problem` names what's missing when `ready` is `false`. When `ready` is `true`, the field isn't in the response.
- `problem_code` names the same thing as a stable code, for a client to act on instead of the sentence: `inventory_modules_missing` (MSI modules disabled or missing), `handler_missing` (no handler for the mode, with MSI the companion package), or `website_without_stock`. Like `problem`, it's only in the response when `ready` is `false`. A client talking to an older package that doesn't send it should treat a missing code as an unknown problem.

A store whose website is assigned to no stock answers, for example:

```http
GET /rest/default/V1/inventory/order-reservation
{"version": "0.1.1", "mode": "inventory", "attribute": "reserve_stock", "ready": false, "problem": "Website \"base\" is assigned to no stock.", "problem_code": "website_without_stock"}
```

The route requires the ACL resource `Hyva_InventoryOrderReservation::capabilities`.

## How stock is registered exactly once

### Recording what the order owes

When the order is saved, the module writes one row per SKU to the `hyva_inventory_order_stock_registration` table. Each row holds the quantity ordered at that moment, the product type, the website, the stock the website sells from, and the store's mode. The stock is looked up with a single query on `inventory_stock_sales_channel`, the same table Magento's own website-to-stock lookup reads, so saving the order doesn't instantiate anything from MSI. Later changes to the order never modify these rows.

While an order has rows waiting to be registered, this README calls it *owed*.

### Registering the stock

Registration runs after the order's transaction has been committed, under Magento's own order lock (`OrderMutexInterface`, which opens a transaction and locks the order row with `SELECT … FOR UPDATE`). The stock write and the new state of each row are saved in that single transaction. If anything fails, all of it is rolled back and the order stays owed. If two processes register the same order at the same time, the lock makes them run one after the other, and the second one finds nothing left to do.

Each SKU row ends up in one of these states:

- **Done by registration**: the stock was written.
- **Done as balanced**: the existing reservations already accounted for what the order owes, so nothing had to be written.
- **Done by compensation** (MSI only): a manual compensation through `inventory:reservation:create-compensations` balanced the row.
- **Done by hand**: `inventory:order-reservation:settle` closed the row without writing stock.
- **Refused**: something is wrong that placing a reservation can't fix. Examples are more being reserved than the order owes, part of the remainder being on a different stock because the website was assigned to another stock in the meantime, or the store having switched between MSI and legacy mode since the order was recorded. The reason is stored on the row. A refusal like this doesn't count as a failed attempt. On an MSI stock, a manual compensation resolves it. After a switch between MSI and legacy mode there is nothing to compensate, so `inventory:order-reservation:settle <id>` closes the row once you've corrected the stock by hand. In both cases, `inventory:order-reservation:run <id>` retries the row.

A row is also refused when its registration has failed 24 times. In that case the reason is the last error, and the row keeps its attempt count.

An order is complete once all its rows are done. The listing, the admin message and the run command show refused rows together with their reason.

All of this depends on the sales tables and the inventory tables sharing one database connection. That's the case on every store since Magento removed the split database. The module checks this and refuses to register the stock otherwise.

### Recovery

If a registration fails, it's retried in three ways:

- On the order's next save. For most integrations, an invoice or a shipment follows within seconds.
- By a cron job that runs every five minutes and picks up orders that have been owed for more than two minutes.
- Manually, with `bin/magento inventory:order-reservation:run`.

Each failure counts as an attempt. After 24 failed attempts, which is about two hours of cron runs, the registration gives up and marks the order's owed rows as refused. Failing orders are retried after the others, so they never hold up the rest. The cron job and `inventory:order-reservation:run` without arguments skip refused orders. `inventory:order-reservation:run <id>` tries a named order again, refused or not, and starts counting its attempts from zero.

`bin/magento inventory:order-reservation:settle <id>` closes an order's owed and refused rows without writing any stock. Use it after you have corrected the stock by hand. Without MSI, this is the only way to close a refused row, because there is no compensation command.

`bin/magento inventory:order-reservation:list` shows which orders are owed or refused, and why the last attempt failed. When an order has been owed for more than an hour, an admin system message appears. For a refused row, the message appears right away.

A missing handler package or a disabled MSI module is an installation problem, not a problem with the order. Orders sent with `reserve_stock` are still accepted. They stay owed, and no failed attempt is counted. The admin message, the listing and the capabilities route report the problem immediately. Once it's fixed, the next registration completes these orders with whatever they still owe. Rejecting sales because a server package is missing would be the worse outcome.

## Magento's repair tools

With MSI, the companion package hooks into `inventory:reservation:create-compensations` and `inventory:reservation:list-inconsistencies`. A manual compensation for an order this module tracks is checked against what the order actually still owes, and the inconsistency listing leaves out SKUs this module still has to register. The companion package's README describes the rules.

## Installation

```sh
composer require hyva-themes/magento2-inventory-order-reservation
bin/magento setup:upgrade
```

On a store with MSI, also install the companion package. It contains everything that references an MSI interface, and its module is sequenced after the MSI modules:

```sh
composer require hyva-themes/magento2-inventory-order-reservation-msi
```

This package doesn't depend on MSI or on any other Hyvä package. It can be installed on a store without MSI and uses the legacy path there.

### Updating with Hyvä POS

Hyvä POS (`Hyva_Pos`) plugs into the companion's handler to assign the register's sources before a placement. Update the POS module and these packages together: an older `Hyva_Pos` with these 0.1 packages leaves that step unplugged, because the handler it targets moved to the companion; and these packages with the new `Hyva_Pos` but without the companion leave an MSI store's orders owed until the companion is installed, which the admin message tells you.

## Without Magento Inventory

On a store without MSI, the module registers the sale on the legacy stock item, the same way the checkout does. From then on, Magento's own legacy behavior applies:

- A cancellation returns the quantity to stock only if "Set Items' Status to be In Stock When Order is Cancelled" is enabled (`cataloginventory/options/can_back_in_stock`, which `CancelOrderItemObserver` reads; enabled by default).
- A credit memo returns the quantity only when it's set to return the items to stock.
- A shipment doesn't change the stock.

If the registration runs after one of these events, the result is the same as for an order placed through the checkout.

There is one special case. If the stock item can't cover a line (backorders are disabled, and the shop sold the last units at the register anyway), `registerProductsSale` rejects the entire sale and deducts nothing. It does the same for a storefront checkout, but not for an admin order. Because the goods have already left the shop, the module then deducts every line of the sale the way an admin order does, without the quantity check. The line that wasn't covered goes past zero, which marks the item as out of stock. With MSI, placing a reservation never fails because of the quantity, so both modes end up in the same state.

## Development

This package can be installed on Magento with or without MSI, and `setup:di:compile` reflects every constructor in both cases. That's why no class in this package references an MSI type. The mode is determined from the module list (`Model\InventoryPresence`), the website's stock is read from a single table, and everything that needs an MSI interface lives in the companion package. The handlers are kept in a lazy map (`Magento\Framework\ObjectManager\TMap`) keyed by mode. The companion package adds its handler to that map in its own `di.xml`.

The unit tests run against the vendor directory of an existing Magento installation:

```sh
MAGENTO_VENDOR_PATH=/path/to/magento/vendor/autoload.php php phpunit-10.phar
```

The integration tests run inside a development store, which they boot through `app/bootstrap.php`. They place orders for two SKUs from the Koti sample data (`bed-walnut-cane` and `bedding-linen-white`) and restore their stock after each test. Run them from the module's directory inside the store:

```sh
MAGENTO_ROOT=/var/www/html php phpunit-10.phar -c phpunit.integration.xml.dist
```

## License

This package is dual-licensed under the Open Software License (OSL 3.0) and the Academic Free License (AFL 3.0). You may choose either license.

- Copyright © 2026-present Hyvä Themes. All rights reserved.
- OSL 3.0 text: `LICENSE.txt` or http://opensource.org/licenses/osl-3.0.php.
- AFL 3.0 text: `LICENSE_AFL.txt` or http://opensource.org/licenses/afl-3.0.php.
