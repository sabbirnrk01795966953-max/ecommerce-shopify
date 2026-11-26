(() => {
 const cfg=window.shopGA;
 if(!cfg || !/^G-[A-Z0-9]+$/.test(cfg.id)) return;
 let enabled=false, client='', session='';
 const getConsent=()=>{try{return localStorage.getItem('ga-consent')}catch{return null}};
 const saveConsent=v=>{try{localStorage.setItem('ga-consent',v)}catch{}};
 const event=async(name,params={})=>{
  if(!enabled)return;
  if(Array.isArray(params.items)){
   const items=await Promise.all(params.items.map(async item=>{
    const id=String(item.item_id||'');
    let normalized=id;
    if(Array.from(id).length>100){
     const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(id));
     normalized=Array.from(id).slice(0,64).join('')+'-'+Array.from(new Uint8Array(digest)).map(n=>n.toString(16).padStart(2,'0')).join('').slice(0,32);
    }
    return {...item,item_id:normalized,item_name:Array.from(String(item.item_name||'')).slice(0,100).join('')};
   }));
   params={...params,items};
  }
  if(enabled)window.gtag('event',name,{...params,...(cfg.debug?{debug_mode:true}:{})});
 };
 const parse=(el,key)=>{try{return JSON.parse(el?.dataset[key]||'[]')}catch{return []}};
 const ecommerce=items=>({currency:'BDT',value:items.reduce((s,i)=>s+Number(i.price)*Number(i.quantity||1),0),items});
 const checkout=form=>{if(!enabled||!form||form.dataset.gaSeen)return;form.dataset.gaSeen='1';const items=parse(form,'gaItems');event('begin_checkout',ecommerce(items))};
 window.shopGAEvent=event;
 window.shopGACheckout=checkout;
 function identity(form){
  for(const [name,value] of Object.entries({ga_consent:enabled?'yes':'no',ga_client_id:client,ga_session_id:session})){
   let input=form.querySelector('[name="'+name+'"]');
   if(!input){input=document.createElement('input');input.type='hidden';input.name=name;form.append(input)}
   input.value=value;
  }
 }
 function start(){
  if(enabled)return;enabled=true;
  window.dataLayer=window.dataLayer||[];window.gtag=function(){window.dataLayer.push(arguments)};
  gtag('consent','default',{analytics_storage:'granted',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
  gtag('js',new Date());
  const url=new URL(location.href);
  for(const key of [...url.searchParams.keys()])if(!/^(utm_source|utm_medium|utm_campaign|utm_content|utm_term|gclid)$/.test(key))url.searchParams.delete(key);
  gtag('config',cfg.id,{page_location:url.href.split('#')[0],page_title:cfg.title||document.title, page_referrer:document.referrer.split('?')[0],allow_google_signals:false,allow_ad_personalization_signals:false,debug_mode:cfg.debug});
  const script=document.createElement('script');script.async=true;script.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(cfg.id);document.head.append(script);
  gtag('get',cfg.id,'client_id',v=>{client=String(v||'')});
  gtag('get',cfg.id,'session_id',v=>{session=String(v||'')});
  document.querySelectorAll('[data-ga-page-event]').forEach(el=>event(el.dataset.gaPageEvent,ecommerce(parse(el,'gaItems'))));
  document.querySelectorAll('.product-grid').forEach(grid=>{const items=[...grid.querySelectorAll('[data-ga-item]')].flatMap(el=>parse(el,'gaItem'));if(items.length)event('view_item_list',{item_list_name:'Store products',items:items.slice(0,200)})});
  document.querySelectorAll('#checkoutForm,[data-quick-checkout-form]').forEach(checkout);
  if(cfg.purchase)event('purchase',cfg.purchase);
  if(cfg.cartEvent)event(cfg.cartEvent.name,cfg.cartEvent.params);
  if(cfg.search)event('search',{search_term:cfg.search});
  if(document.querySelector('.hero'))event('view_promotion',{promotion_id:'home_hero',promotion_name:'Homepage hero'});
 }
 document.addEventListener('click',e=>{
  const link=e.target.closest('a');if(!link)return;
  const card=link.closest('[data-ga-item]');
  if(card)event('select_item',{items:parse(card,'gaItem')});
  if(link.closest('.hero'))event('select_promotion',{promotion_id:'home_hero',promotion_name:'Homepage hero'});
  if(link.href.startsWith('tel:'))event('contact_phone');
  if(/https:\/\/(wa\.me|api\.whatsapp\.com)\//.test(link.href))event('contact_whatsapp');
 });
 document.addEventListener('submit',e=>{
  const form=e.target;
  if(form.matches('#checkoutForm,[data-quick-checkout-form]')){
   identity(form);
   const params=ecommerce(parse(form,'gaItems'));
   const shipping=form.querySelector('[name=shipping_area]:checked');
   event('add_shipping_info',{...params,shipping_tier:shipping?.value||'unknown'});
   event('add_payment_info',{...params,payment_type:'COD'});
  }
 },true);
 const style=document.createElement('style');
 style.textContent=`
 .ga-consent-dialog{border:1px solid #e2e8f0;border-radius:24px;width:min(460px,calc(100% - 32px));max-height:calc(100% - 32px);padding:0;color:#172033;background:#fff;box-shadow:0 24px 80px #0f172a33;font:inherit;overflow:auto}
 .ga-consent-dialog[open]{animation:ga-in .28s ease-out}
 .ga-consent-dialog::backdrop{background:#0f172a70;backdrop-filter:blur(3px)}
 .ga-consent-body{padding:28px;text-align:center}
 .ga-consent-symbol{display:grid;place-items:center;width:64px;height:64px;border-radius:22px;background:#f1f5f9;margin:0 auto 16px;color:#334155}
 .ga-consent-body h2{font-size:22px;line-height:1.4;margin:0 0 10px;color:#172033}
 .ga-consent-body p{font-size:14px;line-height:1.75;color:#475569;margin:0 0 16px}
 .ga-consent-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:18px 0 12px}
 .ga-consent-actions button{font:inherit;font-weight:700;min-height:48px;border:1px solid #334155;border-radius:12px;background:#334155;color:#fff;cursor:pointer;padding:10px;transition:transform .16s,background .16s}
 .ga-consent-actions button:hover{background:#1e293b;transform:translateY(-1px)}
 .ga-consent-actions button:focus-visible,.ga-consent-later:focus-visible,.ga-consent-pref:focus-visible{outline:3px solid #2563eb;outline-offset:3px}
 .ga-consent-later,.ga-consent-pref{font:inherit;font-size:13px;color:inherit;background:transparent;border:0;text-decoration:underline;cursor:pointer;padding:10px}
 .ga-consent-body small{display:block;color:#64748b;font-size:12px;line-height:1.6}
 @keyframes ga-in{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}
 @media(max-width:420px){.ga-consent-body{padding:20px}.ga-consent-body h2{font-size:19px}}
 @media(prefers-reduced-motion:reduce){.ga-consent-dialog[open]{animation:none}.ga-consent-actions button{transition:none}}
 `;
 document.head.append(style);
 const banner=document.createElement('dialog');
 banner.className='ga-consent-dialog';
 banner.setAttribute('aria-labelledby','ga-consent-title');
 banner.innerHTML=`<div class="ga-consent-body"><div class="ga-consent-symbol" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg></div><h2 id="ga-consent-title">আপনার কেনাকাটার অভিজ্ঞতা আরও সহজ করুন!</h2><p>আপনার পছন্দ বুঝতে এবং ওয়েবসাইটের কেনাকাটার অভিজ্ঞতা আরও উন্নত করতে আমরা Analytics Cookies ব্যবহার করি। আপনার অনুমতি আমাদের সেবা আরও উন্নত করতে সাহায্য করবে।</p><div class="ga-consent-actions"><button type="button" data-choice="no">অনুমতি দেব না</button><button type="button" data-choice="yes">অনুমতি দিন</button></div><button type="button" class="ga-consent-later">এখন নয়</button><small>অনুমতি না দিলেও কেনাকাটা করতে পারবেন। আপনি চাইলে পরবর্তী সময়ে আপনার অনুমতি পরিবর্তন করতে পারবেন।</small></div>`;
 document.body.append(banner);
 const deferred=()=>{try{return sessionStorage.getItem('ga-consent-later')==='1'}catch{return false}};
 const postpone=()=>{try{sessionStorage.setItem('ga-consent-later','1')}catch{}};
 const open=()=>{if(!banner.open)banner.showModal()};
 banner.querySelectorAll('[data-choice]').forEach(b=>b.addEventListener('click',()=>{
  const choice=b.dataset.choice;
  saveConsent(choice);banner.close();
  if(choice==='yes'){start();return}
  if(enabled){enabled=false;gtag('consent','update',{analytics_storage:'denied'});location.reload()}
 }));
 banner.querySelector('.ga-consent-later').addEventListener('click',()=>{postpone();banner.close()});
 banner.addEventListener('cancel',postpone);
 const preferences=document.createElement('button');preferences.type='button';preferences.className='ga-consent-pref';preferences.textContent='Analytics preferences';preferences.onclick=open;
 (document.querySelector('footer')||document.body).append(preferences);
 if(getConsent()==='yes')start();else if(!getConsent()&&!deferred())open();
})();
