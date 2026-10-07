package links

import (
	"net/url"
	"strings"
)

// knownSites maps a registrable host to the name people recognise. A host
// matches if it equals the entry or is a sub-domain of it (m.facebook.com).
var knownSites = []struct{ host, name string }{
	{"facebook.com", "Facebook"}, {"fb.com", "Facebook"}, {"fb.me", "Facebook"}, {"fb.watch", "Facebook"},
	{"instagram.com", "Instagram"}, {"youtube.com", "YouTube"}, {"youtu.be", "YouTube"},
	{"tiktok.com", "TikTok"}, {"linkedin.com", "LinkedIn"}, {"lnkd.in", "LinkedIn"},
	{"x.com", "X (Twitter)"}, {"twitter.com", "X (Twitter)"}, {"t.co", "X (Twitter)"},
	{"wa.me", "WhatsApp"}, {"whatsapp.com", "WhatsApp"}, {"t.me", "Telegram"},
	{"pinterest.com", "Pinterest"}, {"reddit.com", "Reddit"},
	{"google.com", "Google"}, {"goo.gl", "Google"}, {"maps.app.goo.gl", "Google Maps"},
	{"blake-uk.com", "Blake UK website"}, {"blakegroup.uk", "Blake Group website"},
}

// SiteName returns a friendly name for the site a URL points at: "Facebook"
// for facebook.com, "Blake UK website" for blake-uk.com, otherwise the bare
// host name (without a leading www.). It returns "" if raw is not a URL.
func SiteName(raw string) string {
	u, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || u.Hostname() == "" {
		return ""
	}
	host := strings.ToLower(u.Hostname())
	for _, k := range knownSites {
		if host == k.host || strings.HasSuffix(host, "."+k.host) {
			return k.name
		}
	}
	return strings.TrimPrefix(host, "www.")
}
