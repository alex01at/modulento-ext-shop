<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

final class CartController extends Controller
{
    public function show(array $params): void
    {
        $app = $this->app;
        $accountId = $this->accountId();
        $cart = new Cart($app->db);
        $items = $cart->items($accountId, $app);
        $subtotal = array_sum(array_column($items, 'line_total'));

        // Switched off, a code applied to the cart while the module was on
        // is simply ignored from here on - applying one never reserves
        // anything (only checkout does), so there is nothing to release.
        $discountsEnabled = $app->modules->enabled('shop.discounts');
        $discountService = new Discounts($app->db);
        $applied = $discountsEnabled ? (new CartDiscount($app->db))->find($accountId) : null;
        $discount = $applied !== null ? $discountService->find($applied['discount_id']) : null;
        $discountProblem = $discount !== null ? $discountService->problem($discount, $subtotal) : null;
        $discountAmount = $discount !== null && $discountProblem === null ? $discountService->amount($discount, $subtotal) : 0;

        $this->render('@shop/cart.twig', [
            'items' => $items,
            'subtotal' => $subtotal,
            'discounts_enabled' => $discountsEnabled,
            'discount_code' => $discount['code'] ?? null,
            'discount_amount' => $discountAmount,
            'discount_problem' => $discountProblem !== null ? $this->trans($discountProblem) : null,
            'total' => $subtotal - $discountAmount,
        ]);
    }

    public function applyDiscount(array $params): void
    {
        $app = $this->app;
        $accountId = $this->accountId();
        $code = trim((string) ($_POST['code'] ?? ''));
        $discountService = new Discounts($app->db);
        $discount = $code !== '' ? $discountService->findByCode($code) : null;

        if ($discount === null) {
            Session::flash('error', $this->trans('shop.discount.error.not_found'));
            $this->redirect('/cart');
            return;
        }

        $subtotal = array_sum(array_column((new Cart($app->db))->items($accountId, $app), 'line_total'));
        $problem = $discountService->problem($discount, $subtotal);
        if ($problem !== null) {
            Session::flash('error', $this->trans($problem));
            $this->redirect('/cart');
            return;
        }

        (new CartDiscount($app->db))->set($accountId, $discount['id']);
        Session::flash('success', $this->trans('shop.discount.applied'));
        $this->redirect('/cart');
    }

    public function removeDiscount(array $params): void
    {
        (new CartDiscount($this->app->db))->remove($this->accountId());
        $this->redirect('/cart');
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
