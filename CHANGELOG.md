# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

---

## Unreleased

### Fixed
* Magento Inventory counts as present only with its modules enabled: a store that disabled them (the usual way to run without Inventory, since the metapackage installs the packages) had every order sent with `reserve_stock` fail with an `\Error` inside the order's save, and the capabilities route reported mode `inventory`.
* An order's lines are recorded per product, under the catalog's sku and type, as Magento's own placement reads them: a line with custom-option skus, a grouped product's child, a configurable or bundle parent in the payload (whether or not it links its child) all land on the product that holds the stock. The row carries the product id and a sku as long as the order's.
* New is decided before the save: an update of an existing order sent with `reserve_stock` no longer registers its stock again.
* The registration gives an order up after 24 failed attempts (`refused`, the reason kept): Magento's inconsistency listing shows the order again, a compensation settles it, `inventory:order-reservation:run <id>` tries it once more, and `:list` and the admin message name it. Failing orders are tried last, so they never hold the rest back, and only the first failure and the give-up log at warning.
* Without Inventory, a line the stock item cannot cover (backorders off) is deducted past zero as an admin order's is, instead of staying owed; rows are registered by product id, whatever the sku's spelling.
* The remaining inconsistency reads a configurable or ship-together bundle child through its parent, as Magento's calculator does, and leaves out a product whose stock is not managed.
* Reservation rows are matched on the order id as JSON, not on a LIKE pattern an id could share.
* `inventory:reservation:create-compensations` runs Magento's own loop (header, standard input, parse errors, exit code, every other plugin on the command); the module adds notes under its output: the remainder after a partial compensation, a registration completed first, the "Decrease Stock When Order is Placed" setting under which Magento writes nothing while printing every row as compensated. The coordination writes the rows it has no say in before each coordinated one, so a refusal leaves only whole writes behind it.
* A compensation that balances one sku of an order the registration gave up on settles that sku's obligation only; before, it settled every sku of the order.

### Changed
* An already balanced compensation is refused as such (`CouldNotSaveException`, "already balanced, nothing written") instead of being reported as a plain line; nothing is written either way.
* The table is `hyva_inventory_order_stock_registration` (the data moves over) and the cron job `hyva_inventory_order_reservation_recover`; `magento/module-catalog` is required and the Inventory packages the module reads are suggested; file headers and the phpcs ruleset name are the module's own.

### Added
* The module: an order created through `POST /V1/orders` that carries `extension_attributes.reserve_stock` gets the stock side of a placed order, the `order_placed` reservation through Magento Inventory's sales-event service on a store with Inventory, the legacy stock-item registration without it. The obligation is recorded with the order in its own save and registered after the commit under Magento's order lock, in one transaction with the completion marker; recovery on the order's next save, by cron and by `inventory:order-reservation:run`; `inventory:order-reservation:list`; an admin system message for orders owed longer than an hour; `GET /V1/inventory/order-reservation` for a client to read the module's version and the store's mode before it sends the attribute.
* Magento's repair tools coordinated: a manual compensation naming an order with an obligation completes the registration first and is read against the order's remaining inconsistency by Magento's own rule (already balanced, applied, partial with the remainder, refused with the discrepancy); the inconsistency listing leaves owed orders out; `inventory:reservation:create-compensations` exits non-zero on a refusal, as Magento's own, and adds the module's notes under its output.
