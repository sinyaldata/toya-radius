package id.web.toyaqi.radius;

/**
 * Skrip yang disuntikkan ke setiap halaman toyaqi.web.id di dalam aplikasi.
 * DIBUAT OTOMATIS dari src/suntikan-web.js -- ubah di sana.
 *  - window.print() -> menu cetak Android
 *  - unduhan blob:/data: (ekspor Excel/PDF) -> disimpan ke folder Download
 *  - halaman login: "Ingat saya" dicentang otomatis + tombol "Ganti ISP"
 */
final class SuntikanWeb {
    private SuntikanWeb() {}

    static final String SKRIP =
        "(function () {\n" +
        "  if (window.__toyaApp) return;\n" +
        "  window.__toyaApp = true;\n" +
        "  var A = window.ToyaApp;\n" +
        "  if (!A) return;\n" +
        "\n" +
        "  function tandai() {\n" +
        "    if (document.documentElement) document.documentElement.classList.add('tya-di-app');\n" +
        "  }\n" +
        "  tandai();\n" +
        "\n" +
        "  // ---------- cetak: window.print() -> menu cetak Android ----------\n" +
        "  window.print = function () {\n" +
        "    try { A.cetak(document.title || 'Toya Radius'); } catch (e) {}\n" +
        "  };\n" +
        "\n" +
        "  // ---------- unduhan blob:/data: (ekspor Excel/PDF Livewire) ----------\n" +
        "  function simpan(href, nama) {\n" +
        "    fetch(href).then(function (r) { return r.blob(); }).then(function (b) {\n" +
        "      var fr = new FileReader();\n" +
        "      fr.onload = function () {\n" +
        "        var s = String(fr.result || '');\n" +
        "        var i = s.indexOf(',');\n" +
        "        try { A.simpanFile(s.slice(i + 1), nama || 'unduhan', b.type || ''); } catch (e) {}\n" +
        "      };\n" +
        "      fr.readAsDataURL(b);\n" +
        "    }).catch(function () {});\n" +
        "  }\n" +
        "  function perluTangkap(a) {\n" +
        "    var h = a && a.getAttribute ? (a.getAttribute('href') || '') : '';\n" +
        "    return /^(blob:|data:)/i.test(h);\n" +
        "  }\n" +
        "  var klikAsli = HTMLAnchorElement.prototype.click;\n" +
        "  HTMLAnchorElement.prototype.click = function () {\n" +
        "    if (perluTangkap(this)) { simpan(this.href, this.getAttribute('download')); return; }\n" +
        "    return klikAsli.apply(this, arguments);\n" +
        "  };\n" +
        "  document.addEventListener('click', function (e) {\n" +
        "    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;\n" +
        "    if (a && perluTangkap(a)) {\n" +
        "      e.preventDefault();\n" +
        "      e.stopPropagation();\n" +
        "      simpan(a.href, a.getAttribute('download'));\n" +
        "    }\n" +
        "  }, true);\n" +
        "\n" +
        "  // ---------- halaman login: \"Ingat saya\" otomatis + tombol Ganti ISP ----------\n" +
        "  function halamanLogin() {\n" +
        "    tandai();\n" +
        "    var cek = document.querySelector('input[type=\"checkbox\"][id*=\"remember\" i], input[type=\"checkbox\"][name*=\"remember\" i], input[type=\"checkbox\"][wire\\\\:model*=\"remember\"]');\n" +
        "    if (cek && !cek.checked && !cek.__toyaDicentang) {\n" +
        "      cek.__toyaDicentang = true;\n" +
        "      cek.click();\n" +
        "    }\n" +
        "    var form = document.querySelector('.fi-simple-page form, form[wire\\\\:submit*=\"authenticate\"]');\n" +
        "    if (form && !document.getElementById('toya-ganti-isp')) {\n" +
        "      var b = document.createElement('button');\n" +
        "      b.type = 'button';\n" +
        "      b.id = 'toya-ganti-isp';\n" +
        "      b.textContent = 'Ganti ISP';\n" +
        "      b.setAttribute('style', 'display:block;margin:18px auto 0;padding:10px 18px;border:0;border-radius:12px;background:#fff7ed;color:#c2410c;font-weight:700;font-size:14px;cursor:pointer');\n" +
        "      b.addEventListener('click', function () { try { A.gantiIsp(); } catch (e) {} });\n" +
        "      form.parentNode.insertBefore(b, form.nextSibling);\n" +
        "    }\n" +
        "  }\n" +
        "  function mulai() {\n" +
        "    tandai();\n" +
        "    if (/\\/login(\\/|$|\\?)/.test(location.pathname) || document.querySelector('.fi-simple-page')) {\n" +
        "      halamanLogin();\n" +
        "      setTimeout(halamanLogin, 700);\n" +
        "      setTimeout(halamanLogin, 1600);\n" +
        "    }\n" +
        "  }\n" +
        "  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mulai);\n" +
        "  else mulai();\n" +
        "  document.addEventListener('livewire:navigated', mulai);\n" +
        "})();\n" +
        "\n";
}
