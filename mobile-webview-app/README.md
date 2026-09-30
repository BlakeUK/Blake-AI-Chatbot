# Blake UK — Android shopping app

Native Android wrapper for www.blake-uk.com: full category navigation drawer,
favourites, recently viewed, in-app search (drives the site's own search
overlay, falls back to a site-restricted web search if that overlay isn't
found), basket/checkout/login run on the real website so payment stays
Blake UK's existing, PCI-compliant checkout rather than a reimplementation.

Build: `gradle assembleDebug` (needs Android SDK platform 34 + build-tools
34.0.0, and a JDK with `jlink`, e.g. openjdk-17-jdk, not the headless-only
JRE image). Output: `app/build/outputs/apk/debug/app-debug.apk`.

Not yet done: release signing (currently debug-signed only — fine for
sideloading, not for Play Store), a confirmed exact selector for the site
search overlay (JS heuristic, with a safety-net fallback), and a real device
test pass — this has only been verified as "builds clean, valid manifest",
not run on hardware.
