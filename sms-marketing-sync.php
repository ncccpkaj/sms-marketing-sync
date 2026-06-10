<?php
/**
 * Plugin Name: SMS Marketing Sync
 * Description: Lightweight WooCommerce order sync for the external SMS marketing system.
 * Version: 1.0.2
 * Author: Nayeem Hasan
 * Plugin URI: https://github.com/ncccpkaj/sms-marketing-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

const SMS_MARKETING_SYNC_OPTION = 'sms_marketing_sync_settings';
const SMS_MARKETING_SYNC_ACTION = 'sms_marketing_sync_send_order';
const SMS_MARKETING_SYNC_GROUP = 'sms-marketing-sync';

register_activation_hook(__FILE__, 'sms_marketing_sync_activate');
add_action('admin_menu', 'sms_marketing_sync_admin_menu');
add_action('admin_init', 'sms_marketing_sync_admin_error_display_guard', 0);
add_action('admin_init', 'sms_marketing_sync_register_settings');
add_action('woocommerce_order_status_changed', 'sms_marketing_sync_maybe_schedule_order', 10, 4);
add_action(SMS_MARKETING_SYNC_ACTION, 'sms_marketing_sync_send_order');
add_action('rest_api_init', 'sms_marketing_sync_register_rest_routes');
add_action('woocommerce_rest_insert_shop_order_object', 'sms_marketing_sync_maybe_schedule_rest_order', 10, 3);
add_action('woocommerce_rest_insert_shop_order', 'sms_marketing_sync_maybe_schedule_legacy_rest_order', 10, 3);
add_filter('handle_bulk_actions-edit-shop_order', 'sms_marketing_sync_maybe_schedule_bulk_orders', 100, 3);
add_filter('handle_bulk_actions-woocommerce_page_wc-orders', 'sms_marketing_sync_maybe_schedule_bulk_orders', 100, 3);
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

function sms_marketing_sync_admin_error_display_guard(): void
{
    if (!is_admin() || (string) ($_GET['page'] ?? '') !== 'sms-marketing-sync') {
        return;
    }

    if (defined('E_DEPRECATED')) {
        error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    }
    @ini_set('display_errors', '0');
}

function sms_marketing_sync_sanitize_settings($input): array
{
    $input = is_array($input) ? $input : [];

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
                            <td><input id="sms-api-base-url" class="regular-text" style="height:38px;border: 1px solid #eee;border-radius: 8px;padding:4px 8px" name="<?php echo esc_attr(SMS_MARKETING_SYNC_OPTION); ?>[api_base_url]" value="<?php echo esc_attr($settings['api_base_url']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sms-sync-secret">Sync Secret</label></th>
                            <td><input id="sms-sync-secret" class="regular-text" style="height:38px;border: 1px solid #eee;border-radius: 8px;padding:4px 8px" name="<?php echo esc_attr(SMS_MARKETING_SYNC_OPTION); ?>[sync_secret]" value="<?php echo esc_attr($settings['sync_secret']); ?>"></td>
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
                <p><a class="button button-secondary" href="https://github.com/ncccpkaj/sms-marketing-sync" target="_blank" rel="noopener">Open GitHub Repository</a></p>
                <p><strong>Outer system setup:</strong> download the SMS system from GitHub, upload it to hosting, edit `.env`, run setup, add the single queue cron, then paste the system URL and secret here.</p>
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
    register_rest_route('sms-marketing-sync/v1', '/users', [
        'methods' => 'GET',
        'callback' => 'sms_marketing_sync_rest_customers',
        'permission_callback' => 'sms_marketing_sync_rest_permission',
    ]);
    register_rest_route('sms-marketing-sync/v1', '/customers', [
        'methods' => 'GET',
        'callback' => 'sms_marketing_sync_rest_customers',
        'permission_callback' => 'sms_marketing_sync_rest_permission',
    ]);
}

function sms_marketing_sync_rest_permission(WP_REST_Request $request)
{
    $settings = sms_marketing_sync_settings();
    $secret = (string) $settings['sync_secret'];
    $sentKey = (string) $request->get_header('x-sms-sync-key');
    $timestamp = (string) $request->get_header('x-sms-sync-timestamp');
    $signature = (string) $request->get_header('x-sms-sync-signature');

    if ($secret === '' || $sentKey === '' || !hash_equals($secret, $sentKey)) {
        return new WP_Error('sms_sync_unauthorized', 'Unauthorized.', ['status' => 401]);
    }
    if ($timestamp === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
        return new WP_Error('sms_sync_stale_request', 'Request timestamp is invalid or expired.', ['status' => 401]);
    }
    if ($signature === '') {
        return new WP_Error('sms_sync_missing_signature', 'Request signature is required.', ['status' => 401]);
    }

    $query = $request->get_query_params();
    unset($query['secret']);
    ksort($query);
    $queryString = http_build_query($query);
    $base = strtoupper($request->get_method()) . "\n" . $request->get_route() . "\n" . $queryString . "\n" . $timestamp;
    $expected = hash_hmac('sha256', $base, $secret);

    if (!hash_equals($expected, $signature)) {
        return new WP_Error('sms_sync_bad_signature', 'Request signature is invalid.', ['status' => 401]);
    }

    return true;
}

function sms_marketing_sync_rest_orders(WP_REST_Request $request): WP_REST_Response
{
    if (!function_exists('wc_get_orders')) {
        return new WP_REST_Response(['ok' => false, 'message' => 'WooCommerce is not available.'], 500);
    }

    $page = max(1, (int) $request->get_param('page'));
    $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 50)));
    $statuses = array_filter(array_map('sanitize_key', explode(',', (string) ($request->get_param('statuses') ?: 'completed,processing'))));
    $statuses = array_map('sms_marketing_sync_normalize_wc_status', $statuses);

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

function sms_marketing_sync_rest_customers(WP_REST_Request $request): WP_REST_Response
{
    $page = max(1, (int) $request->get_param('page'));
    $perPage = max(1, min(100, (int) ($request->get_param('per_page') ?: 50)));

    $result = sms_marketing_sync_query_customer_candidates($page, $perPage);
    $customerIds = array_map('sms_marketing_sync_customer_row_id', $result['rows']);
    if ($customerIds) {
        update_meta_cache('user', $customerIds);
    }

    $customers = [];
    foreach ($result['rows'] as $row) {
        $payload = sms_marketing_sync_build_customer_payload_from_row($row);
        if ($payload !== null) {
            $customers[] = $payload;
        }
    }

    return new WP_REST_Response([
        'ok' => true,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $result['total'],
        'max_pages' => (int) ceil($result['total'] / $perPage),
        'customers' => $customers,
        'users' => $customers,
    ]);
}

function sms_marketing_sync_query_customer_candidates(int $page, int $perPage): array
{
    global $wpdb;

    $offset = ($page - 1) * $perPage;
    $capKey = $wpdb->prefix . 'capabilities';
    $lookupTable = $wpdb->prefix . 'wc_customer_lookup';
    $lookupExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookupTable)) === $lookupTable;
    $lookupJoin = $lookupExists ? "LEFT JOIN {$lookupTable} wccl ON wccl.user_id = u.ID" : '';
    $lookupWhere = $lookupExists ? ' OR wccl.user_id IS NOT NULL' : '';

    $from = "{$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} pm ON pm.user_id = u.ID
            AND pm.meta_key IN ('billing_phone', 'shipping_phone', 'phone', 'mobile', 'user_phone')
            AND pm.meta_value <> ''
        LEFT JOIN {$wpdb->usermeta} caps ON caps.user_id = u.ID AND caps.meta_key = %s
        {$lookupJoin}";
    $where = "(caps.meta_value LIKE %s{$lookupWhere})";

    $sql = $wpdb->prepare(
        "SELECT u.ID, u.user_email, u.display_name
         FROM {$from}
         WHERE {$where}
         GROUP BY u.ID
         ORDER BY u.ID ASC
         LIMIT %d OFFSET %d",
        $capKey,
        '%customer%',
        $perPage,
        $offset
    );
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

    $countSql = $wpdb->prepare(
        "SELECT COUNT(DISTINCT u.ID) FROM {$from} WHERE {$where}",
        $capKey,
        '%customer%'
    );
    $total = (int) $wpdb->get_var($countSql);

    return ['rows' => $rows, 'total' => $total];
}

function sms_marketing_sync_customer_row_id(array $row): int
{
    return (int) ($row['ID'] ?? 0);
}

function sms_marketing_sync_normalize_wc_status(string $status): string
{
    return sms_marketing_sync_starts_with($status, 'wc-') ? $status : 'wc-' . $status;
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

    $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
    if (!$order instanceof WC_Order) {
        return;
    }

    sms_marketing_sync_maybe_schedule_order_object($order, $newStatus, 'status_changed');
}

function sms_marketing_sync_maybe_schedule_rest_order($order, $request, bool $creating): void
{
    if (!$order instanceof WC_Order || !$request instanceof WP_REST_Request) {
        return;
    }

    if ($request->get_param('status') === null) {
        return;
    }

    sms_marketing_sync_maybe_schedule_order_object($order, $order->get_status(), $creating ? 'rest_create' : 'rest_update');
}

function sms_marketing_sync_maybe_schedule_legacy_rest_order($post, $request, bool $creating): void
{
    if (!function_exists('wc_get_order') || !$request instanceof WP_REST_Request || $request->get_param('status') === null) {
        return;
    }

    $orderId = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
    $order = $orderId > 0 ? wc_get_order($orderId) : null;
    if (!$order instanceof WC_Order) {
        return;
    }

    sms_marketing_sync_maybe_schedule_order_object($order, $order->get_status(), $creating ? 'legacy_rest_create' : 'legacy_rest_update');
}

function sms_marketing_sync_maybe_schedule_bulk_orders(string $redirectTo, string $action, array $orderIds): string
{
    if (!function_exists('wc_get_order') || !sms_marketing_sync_starts_with($action, 'mark_')) {
        return $redirectTo;
    }

    foreach ($orderIds as $orderId) {
        $order = wc_get_order((int) $orderId);
        if ($order instanceof WC_Order) {
            sms_marketing_sync_maybe_schedule_order_object($order, $order->get_status(), 'admin_bulk_' . sanitize_key($action));
        }
    }

    return $redirectTo;
}

function sms_marketing_sync_maybe_schedule_order_object(WC_Order $order, string $newStatus, string $source): void
{
    if (!in_array($newStatus, sms_marketing_sync_enabled_statuses(), true)) {
        return;
    }

    $normalizedPhone = sms_marketing_sync_normalize_bd_phone($order->get_billing_phone());
    if ($normalizedPhone === null) {
        $order->update_meta_data('_sms_marketing_sync_status', 'skipped_invalid_phone');
        $order->update_meta_data('_sms_marketing_sync_error', 'Invalid Bangladesh billing phone.');
        $order->save();
        return;
    }

    $orderId = (int) $order->get_id();
    $args = [$orderId];
    $actionId = 0;

    if (function_exists('as_enqueue_async_action')) {
        if (sms_marketing_sync_has_pending_action($args)) {
            $order->update_meta_data('_sms_marketing_sync_status', 'scheduled');
            $order->update_meta_data('_sms_marketing_sync_source', $source);
            $order->save();
            return;
        }

        $actionId = as_enqueue_async_action(SMS_MARKETING_SYNC_ACTION, $args, SMS_MARKETING_SYNC_GROUP, false);
    } else {
        $scheduled = wp_schedule_single_event(time() + 10, SMS_MARKETING_SYNC_ACTION, $args);
        $actionId = $scheduled ? 1 : 0;
    }

    if (!$actionId) {
        $order->update_meta_data('_sms_marketing_sync_status', 'failed_schedule');
        $order->update_meta_data('_sms_marketing_sync_error', 'Could not enqueue sync action.');
        $order->save();
        return;
    }

    $order->update_meta_data('_sms_marketing_sync_status', 'scheduled');
    $order->update_meta_data('_sms_marketing_sync_action_id', $actionId);
    $order->update_meta_data('_sms_marketing_sync_source', $source);
    $order->update_meta_data('_sms_marketing_sync_scheduled_at', current_time('mysql'));
    $order->delete_meta_data('_sms_marketing_sync_error');
    $order->save();
}

function sms_marketing_sync_has_pending_action(array $args): bool
{
    if (function_exists('as_next_scheduled_action')) {
        return (bool) as_next_scheduled_action(SMS_MARKETING_SYNC_ACTION, $args, SMS_MARKETING_SYNC_GROUP);
    }

    if (function_exists('as_next_scheduled')) {
        return (bool) as_next_scheduled(SMS_MARKETING_SYNC_ACTION, $args, SMS_MARKETING_SYNC_GROUP);
    }

    return false;
}

function sms_marketing_sync_send_order($orderId): void
{
    if (!function_exists('wc_get_order')) {
        return;
    }

    if (is_array($orderId)) {
        $orderId = (int) ($orderId['order_id'] ?? reset($orderId));
    }
    $orderId = (int) $orderId;

    $order = wc_get_order($orderId);
    if (!$order) {
        return;
    }

    if (!in_array($order->get_status(), sms_marketing_sync_enabled_statuses(), true)) {
        $order->update_meta_data('_sms_marketing_sync_status', 'skipped_status');
        $order->update_meta_data('_sms_marketing_sync_error', 'Order status is no longer enabled for sync.');
        $order->save();
        return;
    }

    $phone = sms_marketing_sync_normalize_bd_phone($order->get_billing_phone());
    if ($phone === null) {
        $order->update_meta_data('_sms_marketing_sync_status', 'skipped_invalid_phone');
        $order->update_meta_data('_sms_marketing_sync_error', 'Invalid Bangladesh billing phone.');
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
    $decoded = json_decode($body, true);
    $apiRejected = is_array($decoded) && (
        (array_key_exists('ok', $decoded) && $decoded['ok'] === false)
        || (array_key_exists('success', $decoded) && $decoded['success'] === false)
    );

    $order->update_meta_data('_sms_marketing_sync_http_code', $code);
    $order->update_meta_data('_sms_marketing_sync_response', wp_trim_words($body, 40));
    $order->update_meta_data('_sms_marketing_sync_status', $code >= 200 && $code < 300 && !$apiRejected ? 'synced' : 'failed');
    $order->save();

    if ($code < 200 || $code >= 300 || $apiRejected) {
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
        $productId = (int) $item->get_product_id();
        $variationId = (int) $item->get_variation_id();
        $parentId = $product ? ($product->is_type('variation') ? $product->get_parent_id() : $product->get_id()) : $productId;
        $quantity = max(1, (int) $item->get_quantity());
        $lineTotal = (float) $item->get_total();

        $items[] = [
            'product_id' => $parentId,
            'variation_id' => $product ? ($product->is_type('variation') ? $product->get_id() : 0) : $variationId,
            'product_name' => $item->get_name() ?: 'Deleted product #' . ($productId ?: $variationId),
            'product_type' => $product ? $product->get_type() : 'deleted',
            'quantity' => $quantity,
            'unit_total' => $quantity > 0 ? $lineTotal / $quantity : $lineTotal,
            'line_total' => $lineTotal,
            'categories' => $product ? sms_marketing_sync_terms($parentId, 'product_cat') : [],
            'brands' => $product ? sms_marketing_sync_product_brands($parentId) : [],
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

function sms_marketing_sync_build_user_payload(WP_User $user): ?array
{
    $phone = '';
    foreach (['billing_phone', 'phone', 'mobile', 'user_phone'] as $metaKey) {
        $phone = (string) get_user_meta($user->ID, $metaKey, true);
        if ($phone !== '') {
            break;
        }
    }

    $normalizedPhone = sms_marketing_sync_normalize_bd_phone($phone);
    if ($normalizedPhone === null) {
        return null;
    }

    $firstName = (string) get_user_meta($user->ID, 'first_name', true);
    $lastName = (string) get_user_meta($user->ID, 'last_name', true);
    $billingName = trim((string) get_user_meta($user->ID, 'billing_first_name', true) . ' ' . (string) get_user_meta($user->ID, 'billing_last_name', true));
    $name = trim($billingName ?: trim($firstName . ' ' . $lastName) ?: $user->display_name);
    $address = trim(implode(', ', array_filter([
        get_user_meta($user->ID, 'billing_address_1', true),
        get_user_meta($user->ID, 'billing_address_2', true),
        get_user_meta($user->ID, 'billing_city', true),
        get_user_meta($user->ID, 'billing_state', true),
        get_user_meta($user->ID, 'billing_postcode', true),
        get_user_meta($user->ID, 'billing_country', true),
    ])));

    return [
        'user_id' => $user->ID,
        'roles' => array_values(array_intersect(['subscriber', 'customer'], (array) $user->roles)),
        'name' => $name,
        'phone' => $phone,
        'phone_normalized' => $normalizedPhone,
        'email' => (string) $user->user_email,
        'address' => $address,
    ];
}

function sms_marketing_sync_build_customer_payload_from_row(array $row): ?array
{
    $userId = (int) ($row['ID'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $phone = '';
    foreach (['billing_phone', 'shipping_phone', 'phone', 'mobile', 'user_phone'] as $metaKey) {
        $phone = (string) get_user_meta($userId, $metaKey, true);
        if ($phone !== '') {
            break;
        }
    }

    $normalizedPhone = sms_marketing_sync_normalize_bd_phone($phone);
    if ($normalizedPhone === null) {
        return null;
    }

    $billingName = trim((string) get_user_meta($userId, 'billing_first_name', true) . ' ' . (string) get_user_meta($userId, 'billing_last_name', true));
    $shippingName = trim((string) get_user_meta($userId, 'shipping_first_name', true) . ' ' . (string) get_user_meta($userId, 'shipping_last_name', true));
    $name = trim($billingName ?: $shippingName ?: (string) ($row['display_name'] ?? ''));
    $address = trim(implode(', ', array_filter([
        get_user_meta($userId, 'billing_address_1', true) ?: get_user_meta($userId, 'shipping_address_1', true),
        get_user_meta($userId, 'billing_address_2', true) ?: get_user_meta($userId, 'shipping_address_2', true),
        get_user_meta($userId, 'billing_city', true) ?: get_user_meta($userId, 'shipping_city', true),
        get_user_meta($userId, 'billing_state', true) ?: get_user_meta($userId, 'shipping_state', true),
        get_user_meta($userId, 'billing_postcode', true) ?: get_user_meta($userId, 'shipping_postcode', true),
        get_user_meta($userId, 'billing_country', true) ?: get_user_meta($userId, 'shipping_country', true),
    ])));

    return [
        'customer_id' => $userId,
        'user_id' => $userId,
        'name' => $name,
        'phone' => $phone,
        'phone_normalized' => $normalizedPhone,
        'email' => (string) ($row['user_email'] ?? get_user_meta($userId, 'billing_email', true)),
        'address' => $address,
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

    return array_values(array_map('sms_marketing_sync_term_payload', $terms));
}

function sms_marketing_sync_term_payload($term): array
{
    return [
        'name' => $term->name,
        'slug' => $term->slug,
    ];
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

    if (sms_marketing_sync_starts_with($digits, '0088')) {
        $digits = substr($digits, 2);
    }

    if (sms_marketing_sync_starts_with($digits, '88') && strlen($digits) === 13) {
        $local = substr($digits, 2);
    } elseif (sms_marketing_sync_starts_with($digits, '01') && strlen($digits) === 11) {
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

function sms_marketing_sync_starts_with(string $value, string $prefix): bool
{
    return $prefix === '' || strncmp($value, $prefix, strlen($prefix)) === 0;
}
