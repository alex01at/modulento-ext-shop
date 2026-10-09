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
            $router->post('/cart/discount', [CartController::class, 'applyDiscount']);
            $router->post('/cart/discount/remove', [CartController::class, 'removeDiscount']);
            $router->get('/checkout', [CheckoutController::class, 'show']);
            $router->post('/checkout', [CheckoutController::class, 'place']);

            $router->get('/admin/shop/settings', [AdminShopController::class, 'edit'], 'shop.settings.manage');
            $router->post('/admin/shop/settings', [AdminShopController::class, 'save'], 'shop.settings.manage');
            $router->get('/admin/shop/discounts', [AdminDiscountController::class, 'index'], 'shop.settings.manage');
            $router->post('/admin/shop/discounts', [AdminDiscountController::class, 'create'], 'shop.settings.manage');
            $router->post('/admin/shop/discounts/{id}/toggle', [AdminDiscountController::class, 'toggle'], 'shop.settings.manage');
            $router->post('/admin/shop/discounts/{id}/delete', [AdminDiscountController::class, 'delete'], 'shop.settings.manage');

            $router->post('/account/shop/variants/{id}/file', [DownloadController::class, 'upload']);
            $router->post('/account/shop/variants/{id}/file/delete', [DownloadController::class, 'deleteFile']);
            $router->get('/orders/{id}/download/{variant}', [DownloadController::class, 'download']);

            $router->get('/account/shop/import', [ImportController::class, 'show']);
            $router->post('/account/shop/import', [ImportController::class, 'import']);
            $router->get('/account/shop/import/template', [ImportController::class, 'template']);
        });

        // Mails a digital order's download links once it is paid. There is
        // no core event for "an order was paid" (markPaid() does not fire
        // one), so this polls instead - the same way Modulento's own
        // reminder tasks work. order.data.digital_mailed marks one done;
        // checked again every run rather than only at the 'placed'
        // transition, since paying can happen well after that (e.g. a bank
        // transfer) and OrderStateChanged does not fire for it either.
        $registrar->task('shop.mail_downloads', 5, function (App $app): void {
            (new DigitalDeliveries($app->db))->mailDue($app);
        });

        $registrar->navigation('shop.nav.cart', '/cart');
        $registrar->accountLink('shop.nav.import', '/account/shop/import');

        $registrar->permission('shop.settings.manage', 'shop.permission.settings');
        $registrar->adminMenu('shop.admin.menu.settings', '/admin/shop/settings', 'shop.settings.manage', 'marketplace');
        $registrar->adminMenu('shop.admin.menu.discounts', '/admin/shop/discounts', 'shop.settings.manage', 'marketplace');

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
            if (isset($order['data']['discount_id'])) {
                (new Discounts($app->db))->release((int) $order['data']['discount_id']);
            }
        });
    }
}
