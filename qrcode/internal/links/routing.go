package links

import (
	"crypto/sha256"
	"encoding/binary"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Two kinds of smart-routing rule that do not look at the visitor's device:
//
//	time  : a day and hours in UK time, e.g. "Mon-Fri 08:00-16:30; Sat 09:00-12:00"
//	split : a share of visitors, e.g. "30%", for A/B tests and rotation. A visitor is placed by a
//	        one-way scramble of their (daily) visitor token, so the same person sees the same
//	        side all day instead of flipping between them.

var days = [...]string{"Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"}

var windowRe = regexp.MustCompile(`^([A-Za-z][A-Za-z ,-]*?)(?:\s+(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2}))?$`)

type window struct {
	days     [7]bool
	from, to int // minutes after midnight; to is exclusive
	allDay   bool
}

// TimeRule is a parsed "time" rule.
type TimeRule struct{ windows []window }

var london = func() *time.Location {
	if l, err := time.LoadLocation("Europe/London"); err == nil {
		return l
	}
	return time.UTC
}()

func dayIndex(s string) (int, bool) {
	s = strings.ToLower(strings.TrimSpace(s))
	if len(s) < 3 {
		return 0, false
	}
	for i, d := range days {
		if strings.HasPrefix(strings.ToLower(d), s) {
			return i, true
		}
	}
	return 0, false
}

// ParseTimeRule reads a time rule and returns it with a tidy, normalised spelling of what was typed.
func ParseTimeRule(spec string) (*TimeRule, string, error) {
	var r TimeRule
	var norm []string
	parts := strings.Split(spec, ";")
	if len(parts) > 4 {
		return nil, "", fmt.Errorf("use no more than four parts, separated by semicolons")
	}
	for _, part := range parts {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		m := windowRe.FindStringSubmatch(part)
		if m == nil {
			return nil, "", fmt.Errorf("%q is not understood: write days, then hours, such as Mon-Fri 08:00-16:30", part)
		}
		var w window
		var dayText []string
		for _, tok := range strings.Split(m[1], ",") {
			tok = strings.TrimSpace(tok)
			if tok == "" {
				return nil, "", fmt.Errorf("there is an empty day in %q", part)
			}
			a, b, isRange := strings.Cut(tok, "-")
			from, ok := dayIndex(a)
			if !ok {
				return nil, "", fmt.Errorf("%q is not a day (use Mon, Tue, Wed, Thu, Fri, Sat or Sun)", strings.TrimSpace(a))
			}
			to := from
			if isRange {
				if to, ok = dayIndex(b); !ok {
					return nil, "", fmt.Errorf("%q is not a day (use Mon, Tue, Wed, Thu, Fri, Sat or Sun)", strings.TrimSpace(b))
				}
			}
			for d := from; ; d = (d + 1) % 7 { // a range may wrap, such as Fri-Mon
				w.days[d] = true
				if d == to {
					break
				}
			}
			if isRange {
				dayText = append(dayText, days[from][:3]+"-"+days[to][:3])
			} else {
				dayText = append(dayText, days[from][:3])
			}
		}
		text := strings.Join(dayText, ",")
		if m[2] == "" {
			w.allDay = true
		} else {
			h1, _ := strconv.Atoi(m[2])
			m1, _ := strconv.Atoi(m[3])
			h2, _ := strconv.Atoi(m[4])
			m2, _ := strconv.Atoi(m[5])
			if h1 > 23 || m1 > 59 || h2 > 24 || m2 > 59 || (h2 == 24 && m2 != 0) {
				return nil, "", fmt.Errorf("the times in %q are not valid (use 00:00 to 24:00)", part)
			}
			w.from, w.to = h1*60+m1, h2*60+m2
			if w.to <= w.from {
				return nil, "", fmt.Errorf("the end must be after the start in %q (for overnight, use two parts such as Mon-Fri 18:00-24:00; Tue-Sat 00:00-08:00)", part)
			}
			text += fmt.Sprintf(" %02d:%02d-%02d:%02d", h1, m1, h2, m2)
		}
		r.windows = append(r.windows, w)
		norm = append(norm, text)
	}
	if len(r.windows) == 0 {
		return nil, "", fmt.Errorf("type the days and hours, such as Mon-Fri 08:00-16:30")
	}
	return &r, strings.Join(norm, "; "), nil
}

// Match reports whether t (any zone) falls inside the rule, judged in UK time.
func (r *TimeRule) Match(t time.Time) bool {
	lt := t.In(london)
	minute := lt.Hour()*60 + lt.Minute()
	for _, w := range r.windows {
		if w.days[lt.Weekday()] && (w.allDay || (minute >= w.from && minute < w.to)) {
			return true
		}
	}
	return false
}

var splitRe = regexp.MustCompile(`^\s*(\d{1,2})\s*%?\s*$`)

// ParseSplit reads "30" or "30%" and returns the percentage and its normalised spelling.
func ParseSplit(v string) (int, string, error) {
	m := splitRe.FindStringSubmatch(v)
	if m == nil {
		return 0, "", fmt.Errorf("type a percentage from 1 to 99, such as 30%%")
	}
	n, _ := strconv.Atoi(m[1])
	if n < 1 || n > 99 {
		return 0, "", fmt.Errorf("type a percentage from 1 to 99, such as 30%%")
	}
	return n, strconv.Itoa(n) + "%", nil
}

// SplitHit places a visitor: true for roughly percent in every hundred. The same visitor token, link and rule
// position always give the same answer, and different rules are placed independently of each other.
func SplitHit(percent int, visitorToken string, linkID int64, position int) bool {
	if visitorToken == "" {
		return false
	}
	// SHA-256, not a quick hash: nearly identical inputs (the same visitor on rule 0 and rule 1) must still be placed independently
	sum := sha256.Sum256([]byte(fmt.Sprintf("%s|%d|%d", visitorToken, linkID, position)))
	return int(binary.BigEndian.Uint64(sum[:8])%100) < percent
}
