// Package ua classifies a User-Agent string into coarse, privacy-friendly
// families (device class, OS family, browser family) and flags bots and
// link-preview fetchers. It deliberately records nothing finer than a
// family name: no versions, no device models.
package ua

import (
	"regexp"
	"strings"
)

// botWord matches "bot" only at the end of a word (Googlebot/2.1, TelegramBot,
// Slackbot-LinkExpanding) so real device names such as "CUBOT_X30" are not
// mistaken for crawlers.
var botWord = regexp.MustCompile(`bot\b`)

type Info struct {
	DeviceClass string // mobile | tablet | desktop | bot
	OS          string
	Browser     string
	IsBot       bool
}

// botMarkers are lowercase substrings that identify crawlers, uptime
// monitors, scripted clients and the link-preview fetchers chat apps and
// social networks use when a link is pasted (these would otherwise inflate
// scan counts with no human behind them).
var botMarkers = []string{
	"crawl", "spider", "slurp", "facebookexternalhit", "facebot", "whatsapp",
	"slack", "twitterbot", "linkedinbot", "telegram", "discord", "skypeuripreview",
	"pinterest", "bingpreview", "yandex", "baidu", "duckduck", "embedly", "iframely",
	"vkshare", "w3c_validator", "mediapartners", "adsbot", "lighthouse", "pingdom",
	"uptime", "monitor", "headlesschrome", "phantomjs", "curl/", "wget/", "python-requests",
	"python-urllib", "go-http-client", "java/", "libwww", "httpclient", "axios/", "node-fetch",
	"preview", "scanner", "ahrefs", "semrush", "mj12", "applebot", "google-read-aloud",
	"feedfetcher", "outbrain", "flipboard", "mastodon", "bitlybot", "quora link preview",
}

// Parse classifies ua. An empty User-Agent is treated as a bot: real
// browsers always send one.
func Parse(ua string) Info {
	l := strings.ToLower(strings.TrimSpace(ua))
	if l == "" {
		return Info{DeviceClass: "bot", OS: "Other", Browser: "Other", IsBot: true}
	}
	info := Info{OS: osFamily(l), Browser: browserFamily(l)}
	if botWord.MatchString(l) {
		info.IsBot = true
		info.DeviceClass = "bot"
		return info
	}
	for _, m := range botMarkers {
		if strings.Contains(l, m) {
			info.IsBot = true
			info.DeviceClass = "bot"
			return info
		}
	}
	info.DeviceClass = deviceClass(l)
	return info
}

func deviceClass(l string) string {
	switch {
	case strings.Contains(l, "ipad"), strings.Contains(l, "tablet"), strings.Contains(l, "kindle"),
		strings.Contains(l, "silk/"), strings.Contains(l, "playbook"):
		return "tablet"
	case strings.Contains(l, "android") && !strings.Contains(l, "mobile"):
		// Android tablets omit the "Mobile" token that Android phones carry.
		return "tablet"
	case strings.Contains(l, "mobi"), strings.Contains(l, "iphone"), strings.Contains(l, "ipod"),
		strings.Contains(l, "android"), strings.Contains(l, "windows phone"):
		return "mobile"
	}
	return "desktop"
}

func osFamily(l string) string {
	switch {
	case strings.Contains(l, "windows phone"):
		return "Windows Phone"
	case strings.Contains(l, "iphone"), strings.Contains(l, "ipad"), strings.Contains(l, "ipod"):
		return "iOS"
	case strings.Contains(l, "android"):
		return "Android"
	case strings.Contains(l, "cros"):
		return "ChromeOS"
	case strings.Contains(l, "mac os x"), strings.Contains(l, "macintosh"):
		return "macOS"
	case strings.Contains(l, "windows"):
		return "Windows"
	case strings.Contains(l, "linux"), strings.Contains(l, "x11"):
		return "Linux"
	}
	return "Other"
}

func browserFamily(l string) string {
	switch {
	case strings.Contains(l, "fban"), strings.Contains(l, "fbav"):
		return "Facebook app"
	case strings.Contains(l, "instagram"):
		return "Instagram app"
	case strings.Contains(l, "edg/"), strings.Contains(l, "edga/"), strings.Contains(l, "edgios/"), strings.Contains(l, "edge/"):
		return "Edge"
	case strings.Contains(l, "opr/"), strings.Contains(l, "opera"), strings.Contains(l, "opt/"):
		return "Opera"
	case strings.Contains(l, "samsungbrowser"):
		return "Samsung Internet"
	case strings.Contains(l, "firefox"), strings.Contains(l, "fxios"):
		return "Firefox"
	case strings.Contains(l, "crios"), strings.Contains(l, "chrome"), strings.Contains(l, "chromium"):
		return "Chrome"
	case strings.Contains(l, "msie"), strings.Contains(l, "trident"):
		return "Internet Explorer"
	case strings.Contains(l, "safari"):
		return "Safari"
	}
	return "Other"
}
