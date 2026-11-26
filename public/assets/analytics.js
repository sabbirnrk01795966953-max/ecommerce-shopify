(() => {
 const cfg=window.shopGA;
 if(!cfg || !/^G-[A-Z0-9]+$/.test(cfg.id)) return;
 let enabled=false, client='', session='';
 const getConsent=()=>{try{return localStorage.getItem('ga-consent')}catch{return null}};
 const saveConsent=v=>{try{localStorage.setItem('ga-consent',v)}catch{}};
 const event=(name,params={})=>{if(enabled) window.gtag('event',name,{...params, ...(cfg.debug?{debug_mode:true}:{})})};
 const parse=(el,key)=>{try{return JSON.parse(el?.dataset[key]||'[]')}catch{return []}};
 const ecommerce=items=>({currency:'BDT',value:items.reduce((s,i)=>s+Number(i.price)*Number(i.quantity||1),0),items});
 const checkout=form=>{if(!form||form.dataset.gaSeen)return;form.dataset.gaSeen='1';const items=parse(form,'gaItems');event('begin_checkout',ecommerce(items))};
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
  gtag('config',cfg.id,{page_location:url.href,page_title:cfg.title||document.title, page_referrer:document.referrer.split('?')[0],allow_google_signals:false,allow_ad_personalization_signals:false,debug_mode:cfg.debug});
  const script=document.createElement('script');script.async=true;script.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(cfg.id);document.head.append(script);
  gtag('get',cfg.id,'client_id',v=>{client=String(v||'')});
  gtag('get',cfg.id,'session_id',v=>{session=String(v||'')});
  document.querySelectorAll('[data-ga-page-event]').forEach(el=>event(el.dataset.gaPageEvent,ecommerce(parse(el,'gaItems'))));
  document.querySelectorAll('.product-grid').forEach(grid=>{const items=[...grid.querySelectorAll('[data-ga-item]')].flatMap(el=>parse(el,'gaItem'));if(items.length)event('view_item_list',{item_list_name:'Store products',items:items.slice(0,200)})});
  document.querySelectorAll('#checkoutForm,[data-quick-checkout-form]').forEach(checkout);
  if(cfg.purchase)event('purchase',cfg.purchase);
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
 const banner=document.createElement('div');
 banner.style.cssText='position:fixed;bottom:76px;left:12px;right:12px;max-width:440px;background:#fff;color:#111;border:1px solid #ddd;padding:14px;z-index:99999;border-radius:10px;box-shadow:0 4px 20px #0002;font:14px sans-serif';
 banner.textContent='ওয়েবসাইট উন্নত করতে Analytics cookies ব্যবহার করতে অনুমতি দেবেন? ';
 function button(label,fn){const b=document.createElement('button');b.type='button';b.textContent=label;b.style.cssText='margin:6px;padding:8px;border:1px solid #777;border-radius:5px;background:white;color:black';b.onclick=fn;banner.append(b)}
 button('Allow',()=>{saveConsent('yes');banner.remove();start()});
 button('Decline',()=>{saveConsent('no');banner.remove()});
 const preferences=document.createElement('button');preferences.type='button';preferences.textContent='Analytics preferences';preferences.style.cssText='display:block;margin:8px auto;font-size:12px';preferences.onclick=()=>document.body.append(banner);document.querySelector('footer')?.append(preferences);
 if(getConsent()==='yes')start();else if(!getConsent())document.body.append(banner);
 // A visitor can withdraw consent; reload to prevent further collection.
 banner.lastChild.onclick=()=>{saveConsent('no');if(enabled){gtag('consent','update',{analytics_storage:'denied'});location.reload()}else banner.remove()};
})();