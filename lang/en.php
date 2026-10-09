<?php

declare(strict_types=1);

return [
    'shop.type.product' => 'Product',

    // Offer form (one per variant)
    'shop.form.variant' => 'Variant {number}',
    'shop.form.variant_optional' => 'Optional - only saved once a price is entered.',
    'shop.form.variant_label' => 'Label',
    'shop.form.variant_label_placeholder' => 'e.g. Size M, Red',
    'shop.form.price' => 'Price ({currency})',
    'shop.form.stock' => 'Stock',
    'shop.form.sku' => 'SKU',
    'shop.form.sku_hint' => 'Optional, but has to be unique if given.',

    'shop.error.price' => 'At least one variant needs a valid price.',
    'shop.error.stock' => 'The stock is not valid.',
    'shop.error.sku' => 'The SKU may only contain letters, digits, "-" and "_".',
    'shop.error.sku_taken' => 'This SKU is already in use.',
    'shop.error.label' => 'Every variant needs a label in at least one language.',

    // Public product page
    'shop.detail.choose_variant' => 'Choose a variant',
    'shop.detail.out_of_stock' => 'Out of stock',
    'shop.detail.quantity' => 'Quantity',
    'shop.detail.add_to_cart' => 'Add to cart',
    'shop.detail.login_to_buy' => 'Log in to order.',

    // Cart
    'shop.cart.title' => 'Cart',
    'shop.cart.empty' => 'Your cart is empty.',
    'shop.cart.browse' => 'Browse products',
    'shop.cart.product' => 'Product',
    'shop.cart.line_total' => 'Total',
    'shop.cart.total' => 'Grand total',
    'shop.cart.update' => 'Update',
    'shop.cart.remove' => 'Remove',
    'shop.cart.checkout' => 'Checkout',
    'shop.cart.added' => 'Added to the cart.',
    'shop.cart.removed' => 'Removed from the cart.',
    'shop.cart.only_left' => 'Only {count} left in stock.',
    'shop.cart.error.gone' => 'This variant no longer exists.',
    'shop.cart.error.quantity' => 'The quantity is not valid.',
    'shop.cart.error.stock' => 'Not enough in stock.',

    // Checkout
    'shop.checkout.title' => 'Checkout',
    'shop.checkout.subtotal' => 'Subtotal',
    'shop.checkout.shipping' => 'Shipping',
    'shop.checkout.total' => 'Grand total',
    'shop.checkout.submit' => 'Order with obligation to pay',
    'shop.checkout.order_title' => 'Order',
    'shop.checkout.placed' => 'Order placed.',
    'shop.checkout.error.mixed_provider' => 'Your cart holds products from more than one provider - this shop does not support that.',

    // Order page
    'shop.order.shipping_charged' => 'Shipping: {amount}',

    // Flow: states, actions, confirmations
    'shop.state.placed' => 'Waiting to be shipped',
    'shop.state.shipped' => 'Shipped, waiting for confirmation',
    'shop.state.cancel_requested' => 'Cancellation requested',
    'shop.state.completed' => 'Completed',
    'shop.state.cancelled' => 'Cancelled',
    'shop.action.ship' => 'Mark as shipped',
    'shop.action.accept_delivery' => 'Confirm receipt',
    'shop.action.auto_complete' => 'Automatically confirmed',
    'shop.action.auto_ship_expire' => 'Not shipped in time',
    'shop.action.request_cancel' => 'Request cancellation',
    'shop.action.agree_cancel' => 'Agree to cancel',
    'shop.action.refuse_cancel' => 'Refuse the cancellation',
    'shop.action.withdraw_cancel' => 'Withdraw the cancellation request',
    'shop.action.admin_cancel' => 'Cancel by the platform',
    'shop.done.ship' => 'Shipped',
    'shop.done.accept_delivery' => 'Receipt confirmed',
    'shop.done.auto_complete' => 'Automatically confirmed',
    'shop.done.auto_ship_expire' => 'Not shipped in time',
    'shop.done.request_cancel' => 'Cancellation requested',
    'shop.done.agree_cancel' => 'Cancellation agreed',
    'shop.done.refuse_cancel' => 'Cancellation refused',
    'shop.done.withdraw_cancel' => 'Cancellation request withdrawn',
    'shop.done.admin_cancel' => 'Cancelled by the platform',

    // Navigation, permission
    'shop.nav.cart' => 'Cart',
    'shop.permission.settings' => 'Manage shop settings',
    'shop.admin.menu.settings' => 'Shop',

    // Admin settings
    'shop.admin.settings.title' => 'Shop settings',
    'shop.admin.settings.shipping_flat' => 'Flat shipping rate ({currency})',
    'shop.admin.settings.shipping_flat_hint' => 'Added once to every order. Empty or 0 means free shipping.',
    'shop.admin.settings.save' => 'Save',
    'shop.admin.error.shipping' => 'The shipping rate is not valid.',
    'shop.admin.saved' => 'Saved.',
];
