<?php

declare(strict_types=1);

namespace Modulento\Shop;

use PDO;

/**
 * The file behind a digital variant. Kept outside the web root under
 * <uploads>/shop-downloads/<offerId>/<random name>.<extension> and served
 * only through DownloadController, which checks the requester actually
 * bought it (see its own doc comment) - unlike offer pictures, this is
 * never a public URL.
 */
final class Downloads
{
    public const MAX_BYTES = 200 * 1024 * 1024;

    /** Common digital-goods formats. Deliberately no script/executable kind, whatever the upload claims to be. */
    private const ACCEPTED_EXTENSIONS = [
        'pdf', 'zip', 'epub', 'mobi', 'txt', 'csv', 'rtf',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'mp3', 'wav', 'flac', 'm4a', 'mp4', 'mov',
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg',
    ];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    /**
     * @param array{tmp_name?: string, error?: int, name?: string} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function upload(int $variantId, int $offerId, array $upload): ?string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'shop.download.error.too_large'
                : 'shop.download.error.upload';
        }
        if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
            return 'shop.download.error.too_large';
        }

        $originalName = (string) ($upload['name'] ?? '');
        $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            return 'shop.download.error.type';
        }

        $dir = $this->uploadDir . '/' . $offerId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'shop.download.error.storage';
        }

        $name = bin2hex(random_bytes(16));
        $target = "{$dir}/{$name}.{$extension}";
        // move_uploaded_file() only accepts real uploads; rename() is the
        // fallback for the test suite, which has no HTTP upload.
        if (!@move_uploaded_file($upload['tmp_name'], $target) && !@rename($upload['tmp_name'], $target)) {
            return 'shop.download.error.storage';
        }
        @chmod($target, 0640);

        // Any file this variant had before is replaced, not kept alongside.
        $this->removeFile($variantId);

        $this->db->prepare(
            'UPDATE x_shop_variant SET file_name = :name, file_original_name = :original, file_extension = :extension, file_bytes = :bytes WHERE id = :id'
        )->execute([
            'name' => $name, 'original' => mb_substr(basename($originalName), 0, 255), 'extension' => $extension,
            'bytes' => filesize($target), 'id' => $variantId,
        ]);

        return null;
    }

    /** Deletes the file on disk and clears the columns - safe to call on a variant that has none. */
    public function removeFile(int $variantId): void
    {
        $stmt = $this->db->prepare('SELECT offer_id, file_name, file_extension FROM x_shop_variant WHERE id = :id');
        $stmt->execute(['id' => $variantId]);
        $row = $stmt->fetch();
        if ($row === false || $row['file_name'] === null) {
            return;
        }

        @unlink($this->uploadDir . '/' . $row['offer_id'] . '/' . $row['file_name'] . '.' . $row['file_extension']);
        $this->db->prepare('UPDATE x_shop_variant SET file_name = NULL, file_original_name = NULL, file_extension = NULL, file_bytes = NULL WHERE id = :id')
            ->execute(['id' => $variantId]);
    }

    /** The real path of a variant's file, only once it is confirmed downloadable by the caller. */
    public function path(array $variant): ?string
    {
        if ($variant['file_name'] === null) {
            return null;
        }

        $path = $this->uploadDir . '/' . $variant['offer_id'] . '/' . $variant['file_name'] . '.' . $variant['file_extension'];

        return is_file($path) ? $path : null;
    }

    public static function contentType(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'epub' => 'application/epub+zip',
            'mobi' => 'application/x-mobipocket-ebook',
            'txt', 'csv' => 'text/plain',
            'rtf' => 'application/rtf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
