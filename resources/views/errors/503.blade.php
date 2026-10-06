<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>503 - সাইট সাময়িকভাবে unavailable</title>

<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f5f7fa;color:#17202a;font-family:Arial,"Noto Sans Bengali",sans-serif}
.error-card{width:min(620px,100%);background:#fff;border:1px solid #e6e9ee;border-radius:20px;padding:42px 36px;text-align:center;box-shadow:0 20px 55px rgba(15,23,42,.08)}
.error-code{display:inline-flex;align-items:center;justify-content:center;min-width:86px;height:42px;padding:0 18px;border-radius:999px;background:#eef8f2;color:#087f37;font-size:15px;font-weight:800;margin-bottom:18px}
h1{margin:0 0 12px;font-size:30px;line-height:1.15}
p{margin:0 auto 26px;max-width:480px;color:#667085;font-size:15px;line-height:1.7}
.actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:10px;text-decoration:none;font-weight:800;border:1px solid #d7dce2;color:#344054;background:#fff}
.btn.primary{background:#00b957;border-color:#00b957;color:#fff}
.btn:hover{filter:brightness(.96)}
.small{margin-top:24px;color:#98a2b3;font-size:12px}
@media(max-width:560px){.error-card{padding:32px 20px;border-radius:16px}h1{font-size:24px}.actions{display:grid}.btn{width:100%}}
</style>
</head>
<body>
<main class="error-card">
  <div class="error-code">503</div>
  <h1>সাইট সাময়িকভাবে unavailable</h1>
  <p>Maintenance অথবা সাময়িক server কাজের কারণে সাইটটি এখন unavailable। কিছুক্ষণ পর আবার চেষ্টা করুন।</p>
  <div class="actions">
    <a class="btn primary" href="javascript:location.reload()">আবার চেষ্টা করুন</a><a class="btn" href="{{ route('home') }}">হোম পেজে যান</a>
  </div>
  <div class="small">Service unavailable</div>
</main>
</body>
</html>