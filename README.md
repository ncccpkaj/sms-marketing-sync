# SMS Marketing Sync

Author: Nayeem Hasan

Public WordPress plugin for connecting WooCommerce to the external SMS Marketing System.

## Requirements

- WordPress with WooCommerce active.
- PHP 8.1+ recommended.
- The external SMS Marketing System installed on the same server or another public URL.
- A shared sync secret matching the outer system `.env`.

## Setup

1. Upload the `sms-marketing-sync` folder to `wp-content/plugins/`.
2. Activate **SMS Marketing Sync** in WordPress.
3. Open Settings > SMS Marketing Sync.
4. Enter the SMS System URL, for example `https://your-domain.com/sms-system`.
5. Enter the same sync secret used in the outer system `.env`.
6. Choose which WooCommerce order statuses should sync.
7. Save and use **Test Connection**.

## Outer System Connection

The outer system repository should be downloaded, uploaded to hosting, configured through `.env`, installed through setup, and connected back to this plugin.

Use the single combined queue cron in the outer system:

```text
cron/run-queues.php?token=YOUR_CRON_TOKEN
```

You can call that by URL cron or PHP CLI cron. The outer system has a queue lock, so if a URL cron and CLI cron overlap, only one run processes queues and the other exits as locked.

## Import API

The plugin exposes authenticated REST endpoints for the outer system:

- `/wp-json/sms-marketing-sync/v1/orders`
- `/wp-json/sms-marketing-sync/v1/customers`
- `/wp-json/sms-marketing-sync/v1/users` for backward compatibility; it returns the same customer payload as `/customers`.

All import requests require:

- `X-SMS-Sync-Key`
- `X-SMS-Sync-Timestamp`
- `X-SMS-Sync-Signature`

The outer system generates these automatically. You only need to make sure the plugin sync secret matches `WORDPRESS_IMPORT_SECRET` or `SYNC_SECRET` in the outer system `.env`.

## Large Customer Imports

For large WooCommerce sites, use **WooCommerce customers** from the outer system settings page. The customer endpoint reads candidates in pages and only returns customers with a valid Bangladesh mobile number from:

- `billing_phone`
- `shipping_phone`
- `phone`
- `mobile`
- `user_phone`

Customers without a valid phone are skipped before they reach the outer SMS system.

The outer system controls HTTP timeout with `WORDPRESS_IMPORT_TIMEOUT`, measured in **seconds**. Example values:

- `25` means 25 seconds.
- `45` means 45 seconds.
- Do not use minutes here.

For 80k-100k+ customers, start with batch size `25` or `50`. Larger batches can timeout on shared hosting.

## PHP Deprecation Notices

If the WordPress admin shows deprecated notices from other plugins on PHP 8.4, disable display on production in `wp-config.php`:

```php
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
```

Deprecated notices from other plugins are different from fatal errors. If WordPress still shows a critical error, check `wp-content/debug.log` or the hosting PHP error log for the first `Fatal error` line.
