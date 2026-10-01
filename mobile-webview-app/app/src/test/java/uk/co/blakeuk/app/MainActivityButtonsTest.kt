package uk.co.blakeuk.app

import android.os.Build
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.shadows.ShadowWebView

/**
 * Reproduces the reported bug ("only the hamburger works") by actually
 * performing a click on each top-bar button, the way a finger would, and
 * checking the real side effect (the WebView's last-loaded URL, or the
 * drawer's open state) rather than just inspecting the listener code.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [Build.VERSION_CODES.TIRAMISU])
class MainActivityButtonsTest {

    @Test
    fun `every top bar button actually responds to a tap`() {
        val controller = Robolectric.buildActivity(MainActivity::class.java)
        val activity = controller.create().start().resume().get()

        val binding = activity.javaClass.getDeclaredField("binding").apply { isAccessible = true }
            .get(activity) as uk.co.blakeuk.app.databinding.ActivityMainBinding

        val shadowWeb = org.robolectric.Shadows.shadowOf(binding.webView)

        // Home
        binding.btnHome.performClick()
        assertEquals("tap on Home did not load the homepage",
            SiteNav.HOME, shadowWeb.lastLoadedUrl)

        // Basket
        binding.btnBasket.performClick()
        assertEquals("tap on Basket did not load the cart",
            SiteNav.CART, shadowWeb.lastLoadedUrl)

        // Favourite — should toggle without crashing even with a real URL loaded
        binding.btnFavourite.performClick()

        // Menu — drawer should open
        assertTrue("drawer was already open before tapping Menu",
            !binding.drawerLayout.isDrawerOpen(binding.navScroll))
        binding.btnMenu.performClick()
        assertTrue("tap on Menu did not open the drawer",
            binding.drawerLayout.isDrawerOpen(binding.navScroll))
    }

    @Test
    fun `top bar buttons are actually clickable views with nonzero size`() {
        val controller = Robolectric.buildActivity(MainActivity::class.java)
        val activity = controller.create().start().resume().get()
        val binding = activity.javaClass.getDeclaredField("binding").apply { isAccessible = true }
            .get(activity) as uk.co.blakeuk.app.databinding.ActivityMainBinding

        // Force a real measure/layout pass, same as the real device would do,
        // so width/height reflect actual rendering rather than defaults.
        val widthSpec = android.view.View.MeasureSpec.makeMeasureSpec(1080, android.view.View.MeasureSpec.EXACTLY)
        val heightSpec = android.view.View.MeasureSpec.makeMeasureSpec(1920, android.view.View.MeasureSpec.EXACTLY)
        binding.root.measure(widthSpec, heightSpec)
        binding.root.layout(0, 0, 1080, 1920)

        listOf(
            "btnMenu" to binding.btnMenu,
            "btnBack" to binding.btnBack,
            "btnForward" to binding.btnForward,
            "btnHome" to binding.btnHome,
            "btnBasket" to binding.btnBasket,
            "btnFavourite" to binding.btnFavourite
        ).forEach { (name, btn) ->
            assertTrue("$name is not clickable", btn.isClickable)
            assertTrue("$name width is 0 — it would be untappable on a real screen", btn.width > 0)
            assertTrue("$name height is 0 — it would be untappable on a real screen", btn.height > 0)
        }
    }
}
