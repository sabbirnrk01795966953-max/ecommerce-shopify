<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class GoogleAnalyticsController extends Controller
{
    public function edit() { return view('admin.ga4'); }

    public function save(Request $request)
    {
        $data = $request->validate([
            'ga_measurement_id'=>['nullable','required_if:ga_enabled,1','regex:/^G-[A-Z0-9]+$/D','max:40'],
            'ga_api_secret_new'=>'nullable|string|max:255',
        ]);
        DB::transaction(function () use ($request,$data) {
            foreach (['ga_enabled','ga_server_enabled','ga_debug'] as $key) {
                Setting::setValue($key,$request->boolean($key));
            }
            Setting::setValue('ga_measurement_id',$data['ga_measurement_id'] ?? '');
            if (!empty($data['ga_api_secret_new'])) {
                Setting::setValue('ga_api_secret',Crypt::encryptString($data['ga_api_secret_new']));
            }
        });
        return back()->with('success','Google Analytics settings saved.');
    }
}
