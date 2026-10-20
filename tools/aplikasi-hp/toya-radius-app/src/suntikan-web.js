(function () {
  if (window.__toyaApp) return;
  window.__toyaApp = true;
  var A = window.ToyaApp;
  if (!A) return;

  function tandai() {
    if (document.documentElement) document.documentElement.classList.add('tya-di-app');
  }
  tandai();

  // ---------- cetak: window.print() -> menu cetak Android ----------
  window.print = function () {
    try { A.cetak(document.title || 'Toya Radius'); } catch (e) {}
  };

  // ---------- unduhan blob:/data: (ekspor Excel/PDF Livewire) ----------
  function simpan(href, nama) {
    fetch(href).then(function (r) { return r.blob(); }).then(function (b) {
      var fr = new FileReader();
      fr.onload = function () {
        var s = String(fr.result || '');
        var i = s.indexOf(',');
        try { A.simpanFile(s.slice(i + 1), nama || 'unduhan', b.type || ''); } catch (e) {}
      };
      fr.readAsDataURL(b);
    }).catch(function () {});
  }
  function perluTangkap(a) {
    var h = a && a.getAttribute ? (a.getAttribute('href') || '') : '';
    return /^(blob:|data:)/i.test(h);
  }
  var klikAsli = HTMLAnchorElement.prototype.click;
  HTMLAnchorElement.prototype.click = function () {
    if (perluTangkap(this)) { simpan(this.href, this.getAttribute('download')); return; }
    return klikAsli.apply(this, arguments);
  };
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (a && perluTangkap(a)) {
      e.preventDefault();
      e.stopPropagation();
      simpan(a.href, a.getAttribute('download'));
    }
  }, true);

  // ---------- halaman login: "Ingat saya" otomatis + tombol Ganti ISP ----------
  function halamanLogin() {
    tandai();
    var cek = document.querySelector('input[type="checkbox"][id*="remember" i], input[type="checkbox"][name*="remember" i], input[type="checkbox"][wire\\:model*="remember"]');
    if (cek && !cek.checked && !cek.__toyaDicentang) {
      cek.__toyaDicentang = true;
      cek.click();
    }
    var form = document.querySelector('.fi-simple-page form, form[wire\\:submit*="authenticate"]');
    if (form && !document.getElementById('toya-ganti-isp')) {
      var b = document.createElement('button');
      b.type = 'button';
      b.id = 'toya-ganti-isp';
      b.textContent = 'Ganti ISP';
      b.setAttribute('style', 'display:block;margin:18px auto 0;padding:10px 18px;border:0;border-radius:12px;background:#fff7ed;color:#c2410c;font-weight:700;font-size:14px;cursor:pointer');
      b.addEventListener('click', function () { try { A.gantiIsp(); } catch (e) {} });
      form.parentNode.insertBefore(b, form.nextSibling);
    }
  }
  function mulai() {
    tandai();
    if (/\/login(\/|$|\?)/.test(location.pathname) || document.querySelector('.fi-simple-page')) {
      halamanLogin();
      setTimeout(halamanLogin, 700);
      setTimeout(halamanLogin, 1600);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mulai);
  else mulai();
  document.addEventListener('livewire:navigated', mulai);
})();
