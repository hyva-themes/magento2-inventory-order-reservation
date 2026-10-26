# Proposal: the stock side of a placed order for orders created through the order API

A draft for Mage-OS first, then `magento/inventory` (issue #3185, Adobe ticket MC-33287).

## The gap

`POST /V1/orders` is `OrderRepositoryInterface::save`. It creates an order without the placement lifecycle: no `order_placed` reservation with Inventory (placed by a plugin on `OrderManagementInterface::place`, which the cart flow calls and which has no REST route), no legacy stock deduction without it (`SubtractQuoteInventoryObserver` runs on quote submit alone). Reservations have no API of their own.

Any integration that creates orders this way and ships them (a point of sale, an ERP booking counter sales, a marketplace connector) leaves the website's salable quantity overstated by what it sold: the shipment deducts the source and adds its compensating reservation, and the placement that should balance it never happened. Reported in January 2020, confirmed by Adobe in April 2020 and again in November 2025 on 2.4-develop, unfixed.

## Why not reroute the create endpoint

The reporter's patch points `/V1/orders/create` at `OrderService::place()`. That runs the full placement lifecycle (payment placement, the place events, every observer a store's modules hang on them) for every order created through the API, which changes behaviour for every existing integration, ERP imports of already-shipped orders included. Stores that manage stock in an ERP install modules to disable reservations altogether; they must not gain any.

## The proposal: an opt-in stock registration

One boolean extension attribute on the order, `reserve_stock`, absent by default. When a new order carries it:

- with Inventory: the `order_placed` reservation for the order's lines through `PlaceReservationsForSalesEventInterface`, so the shipment's compensation balances it and cancellation and credit memos keep balancing it;
- without Inventory: the legacy sale registration on the stock items (`StockManagement::registerProductsSale`).

The obligation is recorded with the order in its own save (one row per sku with the quantity ordered at creation), and registered after the commit under `OrderMutexInterface`, in one transaction with the completion marker: a failure rolls both back and the obligation stays owed; recovery on the order's next save, by cron, and by a console command; an admin message when an obligation is owed longer than an hour.

Magento's repair tools are coordinated rather than bypassed: a manual compensation naming such an order completes the registration first and is read against the order's remaining inconsistency by the existing rule (ordered less cancelled, refunded, shipped; zero in a final state); the inconsistency listing leaves owed orders out; the compensation command reports per row what happened and exits non-zero on a refusal.

## Shape of the upstream change

- `Magento_InventorySales`: the attribute, the observer pair, the registration service and the Inventory handler.
- `Magento_CatalogInventory`: the legacy handler.
- `Magento_InventoryReservationCli`: the listing exclusion and the command reporting.

The standalone package `hyva-themes/magento2-inventory-order-reservation` carries the same code product-neutrally, with unit tests, and is the reference implementation for this proposal.
