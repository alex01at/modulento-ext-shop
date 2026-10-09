<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Money;
use Modulento\Core\Support\Session;

final class AdminShopController extends Controller
{
    public function edit(array $params): void
    {
        $this->renderForm($this->flatInput(), []);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $locale = $app->translator->locale();
        $input = trim((string) ($_POST['shipping_flat'] ?? ''));
        $minorUnits = $input === '' ? 0 : Money::parse($input);

        if ($minorUnits === null || $minorUnits < 0) {
            $this->renderForm($input, [$this->trans('shop.admin.error.shipping')]);
            return;
        }

        $app->settings->set('shop.shipping_flat', (string) $minorUnits);
        Session::flash('success', $this->trans('shop.admin.saved'));
        $this->redirect('/admin/shop/settings');
    }

    private function renderForm(string $shippingFlatInput, array $errors): void
    {
        $this->render('@shop/admin/shop_settings.twig', [
            'shipping_flat' => $shippingFlatInput,
            'currency' => $this->app->offers->currency(),
            'errors' => $errors,
        ]);
    }

    private function flatInput(): string
    {
        $minorUnits = (int) $this->app->settings->get('shop.shipping_flat', '0');

        return $minorUnits > 0 ? Money::input($minorUnits, $this->app->translator->locale()) : '';
    }
}
