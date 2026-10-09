<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\App;
use Modulento\Core\Controller\Controller;
use Modulento\Core\Order\OrderNotifier;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Support\Session;
use RuntimeException;

/**
 * The cart becomes one order, for one provider - a cart that somehow holds
 * products from more than one provider is refused rather than guessed at;
 * this extension is built for a single-provider shop (see README).
 */
final class CheckoutController extends Controller
{
    public function show(array $params): void
    {
        $app = $this->app;
        $cart = new Cart($app->db);
        $items = $cart->items($this->accountId(), $app);
        $providerId = $this->singleProviderId($items);

        if ($providerId === null) {
            Session::flash('error', $this->trans($items === [] ? 'shop.cart.empty' : 'shop.checkout.error.mixed_provider'));
            $this->redirect('/cart');
            return;
        }

        $this->renderForm($items, $providerId, null, []);
    }

    public function place(array $params): void
    {
        $app = $this->app;
        $accountId = $this->accountId();
        $cart = new Cart($app->db);
        $items = $cart->items($accountId, $app);
        $providerId = $this->singleProviderId($items);

        if ($providerId === null) {
            Session::flash('error', $this->trans($items === [] ? 'shop.cart.empty' : 'shop.checkout.error.mixed_provider'));
            $this->redirect('/cart');
            return;
        }

        $locale = $app->translator->locale();
        $methods = $app->payments->availableFor($providerId, $app);
        $chosen = $_POST['payment_method'] ?? (count($methods) === 1 ? array_key_first($methods) : '');
        $methodId = is_string($chosen) ? $chosen : '';

        $errors = [];
        if ($methods === []) {
            $errors[] = 'core.order.error.no_payment_method';
        } elseif (!isset($methods[$methodId])) {
            $errors[] = 'core.order.error.payment_method';
        }
        $mustAccept = $app->pages->links('terms', $locale) !== [];
        if ($mustAccept && !isset($_POST['accept_terms'])) {
            $errors[] = 'core.register.error.terms';
        }

        if ($errors !== []) {
            $this->renderForm($items, $providerId, $methodId, array_map(fn (string $key) => $this->trans($key), array_unique($errors)));
            return;
        }

        $variantsService = new Variants($app->db);
        $reserved = [];
        foreach ($items as $item) {
            if (!$variantsService->reserve($item['variant_id'], $item['quantity'])) {
                foreach ($reserved as [$variantId, $quantity]) {
                    $variantsService->release($variantId, $quantity);
                }
                Session::flash('error', $this->trans('shop.cart.error.stock'));
                $this->redirect('/cart');
                return;
            }
            $reserved[] = [$item['variant_id'], $item['quantity']];
        }

        $provider = $app->providers->find($providerId);
        $currency = $items[0]['currency'];
        $shipping = $this->shippingFlat($app);

        $orderItems = array_map(fn (array $item) => [
            'label' => $item['title'] . ' — ' . $item['label'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
        ], $items);
        if ($shipping > 0) {
            $orderItems[] = ['label' => $this->trans('shop.checkout.shipping'), 'quantity' => 1, 'unit_price' => $shipping];
        }

        $orderId = $app->orders->create(
            $app->auth->account(),
            ['id' => null, 'provider_id' => $providerId, 'provider_name' => $provider['name'] ?? '', 'currency' => $currency],
            $this->trans('shop.checkout.order_title'),
            $app->orders->flow(ShopFlow::ID),
            $orderItems,
            ['shipping' => $shipping, 'lines' => array_map(fn (array $item) => ['variant_id' => $item['variant_id'], 'quantity' => $item['quantity']], $items)],
            $methodId,
            $locale,
            null,
            $mustAccept
        );

        $cart->removeMany($accountId, array_column($items, 'id'));

        $order = $app->orders->find($orderId);
        OrderNotifier::stateChanged($app, null, $order, 'place', 'buyer', null);

        Session::flash('success', $this->trans('shop.checkout.placed'));

        try {
            $payUrl = $methods[$methodId]->begin($order, $app);
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
            $payUrl = null;
        }

        if ($payUrl !== null) {
            header('Location: ' . $payUrl);
            return;
        }

        $this->redirect('/orders/' . $orderId);
    }

    private function renderForm(array $items, int $providerId, ?string $paymentMethod, array $errors): void
    {
        $app = $this->app;
        $locale = $app->translator->locale();
        $shipping = $this->shippingFlat($app);
        $methods = $app->payments->availableFor($providerId, $app);

        $this->render('@shop/checkout.twig', [
            'items' => $items,
            'subtotal' => array_sum(array_column($items, 'line_total')),
            'shipping' => $shipping,
            'total' => array_sum(array_column($items, 'line_total')) + $shipping,
            'currency' => $items[0]['currency'],
            'methods' => array_map(fn ($m) => ['id' => $m->id(), 'label_key' => $m->labelKey()], $methods),
            'payment_method' => $paymentMethod,
            'terms' => $app->pages->links('terms', $locale)[0] ?? null,
            'errors' => $errors,
        ]);
    }

    private function shippingFlat(App $app): int
    {
        return (int) $app->settings->get('shop.shipping_flat', '0');
    }

    /** @param list<array{offer_id: int}> $items */
    private function singleProviderId(array $items): ?int
    {
        if ($items === []) {
            return null;
        }

        $db = $this->app->db;
        $providerIds = [];
        foreach (array_unique(array_column($items, 'offer_id')) as $offerId) {
            $stmt = $db->prepare('SELECT provider_id FROM offer WHERE id = :id');
            $stmt->execute(['id' => $offerId]);
            $providerIds[(int) $stmt->fetchColumn()] = true;
        }

        return count($providerIds) === 1 ? array_key_first($providerIds) : null;
    }

    private function accountId(): int
    {
        return $this->app->auth->account()['id'];
    }
}
