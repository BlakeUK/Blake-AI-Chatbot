package ua

import "testing"

func TestParse(t *testing.T) {
	cases := []struct {
		name, ua            string
		device, os, browser string
		bot                 bool
	}{
		{"iphone safari", "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1", "mobile", "iOS", "Safari", false},
		{"iphone chrome", "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/123.0.0.0 Mobile/15E148 Safari/604.1", "mobile", "iOS", "Chrome", false},
		{"android chrome phone", "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36", "mobile", "Android", "Chrome", false},
		{"android tablet", "Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36", "tablet", "Android", "Chrome", false},
		{"ipad", "Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1", "tablet", "iOS", "Safari", false},
		{"windows edge", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0", "desktop", "Windows", "Edge", false},
		{"mac firefox", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:125.0) Gecko/20100101 Firefox/125.0", "desktop", "macOS", "Firefox", false},
		{"linux chrome", "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36", "desktop", "Linux", "Chrome", false},
		{"samsung", "Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36", "mobile", "Android", "Samsung Internet", false},
		{"whatsapp preview", "WhatsApp/2.23.20.0 A", "bot", "Other", "Other", true},
		{"facebook preview", "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)", "bot", "Other", "Other", true},
		{"slackbot", "Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)", "bot", "Other", "Other", true},
		{"googlebot", "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)", "bot", "Other", "Other", true},
		{"cubot phone is not a bot", "Mozilla/5.0 (Linux; Android 12; CUBOT_X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36", "mobile", "Android", "Chrome", false},
		{"curl", "curl/8.4.0", "bot", "Other", "Other", true},
		{"empty", "", "bot", "Other", "Other", true},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := Parse(c.ua)
			if got.DeviceClass != c.device || got.IsBot != c.bot {
				t.Errorf("device/bot: got %q/%v want %q/%v", got.DeviceClass, got.IsBot, c.device, c.bot)
			}
			if !c.bot && (got.OS != c.os || got.Browser != c.browser) {
				t.Errorf("os/browser: got %q/%q want %q/%q", got.OS, got.Browser, c.os, c.browser)
			}
		})
	}
}
