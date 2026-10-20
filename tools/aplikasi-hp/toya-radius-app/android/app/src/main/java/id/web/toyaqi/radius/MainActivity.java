package id.web.toyaqi.radius;

import android.app.DownloadManager;
import android.content.Context;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.widget.Toast;

import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;
import com.getcapacitor.WebViewListener;

import java.util.Arrays;
import java.util.HashSet;

/**
 * Toya Radius -- pembungkus aplikasi web (semua tenant *.toyaqi.web.id).
 *  - jembatan "ToyaApp" untuk JavaScript halaman web (simpan file, cetak, dll)
 *  - skrip SuntikanWeb disuntikkan ke setiap halaman toyaqi.web.id
 *  - unduhan file biasa lewat DownloadManager (cookie login ikut terkirim)
 */
public class MainActivity extends BridgeActivity {

    private static final String[] ASAL_DIIZINKAN = new String[] {
        "https://toyaqi.web.id",
        "https://*.toyaqi.web.id"
    };

    private boolean suntikDiAwal = false;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        final WebView wv = getBridge().getWebView();
        WebSettings s = wv.getSettings();
        s.setSupportMultipleWindows(false);
        s.setJavaScriptCanOpenWindowsAutomatically(true);

        CookieManager cm = CookieManager.getInstance();
        cm.setAcceptCookie(true);
        cm.setAcceptThirdPartyCookies(wv, true);

        wv.addJavascriptInterface(new JembatanToya(this, wv), "ToyaApp");

        // Halaman toyaqi.web.id dimuat LANGSUNG oleh WebView (cache browser,
        // HTTP/2, kompresi), TIDAK lewat proxy bawaan Capacitor yang mengunduh
        // ulang setiap halaman lewat Java tanpa cache -- itu yang membuat
        // halaman besar (Voucher) terasa berat di aplikasi.
        getBridge().setWebViewClient(new BridgeWebViewClient(getBridge()) {
            @Override
            public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                String host = request.getUrl() != null ? request.getUrl().getHost() : null;
                if (host != null && (host.equals("toyaqi.web.id") || host.endsWith(".toyaqi.web.id"))) {
                    return null;
                }
                return super.shouldInterceptRequest(view, request);
            }
        });

        // Skrip pembantu di setiap halaman: sejak awal halaman (kalau didukung),
        // supaya window.print() di halaman cetak voucher juga tertangkap.
        if (WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {
            try {
                WebViewCompat.addDocumentStartJavaScript(wv, SuntikanWeb.SKRIP, new HashSet<>(Arrays.asList(ASAL_DIIZINKAN)));
                suntikDiAwal = true;
            } catch (Exception e) {
                suntikDiAwal = false;
            }
        }
        getBridge().addWebViewListener(new WebViewListener() {
            @Override
            public void onPageLoaded(WebView webView) {
                CookieManager.getInstance().flush();
                if (!suntikDiAwal) {
                    String url = webView.getUrl();
                    if (url != null && Uri.parse(url).getHost() != null && Uri.parse(url).getHost().endsWith("toyaqi.web.id")) {
                        webView.evaluateJavascript(SuntikanWeb.SKRIP, null);
                    }
                }
            }
        });

        wv.setDownloadListener((url, userAgent, contentDisposition, mimetype, contentLength) ->
            unduhUrl(url, userAgent, contentDisposition, mimetype));
    }

    @Override
    public void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }

    /** Unduhan biasa (http/https). blob:/data: ditangani skrip web -> ToyaApp.simpanFile. */
    void unduhUrl(String url, String userAgent, String contentDisposition, String mimetype) {
        if (url == null || url.startsWith("blob:") || url.startsWith("data:")) {
            return;
        }
        try {
            String nama = URLUtil.guessFileName(url, contentDisposition, mimetype);
            DownloadManager.Request r = new DownloadManager.Request(Uri.parse(url));
            String cookie = CookieManager.getInstance().getCookie(url);
            if (cookie != null) {
                r.addRequestHeader("Cookie", cookie);
            }
            if (userAgent != null) {
                r.addRequestHeader("User-Agent", userAgent);
            }
            if (mimetype != null && !mimetype.isEmpty()) {
                r.setMimeType(mimetype);
            }
            r.setTitle(nama);
            r.setDescription("Toya Radius");
            r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            if (Build.VERSION.SDK_INT >= 29) {
                r.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, nama);
            } else {
                r.setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, nama);
            }
            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            dm.enqueue(r);
            Toast.makeText(this, "Mengunduh " + nama + "…", Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            Toast.makeText(this, "Gagal mengunduh: " + e.getMessage(), Toast.LENGTH_LONG).show();
        }
    }
}
