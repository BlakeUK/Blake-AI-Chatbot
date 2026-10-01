package uk.co.blakeuk.app

import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.net.Uri
import android.os.Bundle
import android.util.Log
import android.view.View
import android.webkit.CookieManager
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebChromeClient.FileChooserParams
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.net.toUri
import androidx.drawerlayout.widget.DrawerLayout
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import androidx.webkit.WebSettingsCompat
import androidx.webkit.WebViewFeature
import uk.co.blakeuk.app.databinding.ActivityMainBinding

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var store: PageStore
    private lateinit var prefs: SharedPreferences
    private var fileCallback: ValueCallback<Array<Uri>>? = null

    private val fileChooserLauncher =
        registerForActivityResult(androidx.activity.result.contract.ActivityResultContracts.StartActivityForResult()) { result ->
            val data = result.data
            val uris: Array<Uri>? = if (result.resultCode == RESULT_OK && data != null) {
                if (data.clipData != null) {
                    (0 until data.clipData!!.itemCount).map { data.clipData!!.getItemAt(it).uri }.toTypedArray()
                } else {
                    data.data?.let { arrayOf(it) }
                }
            } else null
            fileCallback?.onReceiveValue(uris)
            fileCallback = null
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        store = PageStore(this)
        prefs = getSharedPreferences("blake_uk_app", Context.MODE_PRIVATE)

        setupWebView()
        buildDrawer()
        wireControls()

        val startUrl = if (savedInstanceState == null) {
            prefs.getString(KEY_LAST_URL, SiteNav.HOME) ?: SiteNav.HOME
        } else null

        if (savedInstanceState != null) {
            binding.webView.restoreState(savedInstanceState)
        } else {
            binding.webView.loadUrl(startUrl!!)
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        binding.webView.saveState(outState)
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val web = binding.webView
        val s: WebSettings = web.settings
        s.javaScriptEnabled = true
        s.domStorageEnabled = true
        s.databaseEnabled = true
        s.loadWithOverviewMode = true
        s.useWideViewPort = true
        s.setSupportMultipleWindows(false)
        s.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
        s.cacheMode = WebSettings.LOAD_DEFAULT
        s.userAgentString = s.userAgentString + " BlakeUKApp/1.0"

        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(web, true)

        if (WebViewFeature.isFeatureSupported(WebViewFeature.FORCE_DARK)) {
            val night = resources.configuration.uiMode and
                android.content.res.Configuration.UI_MODE_NIGHT_MASK
            @Suppress("DEPRECATION")
            WebSettingsCompat.setForceDark(
                s,
                if (night == android.content.res.Configuration.UI_MODE_NIGHT_YES)
                    WebSettingsCompat.FORCE_DARK_ON else WebSettingsCompat.FORCE_DARK_OFF
            )
        }

        web.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val uri = request.url
                val scheme = uri.scheme ?: ""
                if (scheme == "tel" || scheme == "mailto") {
                    startActivity(Intent(Intent.ACTION_VIEW, uri))
                    return true
                }
                val host = uri.host ?: ""
                val onSite = host == "www.blake-uk.com" || host == "blake-uk.com" ||
                    host.endsWith(".blake-uk.com")
                if (!onSite && (scheme == "http" || scheme == "https")) {
                    // Off-site links (payment providers, socials, courier tracking) open
                    // in the device browser rather than inside the app's WebView.
                    startActivity(Intent(Intent.ACTION_VIEW, uri))
                    return true
                }
                return false
            }

            override fun onPageFinished(view: WebView, url: String) {
                super.onPageFinished(view, url)
                binding.progressBar.visibility = View.GONE
                binding.swipeRefresh.isRefreshing = false
                prefs.edit().putString(KEY_LAST_URL, url).apply()
                updateFavouriteIcon(url)

                val title = view.title ?: url
                if (url != SiteNav.HOME && url != SiteNav.CART && !url.startsWith(SiteNav.REGISTER)) {
                    store.recordVisit(title, url)
                }
            }

            override fun onReceivedError(
                view: WebView, request: WebResourceRequest, error: android.webkit.WebResourceError
            ) {
                super.onReceivedError(view, request, error)
                if (request.isForMainFrame) {
                    Toast.makeText(this@MainActivity, R.string.saved_offline_notice, Toast.LENGTH_SHORT).show()
                }
            }
        }

        web.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView, newProgress: Int) {
                binding.progressBar.progress = newProgress
                binding.progressBar.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
            }

            override fun onShowFileChooser(
                webView: WebView,
                filePathCallback: ValueCallback<Array<Uri>>,
                fileChooserParams: FileChooserParams
            ): Boolean {
                fileCallback = filePathCallback
                val intent = fileChooserParams.createIntent()
                return try {
                    fileChooserLauncher.launch(intent)
                    true
                } catch (e: Exception) {
                    fileCallback = null
                    false
                }
            }
        }

        web.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            try {
                val request = DownloadManager.Request(url.toUri())
                request.setMimeType(mimeType)
                request.addRequestHeader("cookie", cookieManager.getCookie(url))
                request.addRequestHeader("User-Agent", userAgent)
                val fileName = URLUtil.guessFileName(url, contentDisposition, mimeType)
                request.setDestinationInExternalPublicDir(android.os.Environment.DIRECTORY_DOWNLOADS, fileName)
                request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                (getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
                Toast.makeText(this, "Downloading $fileName", Toast.LENGTH_SHORT).show()
            } catch (e: Exception) {
                Log.e("BlakeUKApp", "Download failed", e)
                startActivity(Intent(Intent.ACTION_VIEW, url.toUri()))
            }
        }
    }

    private fun wireControls() {
        binding.btnMenu.setOnClickListener {
            redrawDrawer() // pick up any new favourites/recently-viewed since last open
            binding.drawerLayout.openDrawer(binding.navScroll)
        }

        binding.swipeRefresh.setOnRefreshListener { binding.webView.reload() }

        binding.btnBack.setOnClickListener {
            if (binding.webView.canGoBack()) binding.webView.goBack()
        }
        binding.btnForward.setOnClickListener {
            if (binding.webView.canGoForward()) binding.webView.goForward()
        }
        binding.btnHome.setOnClickListener { binding.webView.loadUrl(SiteNav.HOME) }
        binding.btnBasket.setOnClickListener { binding.webView.loadUrl(SiteNav.CART) }

        binding.btnFavourite.setOnClickListener {
            val url = binding.webView.url ?: return@setOnClickListener
            val title = binding.webView.title ?: url
            val nowFav = store.toggleFavourite(title, url)
            binding.btnFavourite.setImageResource(
                if (nowFav) R.drawable.ic_star_filled else R.drawable.ic_star_outline
            )
            rebuildFavouritesSection()
        }
    }

    private fun updateFavouriteIcon(url: String) {
        binding.btnFavourite.setImageResource(
            if (store.isFavourite(url)) R.drawable.ic_star_filled else R.drawable.ic_star_outline
        )
    }

    // ---- Drawer ----

    private fun addHeader(container: android.widget.LinearLayout, text: String) {
        val v = layoutInflater.inflate(R.layout.drawer_header, container, false) as TextView
        v.text = text
        container.addView(v)
    }

    private fun addItem(container: android.widget.LinearLayout, title: String, onClick: () -> Unit) {
        val v = layoutInflater.inflate(R.layout.drawer_item, container, false) as TextView
        v.text = title
        v.setOnClickListener {
            onClick()
            binding.drawerLayout.closeDrawers()
        }
        container.addView(v)
    }

    private fun buildDrawer() = redrawDrawer()

    private fun rebuildFavouritesSection() = redrawDrawer()

    /**
     * Favourites and Recently Viewed change as the customer browses, so the
     * whole drawer body is simply regenerated — it's a couple of dozen
     * lightweight TextViews, not worth tracking incremental diffs for.
     */
    private fun redrawDrawer() {
        val c = binding.navContainer
        c.removeAllViews()

        addItem(c, "🏠  " + getString(R.string.nav_home)) { binding.webView.loadUrl(SiteNav.HOME) }
        addItem(c, "🧺  Basket") { binding.webView.loadUrl(SiteNav.CART) }
        addItem(c, "👤  Sign In / Register") { binding.webView.loadUrl(SiteNav.REGISTER) }

        addHeader(c, getString(R.string.nav_categories))
        SiteNav.categories.forEach { link -> addItem(c, link.title) { binding.webView.loadUrl(link.url) } }

        addHeader(c, getString(R.string.nav_help))
        SiteNav.helpAndInfo.forEach { link -> addItem(c, link.title) { binding.webView.loadUrl(link.url) } }

        addHeader(c, getString(R.string.nav_favourites))
        val favs = store.favourites()
        if (favs.isEmpty()) {
            addItem(c, getString(R.string.favourites_empty)) {}
        } else {
            favs.forEach { e -> addItem(c, "★ " + e.title) { binding.webView.loadUrl(e.url) } }
        }

        addHeader(c, getString(R.string.nav_recent))
        val recent = store.recent()
        if (recent.isEmpty()) {
            addItem(c, getString(R.string.recent_empty)) {}
        } else {
            recent.forEach { e -> addItem(c, e.title) { binding.webView.loadUrl(e.url) } }
        }
    }

    override fun onBackPressed() {
        when {
            binding.drawerLayout.isDrawerOpen(binding.navScroll) -> binding.drawerLayout.closeDrawers()
            binding.webView.canGoBack() -> binding.webView.goBack()
            else -> super.onBackPressed()
        }
    }

    companion object {
        private const val KEY_LAST_URL = "last_url"
    }
}
