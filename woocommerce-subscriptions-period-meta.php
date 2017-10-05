<?php
/**
 * WooCommerce Subscriptions Period Meta
 *
 * Plugin Name:       WooCommerce Subscriptions Period Meta
 * Plugin URI:        https://github.com/holzhannes/woocommerce-subscriptions-period-meta
 * GitHub Plugin URI: https://github.com/holzhannes/woocommerce-subscriptions-period-meta
 * Description:       This Plugin will add the subscription period as meta value to every WooCommerce Subscription & Order items.
 * Version:           0.1
 * Author:            holzhannes
 * Author URI:        https://github.com/holzhannes/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-sub-period-meta
 * Domain Path:       /languages
 */

//Translation
add_action('plugins_loaded', 'load_textdomain_period_meta');
function load_textdomain_period_meta() {
    load_plugin_textdomain( 'wc-sub-period-meta', false, dirname( plugin_basename(__FILE__) ) . '/languages/' );
}

//First Order
add_action('woocommerce_email_order_meta', 'add_subscription_period_to_first_order');
add_action('subscriptions_created_for_order', 'update_subscription');

//Other Orders
add_action('woocommerce_scheduled_subscription_payment', 'update_subscription_meta');

// Subscription Save & Update
add_action('save_post','save_subscription_meta', 10, 2);

// Subscription Cancelled
add_action('woocommerce_subscription_status_updated', 'subscription_status_update', 10, 3);



function subscription_status_update( $subscription, $new_status, $old_status)
{
    if ( $new_status == "active" || $new_status == "on-hold" || $new_status == "pending"){
        update_subscription_meta($subscription->id);
    }
    if ( $new_status == "pending-cancel" || $new_status == "cancelled"){
        // todo
    }
}


function save_subscription_meta( $post_id, $post)
{
    /*
     * In production code, $slug should be set only once in the plugin,
     * preferably as a class property, rather than in each function that needs it.
     */
    $post_type = get_post_type($post_id);

    // If this isn't a 'book' post, don't update it.
    if ( "shop_subscription" != $post_type ) return;

    // - Update the post's metadata.
    update_subscription_meta($post_id);

}

function update_subscription_meta($subscription_id)
{
    $status = get_post_status($subscription_id);

    if($status == "wc-cancelled" || $status == "wc-pending-cancel") {
        return;
    }

    if($status == "wc-active" || $status == "wc-on-hold" || $status == "wc-pending") {

    $order = wc_get_order($subscription_id);

    $schedule_next_payment = get_post_meta($subscription_id, '_schedule_next_payment', true);
    $schedule_end = get_post_meta($subscription_id, '_schedule_end', true);
    $billing_interval = intval(get_post_meta($subscription_id, '_billing_interval', true));
    $billing_period = get_post_meta($subscription_id, '_billing_period', true);

    $end_interval = date(wc_date_format(), strtotime($schedule_next_payment.calculate_period_to_add($billing_interval, $billing_period)));
    $start_interval = date(wc_date_format(), strtotime($schedule_next_payment));
    $notice_period = '-'.'1 month';
    $last_termination_date = date(wc_date_format(), strtotime($end_interval.$notice_period));


    foreach ($order->get_items() as $item_key => $item_values) {
        $item_data = $item_values->get_data();
        $item_id = $item_values->get_id();
        if(intval($schedule_end) === 0) {
            //No subscription end set! 
        	wc_update_order_item_meta($item_id, __('Period', 'wc-sub-period-meta'), $start_interval.' - '.$end_interval, false);
            wc_update_order_item_meta($item_id, __('Last possible termination date', 'wc-sub-period-meta'), date(wc_date_format(), strtotime($last_termination_date)), false);
            wc_delete_order_item_meta($item_id, __('Termination', 'wc-sub-period-meta') );
        } else {
            wc_update_order_item_meta($item_id, __('Period', 'wc-sub-period-meta'), $start_interval.' - '.$end_interval, false);
        	wc_update_order_item_meta($item_id, __('Termination', 'wc-sub-period-meta'), date(wc_date_format(), strtotime($schedule_end)), false);
            wc_delete_order_item_meta($item_id, __('Last possible termination date', 'wc-sub-period-meta') );
        }
    }
}
}

function update_subscription($order)
{
    $subscriptions_ids = wcs_get_subscriptions_for_order($order->ID);
    foreach ($subscriptions_ids as $subscription_id => $subscription_obj) {
        if ($subscription_obj->order->id == $order_id) {
            break;
        } // Stop the loop
        update_subscription_meta($subscription_id);
    }
}

function update_subscription_meta_new($subscription_id)
{
    $parent_id = WC_Subscriptions_Renewal_Order::get_parent_order_id($order); /* This gets the original parent order id */
    $parent_order = new WC_Order($parent_id);
    foreach ($order->get_items() as $item_key => $item_values) { /* This loops through each item in the order */
        $item_data = $item_values->get_data();
        $item_id = $item_values->get_id();
        $date = WC_Subscriptions_Order::get_next_payment_date($parent_order, $item_data['product_id']); /* get the next payment date... */
        if ($date) {
            wc_update_order_item_meta($item_id, __('Period', 'wc-sub-period-meta'), date(wc_date_format(), current_time('timestamp')).' - '.date(wc_date_format(), strtotime($date)), false);
        }
    }
}

function add_subscription_period_to_first_order($order_id)
{
    $order = wc_get_order($order_id);

    if (wcs_order_contains_subscription($order)) {
        foreach ($order->get_items() as $item_key => $item_values) { /* This loops through each item in the order */
            $item_data = $item_values->get_data();
            $item_id = $item_values->get_id();
            $date = WC_Subscriptions_Order::get_next_payment_date($order, $item_data['product_id']); /* get the next payment date... */

            if ($date) {
                wc_update_order_item_meta($item_id, __('Period', 'wc-sub-period-meta'), date(wc_date_format(), current_time('timestamp')).' - '.date(wc_date_format(), strtotime($date)), false);
            }
        }
    }
}

function calculate_period_to_add($billing_interval, $billing_period)
{
    switch ($billing_interval) {
        case 1:
            switch ($billing_period) {
                case 'day':
                    $period_to_add = '1 day';
                    break;
                case 'week':
                    $period_to_add = '1 week';
                    break;
                case 'month':
                    $period_to_add = '1 month';
                    break;
                case 'year':
                    $period_to_add = '1 year';
                    break;
            }
            break;
        default:
            switch ($billing_period) {
                case 'day':
                    $period_to_add = $billing_interval.' days';
                    break;
                case 'week':
                    $period_to_add = $billing_interval.' weeks';
                    break;
                case 'month':
                    $period_to_add = $billing_interval.' months';
                    break;
                case 'year':
                    $period_to_add = $billing_interval.' years';
                    break;
            }
    }

    return $period_to_add;
}
