package web

import (
	"fmt"
	"net/url"
	"strings"
	"testing"
	"time"
)

func (c *clock) Set(t time.Time) { c.mu.Lock(); c.t = t; c.mu.Unlock() }

// nextUK returns the next given weekday at hh:mm UK time, comfortably inside a code's default window.
func nextUK(from time.Time, wd time.Weekday, hh, mm int) time.Time {
	uk, _ := time.LoadLocation("Europe/London")
	d := from.In(uk).AddDate(0, 0, 1)
	for d.Weekday() != wd {
		d = d.AddDate(0, 0, 1)
	}
	return time.Date(d.Year(), d.Month(), d.Day(), hh, mm, 0, 0, uk)
}

func TestRoutingByTimeOfDayAndShareOfVisitors(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id, code := h.create(c, "dynamic", "smart_url", url.Values{
		"f_url": {"https://www.blake-uk.com/closed"}, "window": {"90d"},
		"f_r1_match": {"time"}, "f_r1_value": {"mon-fri 8:00 - 16:30"}, "f_r1_url": {"https://www.blake-uk.com/phone"},
		"f_r2_match": {"split"}, "f_r2_value": {"30"}, "f_r2_url": {"https://www.blake-uk.com/b"},
	})
	where := func(ip string) string {
		return h.scanHdr(code, map[string]string{"User-Agent": iphoneUA, "X-Forwarded-For": ip}).Header.Get("Location")
	}

	// in office hours the time rule comes first, so everybody goes to the phone page whatever the split would say
	h.clk.Set(nextUK(h.clk.Now(), time.Tuesday, 10, 0))
	for i := 1; i <= 40; i++ {
		if got := where(fmt.Sprintf("198.51.100.%d", i)); got != "https://www.blake-uk.com/phone" {
			t.Fatalf("Tuesday 10:00: visitor %d sent to %q, want the phone page", i, got)
		}
	}
	// just after closing the time rule no longer matches: the split decides
	h.clk.Set(nextUK(h.clk.Now(), time.Tuesday, 16, 30))
	if got := where("198.51.100.200"); got == "https://www.blake-uk.com/phone" {
		t.Errorf("16:30 is closing time, but a visitor was still sent to the phone page")
	}

	// a Saturday: only the split applies. About 30% should go to B, and each person must stay on their side.
	h.clk.Set(nextUK(h.clk.Now(), time.Saturday, 11, 0))
	toB, total := 0, 0
	side := map[string]string{}
	for a := 1; a <= 2; a++ {
		for b := 1; b <= 150; b++ {
			ip := fmt.Sprintf("203.0.%d.%d", 100+a, b)
			got := where(ip)
			if got != "https://www.blake-uk.com/b" && got != "https://www.blake-uk.com/closed" {
				t.Fatalf("unexpected destination %q", got)
			}
			side[ip] = got
			total++
			if got == "https://www.blake-uk.com/b" {
				toB++
			}
		}
	}
	if pct := float64(toB) * 100 / float64(total); pct < 20 || pct > 40 {
		t.Errorf("a 30%% split sent %.0f%% of %d visitors to B", pct, total)
	}
	for _, i := range []int{1, 7, 33, 80, 149} {
		ip := fmt.Sprintf("203.0.101.%d", i)
		if again := where(ip); again != side[ip] {
			t.Errorf("%s was sent to %q and then %q: a visitor must stay on one side", ip, side[ip], again)
		}
	}

	// every scan records where that person was sent, and the code's page shows the totals so a test can be read
	h.scanCount(id)
	var nB int
	h.db.QueryRow(`SELECT COUNT(*) FROM scans WHERE link_id = ? AND destination_url = 'https://www.blake-uk.com/b'`, id).Scan(&nB)
	if nB < toB {
		t.Errorf("scans recorded %d visits to B, but %d visitors were sent there", nB, toB)
	}
	c = h.client() // the clock has moved on by days, so staff sign in again
	if resp, _ := h.rawLogin(c, newPW, "198.51.100.201"); resp.StatusCode != 303 {
		t.Fatalf("sign-in status %d", resp.StatusCode)
	}
	_, page := h.do(c, "GET", fmt.Sprintf("/admin/links/%d", id), nil, nil)
	for _, want := range []string{"Where visitors were sent", "https://www.blake-uk.com/b", "https://www.blake-uk.com/phone", "for <strong>30%</strong> of visitors", "if the time (UK) is <strong>Mon-Fri 08:00-16:30</strong>"} {
		if !strings.Contains(page, want) {
			t.Errorf("the code's page should show %q", want)
		}
	}
}

// the rule list in the database was widened by migration 0005: old rules survive, the new kinds are accepted
func TestRoutingRuleKindsAreAcceptedByTheDatabase(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	id, _ := h.create(c, "dynamic", "smart_url", url.Values{"f_url": {"https://www.blake-uk.com/"},
		"f_r1_match": {"country"}, "f_r1_value": {"fr"}, "f_r1_url": {"https://www.blake-uk.com/fr"},
		"f_r2_match": {"time"}, "f_r2_value": {"sat,sun"}, "f_r2_url": {"https://www.blake-uk.com/weekend"},
		"f_r3_match": {"split"}, "f_r3_value": {"5%"}, "f_r3_url": {"https://www.blake-uk.com/trial"}})
	rows, _ := h.db.Query(`SELECT match, value FROM link_rules WHERE link_id = ? ORDER BY position`, id)
	var got []string
	for rows.Next() {
		var m, v string
		rows.Scan(&m, &v)
		got = append(got, m+"="+v)
	}
	if strings.Join(got, ",") != "country=FR,time=Sat,Sun,split=5%" {
		t.Errorf("rules stored: %v", got)
	}
	if _, err := h.db.Exec(`INSERT INTO link_rules(link_id, position, match, value, url) VALUES (?, 9, 'nonsense', 'x', 'https://a.example')`, id); err == nil {
		t.Error("the database should still refuse a rule kind it does not know")
	}
}

// Once its window or scan limit is over, a code in "keep redirecting, no longer counted" mode must still ask for
// its password and still route people by their rules. It simply stops counting.
func TestAnEndedCodeStillKeepsItsPasswordAndItsRules(t *testing.T) {
	h := newHarness(t)
	c := h.adminClient()
	_, locked := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/secret"}, "link_password": {"trade-only"}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}})
	id, apps := h.create(c, "dynamic", "app_stores", url.Values{"f_ios_url": {"https://apps.apple.com/app/id1"}, "f_android_url": {"https://play.google.com/store/apps/details?id=x"},
		"f_url": {"https://www.example.com/app"}, "window": {"7d"}, "expiry_mode": {"redirect_untracked"}})
	_, expiring := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/a"}, "window": {"7d"}, "expiry_mode": {"show_expired_page"}})
	_, fallback := h.create(c, "dynamic", "url", url.Values{"f_url": {"https://www.blake-uk.com/a"}, "window": {"7d"}, "expiry_mode": {"redirect_fallback_url"}, "fallback_url": {"https://www.blake-uk.com/sorry"}})
	hdr := func(ua string) map[string]string {
		return map[string]string{"User-Agent": ua, "X-Forwarded-For": "203.0.113.9"}
	}
	h.scanHdr(apps, hdr(iphoneUA))
	before, _ := h.scanCount(id)

	h.clk.Advance(30 * 24 * time.Hour)

	if r := h.scanHdr(locked, hdr(iphoneUA)); r.StatusCode != 200 || r.Header.Get("Location") != "" {
		t.Errorf("an ended password code must still ask for the password: status %d, location %q", r.StatusCode, r.Header.Get("Location"))
	}
	if got := h.scanHdr(apps, hdr(iphoneUA)).Header.Get("Location"); got != "https://apps.apple.com/app/id1" {
		t.Errorf("an ended App Stores code sent an iPhone to %q", got)
	}
	if got := h.scanHdr(apps, hdr(androidUA)).Header.Get("Location"); got != "https://play.google.com/store/apps/details?id=x" {
		t.Errorf("an ended App Stores code sent an Android phone to %q", got)
	}
	if got := h.scanHdr(apps, hdr(windowsUA)).Header.Get("Location"); got != "https://www.example.com/app" {
		t.Errorf("an ended App Stores code sent a PC to %q", got)
	}
	if after, _ := h.scanCount(id); after != before {
		t.Errorf("an ended code must not count: %d scans before, %d after", before, after)
	}
	if r := h.scanHdr(expiring, hdr(iphoneUA)); r.StatusCode != 410 {
		t.Errorf("show-expired-page mode: status %d, want 410", r.StatusCode)
	}
	if r := h.scanHdr(fallback, hdr(iphoneUA)); r.Header.Get("Location") != "https://www.blake-uk.com/sorry" {
		t.Errorf("fallback mode sent a visitor to %q", r.Header.Get("Location"))
	}
}
