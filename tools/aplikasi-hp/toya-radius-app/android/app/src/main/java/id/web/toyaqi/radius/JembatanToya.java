package id.web.toyaqi.radius;

import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageInfo;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.print.PrintAttributes;
import android.print.PrintDocumentAdapter;
import android.print.PrintManager;
import android.provider.MediaStore;
import android.util.Base64;
import android.webkit.JavascriptInterface;
import android.webkit.MimeTypeMap;
import android.webkit.WebView;
import android.widget.Toast;

import androidx.core.content.FileProvider;

import java.io.File;
import java.io.FileOutputStream;
import java.io.OutputStream;

/**
 * Jembatan JavaScript "window.ToyaApp" untuk halaman web Toya Radius.
 * Hanya halaman toyaqi.web.id yang bisa dibuka di aplikasi ini
 * (server.allowNavigation), link lain otomatis dibuka di browser.
 */
public class JembatanToya {

    private final MainActivity aktivitas;
    private final WebView webView;

    JembatanToya(MainActivity aktivitas, WebView webView) {
        this.aktivitas = aktivitas;
        this.webView = webView;
    }

    @JavascriptInterface
    public String versi() {
        try {
            PackageInfo p = aktivitas.getPackageManager().getPackageInfo(aktivitas.getPackageName(), 0);
            return p.versionName;
        } catch (Exception e) {
            return "";
        }
    }

    @JavascriptInterface
    public int versiKode() {
        try {
            PackageInfo p = aktivitas.getPackageManager().getPackageInfo(aktivitas.getPackageName(), 0);
            return (int) (Build.VERSION.SDK_INT >= 28 ? p.getLongVersionCode() : p.versionCode);
        } catch (Exception e) {
            return 0;
        }
    }

    /** Buka alamat di browser HP (mis. unduh APK versi baru). */
    @JavascriptInterface
    public void bukaBrowser(String url) {
        if (url == null || !(url.startsWith("https://") || url.startsWith("http://"))) {
            return;
        }
        try {
            Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            aktivitas.startActivity(i);
        } catch (Exception e) {
            pesan("Tidak ada browser untuk membuka tautan.");
        }
    }

    /** Kembali ke layar "Kode ISP". */
    @JavascriptInterface
    public void gantiIsp() {
        aktivitas.runOnUiThread(() -> webView.loadUrl("https://localhost/index.html?ganti=1"));
    }

    /** window.print() -> menu cetak Android (printer terpasang / Simpan sebagai PDF). */
    @JavascriptInterface
    public void cetak(String judul) {
        final String j = (judul == null || judul.trim().isEmpty()) ? "Toya Radius" : judul.trim();
        aktivitas.runOnUiThread(() -> {
            try {
                PrintManager pm = (PrintManager) aktivitas.getSystemService(Context.PRINT_SERVICE);
                PrintDocumentAdapter adapter = webView.createPrintDocumentAdapter(j);
                pm.print(j, adapter, new PrintAttributes.Builder().build());
            } catch (Exception e) {
                pesan("Gagal membuka menu cetak: " + e.getMessage());
            }
        });
    }

    /** Simpan file dari halaman web (Excel/PDF hasil ekspor) ke folder Download lalu buka. */
    @JavascriptInterface
    public void simpanFile(String base64, String nama, String mime) {
        try {
            byte[] data = Base64.decode(base64, Base64.DEFAULT);
            String n = (nama == null ? "" : nama).replaceAll("[\\\\/:*?\"<>|]", "_").trim();
            if (n.isEmpty()) {
                n = "unduhan";
            }
            String m = mime;
            if (m == null || m.isEmpty() || m.equals("application/octet-stream")) {
                String ext = MimeTypeMap.getFileExtensionFromUrl(n.replace(" ", "_"));
                String tebak = ext == null ? null : MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext.toLowerCase());
                m = tebak != null ? tebak : "application/octet-stream";
            }

            Uri uri;
            if (Build.VERSION.SDK_INT >= 29) {
                ContentResolver cr = aktivitas.getContentResolver();
                ContentValues cv = new ContentValues();
                cv.put(MediaStore.Downloads.DISPLAY_NAME, n);
                cv.put(MediaStore.Downloads.MIME_TYPE, m);
                cv.put(MediaStore.Downloads.IS_PENDING, 1);
                uri = cr.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, cv);
                if (uri == null) {
                    throw new Exception("folder Download tidak bisa ditulis");
                }
                try (OutputStream os = cr.openOutputStream(uri)) {
                    if (os == null) {
                        throw new Exception("file tidak bisa dibuat");
                    }
                    os.write(data);
                }
                cv.clear();
                cv.put(MediaStore.Downloads.IS_PENDING, 0);
                cr.update(uri, cv, null, null);
            } else {
                File dir = aktivitas.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
                if (dir == null) {
                    throw new Exception("penyimpanan tidak tersedia");
                }
                dir.mkdirs();
                File f = new File(dir, n);
                try (FileOutputStream fo = new FileOutputStream(f)) {
                    fo.write(data);
                }
                uri = FileProvider.getUriForFile(aktivitas, aktivitas.getPackageName() + ".fileprovider", f);
            }

            final Uri u = uri;
            final String mm = m;
            final String nn = n;
            aktivitas.runOnUiThread(() -> {
                Toast.makeText(aktivitas, "Tersimpan di Download: " + nn, Toast.LENGTH_LONG).show();
                try {
                    Intent v = new Intent(Intent.ACTION_VIEW);
                    v.setDataAndType(u, mm);
                    v.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
                    aktivitas.startActivity(Intent.createChooser(v, "Buka dengan").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
                } catch (Exception e) {
                    // tidak ada aplikasi pembuka -- file tetap tersimpan
                }
            });
        } catch (Exception e) {
            pesan("Gagal menyimpan file: " + e.getMessage());
        }
    }

    private void pesan(String t) {
        aktivitas.runOnUiThread(() -> Toast.makeText(aktivitas, t, Toast.LENGTH_LONG).show());
    }
}
