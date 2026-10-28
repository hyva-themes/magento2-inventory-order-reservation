# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

---

## Unreleased

### Fixed
* The owed and refused lookups group by order, so "tried least first" sorts each order by its most-tried row instead of an arbitrary one; `inventory:order-reservation:list` says when it shows only the first 200 orders.
* An order recorded in one mode and registered after the store switched to the other (Inventory enabled or disabled in between) has its rows refused with the reason, instead of being marked done by a legacy deduction Inventory turns into a no-op, or failing 24 times for a missing handler. The reason points to `inventory:order-reservation:settle`: no compensation applies to such a row.
* Without Inventory, a line deducted past zero is taken off the stock row's quantity as read under the lock `registerProductsSale` took, and on a stock item loaded afresh, not on one the registry may have cached earlier in the process; a product type without quantity is left alone there, as `registerProductsSale` leaves it.

### Changed
* The capabilities route reports the version Composer installed (`Composer\InstalledVersions`; `composer-runtime-api` ^2.0 is required), or `unknown` for a copy outside Composer, so no constant has to follow the tags. `dev-main` is aliased as `0.1.x-dev`, so a branch install still satisfies the companion package's `^0.1`.
* `magento/module-sales` is required from 103.0.4 (Magento 2.4.4), the first release with `OrderMutexInterface`; the module is sequenced after `Magento_Store`, which it requires.

### Added
* `inventory:order-reservation:settle <order id>` closes an order's owed and refused rows without writing stock (`settled_by` `manual`), under the order's lock, and settles nothing when any id given is not an order entity id, for stock corrected by hand: without Inventory, the only way to close a row the registration gave up on. The admin message and `inventory:order-reservation:list` name it there instead of the compensation command, which needs Inventory.

## 0.1.0 (2026-10-28)

### Added
* The module: an order created through `POST /V1/orders` that carries `extension_attributes.reserve_stock` gets the stock side of a placed order. The order's save records one row per product (the sku and type as Magento's own placement reads them, the website, the stock it sells from, the store's mode), and the registration runs after the commit under Magento's order lock, in one transaction with each row's state. Nothing is registered twice; an update of an existing order sent with the attribute registers nothing. On a store with Magento Inventory the companion package `hyva-themes/magento2-inventory-order-reservation-msi` places the `order_placed` reservation for what each sku still owes; without Inventory the module registers the sale on the legacy stock item the way the checkout does, a line the item cannot cover deducted past zero as an admin order's is.
* Completion per row: the mode's handler answers for each owed sku (placed, balanced, or refused with the reason), and a refused row counts no attempt, is named with its reason by `inventory:order-reservation:list`, the admin message and `inventory:order-reservation:run <id>` (which exits non-zero for it), and is tried again by that command once a compensation has repaired it. A registration that keeps failing is given up on after 24 attempts with the reason kept; failing orders are tried last so they never hold the rest back.
* Readiness apart from the mode: `GET /V1/inventory/order-reservation` answers the module's version, the mode (`inventory` when `Magento_InventorySales` is enabled, `legacy` otherwise), the attribute, and `ready` with `problem`: whether the mode's handler package is installed, the Inventory modules it reads are enabled, and the website sells from a stock. A missing package or a disabled module is an installation problem, not an order's failure: orders are accepted and stay owed with no attempt counted, the admin message and the listing name the installation at once, and the first registration after the fix completes them. The route needs the ACL resource `Hyva_InventoryOrderReservation::capabilities`.
* Recovery: the order's next save, a cron job every five minutes for orders owed longer than two minutes, and `bin/magento inventory:order-reservation:run` by hand; `inventory:order-reservation:list` shows what is owed and why the last attempt failed; an admin system message for orders owed longer than an hour.
* The module installs with or without Magento Inventory: no class names an Inventory type, the mode comes from the module list, the website's stock from one query on `inventory_stock_sales_channel`, and the handlers are a lazy map the companion package adds to. `magento/module-catalog` is required; the Inventory packages are the companion's.
* Update with Hyvä POS together: `Hyva_Pos` plugs into the companion's handler, so an older `Hyva_Pos` with these packages leaves the register's source assignment before the placement unplugged, and these packages with the new `Hyva_Pos` but without the companion leave a Magento Inventory store's orders owed until it is installed.
