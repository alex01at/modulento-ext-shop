<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

/**
 * Bulk product creation from a CSV file (see CsvImport). Gated the same way
 * as the offer wizard: a provider profile is required, approved or not -
 * an unapproved provider's imported products simply land as "pending" like
 * anything else they create by hand (CsvImport::import() uses the same
 * Offers::approvalRequired() check the wizard does).
 */
final class ImportController extends Controller
{
    public function show(array $params): void
    {
        if ($this->provider() === null) {
            return;
        }

        $this->render('@shop/import.twig', ['result' => null]);
    }

    public function import(array $params): void
    {
        $provider = $this->provider();
        if ($provider === null) {
            return;
        }

        $upload = $_FILES['file'] ?? [];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            Session::flash('error', $this->trans('shop.import.error.upload'));
            $this->redirect('/account/shop/import');
            return;
        }

        $app = $this->app;
        $result = (new CsvImport())->import($upload['tmp_name'], (int) $provider['id'], $app->translator->locale(), $app);

        if ($result['fatal'] !== null) {
            Session::flash('error', $this->trans($result['fatal']));
            $this->redirect('/account/shop/import');
            return;
        }

        $this->render('@shop/import.twig', ['result' => $result]);
    }

    public function template(array $params): void
    {
        if ($this->provider() === null) {
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="products-template.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, CsvImport::TEMPLATE_HEADER);
        fputcsv($out, ['T-Shirt', 'Kleidung', 'Weiches Baumwoll-Shirt', '', 'S', 'TSHIRT-S', '19.99', '10', '']);
        fputcsv($out, ['T-Shirt', 'Kleidung', 'Weiches Baumwoll-Shirt', '', 'M', 'TSHIRT-M', '19.99', '10', '']);
        fclose($out);
    }

    /** The logged-in account's provider profile; without one, there is nothing to import into. */
    private function provider(): ?array
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);

        if ($provider === null) {
            Session::flash('error', $this->trans('core.offer.needs_provider'));
            $this->redirect('/account/provider');
        }

        return $provider;
    }
}
