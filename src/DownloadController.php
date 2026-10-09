<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Session;

/**
 * Uploading a digital variant's file (the provider, on their own product)
 * and downloading it (the buyer, once they paid for it - checked against
 * the order itself: its buyer_id, its payment_state, and that the variant
 * was actually one of its lines, kept in order.data.lines at checkout
 * time). Never a public URL, unlike offer pictures.
 */
final class DownloadController extends Controller
{
    public function upload(array $params): void
    {
        $app = $this->app;
        $variant = $this->ownVariant($params);
        if ($variant === null) {
            return;
        }

        $error = (new Downloads($app->db, ProductType::uploadDir($app)))->upload($variant['id'], $variant['offer_id'], $_FILES['file'] ?? []);
        Session::flash($error === null ? 'success' : 'error', $this->trans($error ?? 'shop.download.uploaded'));
        $this->redirect($this->safeReturn('/account/offers/' . $variant['offer_id']));
    }

    public function deleteFile(array $params): void
    {
        $app = $this->app;
        $variant = $this->ownVariant($params);
        if ($variant === null) {
            return;
        }

        (new Downloads($app->db, ProductType::uploadDir($app)))->removeFile($variant['id']);
        Session::flash('success', $this->trans('shop.download.removed'));
        $this->redirect($this->safeReturn('/account/offers/' . $variant['offer_id']));
    }

    public function download(array $params): void
    {
        $app = $this->app;
        $accountId = $app->auth->account()['id'];
        $order = $app->orders->find((int) $params['id']);
        $variantId = (int) $params['variant'];
        $ordered = $order !== null && in_array($variantId, array_map('intval', array_column($order['data']['lines'] ?? [], 'variant_id')), true);

        if ($order === null || (int) $order['buyer_id'] !== $accountId || $order['payment_state'] !== 'paid' || !$ordered) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        $variant = (new Variants($app->db))->find($variantId);
        $downloads = new Downloads($app->db, ProductType::uploadDir($app));
        $path = $variant !== null && $variant['is_digital'] ? $downloads->path($variant) : null;
        if ($path === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        header('Content-Type: ' . Downloads::contentType($variant['file_extension']));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $variant['file_original_name']) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
    }

    /** The variant from the route, if it belongs to an offer the logged-in account's own provider owns. */
    private function ownVariant(array $params): ?array
    {
        $app = $this->app;
        $variant = (new Variants($app->db))->find((int) $params['id']);
        $provider = $variant !== null ? $app->providers->findByAccount($app->auth->account()['id']) : null;
        $offer = $provider !== null ? $app->offers->find($variant['offer_id']) : null;

        if ($variant === null || $offer === null || (int) $offer['provider_id'] !== (int) $provider['id']) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);

            return null;
        }

        return $variant;
    }
}
