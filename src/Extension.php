<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\App;
use Modulento\Core\Event\OrderStateChanged;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Turns the catalogue into a single-provider shop: an offer of the type
 * "shop.product" is a product with one or more variants (each its own
 * price and stock). A cart holds variants from several products at once -
 * something no generic offer-order form can express - so checkout is its
 * own small flow (CartController, CheckoutController) that calls
 * Orders::create() directly, the same way Modulento\Core\Request does.
 *
 * Deliberately single-provider: a cart that somehow spans more than one
 * provider is refused at checkout rather than guessed at (see
 * CheckoutController::singleProviderId()). Nothing stops installing this
 * next to several approved providers, but the checkout was not built for
 * that - see the README.
 */
final class Extension implements ExtensionContract
{
    public function register(Registrar $registrar): void
    {
        $type = new ProductType();
        $registrar->offerType($type);
        $registrar->orderFlow(new ShopFlow($type));

        $registrar->routes(function (Router $router): void {
            $router->get('/cart', [CartController::class, 'show']);
            $router->post('/cart/add', [CartController::class, 'add']);
            $router->post('/cart/{id}/update', [CartController::class, 'update']);
            $router->post('/cart/{id}/remove', [CartController::class, 'remove']);
            $router->get('/checkout', [CheckoutController::class, 'show']);
            $router->post('/checkout', [CheckoutController::class, 'place']);

            $router->get('/admin/shop/settings', [AdminShopController::class, 'edit'], 'shop.settings.manage');
            $router->post('/admin/shop/settings', [AdminShopController::class, 'save'], 'shop.settings.manage');
        });

        $registrar->navigation('shop.nav.cart', '/cart');

        $registrar->permission('shop.settings.manage', 'shop.permission.settings');
        $registrar->adminMenu('shop.admin.menu.settings', '/admin/shop/settings', 'shop.settings.manage', 'marketplace');

        // A cancelled order gives its stock back. Placing an order already
        // reserves stock itself (CheckoutController, before Orders::create()
        // runs), so there is nothing to do when oldState is null. Which
        // variant each line was and how many were taken is kept in the
        // order's own data (order_item has no product reference of its
        // own) - see CheckoutController::place().
        $registrar->listen(OrderStateChanged::class, function (OrderStateChanged $event, App $app): void {
            if ($event->newState !== 'cancelled') {
                return;
            }
            $order = $app->orders->find($event->orderId);
            if ($order === null || $order['flow'] !== ShopFlow::ID) {
                return;
            }

            $variants = new Variants($app->db);
            foreach ((array) ($order['data']['lines'] ?? []) as $line) {
                $variants->release((int) $line['variant_id'], (int) $line['quantity']);
            }
        });
    }
}
