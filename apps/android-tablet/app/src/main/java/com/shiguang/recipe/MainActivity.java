package com.shiguang.recipe;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.DialogInterface;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import org.json.JSONObject;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.JavascriptInterface;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.ProgressBar;
import android.widget.Toast;

public class MainActivity extends Activity {

    private static final String SITE_URL = "https://dongshun.gt.tc/";
    private static final String SITE_HOST = "dongshun.gt.tc";
    private static final int REQUEST_FILE = 1001;
    private static final int REQUEST_CAMERA = 1002;

    private WebView webView;
    private ProgressBar progressBar;
    private ValueCallback<Uri[]> fileCallback;
    private PermissionRequest pendingCameraRequest;
    private boolean updateChecked = false;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        Window window = getWindow();
        window.setStatusBarColor(Color.rgb(200, 80, 43));
        window.setNavigationBarColor(Color.rgb(255, 253, 249));
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            window.getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR);
        }
        window.setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE);

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.rgb(255, 253, 249));

        webView = new WebView(this);
        webView.setBackgroundColor(Color.rgb(255, 253, 249));
        FrameLayout.LayoutParams webParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT);
        root.addView(webView, webParams);

        progressBar = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progressBar.setIndeterminate(false);
        progressBar.setMax(100);
        progressBar.setProgress(0);
        progressBar.setVisibility(View.GONE);
        FrameLayout.LayoutParams progressParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, dp(3));
        progressParams.gravity = Gravity.TOP;
        root.addView(progressBar, progressParams);

        setContentView(root);
        ensureCameraPermission();

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            cookies.setAcceptThirdPartyCookies(webView, true);
        }

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setJavaScriptCanOpenWindowsAutomatically(true);
        settings.setSupportMultipleWindows(false);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setBuiltInZoomControls(false);
        settings.setDisplayZoomControls(false);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setUserAgentString(settings.getUserAgentString());

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                return handleUrl(request.getUrl());
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                return handleUrl(Uri.parse(url));
            }

            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                progressBar.setVisibility(View.VISIBLE);
                progressBar.setProgress(8);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                progressBar.setVisibility(View.GONE);
                CookieManager.getInstance().flush();
                injectSiteFixes();
                if (!updateChecked) {
                    updateChecked = true;
                    checkForUpdate();
                }
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) showNetworkError();
            }

            @Override
            public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse response) {
                if (request.isForMainFrame() && response.getStatusCode() >= 500) {
                    showNetworkError();
                }
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progressBar.setProgress(newProgress);
                progressBar.setVisibility(newProgress >= 100 ? View.GONE : View.VISIBLE);
            }

            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        boolean wantsVideo = false;
                        for (String resource : request.getResources()) {
                            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)) {
                                wantsVideo = true;
                                break;
                            }
                        }
                        if (!wantsVideo) {
                            request.deny();
                            return;
                        }
                        if (hasCameraPermission()) {
                            request.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
                            return;
                        }
                        if (pendingCameraRequest != null) {
                            try { pendingCameraRequest.deny(); } catch (Exception ignored) { }
                        }
                        pendingCameraRequest = request;
                        ensureCameraPermission();
                    }
                });
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback,
                                             FileChooserParams params) {
                if (fileCallback != null) fileCallback.onReceiveValue(null);
                fileCallback = callback;
                Intent intent = new Intent(Intent.ACTION_GET_CONTENT);
                intent.addCategory(Intent.CATEGORY_OPENABLE);
                intent.setType("*/*");
                intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, false);
                try {
                    startActivityForResult(Intent.createChooser(intent, "选择图片"), REQUEST_FILE);
                    return true;
                } catch (Exception e) {
                    fileCallback = null;
                    return false;
                }
            }
        });

        webView.addJavascriptInterface(new UpdateBridge(), "SGUpdate");

        webView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition,
                                        String mimeType, long contentLength) {
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                } catch (Exception ignored) {
                    Toast.makeText(MainActivity.this, "请复制链接到浏览器下载", Toast.LENGTH_SHORT).show();
                }
            }
        });

        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState);
        } else {
            webView.loadUrl(startUrl(getIntent()));
        }
    }

    private boolean hasCameraPermission() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.M
                || checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED;
    }

    private void ensureCameraPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !hasCameraPermission()) {
            requestPermissions(new String[]{Manifest.permission.CAMERA}, REQUEST_CAMERA);
        }
    }

    private boolean handleUrl(Uri uri) {
        if (uri == null) return false;
        String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase();
        String host = uri.getHost() == null ? "" : uri.getHost().toLowerCase();
        if (("http".equals(scheme) || "https".equals(scheme)) && SITE_HOST.equals(host)) {
            return false;
        }
        if ("http".equals(scheme) || "https".equals(scheme)
                || "mailto".equals(scheme) || "tel".equals(scheme)) {
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, uri));
            } catch (Exception ignored) { }
            return true;
        }
        return false;
    }


    private String startUrl(Intent intent) {
        String shortcut = intent == null ? null : intent.getStringExtra("shortcut");
        return "scan".equals(shortcut) ? SITE_URL + "?scan=1" : SITE_URL;
    }

    private String readAsset(String name) {
        try {
            java.io.InputStream in = getAssets().open(name);
            java.io.ByteArrayOutputStream out = new java.io.ByteArrayOutputStream();
            byte[] buf = new byte[8192];
            int n;
            while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
            in.close();
            return out.toString("UTF-8");
        } catch (Exception e) {
            return "";
        }
    }

    /* 站点 app.js / jsQR.js 被免费主机拦截时，用 APK 内置副本补上图标、翻面、刷新和扫码。 */
    private void injectSiteFixes() {
        if (webView == null) return;
        webView.evaluateJavascript("(function(){return !!(window.__sgAppLoaded);})()", value -> {
            if ("true".equals(value)) {
                /* app.js 已由站点加载，但 jsQR 仍可能被免费主机拦截；扫码前先补一份本地库。 */
                String jsqrOnly = readAsset("jsQR.js");
                String scriptOn = "(function(){try{if(!window.jsQR&&document.getElementById('scanOverlay'))(0,eval)(" + JSONObject.quote(jsqrOnly) + ");}catch(e){}" +
                        "['.titlebar','.front-topbar'].forEach(function(sel){document.querySelectorAll(sel).forEach(function(el){el.remove();});});" +
                        "document.querySelectorAll('.nav-item[href*=\"download.php\"]').forEach(function(el){el.remove();});" +
                        "document.querySelectorAll('.side-scroll-hint').forEach(function(el){el.remove();});" +
                        "var b=document.getElementById('qrRefreshBtn'),e=document.getElementById('qrExpired');" +
                        "if(b&&e&&e.firstElementChild!==b){e.insertBefore(b,e.firstElementChild);b.classList.remove('qr-refresh-mini');}" +
                        "var l=document.querySelector('.qr-status-line');" +
                        "if(l){var st=document.getElementById('qrStatus');if(st)l.parentNode.insertBefore(st,l);l.remove();}})()";
                webView.evaluateJavascript(scriptOn, null);
                return;
            }
            String jsqr = readAsset("jsQR.js");
            String app = readAsset("app.js");
            String script = "(function(){if(window.__sgAndroidFix)return;window.__sgAndroidFix=true;" +
                    "try{if(!window.jsQR&&document.getElementById('scanOverlay'))(0,eval)(" + JSONObject.quote(jsqr) + ");}catch(e){}" +
                    "try{(0,eval)(" + JSONObject.quote(app) + ");}catch(e){}" +
                    "['.titlebar','.front-topbar'].forEach(function(sel){document.querySelectorAll(sel).forEach(function(el){el.remove();});});" +
                    "document.querySelectorAll('.nav-item[href*=\"download.php\"]').forEach(function(el){el.remove();});" +
                    "document.querySelectorAll('.side-scroll-hint').forEach(function(el){el.remove();});" +
                    "var qb=document.getElementById('qrRefreshBtn'),qe=document.getElementById('qrExpired');" +
                    "if(qb&&qe&&qb.parentNode!==qe){qe.appendChild(qb);qb.classList.remove('qr-refresh-mini');}" +
                    "var ql=document.querySelector('.qr-status-line');" +
                    "if(ql){var qs=document.getElementById('qrStatus');if(qs)ql.parentNode.insertBefore(qs,ql);ql.remove();}" +
                    "var body=document.querySelector('.shop-body'),copy=document.querySelector('[data-copy]');" +
                    "if(body&&copy&&copy.parentNode===body){body.parentNode.insertBefore(copy,body.nextSibling);copy.style.marginTop='14px';}" +
                    "var side=document.querySelector('.side-user');" +
                    "if(side&&side.tagName==='DIV'){side.style.cursor='pointer';side.addEventListener('click',function(e){if(e.target&&e.target.closest&&e.target.closest('a'))return;location.href=window.SG_USER?'user.php':'login.php';});}" +
                    "})()";
            webView.evaluateJavascript(script, null);
        });
    }


    private void checkForUpdate() {
        if (webView == null) return;
        String script = "(function(){if(!window.__sgIframeFetch)return;" +
                "window.__sgIframeFetch('https://dongshun.gt.tc/version.php?t='+Date.now())" +
                ".then(function(r){return r.json();})" +
                ".then(function(d){if(window.SGUpdate&&d&&d.android)window.SGUpdate.onVersion(JSON.stringify(d.android));})" +
                ".catch(function(){});})()";
        webView.evaluateJavascript(script, null);
    }

    private boolean versionNewer(String latest, String current) {
        String[] a = String.valueOf(latest).replaceAll("^v", "").split("\\.");
        String[] b = String.valueOf(current).replaceAll("^v", "").split("\\.");
        int len = Math.max(a.length, b.length);
        for (int i = 0; i < len; i++) {
            int x = 0, y = 0;
            try { x = i < a.length ? Integer.parseInt(a[i]) : 0; } catch (Exception ignored) { }
            try { y = i < b.length ? Integer.parseInt(b[i]) : 0; } catch (Exception ignored) { }
            if (x > y) return true;
            if (x < y) return false;
        }
        return false;
    }

    public class UpdateBridge {
        @JavascriptInterface
        public void onVersion(String json) {
            try {
                JSONObject data = new JSONObject(json);
                final String latest = data.optString("version", "");
                final String url = data.optString("url", "https://dongshun.gt.tc/download.php");
                final String notes = data.optString("notes", "");
                if (latest.isEmpty() || !versionNewer(latest, BuildConfig.VERSION_NAME)) return;
                runOnUiThread(new Runnable() {
                    @Override
                    public void run() {
                        new AlertDialog.Builder(MainActivity.this)
                                .setTitle("发现新版本")
                                .setMessage("有新版本了，请更新\n\n当前版本 " + BuildConfig.VERSION_NAME +
                                        "，最新版本 " + latest + (notes.isEmpty() ? "" : "\n" + notes))
                                .setPositiveButton("去下载", new DialogInterface.OnClickListener() {
                                    @Override
                                    public void onClick(DialogInterface dialog, int which) {
                                        try {
                                            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                                        } catch (Exception ignored) { }
                                    }
                                })
                                .setNegativeButton("稍后", null)
                                .show();
                    }
                });
            } catch (Exception ignored) { }
        }
    }

    private void showNetworkError() {
        String html = "<!doctype html><html><head><meta name='viewport' content='width=device-width,initial-scale=1'>"
                + "<style>body{margin:0;background:#FFFDF9;color:#3A3029;font-family:system-ui,sans-serif;"
                + "display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:24px;box-sizing:border-box}"
                + ".box{max-width:320px}.logo{width:58px;height:58px;border-radius:15px;background:#C8502B;color:#fff;"
                + "display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:700;margin:0 auto 18px}"
                + "h1{font-size:20px;margin:0 0 8px}p{font-size:13px;line-height:1.8;color:#7C6E60;margin:0 0 20px}"
                + "a{display:inline-block;background:#C8502B;color:#fff;text-decoration:none;padding:12px 28px;border-radius:10px;font-size:14px}"
                + "</style></head><body><div class='box'><div class='logo'>食</div><h1>暂时连不上</h1>"
                + "<p>请检查手机网络后再试。免费主机偶尔会打盹，等一会儿重新加载通常就好。</p>"
                + "<a href='" + SITE_URL + "'>重新加载</a></div></body></html>";
        progressBar.setVisibility(View.GONE);
        webView.loadDataWithBaseURL(SITE_URL, html, "text/html", "UTF-8", null);
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode != REQUEST_CAMERA) return;
        boolean granted = grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED;
        if (pendingCameraRequest != null) {
            try {
                if (granted) {
                    pendingCameraRequest.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
                } else {
                    pendingCameraRequest.deny();
                }
            } catch (Exception ignored) { }
            pendingCameraRequest = null;
        }
        if (!granted) {
            Toast.makeText(this, "扫码需要相机权限，请在系统设置中允许食光菜谱使用相机", Toast.LENGTH_LONG).show();
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == REQUEST_FILE) {
            if (fileCallback == null) return;
            Uri[] results = null;
            if (resultCode == RESULT_OK && data != null) {
                if (data.getClipData() != null) {
                    int count = data.getClipData().getItemCount();
                    results = new Uri[count];
                    for (int i = 0; i < count; i++) {
                        results[i] = data.getClipData().getItemAt(i).getUri();
                    }
                } else if (data.getData() != null) {
                    results = new Uri[]{data.getData()};
                }
            }
            fileCallback.onReceiveValue(results);
            fileCallback = null;
            return;
        }
        super.onActivityResult(requestCode, resultCode, data);
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        if (webView != null) {
            webView.loadUrl(startUrl(intent));
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        if (webView != null) webView.saveState(outState);
    }

    @Override
    protected void onPause() {
        if (webView != null) webView.onPause();
        CookieManager.getInstance().flush();
        super.onPause();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) webView.onResume();
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.loadUrl("about:blank");
            webView.clearHistory();
            webView.removeAllViews();
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}
