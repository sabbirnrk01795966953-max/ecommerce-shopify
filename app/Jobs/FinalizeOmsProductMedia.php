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
        public readonly array $expectedState
    ) {
    }

    public function handle(): void
    {
        $product = Product::query()->find($this->productId);
        if (! $product) {
            return;
        }

        if (! $this->expectedMainIsReady($product)) {
            $this->release(30);
            return;
        }

        if (! $this->expectedVideoIsReady($product)) {
            $this->release(30);
            return;
        }

        foreach (($this->expectedState['gallery'] ?? []) as $expected) {
            $ready = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('oms_media_id', (string) $expected['id'])
                ->where('oms_media_checksum', ($expected['checksum'] ?? '') !== '' ? (string) $expected['checksum'] : null)
                ->whereNotNull('path')
                ->get()
                ->contains(fn (ProductImage $image) =>
                    $image->path && Storage::disk('public')->exists($image->path)
                );

            if (! $ready) {
                $this->release(30);
                return;
            }
        }

        $this->applyMainExpectation($product);
        $this->applyVideoExpectation($product);
        $this->finalizeGallery($product);
    }

    private function expectedMainIsReady(Product $product): bool
    {
        $expected = $this->expectedState['main'] ?? null;

        if ($expected === null) {
            return true;
        }

        return $product->oms_main_media_id === (string) $expected['id']
            && (string) $product->oms_main_media_checksum === (string) ($expected['checksum'] ?? '')
            && $product->main_image_path
            && Storage::disk('public')->exists($product->main_image_path);
    }

    private function expectedVideoIsReady(Product $product): bool
    {
        $expected = $this->expectedState['video'] ?? null;

        if ($expected === null) {
            return true;
        }

        return $product->oms_video_media_id === (string) $expected['id']
            && (string) $product->oms_video_media_checksum === (string) ($expected['checksum'] ?? '')
            && $product->video_path
            && Storage::disk('public')->exists($product->video_path);
    }

    private function applyMainExpectation(Product $product): void
    {
        $expected = $this->expectedState['main'] ?? null;

        if ($expected !== null || ! $product->oms_main_media_id) {
            return;
        }

        $oldPath = $product->main_image_path;

        $product->update([
            'main_image_path' => null,
            'main_image_url' => null,
            'oms_main_media_id' => null,
            'oms_main_media_checksum' => null,
        ]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    private function applyVideoExpectation(Product $product): void
    {
        $expected = $this->expectedState['video'] ?? null;

        if ($expected !== null || ! $product->oms_video_media_id) {
            return;
        }

        $oldPath = $product->video_path;

        $product->update([
            'video_path' => null,
            'oms_video_media_id' => null,
            'oms_video_media_checksum' => null,
        ]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    private function finalizeGallery(Product $product): void
    {
        $expectedGallery = collect($this->expectedState['gallery'] ?? []);
        $expectedIds = $expectedGallery->pluck('id')->map(fn ($id) => (string) $id)->all();

        $query = ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNotNull('oms_media_id');

        if ($expectedIds !== []) {
            $query->whereNotIn('oms_media_id', $expectedIds);
        }

        $obsolete = $query->get();

        foreach ($obsolete as $image) {
            $path = $image->path;
            $image->delete();

            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }

        foreach ($expectedGallery as $expected) {
            ProductImage::query()
                ->where('product_id', $product->id)
                ->where('oms_media_id', (string) $expected['id'])
                ->update([
                    'sort_order' => (int) $expected['sort_order'],
                ]);
        }
    }
}
