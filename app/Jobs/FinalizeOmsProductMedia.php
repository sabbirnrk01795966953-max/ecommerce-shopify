<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class FinalizeOmsProductMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $timeout = 60;

    public function __construct(
        public readonly int $productId,
        public readonly array $expectedGallery
    ) {
    }

    public function handle(): void
    {
        $product = Product::query()->find($this->productId);
        if (! $product) {
            return;
        }

        foreach ($this->expectedGallery as $mediaId => $checksum) {
            $ready = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('oms_media_id', $mediaId)
                ->where('oms_media_checksum', $checksum ?: null)
                ->exists();

            if (! $ready) {
                $this->release(30);
                return;
            }
        }

        $query = ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotNull('oms_media_id');

        if ($this->expectedGallery !== []) {
            $query->whereNotIn('oms_media_id', array_keys($this->expectedGallery));
        }

        $obsolete = $query->get();

        foreach ($obsolete as $image) {
            $path = $image->path;
            $image->delete();

            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
