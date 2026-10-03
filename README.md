# VendorPulse for WooCommerce

VendorPulse helps an online store notice orders that need a human decision. It is an installable WordPress plugin for WooCommerce, with an operations queue and an internal follow-up record. It does not change an order's payment or fulfillment status, send customer messages, or pretend to solve the underlying issue automatically.

**Example:** An order has been marked “Processing” for three days. The team sees a *Dispatch follow-up* in VendorPulse, writes “Ask the warehouse whether this order shipped,” sets a date, and marks the follow-up as *Watching*. An internal order note records who made the update. If the order is still processing, it remains in the queue, even if someone marks the follow-up *Resolved*.

This is a portfolio prototype for a store's own operations team. The name “vendor” describes a store operator; this version does **not** integrate with Dokan, WCFM, or any multivendor marketplace. It uses WooCommerce order APIs, including its current order storage mechanism, rather than querying WordPress order tables directly. WooCommerce documents `wc_get_orders()` pagination and date filters and recommends using the order APIs for storage compatibility. See [order query documentation](https://developer.woocommerce.com/docs/features/orders/wc-get-orders/) and [HPOS guidance](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/).

## Try the fictional visual demo

Open [`docs/index.html`](docs/index.html) in a browser. Filter the example queue, click **Review**, save a next action and due date, then reset the samples. Changes persist only in the browser session. This page has no live store connection and is suitable for GitHub Pages from the `/docs` folder.

## Install and test locally

You need a local WordPress site with WooCommerce active, PHP 8.0 or newer, and a WooCommerce manager or administrator account. Use sample orders only until you have reviewed the workflow for a real store.

1. Upload `vendorpulse-woo-plugin.zip` through **WordPress → Plugins → Add New → Upload Plugin**, activate it, and verify the **WooCommerce → VendorPulse** menu appears. Or copy the repository's `vendorpulse-woo` folder to `wp-content/plugins/` and activate it.
2. Create a fictional WooCommerce order with status **Processing**, and adjust its creation date to more than 48 hours earlier in a disposable test store. Create another with status **On hold** older than 24 hours, and a recent **Failed** payment order. The sample data in the standalone HTML demo does not create these orders.
3. Open **WooCommerce → VendorPulse**. Review the order's reason, choose a follow-up state, write a next action, optionally add a due date and internal note, and save.
4. Reopen the order in WooCommerce and check its private order notes. Return to VendorPulse to verify the saved action and state. Test the queue's later pages if your store has many matching orders.
5. WordPress schedules a daily email summary to the site's admin address. It names its numbers as a **first-page sample**, with up to 25 matching orders from each status. Email delivery depends on the site's mail setup and WP-Cron running. No email is sent when that sample has no issues.

Thresholds are currently fixed at 48 hours for **Processing**, 24 hours for **On hold**, and 14 days for recent **Failed** payments. Follow-up states are Open, Watching, and Resolved. This version does not have configurable thresholds, assignment to individual staff, stock alerts, customer notifications, or performance claims.

## How the code is organized

| Path | Purpose |
| --- | --- |
| `vendorpulse-woo.php` | WordPress entry, activation, and HPOS compatibility declaration. |
| `includes/class-vendorpulse-rules.php` | Age and status rules, date and follow-up validation. |
| `includes/class-vendorpulse-woo.php` | WooCommerce order queries, dashboard, protected writes, internal notes, and scheduled digest. |
| `docs/index.html` | Standalone fictional browser demo. |
| `tests/operations-test.php` | Rule boundaries, query pagination, order metadata and note writes, and digest tests with WooCommerce test doubles. |
| `tests/woocommerce-integration.php` | Creates and deletes fictional orders in a disposable real WooCommerce installation to check queries and persisted follow-ups. |

The admin save action requires `manage_woocommerce` and an order-specific WordPress nonce. Follow-up input is sanitized and validated, and rendered data is escaped. Updates use WooCommerce order metadata and a private order note, so personal information already present in an order stays within the store's WordPress installation. Normal store administrators can see the order data. The plugin does not add public endpoints or a frontend collection form.

## Developer checks

Run these from the repository folder:

```sh
php tests/operations-test.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
# In a disposable WordPress installation with WooCommerce active:
wp eval-file /absolute/path/to/vendorpulse-woo/tests/woocommerce-integration.php --path=/absolute/path/to/wordpress
```

The first automated test uses WooCommerce test doubles; the second uses real WooCommerce and deletes the sample orders in a `finally` block. Neither substitutes for the local store walkthrough above, ideally with WooCommerce's High-Performance Order Storage enabled and disabled. The UI should be piloted with fictional orders first, particularly before enabling email summaries in a production store.

## License

GPL-2.0-or-later; see [`LICENSE`](LICENSE).
