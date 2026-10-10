<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DownloadOmsProductMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public readonly int $productId,
        public readonly array $media,
        public readonly string $signedUrlCacheKey
    ) {
    }

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(): void
    {
        $product = Product::query()->find($this->productId);
        if (! $product) {
            return;
        }

        $encrypted = Cache::get($this->signedUrlCacheKey);
        if (! is_string($encrypted) || $encrypted === '') {
            throw new RuntimeException('OMS media signed URL expired from temporary cache. Re-sync the product.');
        }

        $url = Crypt::decryptString($encrypted);
        $this->assertSafeRemoteUrl($url);

        $kind = strtoupper((string) ($this->media['kind'] ?? ''));
        if (! in_array($kind, ['IMAGE', 'VIDEO'], true)) {
            throw new RuntimeException('Unsupported OMS media kind.');
        }

        $maxBytes = $kind === 'VIDEO'
            ? (int) config('oms_catalog.video_max_bytes', 60 * 1024 * 1024)
            : (int) config('oms_catalog.image_max_bytes', 12 * 1024 * 1024);

        $declaredSize = (int) ($this->media['sizeBytes'] ?? 0);
        if ($declaredSize > 0 && $declaredSize > $maxBytes) {
            throw new RuntimeException('OMS media exceeds the allowed file-size limit.');
        }

        $temp = tempnam(storage_path('app'), 'oms-media-');
        if (! $temp) {
            throw new RuntimeException('Could not create OMS media temporary file.');
        }

        try {
            $response = Http::connectTimeout(15)
                ->timeout(150)
                ->withOptions([
                    'sink' => $temp,
                    'allow_redirects' => false,
                ])
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException('OMS media download failed with HTTP '.$response->status().'.');
            }

            $size = (int) (@filesize($temp) ?: 0);
            if ($size <= 0 || $size > $maxBytes) {
                throw new RuntimeException('Downloaded OMS media has an invalid file size.');
            }

            $mime = $this->detectMime($temp);
            $this->assertAllowedMime($kind, $mime);

            $checksum = (string) ($this->media['checksum'] ?? '');
            $this->verifyChecksumWhenSha256($temp, $checksum);

            $extension = $this->extensionFor($mime, (string) ($this->media['originalFileName'] ?? ''));
            $mediaId = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $this->media['id']) ?: 'media';
            $version = substr(hash_file('sha256', $temp), 0, 16);
            $path = 'products/oms/'.$product->id.'/'.$mediaId.'-'.$version.'.'.$extension;

            $stream = fopen($temp, 'rb');
            if (! $stream) {
                throw new RuntimeException('Could not open downloaded OMS media.');
            }

            try {
                if (! Storage::disk('public')->put($path, $stream)) {
                    throw new RuntimeException('Could not save OMS media to local storefront storage.');
                }
            } finally {
                fclose($stream);
            }

            $this->applyLocalMedia($product, $path, $mime, $checksum);
            Cache::forget($this->signedUrlCacheKey);
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            @unlink($temp);
        }
    }

    private function applyLocalMedia(Product $product, string $path, string $mime, string $checksum): void
    {
        $kind = strtoupper((string) $this->media['kind']);
        $isPrimary = (bool) ($this->media['isPrimary'] ?? false);
        $mediaId = (string) $this->media['id'];
        $sortOrder = (int) ($this->media['sortOrder'] ?? 0);

        if ($kind === 'IMAGE' && $isPrimary) {
            $oldPath = $product->main_image_path;

            DB::transaction(function () use ($product, $path, $mediaId, $checksum) {
                $product->refresh();
                $product->update([
                    'main_image_path' => $path,
                    'main_image_url' => null,
                    'oms_main_media_id' => $mediaId,
                    'oms_main_media_checksum' => $checksum ?: null,
                ]);
            });

            if ($oldPath && $oldPath !== $path) {
                Storage::disk('public')->delete($oldPath);
            }

            return;
        }

        if ($kind === 'VIDEO') {
            $oldPath = $product->video_path;

            DB::transaction(function () use ($product, $path, $mediaId, $checksum) {
                $product->refresh();
                $product->update([
                    'video_path' => $path,
                    'oms_video_media_id' => $mediaId,
                    'oms_video_media_checksum' => $checksum ?: null,
                ]);
            });

            if ($oldPath && $oldPath !== $path) {
                Storage::disk('public')->delete($oldPath);
            }

            return;
        }

        $existing = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('oms_media_id', $mediaId)
            ->first();

        $oldPath = $existing?->path;

        ProductImage::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'oms_media_id' => $mediaId,
            ],
            [
                'path' => $path,
                'source_url' => null,
                'alt_text' => (string) ($this->media['originalFileName'] ?? ''),
                'sort_order' => $sortOrder,
                'oms_media_checksum' => $checksum ?: null,
            ]
        );

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    private function assertSafeRemoteUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Invalid OMS media download URL.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('Unsafe OMS media host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Unsafe OMS media IP address.');
            }
            return;
        }

        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === []) {
            throw new RuntimeException('Could not resolve OMS media host.');
        }

        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('OMS media host resolves to a private/reserved address.');
            }
        }
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string) ($finfo->file($path) ?: 'application/octet-stream');
    }

    private function assertAllowedMime(string $kind, string $mime): void
    {
        $allowed = $kind === 'VIDEO'
            ? ['video/mp4', 'video/webm', 'video/quicktime']
            : ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        if (! in_array($mime, $allowed, true)) {
            throw new RuntimeException('OMS media MIME type is not allowed: '.$mime);
        }
    }

    private function extensionFor(string $mime, string $originalName): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
        ];

        if (isset($map[$mime])) {
            return $map[$mime];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return preg_match('/^[a-z0-9]{2,5}$/', $ext) ? $ext : 'bin';
    }

    private function verifyChecksumWhenSha256(string $path, string $checksum): void
    {
        if ($checksum === '') {
            return;
        }

        $expected = preg_replace('/^sha256:/i', '', trim($checksum));

        if (! preg_match('/^[a-f0-9]{64}$/i', $expected)) {
            return;
        }

        $actual = hash_file('sha256', $path);
        if (! hash_equals(strtolower($expected), strtolower($actual))) {
            throw new RuntimeException('OMS media SHA-256 checksum mismatch.');
        }
    }
}
