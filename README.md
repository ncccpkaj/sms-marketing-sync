# SMS Marketing Sync

Author: Nayeem Hasan

Public WordPress plugin for connecting WooCommerce to the external SMS Marketing System.

## Setup

1. Upload the `sms-marketing-sync` folder to `wp-content/plugins/`.
2. Activate **SMS Marketing Sync** in WordPress.
3. Open Settings > SMS Marketing Sync.
4. Enter the SMS System URL, for example `https://your-domain.com/sms-system`.
5. Enter the same sync secret used in the outer system `.env`.
6. Choose which WooCommerce order statuses should sync.
7. Save and use **Test Connection**.

The outer system repository should be downloaded, uploaded to hosting, configured through `.env`, installed through setup, and connected back to this plugin. The recommended cron is the single combined queue endpoint:

```text
cron/run-queues.php?token=YOUR_CRON_TOKEN
```
