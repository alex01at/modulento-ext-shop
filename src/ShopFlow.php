<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\App;
use Modulento\Core\Order\OrderFlow;
use Modulento\Core\Order\Orders;

/**
 * What follows a checkout:
 *
 *   placed -> shipped -> completed
 *
 * The order is built by CheckoutController, never through the regular
 * order form - a cart can hold several products, which the generic
 * "one offer, one order" form has no way to express. Modelled on
 * Modulento\Core\Request\RequestFlow: the same cancel negotiation
 * (request_cancel/agree_cancel/refuse_cancel/withdraw_cancel, admin_cancel
 * any time), without a revision step - there is nothing to revise about a
 * shipped parcel.
 */
final class ShopFlow implements OrderFlow
{
    public const ID = 'shop.product';

    private const SHIP_WITHIN_SECONDS = 14 * 86400;
    private const CONFIRM_WITHIN_SECONDS = 14 * 86400;

    public function __construct(private ProductType $type)
    {
    }

    public function id(): string
    {
        return $this->type->id();
    }

    public function offerType(): string
    {
        return $this->type->id();
    }

    public function checkout(): bool
    {
        return false;
    }

    public function initialState(): string
    {
        return 'placed';
    }

    public function states(): array
    {
        return [
            'placed' => ['label' => 'shop.state.placed'],
            'shipped' => ['label' => 'shop.state.shipped'],
            'cancel_requested' => ['label' => 'shop.state.cancel_requested'],
            'completed' => ['label' => 'shop.state.completed', 'final' => true, 'reviewable' => true],
            'cancelled' => ['label' => 'shop.state.cancelled', 'final' => true],
        ];
    }

    public function transitions(): array
    {
        return [
            'ship' => ['from' => ['placed'], 'to' => 'shipped', 'actor' => ['provider'], 'label' => 'shop.action.ship', 'done' => 'shop.done.ship', 'note' => 'optional'],
            'accept_delivery' => ['from' => ['shipped'], 'to' => 'completed', 'actor' => ['buyer'], 'label' => 'shop.action.accept_delivery', 'done' => 'shop.done.accept_delivery'],
            'auto_complete' => ['from' => ['shipped'], 'to' => 'completed', 'actor' => ['system'], 'label' => 'shop.action.auto_complete', 'done' => 'shop.done.auto_complete'],
            'auto_ship_expire' => ['from' => ['placed'], 'to' => 'cancelled', 'actor' => ['system'], 'label' => 'shop.action.auto_ship_expire', 'done' => 'shop.done.auto_ship_expire'],

            'request_cancel' => ['from' => ['placed', 'shipped'], 'to' => 'cancel_requested', 'actor' => ['buyer', 'provider'], 'label' => 'shop.action.request_cancel', 'done' => 'shop.done.request_cancel', 'note' => 'required'],
            'agree_cancel' => ['from' => ['cancel_requested'], 'to' => 'cancelled', 'actor' => ['buyer', 'provider'], 'by' => 'counterparty', 'label' => 'shop.action.agree_cancel', 'done' => 'shop.done.agree_cancel'],
            'refuse_cancel' => ['from' => ['cancel_requested'], 'to' => Orders::PREVIOUS, 'actor' => ['buyer', 'provider'], 'by' => 'counterparty', 'label' => 'shop.action.refuse_cancel', 'done' => 'shop.done.refuse_cancel', 'note' => 'optional'],
            'withdraw_cancel' => ['from' => ['cancel_requested'], 'to' => Orders::PREVIOUS, 'actor' => ['buyer', 'provider'], 'by' => 'initiator', 'label' => 'shop.action.withdraw_cancel', 'done' => 'shop.done.withdraw_cancel'],

            'admin_cancel' => ['from' => ['placed', 'shipped', 'cancel_requested'], 'to' => 'cancelled', 'actor' => ['admin'], 'label' => 'shop.action.admin_cancel', 'done' => 'shop.done.admin_cancel', 'note' => 'required'],
        ];
    }

    public function deadline(string $state, array $order): ?array
    {
        return match ($state) {
            'placed' => ['seconds' => self::SHIP_WITHIN_SECONDS, 'transition' => 'auto_ship_expire'],
            'shipped' => ['seconds' => self::CONFIRM_WITHIN_SECONDS, 'transition' => 'auto_complete'],
            default => null,
        };
    }

    public function allows(string $transition, array $order, App $app): bool
    {
        return true;
    }

    // --- Not used: checkout() is false ------------------------------------------

    public function orderFormTemplate(): string
    {
        return '';
    }

    public function orderFormData(array $offer, array $input, string $locale, App $app): array
    {
        return [];
    }

    public function build(array $offer, array $input, string $locale, App $app): array
    {
        return ['items' => [], 'data' => [], 'errors' => ['core.order.error.not_possible']];
    }

    // -----------------------------------------------------------------------------

    public function orderDetailTemplate(): string
    {
        return '@shop/order_detail.twig';
    }

    /** @return array{shipping: int, discount_code: ?string, discount_amount: int} kept at order time so a later change to the shipping rate or the discount never alters a placed order */
    public function orderDetailData(array $order, string $locale, App $app): array
    {
        return [
            'shipping' => (int) ($order['data']['shipping'] ?? 0),
            'discount_code' => $order['data']['discount_code'] ?? null,
            'discount_amount' => (int) ($order['data']['discount_amount'] ?? 0),
        ];
    }
}
