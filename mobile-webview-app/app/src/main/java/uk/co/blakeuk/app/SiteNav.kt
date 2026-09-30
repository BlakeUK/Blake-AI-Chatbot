package uk.co.blakeuk.app

/**
 * Static site navigation, sourced from the Blake UK category structure
 * (project spec section 3.2/3.3) and confirmed against the live header/footer
 * of https://www.blake-uk.com/ at build time. Top-level categories only are
 * shown in the drawer — full subcategory trees stay on the website itself,
 * which the WebView renders natively when a top-level link is opened.
 */
object SiteNav {

    const val BASE = "https://www.blake-uk.com"
    const val HOME = "$BASE/"
    const val CART = "$BASE/cart/"
    const val REGISTER = "$BASE/account/register/"
    const val LOGIN = HOME // login form is on the homepage overlay
    const val SITEMAP = "$BASE/sitemap.html"

    data class Link(val title: String, val url: String)

    val categories = listOf(
        Link("Aerials", "$BASE/category/aerials.html"),
        Link("IRS", "$BASE/category/irs.html"),
        Link("CCTV", "$BASE/category/cctv.html"),
        Link("Leisure", "$BASE/category/aerials-leisure-products.html"),
        Link("Distribution", "$BASE/category/distribution.html"),
        Link("Installation", "$BASE/category/installation.html"),
        Link("Networking", "$BASE/category/networking.html"),
        Link("Fibre", "$BASE/category/fibre.html"),
        Link("Kits", "$BASE/category/kits.html"),
        Link("New", "$BASE/category/new-products.html"),
        Link("Hot", "$BASE/category/hot-products.html"),
        Link("Sale", "$BASE/category/sale.html")
    )

    val helpAndInfo = listOf(
        Link("Information Centre", "$BASE/info.html"),
        Link("Delivery Information", "$BASE/delivery.html"),
        Link("Free Technical Support", "$BASE/support.html"),
        Link("Track my order", "$BASE/delivery.html"),
        Link("Instruction Manuals", "$BASE/instruction-manuals.html"),
        Link("Guides", "$BASE/guides.html"),
        Link("FAQs", "$BASE/faq.html"),
        Link("Aerial Buying Wizard", "$BASE/aerial-buy-assistant-aerial.html"),
        Link("CCTV Wizard", "$BASE/cctv-wizard.html"),
        Link("Masting Wizard", "$BASE/masting-wizard-p1.html"),
        Link("Bracket Selection Guide", "$BASE/bracket-selection-guide.html"),
        Link("Warranty and Guarantee", "$BASE/warranty.html"),
        Link("Returns Policy", "$BASE/returns-policy.html"),
        Link("Contact Us", "$BASE/contact-us.html"),
        Link("About Us", "$BASE/about-us.html")
    )

    val account = listOf(
        Link("Your Basket", CART),
        Link("Sign In / Register", REGISTER)
    )
}
