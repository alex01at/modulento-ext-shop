<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

final class CartController extends Controller
{
    public function show(array $params): void
    {
        $cart = new Cart($this->app->db);
        $items = $cart->items($this->accountId(), $this->app);

        $this->render('@shop/cart.twig', [
            'items' => $items,
            'total' => array_sum(array_column($items, 'line_total')),
        ]);
    }

    public function add(array $params): void
    {
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        $error = (new Cart($this->app->db))->add($this->accountId(), $variantId, $quantity);
        Session::flash($error === null ? 'success' : 'error', $this->trans($error ?? 'shop.cart.added'));
        $this->redirect($this->safeReturn('/cart'));
    }

    public function update(array $params): void
    {
        $quantity = max(0, (int) ($_POST['quantity'] ?? 0));
        (new Cart($this->app->db))->setQuantity($this->accountId(), (int) $params['id'], $quantity);
        $this->redirect('/cart');
    }

    public function remove(array $params): void
    {
        (new Cart($this->app->db))->remove($this->accountId(), (int) $params['id']);
        Session::flash('success', $this->trans('shop.cart.removed'));
        $this->redirect('/cart');
    }

    private function accountId(): int
    {
        return $this->app->auth->account()['id'];
    }
}
