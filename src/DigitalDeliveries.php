<?php

declare(strict_types=1);

namespace Modulento\Shop;

use Modulento\Core\App;
use PDO;

/**
 * Mails a paid order's download links, once. Called from the scheduled
 * task (Extension::register()) and directly by tests - the Scheduler
 * itself uses MySQL's GET_LOCK(), which the test suite's SQLite cannot
 * run, the same reason Modulento\Auction\Auctions::closeDue() is a method
 * of its own rather than only reachable through a task closure.
 */
final class DigitalDeliveries
{
    public function __construct(private PDO $db)
    {
    }

    /** @return int how many orders were mailed */
    public function mailDue(App $app): int
    {
        $stmt = $this->db->prepare("SELECT id FROM orders WHERE flow = :flow AND payment_state = 'paid'");
        $stmt->execute(['flow' => ShopFlow::ID]);

        $mailed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            if ($this->mailOne($app, (int) $orderId)) {
                $mailed++;
            }
        }

        return $mailed;
    }

    private function mailOne(App $app, int $orderId): bool
    {
        $order = $app->orders->find($orderId);
        if ($order === null || ($order['data']['digital_mailed'] ?? false)) {
            return false;
        }

        $variantsService = new Variants($this->db);
        $lines = [];
        foreach ((array) ($order['data']['lines'] ?? []) as $line) {
            $variant = $variantsService->findWithTexts((int) $line['variant_id']);
            if ($variant !== null && $variant['is_digital'] && $variant['file_name'] !== null) {
                $lines[] = $variantsService->label($variant, $order['locale'], $app->locales->default());
            }
        }

        // Marked done either way: nothing to send is also "done", so a
        // purely physical order is not looked at again on every run.
        $this->markMailed($order);
        if ($lines === []) {
            return false;
        }

        $stmt = $this->db->prepare('SELECT email FROM account WHERE id = :id');
        $stmt->execute(['id' => $order['buyer_id']]);
        $email = $stmt->fetchColumn();
        if ($email === false) {
            return false;
        }

        return $app->mailer->send((string) $email, '@shop/emails/downloads.txt.twig', [
            'order_number' => $order['number'],
            'order_link' => $app->url('/orders/' . $order['id'], $order['locale'], true),
            'lines' => $lines,
        ], $order['locale']);
    }

    private function markMailed(array $order): void
    {
        $data = $order['data'];
        $data['digital_mailed'] = true;
        $this->db->prepare('UPDATE orders SET data = :data WHERE id = :id')->execute(['data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'id' => $order['id']]);
    }
}
