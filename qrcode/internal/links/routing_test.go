package links

import (
	"fmt"
	"testing"
	"time"
)

func uk(t *testing.T, s string) time.Time {
	t.Helper()
	tm, err := time.ParseInLocation("2006-01-02 15:04", s, london)
	if err != nil {
		t.Fatal(err)
	}
	return tm
}

func TestTimeRuleParsingAndNormalising(t *testing.T) {
	good := map[string]string{
		"Mon-Fri 08:00-16:30":                      "Mon-Fri 08:00-16:30",
		"mon-fri 8:00 - 16:30":                     "Mon-Fri 08:00-16:30",
		"MONDAY-FRIDAY 09:00-17:00":                "Mon-Fri 09:00-17:00",
		"Sat,Sun":                                  "Sat,Sun",
		"Mon-Thu 08:00-16:30; Fri 08:00-16:00":     "Mon-Thu 08:00-16:30; Fri 08:00-16:00",
		"Fri-Mon":                                  "Fri-Mon",
		"Tues, Thurs 10:00-12:00":                  "Tue,Thu 10:00-12:00",
		"Mon-Fri 18:00-24:00; Tue-Sat 00:00-08:00": "Mon-Fri 18:00-24:00; Tue-Sat 00:00-08:00",
	}
	for in, want := range good {
		if _, got, err := ParseTimeRule(in); err != nil || got != want {
			t.Errorf("%q -> %q, %v (want %q)", in, got, err, want)
		}
	}
	for _, bad := range []string{"", "  ;  ", "Funday", "Mon-Fun", "Mon 25:00-26:00", "Mon 16:00-08:00", "Mon 08:00-08:00", "Mon 08:00-24:30", "Mo", "8:00-16:00",
		"Mon,,Tue", "Mon-Fri 08:00", "Mon;Tue;Wed;Thu;Fri", "Mon-Fri 08:00-16:30 extra", "Mon 08:60-09:00"} {
		if _, _, err := ParseTimeRule(bad); err == nil {
			t.Errorf("%q should be refused", bad)
		}
	}
}

func TestTimeRuleMatchesInUKTime(t *testing.T) {
	office, _, _ := ParseTimeRule("Mon-Thu 08:00-16:30; Fri 08:00-16:00")
	cases := []struct {
		at   string
		want bool
	}{
		{"2026-10-05 07:59", false}, {"2026-10-05 08:00", true}, {"2026-10-05 16:29", true}, {"2026-10-05 16:30", false}, // a Monday
		{"2026-10-09 15:59", true}, {"2026-10-09 16:00", false}, // a Friday closes earlier
		{"2026-10-10 12:00", false}, {"2026-10-11 12:00", false}, // the weekend
	}
	for _, c := range cases {
		if got := office.Match(uk(t, c.at)); got != c.want {
			t.Errorf("%s: %v, want %v", c.at, got, c.want)
		}
	}
	// the rule is judged in UK time whatever zone the clock is in, across both clock changes
	summer := time.Date(2026, 7, 6, 7, 30, 0, 0, time.UTC)  // 08:30 BST on a Monday
	winter := time.Date(2026, 12, 7, 7, 30, 0, 0, time.UTC) // 07:30 GMT on a Monday
	if !office.Match(summer) || office.Match(winter) {
		t.Errorf("summer %v winter %v: want true, false", office.Match(summer), office.Match(winter))
	}
	if !office.Match(summer.In(time.FixedZone("x", -8*3600))) {
		t.Error("the zone of the clock must not matter")
	}
	wrap, _, _ := ParseTimeRule("Fri-Mon")
	for _, d := range []string{"2026-10-09 12:00", "2026-10-10 12:00", "2026-10-11 12:00", "2026-10-12 12:00"} {
		if !wrap.Match(uk(t, d)) {
			t.Errorf("Fri-Mon should include %s", d)
		}
	}
	if wrap.Match(uk(t, "2026-10-13 12:00")) {
		t.Error("Fri-Mon should not include Tuesday")
	}
}

func TestSplitParsing(t *testing.T) {
	for in, want := range map[string]string{"30": "30%", "30%": "30%", " 5 % ": "5%", "99": "99%", "01": "1%"} {
		if _, got, err := ParseSplit(in); err != nil || got != want {
			t.Errorf("%q -> %q, %v", in, got, err)
		}
	}
	for _, bad := range []string{"", "0", "100", "150", "-5", "3.5", "abc", "30 %%", "5 0"} {
		if _, _, err := ParseSplit(bad); err == nil {
			t.Errorf("%q should be refused", bad)
		}
	}
}

func TestSplitIsSteadyFairAndIndependent(t *testing.T) {
	const n = 20000
	for _, pct := range []int{1, 10, 30, 50, 90} {
		hits := 0
		for i := 0; i < n; i++ {
			if SplitHit(pct, fmt.Sprintf("visitor-%d", i), 7, 0) {
				hits++
			}
		}
		if got := float64(hits) * 100 / n; got < float64(pct)-2 || got > float64(pct)+2 {
			t.Errorf("%d%% split put %.1f%% on the first side", pct, got)
		}
	}
	for i := 0; i < 200; i++ { // the same person always lands on the same side
		tok := fmt.Sprintf("v%d", i)
		if SplitHit(40, tok, 3, 1) != SplitHit(40, tok, 3, 1) {
			t.Fatal("not steady")
		}
	}
	if SplitHit(99, "", 1, 0) {
		t.Error("with no visitor token nobody is placed")
	}
	// two rules on one code place people independently, so a 50% then 50% rotation really gives about 50/25/25
	first, second := 0, 0
	for i := 0; i < n; i++ {
		tok := fmt.Sprintf("p%d", i)
		if SplitHit(50, tok, 9, 0) {
			first++
		} else if SplitHit(50, tok, 9, 1) {
			second++
		}
	}
	if f, s := float64(first)*100/n, float64(second)*100/n; f < 48 || f > 52 || s < 23 || s > 27 {
		t.Errorf("a 50%% then 50%% rotation gave %.1f%% / %.1f%% / the rest (want 50 / 25 / 25)", f, s)
	}
}

func FuzzParseTimeRule(f *testing.F) {
	for _, s := range []string{"Mon-Fri 08:00-16:30", "Sat,Sun", "", ";;;", "Fri-Mon 00:00-24:00", "\x00", "Mon 99999999999:00-1:00"} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, s string) {
		r, norm, err := ParseTimeRule(s)
		if err != nil {
			return
		}
		again, norm2, err := ParseTimeRule(norm) // what we hand back must parse to the same thing
		if err != nil || norm2 != norm {
			t.Fatalf("%q -> %q does not round-trip: %q, %v", s, norm, norm2, err)
		}
		now := time.Now()
		if r.Match(now) != again.Match(now) {
			t.Fatalf("%q and %q disagree", s, norm)
		}
	})
}
