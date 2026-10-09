<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

final class AdminDiscountController extends Controller
{
    public function index(array $params): void
    {
        $this->renderList([]);
    }

    public function create(array $params): void
    {
        $app = $this->app;
        $error = (new Discounts($app->db))->create(
            (string) ($_POST['code'] ?? ''),
            (string) ($_POST['type'] ?? ''),
            (string) ($_POST['value'] ?? ''),
            isset($_POST['active']),
            trim((string) ($_POST['expires_at'] ?? '')) !== '' ? (string) $_POST['expires_at'] : null,
            (string) ($_POST['max_uses'] ?? ''),
            (string) ($_POST['min_subtotal'] ?? ''),
            $app->translator->locale()
        );

        if ($error !== null) {
            $this->renderList([$this->trans($error)]);
            return;
        }

        Session::flash('success', $this->trans('shop.discount.admin.created'));
        $this->redirect('/admin/shop/discounts');
    }

    public function toggle(array $params): void
    {
        $discounts = new Discounts($this->app->db);
        $discount = $discounts->find((int) $params['id']);
        if ($discount !== null) {
            $discounts->setActive($discount['id'], !$discount['active']);
        }
        $this->redirect('/admin/shop/discounts');
    }

    public function delete(array $params): void
    {
        (new Discounts($this->app->db))->delete((int) $params['id']);
        Session::flash('success', $this->trans('shop.discount.admin.deleted'));
        $this->redirect('/admin/shop/discounts');
    }

    private function renderList(array $errors): void
    {
        $this->render('@shop/admin/discounts.twig', [
            'discounts' => (new Discounts($this->app->db))->all(),
            'currency' => $this->app->offers->currency(),
            'errors' => $errors,
        ]);
    }
}
