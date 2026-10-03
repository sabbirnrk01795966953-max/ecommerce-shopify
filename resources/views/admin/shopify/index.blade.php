@extends('layouts.admin')

@section('title', 'Shopify Import')
@section('page_title', 'Shopify Import & Sync')

@section('content')
<div class="panel form-grid integration-panel">
    <div class="span-2">
        <h2>Shopify Admin API Connection</h2>
        <p class="muted">এই website-এর নিজস্ব Shopify store connect করুন। Token server-side encrypted অবস্থায় save হবে।</p>
    </div>

    <label class="span-2">Shopify Store Domain
        <input id="shopifyDomain" value="{{ $shopify['domain'] }}" placeholder="your-store.myshopify.com">
        <small>শুধু myshopify.com domain দিন। https:// দিলে সেটাও normalize হবে।</small>
    </label>

    <label>Admin API Version
        <input id="shopifyApiVersion" value="{{ $shopify['api_version'] ?: '2026-10' }}" placeholder="2026-10">
    </label>

    <label>New Admin API Access Token
        <input id="shopifyToken" type="password" value="" autocomplete="off" placeholder="{{ $shopify['token_saved'] ? 'Token already saved — leave blank to keep it' : 'shpat_...' }}">
        <small>{{ $shopify['token_saved'] ? '✓ Token saved' : '⚠ No token saved' }}</small>
    </label>

    <div class="span-2" style="display:flex;gap:10px;flex-wrap:wrap">
        <button type="button" class="btn primary" id="shopifySave">Save Connection</button>
        <button type="button" class="btn outline" id="shopifyTest">Test Connection</button>
    </div>

    <div id="shopifyResult" class="span-2 info-box" hidden></div>
</div>

<div class="panel form-grid integration-panel">
    <div class="span-2">
        <h2>Product Import / Sync</h2>
        <p class="muted">
            Shopify products, variants, inventory, prices, descriptions, SEO, images and collection data import হবে।
            আগের imported product আবার sync করলে duplicate হবে না।
        </p>
    </div>

    <div>
        <strong>Imported Shopify Products</strong>
        <div style="font-size:28px;margin-top:6px">{{ number_format($shopify['imported_products']) }}</div>
    </div>
    <div>
        <strong>Last Sync</strong>
        <div style="margin-top:6px">{{ $shopify['last_sync_at'] ?: 'Never' }}</div>
    </div>

    <div class="span-2">
        <button type="button" class="btn primary large" id="shopifySync">Import / Sync All Products</button>
    </div>

    <div id="shopifySyncResult" class="span-2 info-box" hidden></div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const domain = document.getElementById('shopifyDomain');
    const version = document.getElementById('shopifyApiVersion');
    const token = document.getElementById('shopifyToken');
    const result = document.getElementById('shopifyResult');
    const syncResult = document.getElementById('shopifySyncResult');
    const save = document.getElementById('shopifySave');
    const test = document.getElementById('shopifyTest');
    const sync = document.getElementById('shopifySync');

    const jsonPost = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        });

        const text = await response.text();
        let data;
        try { data = JSON.parse(text); } catch (_) { data = { ok:false, message:text }; }
        if (!response.ok || data.ok === false) throw new Error(data.message || 'Request failed.');
        return data;
    };

    const show = (box, message, ok = true) => {
        box.hidden = false;
        box.innerHTML = '<b>' + (ok ? '✓ ' : '✕ ') + '</b>' + String(message);
    };

    save.addEventListener('click', async () => {
        save.disabled = true;
        try {
            const data = await jsonPost(@json(route('admin.shopify.save')), {
                domain: domain.value.trim(),
                api_version: version.value.trim(),
                token: token.value.trim(),
            });
            token.value = '';
            show(result, data.message);
        } catch (e) {
            show(result, e.message, false);
        } finally {
            save.disabled = false;
        }
    });

    test.addEventListener('click', async () => {
        test.disabled = true;
        try {
            const data = await jsonPost(@json(route('admin.shopify.test')), {
                domain: domain.value.trim(),
                api_version: version.value.trim(),
                token: token.value.trim(),
            });
            const shop = data.shop || {};
            show(result, data.message + (shop.name ? ' Store: ' + shop.name + ' (' + shop.myshopifyDomain + ')' : ''));
        } catch (e) {
            show(result, e.message, false);
        } finally {
            test.disabled = false;
        }
    });

    sync.addEventListener('click', async () => {
        sync.disabled = true;
        let cursor = null;
        let totals = { processed:0, created:0, updated:0, failed:0 };
        syncResult.hidden = false;

        try {
            do {
                syncResult.textContent = 'Syncing... Processed: ' + totals.processed;
                const data = await jsonPost(@json(route('admin.shopify.sync-batch')), { cursor });

                for (const key of Object.keys(totals)) {
                    totals[key] += Number(data.stats?.[key] || 0);
                }

                cursor = data.has_next_page ? data.next_cursor : null;
                syncResult.innerHTML =
                    '<b>Syncing Shopify products...</b><br>' +
                    'Processed: ' + totals.processed +
                    ' | Created: ' + totals.created +
                    ' | Updated: ' + totals.updated +
                    ' | Failed: ' + totals.failed;
            } while (cursor);

            syncResult.innerHTML =
                '<b>✓ Shopify sync complete.</b><br>' +
                'Processed: ' + totals.processed +
                ' | Created: ' + totals.created +
                ' | Updated: ' + totals.updated +
                ' | Failed: ' + totals.failed +
                '<br>Refresh this page to see the updated imported-product count.';
        } catch (e) {
            syncResult.innerHTML = '<b>✕ Sync stopped.</b><br>' + e.message;
        } finally {
            sync.disabled = false;
        }
    });
})();
</script>
@endpush
