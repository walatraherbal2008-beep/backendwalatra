<?php

namespace App\Services\Marketplace;

use App\Models\CartItem;
use App\Models\OrderItem;
use App\Support\CartItemImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OrderImageSnapshots
{
    private array $directories = [];

    public function capture(CartItem $cartItem, OrderItem $orderItem): void
    {
        $source = CartItemImage::resolve($cartItem);
        if ($source === null) {
            return;
        }

        $directory = 'order-item-images/'.Str::uuid();
        // Track before writing: database rollback also removes media rows needed to locate files.
        $this->directories[] = $directory;
        $input = null;
        $output = null;
        $temporaryPath = null;

        try {
            $input = Storage::disk($source->disk)->readStream($source->getPathRelativeToRoot());
            if (! is_resource($input)) {
                throw new RuntimeException('Foto sumber pesanan tidak dapat dibaca.');
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'order-image-');
            if ($temporaryPath === false) {
                throw new RuntimeException('Berkas sementara foto pesanan tidak dapat dibuat.');
            }
            $output = fopen($temporaryPath, 'wb');
            if (! is_resource($output) || stream_copy_to_stream($input, $output) !== (int) $source->size) {
                throw new RuntimeException('Foto pesanan tidak dapat disalin.');
            }
            fclose($output);
            $output = null;

            $snapshot = $orderItem->addMedia($temporaryPath)
                ->usingFileName($source->file_name)
                ->usingName($source->name)
                ->withCustomProperties(['snapshot_directory' => $directory])
                ->toMediaCollection('image', 'public');

            if (! Storage::disk('public')->exists($snapshot->getPathRelativeToRoot())
                || Storage::disk('public')->size($snapshot->getPathRelativeToRoot()) !== (int) $source->size) {
                throw new RuntimeException('Salinan foto pesanan tidak tersedia.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function discard(): void
    {
        foreach ($this->directories as $directory) {
            try {
                if (! Storage::disk('public')->deleteDirectory($directory)) {
                    throw new RuntimeException('Direktori salinan foto pesanan gagal dibersihkan: '.$directory);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $this->directories = [];
    }
}
