<?php
/**
 * Tema gelap/terang untuk halaman mandiri (cetak, diagnosa). Panggil wmsThemeEmit() sebelum </head>.
 * Pilihan disimpan di cookie + localStorage 'wmsTheme' (dibagi dengan layout.php dan login.php).
 * Kertas dokumen cetak tetap putih (barcode harus hitam di atas putih), hanya latar layar & toolbar yang gelap,
 * dan semuanya kembali terang saat dicetak.
 */
if (!function_exists('wmsThemeEmit')) {
    function wmsThemeEmit()
    {
        echo <<<'HTML'
<script>(function(){try{var m=document.cookie.match(/(?:^|; )wmsTheme=(\w+)/),c=m?m[1]:null,l=null;try{l=localStorage.getItem('wmsTheme')}catch(e){}var t=l||c;if(!t&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)t='dark';if(t==='dark')document.documentElement.setAttribute('data-theme','dark');else document.documentElement.removeAttribute('data-theme')}catch(e){}})();</script>
<style>
  .thm-fab{position:fixed;top:10px;right:12px;width:38px;height:38px;padding:0;border-radius:10px;border:1px solid #bbb;background:#fff;color:#222;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:99}
  .thm-fab svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .thm-fab .i-sun{display:none}
  html[data-theme=dark] .thm-fab .i-moon{display:none}
  html[data-theme=dark] .thm-fab .i-sun{display:block}
  @media print{.thm-fab{display:none!important}}
  @media screen{
    html[data-theme=dark]{color-scheme:dark}
    html[data-theme=dark] body{background:#14171a!important;color:#d5d9dd}
    html[data-theme=dark] .thm-fab{background:#262b30;border-color:#3a4148;color:#e6e8ea}
    html[data-theme=dark] .bar span,html[data-theme=dark] .tool,html[data-theme=dark] .tool label{color:#aab0b6}
    html[data-theme=dark] .tool input,html[data-theme=dark] .tool select{background:#14171a;border-color:#3a4148;color:#e6e8ea}
    html[data-theme=dark] main h1{color:#f3f4f5}
    html[data-theme=dark] .page,html[data-theme=dark] .sheet{color:#000}
    html[data-theme=dark] .row{background:#1d2125!important;box-shadow:0 1px 4px rgba(0,0,0,.5)}
    html[data-theme=dark] main small{color:#aab0b6}
    html[data-theme=dark] .fix{color:#ff9b93}
  }
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  var b=document.createElement('button');b.type='button';b.className='thm-fab';b.title='Ganti mode gelap / terang';b.setAttribute('aria-label','Ganti mode gelap atau terang');
  b.innerHTML='<svg class="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg><svg class="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  b.addEventListener('click',function(){var r=document.documentElement,d=r.getAttribute('data-theme')==='dark',v=d?'light':'dark';
    if(d)r.removeAttribute('data-theme');else r.setAttribute('data-theme','dark');
    document.cookie='wmsTheme='+v+';path=/;max-age=31536000;SameSite=Lax';try{localStorage.setItem('wmsTheme',v)}catch(e){}});
  document.body.appendChild(b);
});
</script>
HTML;
    }
}
