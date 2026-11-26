@extends('layouts.admin')
@section('title','Google Analytics 4')
@section('page_title','Google Analytics 4')
@section('content')
<form class="panel form-grid" method="post" action="{{ route('admin.ga4.save') }}">
@csrf
<h2 class="span-2">Google Analytics 4</h2>
<label class="check"><input type="checkbox" name="ga_enabled" value="1" @checked(($siteSettings['ga_enabled'] ?? '0') === '1')> Enable GA4</label>
<label class="check"><input type="checkbox" name="ga_server_enabled" value="1" @checked(($siteSettings['ga_server_enabled'] ?? '0') === '1')> Server purchase tracking</label>
<label>Measurement ID<input name="ga_measurement_id" value="{{ $siteSettings['ga_measurement_id'] ?? '' }}" placeholder="G-XXXXXXXXXX"></label>
<label>API Secret<input type="password" name="ga_api_secret_new" autocomplete="new-password" placeholder="{{ empty($siteSettings['ga_api_secret']) ? 'Enter secret' : 'Saved — leave blank to keep' }}"></label>
<label class="check"><input type="checkbox" name="ga_debug" value="1" @checked(($siteSettings['ga_debug'] ?? '0') === '1')> Debug mode</label>
<p class="span-2">Analytics requires visitor consent. Verify events in GA4 DebugView and Realtime.</p>
<button class="btn primary">Save GA4 Settings</button>
</form>
@endsection
