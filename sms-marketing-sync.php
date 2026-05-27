<?php
/**
 * Plugin Name: SMS Marketing Sync
 * Description: Lightweight WooCommerce order sync for the external SMS marketing system.
 * Version: 1.0.0
 * Author: Nayeem Hasan
 * Plugin URI: https://github.com/nayeemhasan/sms-marketing-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

const SMS_MARKETING_SYNC_OPTION = 'sms_marketing_sync_settings';
const SMS_MARKETING_SYNC_ACTION = 'sms_marketing_sync_send_order';
const SMS_MARKETING_SYNC_GROUP = 'sms-marketing-sync';

register_activation_hook(__FILE__, 'sms_marketing_sync_activate');
add_action('admin_menu', 'sms_marketing_sync_admin_menu');
add_action('admin_init', 'sms_marketing_sync_register_settings');
add_action('woocommerce_order_status_changed', 'sms_marketing_sync_maybe_schedule_order', 10, 4);
add_action(SMS_MARKETING_SYNC_ACTION, 'sms_marketing_sync_send_order');
add_action('rest_api_init', 'sms_marketing_sync_register_rest_routes');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sms_marketing_sync_action_links');

function sms_marketing_sync_activate(): void
{
    $defaults = sms_marketing_sync_default_settings();
    $current = get_option(SMS_MARKETING_SYNC_OPTION, []);
    update_option(SMS_MARKETING_SYNC_OPTION, array_merge($defaults, is_array($current) ? $current : []));
}

function sms_marketing_sync_default_settings(): array
{
    return [
        'api_base_url' => 'http://sms-marteting.test/sms-system',
        'sync_secret' => 'local-sync-secret-change-me',
        'sync_completed' => '1',
        'sync_processing' => '0',
    ];
}

function sms_marketing_sync_settings(): array
{
    $settings = get_option(SMS_MARKETING_SYNC_OPTION, []);
    return array_merge(sms_marketing_sync_default_settings(), is_array($settings) ? $settings : []);
}

function sms_marketing_sync_admin_menu(): void
{
    add_options_page(
        'SMS Marketing Sync',
        'SMS Marketing Sync',
        'manage_options',
        'sms-marketing-sync',
        'sms_marketing_sync_settings_page'
    );
}

function sms_marketing_sync_action_links(array $links): array
{
    $settingsLink = '<a href="' . esc_url(admin_url('options-general.php?page=sms-marketing-sync')) . '">' . esc_html__('Settings', 'sms-marketing-sync') . '</a>';
    array_unshift($links, $settingsLink);
    return $links;
}

function sms_marketing_sync_register_settings(): void
{
    register_setting('sms_marketing_sync', SMS_MARKETING_SYNC_OPTION, [
        'sanitize_callback' => 'sms_marketing_sync_sanitize_settings',
    ]);
}

function sms_marketing_sync_sanitize_settings(array $input): array
{
    return [
        'api_base_url' => esc_url_raw(rtrim((string) ($input['api_base_url'] ?? ''), '/')),
        'sync_secret' => sanitize_text_field((string) ($input['sync_secret'] ?? '')),
        'sync_completed' => empty($input['sync_completed']) ? '0' : '1',
        'sync_processing' => empty($input['sync_processing']) ? '0' : '1',
    ];
}

function sms_marketing_sync_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = sms_marketing_sync_settings();
    $testMessage = '';
    $testClass = 'notice notice-info';

    if (isset($_POST['sms_marketing_sync_test'])) {
        check_admin_referer('sms_marketing_sync_test');
        $response = wp_remote_get(rtrim($settings['api_base_url'], '/') . '/api/ping.php', [
            'timeout' => 10,
            'headers' => ['X-SMS-Sync-Key' => $settings['sync_secret']],
        ]);
        if (is_wp_error($response)) {
            $testMessage = $response->get_error_message();
            $testClass = 'notice notice-error';
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $testMessage = 'Ping response HTTP ' . $code . ': ' . wp_remote_retrieve_body($response);
            $testClass = $code >= 200 && $code < 300 ? 'notice notice-success' : 'notice notice-error';
        }
    }
    ?>
    <div class="wrap">
        <style>
            .sms-sync-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;max-width:1040px}
            .sms-sync-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .sms-sync-card h2{margin-top:0}
            .sms-sync-meta{display:grid;gap:10px}
            .sms-sync-meta code{display:block;white-space:normal}
            @media(max-width:900px){.sms-sync-grid{grid-template-columns:1fr}}
        </style>
        <h1>SMS Marketing Sync</h1>
        <?php if ($testMessage): ?>
            <div class="<?php echo esc_attr($testClass); ?>"><p><?php echo esc_html($testMessage); ?></p></div>
        <?php endif; ?>
        <div class="sms-sync-grid">
            <div class="sms-sync-card">
                <h2>Connection Settings</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('sms_marketing_sync'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="sms-api-base-url">SMS System URL</label></th>
                            <td><input id="sms-api-base-url" class="regular-text" name="<?php echo esc_attr(SMS_MARKETING_SYNC_OPTION); ?>[api_base_url]" value="<?php echo esc_attr($settings['api_base_url']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sms-sync-secret">Sync Secret</label></th>
                            <td><input id="sms-sync-secret" class="regular-text" name="<?php echo esc_attr(SMS_MARKETING_SYNC_OPTION); ?>[sync_secret]" value="<?php echo esc_attr($settings['sync_secret']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row">Sync Order Statuses</th>
                            <td>
                                <label><input type="checkbox" name="<?php echo esc_attr(SMS_MARKETING_SYNC_OPTION); ?>[sync_completed]" value="1" <?php checked($settings['sync_completed'], '1'); ?>> Completed</label><br>
                                <label><input type="checkbox" name="<?php echo esc_attr(SMS_MARKETING_SYNC_OPTION); ?>[sync_processing]" value="1" <?php checked($settings['sync_processing'], '1'); ?>> Processing</label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </form>
                <form method="post">
                    <?php wp_nonce_field('sms_marketing_sync_test'); ?>
                    <?php submit_button('Test Connection', 'secondary', 'sms_marketing_sync_test'); ?>
                </form>
            </div>
            <div class="sms-sync-card sms-sync-meta">
                <h2>Repository</h2>
                <p>This public plugin repository is maintained by Nayeem Hasan.</p>
                <p><a class="button button-secondary" href="https://github.com/nayeemhasan/sms-marketing-sync" target="_blank" rel="noopener">Open GitHub Repository</a></p>
                <p><strong>Outer system setup:</strong> download the SMS system from GitHub, upload it to hosting, edit `.env`, run setup, add the single queue cron, then paste the system URL and secret here.</p>
                <code>cron/run-queues.php?token=YOUR_CRON_TOKEN</code>
            </div>
        </div>
    </div>
    <?php
}

function sms_marketing_sync_register_rest_routes(): void
{
    register_rest_route('sms-marketing-sync/v1', '/orders', [
        'methods' => 'GET',
        'callback' => 'sms_marketing_sync_rest_orders',
        'permission_callback' => 'sms_marketing_sync_rest_permission',
    ]);
}

function sms_marketing_sync_rest_permission(WP_REST_Request $request): bool
{
    $settings = sms_marketing_sync_settings();
    $secret = (string) ($request->get_header('x-sms-sync-key') ?: $request->get_param('secret'));
    return $secret !== '' && hash_equals((string) $settings['sync_secret'], $secret);
}

function sms_marketing_sync_rest_orders(WP_REST_Request $request): WP_REST_Response
{
    if (!function_exists('wc_get_orders')) {
        return new WP_REST_Response(['ok' => false, 'message' => 'WooCommerce is not available.'], 500);
    }

    $page = max(1, (int) $request->get_param('page'));
    $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 50)));
    $statuses = array_filter(array_map('sanitize_key', explode(',', (string) ($request->get_param('statuses') ?: 'completed,processing'))));
    $statuses = array_map(static fn ($status) => str_starts_with($status, 'wc-') ? $status : 'wc-' . $status, $statuses);

    $result = wc_get_orders([
        'status' => $statuses,
        'limit' => $perPage,
        'page' => $page,
        'orderby' => 'date',
        'order' => 'ASC',
        'paginate' => true,
    ]);

    $orders = [];
    foreach ($result->orders as $order) {
        if (!$order instanceof WC_Order) {
            continue;
        }
        $phone = sms_marketing_sync_normalize_bd_phone($order->get_billing_phone());
        if ($phone === null) {
            continue;
        }
        $orders[] = sms_marketing_sync_build_order_payload($order, $phone);
    }

    return new WP_REST_Response([
        'ok' => true,
        'page' => $page,
        'per_page' => $perPage,
        'total' => (int) $result->total,
        'max_pages' => (int) $result->max_num_pages,
        'orders' => $orders,
    ]);
}

function sms_marketing_sync_enabled_statuses(): array
{
    $settings = sms_marketing_sync_settings();
    $statuses = [];
    if ($settings['sync_completed'] === '1') {
        $statuses[] = 'completed';
    }
    if ($settings['sync_processing'] === '1') {
        $statuses[] = 'processing';
    }
    return $statuses;
}

function sms_marketing_sync_maybe_schedule_order(int $orderId, string $oldStatus, string $newStatus, $order): void
{
    if (!function_exists('wc_get_order')) {
        return;
    }

    if (!in_array($newStatus, sms_marketing_sync_enabled_statuses(), true)) {
        return;
    }

    $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
    if (!$order) {
        return;
    }

    $normalizedPhone = sms_marketing_sync_normalize_bd_phone($order->get_billing_phone());
    if ($normalizedPhone === null) {
        $order->update_meta_data('_sms_marketing_sync_status', 'skipped_invalid_phone');
        $order->save();
        return;
    }

    $args = ['order_id' => $orderId];
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action(SMS_MARKETING_SYNC_ACTION, $args, SMS_MARKETING_SYNC_GROUP, true);
    } else {
        wp_schedule_single_event(time() + 10, SMS_MARKETING_SYNC_ACTION, $args);
    }

    $order->update_meta_data('_sms_marketing_sync_status', 'scheduled');
    $order->save();
}

function sms_marketing_sync_send_order(int $orderId): void
{
    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($orderId);
    if (!$order) {
        return;
    }

    $phone = sms_marketing_sync_normalize_bd_phone($order->get_billing_phone());
    if ($phone === null) {
        $order->update_meta_data('_sms_marketing_sync_status', 'skipped_invalid_phone');
        $order->save();
        return;
    }

    $settings = sms_marketing_sync_settings();
    $payload = sms_marketing_sync_build_order_payload($order, $phone);
    $response = wp_remote_post(rtrim($settings['api_base_url'], '/') . '/api/order-sync.php', [
        'timeout' => 20,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-SMS-Sync-Key' => $settings['sync_secret'],
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        $order->update_meta_data('_sms_marketing_sync_status', 'failed');
        $order->update_meta_data('_sms_marketing_sync_error', $response->get_error_message());
        $order->save();
        throw new RuntimeException($response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $order->update_meta_data('_sms_marketing_sync_http_code', $code);
    $order->update_meta_data('_sms_marketing_sync_response', wp_trim_words($body, 40));
    $order->update_meta_data('_sms_marketing_sync_status', $code >= 200 && $code < 300 ? 'synced' : 'failed');
    $order->save();

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('SMS sync failed with HTTP ' . $code . ': ' . $body);
    }
}

function sms_marketing_sync_build_order_payload(WC_Order $order, string $normalizedPhone): array
{
    $created = $order->get_date_created();
    $billingName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $billingAddress = trim(implode(', ', array_filter([
        $order->get_billing_address_1(),
        $order->get_billing_address_2(),
        $order->get_billing_city(),
        $order->get_billing_state(),
        $order->get_billing_postcode(),
        $order->get_billing_country(),
    ])));

    $items = [];
    foreach ($order->get_items('line_item') as $item) {
        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }
        $product = $item->get_product();
        if (!$product) {
            continue;
        }
        $parentId = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $quantity = max(1, (int) $item->get_quantity());
        $lineTotal = (float) $item->get_total();

        $items[] = [
            'product_id' => $parentId,
            'variation_id' => $product->is_type('variation') ? $product->get_id() : 0,
            'product_name' => $item->get_name(),
            'product_type' => $product->get_type(),
            'quantity' => $quantity,
            'unit_total' => $quantity > 0 ? $lineTotal / $quantity : $lineTotal,
            'line_total' => $lineTotal,
            'categories' => sms_marketing_sync_terms($parentId, 'product_cat'),
            'brands' => sms_marketing_sync_product_brands($parentId),
        ];
    }

    return [
        'order_id' => $order->get_id(),
        'status' => $order->get_status(),
        'order_date' => $created ? $created->date('Y-m-d H:i:s') : current_time('mysql'),
        'total' => (float) $order->get_total(),
        'billing' => [
            'name' => $billingName,
            'phone' => $order->get_billing_phone(),
            'phone_normalized' => $normalizedPhone,
            'email' => $order->get_billing_email(),
            'address' => $billingAddress,
        ],
        'items' => $items,
    ];
}

function sms_marketing_sync_terms(int $productId, string $taxonomy): array
{
    if (!$productId || !taxonomy_exists($taxonomy)) {
        return [];
    }

    $terms = get_the_terms($productId, $taxonomy);
    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    return array_values(array_map(static fn ($term) => [
        'name' => $term->name,
        'slug' => $term->slug,
    ], $terms));
}

function sms_marketing_sync_product_brands(int $productId): array
{
    $brands = [];
    foreach (['product_brand', 'pa_brand'] as $taxonomy) {
        foreach (sms_marketing_sync_terms($productId, $taxonomy) as $term) {
            $term['source'] = $taxonomy;
            $brands[$term['source'] . ':' . $term['slug']] = $term;
        }
    }
    return array_values($brands);
}

function sms_marketing_sync_normalize_bd_phone(?string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') {
        return null;
    }

    if (str_starts_with($digits, '0088')) {
        $digits = substr($digits, 2);
    }

    if (str_starts_with($digits, '88') && strlen($digits) === 13) {
        $local = substr($digits, 2);
    } elseif (str_starts_with($digits, '01') && strlen($digits) === 11) {
        $local = $digits;
    } else {
        return null;
    }

    if (!preg_match('/^01[3-9]\d{8}$/', $local)) {
        return null;
    }

    $tail = substr($local, 3);
    if (preg_match('/^0+$/', $tail) || preg_match('/^(\d)\1{7}$/', $tail)) {
        return null;
    }

    if (in_array($local, ['01700000000', '01800000000', '01900000000', '01600000000', '01500000000', '01400000000', '01300000000'], true)) {
        return null;
    }

    return '88' . $local;
}
