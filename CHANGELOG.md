# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

---

## Unreleased

### Added
* The module: an order created through `POST /V1/orders` that carries `extension_attributes.reserve_stock` gets the stock side of a placed order, the `order_placed` reservation through Magento Inventory's sales-event service on a store with Inventory, the legacy stock-item registration without it. The obligation is recorded with the order in its own save and registered after the commit under Magento's order lock, in one transaction with the completion marker; recovery on the order's next save, by cron and by `inventory:order-reservation:run`; `inventory:order-reservation:list`; an admin system message for orders owed longer than an hour; `GET /V1/inventory/order-reservation` for a client to read the module's version and the store's mode before it sends the attribute.
* Magento's repair tools coordinated: a manual compensation naming an order with an obligation completes the registration first and is read against the order's remaining inconsistency by Magento's own rule (already balanced, applied, partial with the remainder, refused with the discrepancy); the inconsistency listing leaves owed orders out; `inventory:reservation:create-compensations` reports what happened per row and exits non-zero on a refusal.
