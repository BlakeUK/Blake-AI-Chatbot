package web

import (
	"sync"
	"time"
)

// limiter is a per-key token bucket held in memory: capacity perMin, refilled
// continuously at perMin tokens a minute. Idle buckets are swept so a flood
// of distinct keys cannot grow the map without bound.
type limiter struct {
	mu        sync.Mutex
	perMin    float64
	now       func() time.Time
	buckets   map[string]*bucket
	lastSweep time.Time
}

type bucket struct {
	tokens float64
	last   time.Time
}

const maxBuckets = 200000

func newLimiter(perMin int, now func() time.Time) *limiter {
	return &limiter{perMin: float64(perMin), now: now, buckets: map[string]*bucket{}, lastSweep: now()}
}

func (l *limiter) Allow(key string) bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	t := l.now()
	if t.Sub(l.lastSweep) > 5*time.Minute || len(l.buckets) > maxBuckets {
		for k, b := range l.buckets {
			if t.Sub(b.last) > 10*time.Minute {
				delete(l.buckets, k)
			}
		}
		if len(l.buckets) > maxBuckets {
			l.buckets = map[string]*bucket{}
		}
		l.lastSweep = t
	}
	b, ok := l.buckets[key]
	if !ok {
		b = &bucket{tokens: l.perMin, last: t}
		l.buckets[key] = b
	}
	b.tokens += t.Sub(b.last).Seconds() * l.perMin / 60
	if b.tokens > l.perMin {
		b.tokens = l.perMin
	}
	b.last = t
	if b.tokens < 1 {
		return false
	}
	b.tokens--
	return true
}
