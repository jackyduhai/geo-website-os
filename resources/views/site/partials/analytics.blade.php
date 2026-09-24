{{--
  GEO Website OS Analytics —— 统一事件层（P-STEP 18H-3）
  仅当存在「已启用且 ID 有效」的 provider 时输出。
  契约：
    - 只暴露 window.GeoAnalytics.track；事件层不承载 provider 业务语义。
    - consent_required 且未「接受」前：不加载第三方脚本、不发第三方请求；事件仅在内存排队。
    - 接受后初始化 adapters 并回放；拒绝则丢弃队列。
    - form_submit 仅带 form 标识，不采集任何表单字段值。
--}}
@php($geoAnalytics = app(\App\Support\Analytics\AnalyticsConfig::class))
@if($geoAnalytics->hasAnyProvider())
<script nonce="{{ $cspNonce ?? '' }}">
(function(){
  var CONFIG = @json($geoAnalytics->forFrontend());
  var LOCALE = @json(\App\Support\Localization\LocaleContext::current());
  var CONSENT_KEY = 'gwos-consent';

  function storedConsent(){ try{ return localStorage.getItem(CONSENT_KEY); }catch(e){ return null; } }
  var consent = storedConsent();
  var needConsent = CONFIG.consentRequired !== false;
  function mayLoad(){ return !needConsent || consent === 'accepted'; }

  var queue = [];
  var initialized = false;
  var adapters = [];
  var NONCE = @json($cspNonce ?? '');

  function basePayload(name, params){
    return Object.assign({ event: name, locale: LOCALE, page_path: location.pathname }, params || {});
  }
  function dispatch(ev){ adapters.forEach(function(a){ try{ a(ev); }catch(e){} }); }

  window.GeoAnalytics = {
    track: function(name, params){
      var ev = basePayload(name, params);
      if(!initialized){ queue.push(ev); return; }
      dispatch(ev);
    },
    /* Consent banner 回调；banner 自身不依赖任何第三方。 */
    resolveConsent: function(state){
      consent = state;
      try{ localStorage.setItem(CONSENT_KEY, state); }catch(e){}
      if(state === 'accepted'){
        if(!initialized){ initAdapters(); }
        var q = queue; queue = []; q.forEach(dispatch);
      }else if(state === 'denied'){
        queue = [];
      }
    },
    state: function(){ return {needConsent: needConsent, consent: consent, initialized: initialized, queued: queue.length}; }
  };

  function createScript(src){
    return new Promise(function(resolve){
      var s = document.createElement('script');
      s.src = src; s.async = true;
      s.setAttribute('nonce', NONCE);
      s.onload = function(){ resolve(true); };
      s.onerror = function(){ resolve(false); };   /* provider 加载失败不阻断网站 */
      document.head.appendChild(s);
    });
  }

  function initAdapters(){
    initialized = true;
    var p = CONFIG.providers;

    /* GTM：container 与 gtm.js 均带 nonce；事件直接 push dataLayer。 */
    if(p.gtm.enabled){
      window.dataLayer = window.dataLayer || [];
      (function(w,d,s,l,i){
        w[l] = w[l] || [];
        w[l].push({'gtm.start': new Date().getTime(), event: 'gtm.js'});
        var f = d.getElementsByTagName(s)[0],
            j = d.createElement(s),
            dl = l !== 'dataLayer' ? '&l=' + l : '';
        j.async = true;
        j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
        j.setAttribute('nonce', NONCE);
        f.parentNode.insertBefore(j, f);
      })(window, document, 'script', 'dataLayer', p.gtm.id);
      adapters.push(function(ev){ window.dataLayer.push(ev); });
    }

    /* GA4：与 GTM 同时启用时交由 GTM 内 GA 配置，避免重复计数。 */
    if(p.ga4.enabled && !p.gtm.enabled){
      window.dataLayer = window.dataLayer || [];
      window.gtag = function(){ window.dataLayer.push(arguments); };
      window.gtag('js', new Date());
      window.gtag('config', p.ga4.id, {language: LOCALE});
      createScript('https://www.googletagmanager.com/gtag/js?id=' + p.ga4.id);
      adapters.push(function(ev){ window.gtag('event', ev.event, ev); });
    }

    /* Meta Pixel */
    if(p.meta.enabled){
      (function(){
        if(window.fbq) return;
        var b = document, e = 'script';
        var n = function(){ n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
        window.fbq = n; window._fbq = n;
        n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
        var t = b.createElement(e); t.async = true;
        t.src = 'https://connect.facebook.net/en_US/fbevents.js';
        t.setAttribute('nonce', NONCE);
        var s = b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t, s);
      })();
      var FB_MAP = {page_view: 'PageView', cta_click: 'CTA Click', form_submit: 'Contact', download: 'Download', contact: 'Contact'};
      window.fbq('init', p.meta.id);
      window.fbq('track', 'PageView');
      adapters.push(function(ev){ window.fbq('track', FB_MAP[ev.event] || ev.event, ev); });
    }
  }

  /* ---- 事件委托 ---- */
  var DOWNLOAD_RE = /\.(pdf|docx?|xlsx?|zip|rar|csv)$/i;
  function isDownloadLink(a){
    var h = (a.getAttribute('href') || '').split('?')[0];
    return a.hasAttribute('download') || DOWNLOAD_RE.test(h);
  }
  function onReady(fn){
    if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', fn); }
    else { fn(); }
  }

  onReady(function(){
    window.GeoAnalytics.track('page_view');

    document.addEventListener('click', function(e){
      var t = e.target.closest('[data-geo-event], a.btn, button.btn, a.button, a[download]');
      if(!t) return;
      if(t.hasAttribute('data-geo-event')){
        var name = t.getAttribute('data-geo-event');
        var params = {};
        var raw = t.getAttribute('data-geo-params');
        if(raw){ try{ params = JSON.parse(raw); }catch(err){} }
        window.GeoAnalytics.track(name, params);
        return;
      }
      var link = t.closest('a');
      if(link && isDownloadLink(link)){
        var file = link.getAttribute('download') || (link.getAttribute('href') || '').split('/').pop();
        window.GeoAnalytics.track('download', {file_name: file});
        return;
      }
      window.GeoAnalytics.track('cta_click', {cta_text: (t.innerText || t.textContent || '').trim().slice(0, 80)});
    });

    document.addEventListener('submit', function(e){
      var f = e.target;
      if(!f || f.tagName !== 'FORM') return;
      window.GeoAnalytics.track('form_submit', {
        form_id: f.getAttribute('data-form-id') || '',
        form_slug: f.getAttribute('data-form-slug') || ''
      });
    }, true);
  });

  /* ---- 自动初始化（无需 consent 或已接受）---- */
  if(mayLoad()){ initAdapters(); }
})();
</script>
@endif
