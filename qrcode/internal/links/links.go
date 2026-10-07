// Package links holds tracked links: validation, short-code generation, the
// tracking-window state machine and persistence.
package links

import (
	"context"
	"crypto/rand"
	"database/sql"
	"errors"
	"fmt"
	"net/url"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

const (
	CodeLen          = 8
	MaxURLLen        = 2048
	MaxLabelRunes    = 100
	MaxCampaignRunes = 60
	base62           = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"
)

type ExpiryMode string

const (
	RedirectUntracked   ExpiryMode = "redirect_untracked"
	ShowExpiredPage     ExpiryMode = "show_expired_page"
	RedirectFallbackURL ExpiryMode = "redirect_fallback_url"
)

func (m ExpiryMode) Valid() bool {
	return m == RedirectUntracked || m == ShowExpiredPage || m == RedirectFallbackURL
}

type Status string

const (
	Scheduled Status = "scheduled"
	Active    Status = "active"
	Ended     Status = "ended"
)

type Link struct {
	ID             int64
	Code           string
	Label          string
	DestinationURL string
	TrackStart     time.Time
	TrackEnd       time.Time
	ExpiryMode     ExpiryMode
	FallbackURL    string
	QRECC          string
	Campaign       string
	Enabled        bool
	CreatedAt      time.Time
	UpdatedAt      time.Time
}

// StatusAt reports where t falls relative to the tracking window. The window
// is half-open: [TrackStart, TrackEnd).
func (l *Link) StatusAt(t time.Time) Status {
	switch {
	case t.Before(l.TrackStart):
		return Scheduled
	case t.Before(l.TrackEnd):
		return Active
	}
	return Ended
}

// GenerateCode returns a random 8-character base62 code from crypto/rand,
// using rejection sampling so every character is equally likely.
func GenerateCode() (string, error) {
	out := make([]byte, 0, CodeLen)
	buf := make([]byte, 32)
	for len(out) < CodeLen {
		if _, err := rand.Read(buf); err != nil {
			return "", err
		}
		for _, b := range buf {
			if b >= 248 { // 248 = 62*4; discard the biased tail
				continue
			}
			out = append(out, base62[int(b)%62])
			if len(out) == CodeLen {
				break
			}
		}
	}
	return string(out), nil
}

// ValidCode reports whether s has the shape of a generated code.
func ValidCode(s string) bool {
	if len(s) != CodeLen {
		return false
	}
	for i := 0; i < len(s); i++ {
		if !strings.ContainsRune(base62, rune(s[i])) {
			return false
		}
	}
	return true
}

// ValidateURL checks a destination or fallback URL and returns it normalised.
// Only absolute http and https URLs are accepted, which rejects javascript:,
// data:, file:, ftp: and scheme-relative forms. Credentials in the URL are
// refused, as are control characters and whitespace.
func ValidateURL(raw string) (string, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return "", errors.New("required")
	}
	if len(raw) > MaxURLLen {
		return "", fmt.Errorf("must be %d characters or fewer", MaxURLLen)
	}
	for _, r := range raw {
		if unicode.IsControl(r) || unicode.IsSpace(r) {
			return "", errors.New("must not contain spaces or control characters")
		}
	}
	u, err := url.Parse(raw)
	if err != nil {
		return "", errors.New("is not a valid URL")
	}
	switch strings.ToLower(u.Scheme) {
	case "http", "https":
	default:
		return "", errors.New("must start with http:// or https://")
	}
	if u.Hostname() == "" {
		return "", errors.New("must include a host name")
	}
	if u.User != nil {
		return "", errors.New("must not contain a username or password")
	}
	return u.String(), nil
}

// Input is the user-supplied part of a link, before validation.
type Input struct {
	Label       string
	Destination string
	Start, End  time.Time
	ExpiryMode  ExpiryMode
	FallbackURL string
	QRECC       string
	Campaign    string
}

// Clean validates in and returns the normalised copy plus per-field messages.
// selfHost, if set, is the tracker's own host: destinations pointing back at
// it are refused because they would create a redirect loop.
func (in Input) Clean(selfHost string) (Input, map[string]string) {
	errs := map[string]string{}
	out := in
	out.Label = strings.TrimSpace(in.Label)
	switch n := utf8.RuneCountInString(out.Label); {
	case n == 0:
		errs["label"] = "Label is required."
	case n > MaxLabelRunes:
		errs["label"] = fmt.Sprintf("Label must be %d characters or fewer.", MaxLabelRunes)
	}
	if d, err := ValidateURL(in.Destination); err != nil {
		errs["destination"] = "Destination " + err.Error() + "."
	} else {
		out.Destination = d
		if pointsAtSelf(d, selfHost) {
			errs["destination"] = "Destination must not point back at this tracker."
		}
	}
	if in.Start.IsZero() || in.End.IsZero() {
		errs["window"] = "Start and end are required."
	} else if !in.End.After(in.Start) {
		errs["window"] = "The end must be after the start."
	}
	if !in.ExpiryMode.Valid() {
		errs["expiry_mode"] = "Choose what happens after the window ends."
	}
	out.FallbackURL = strings.TrimSpace(in.FallbackURL)
	if in.ExpiryMode == RedirectFallbackURL {
		if f, err := ValidateURL(in.FallbackURL); err != nil {
			errs["fallback_url"] = "Fallback URL " + err.Error() + "."
		} else {
			out.FallbackURL = f
			if pointsAtSelf(f, selfHost) {
				errs["fallback_url"] = "Fallback URL must not point back at this tracker."
			}
		}
	} else if out.FallbackURL != "" {
		if f, err := ValidateURL(in.FallbackURL); err != nil {
			errs["fallback_url"] = "Fallback URL " + err.Error() + "."
		} else {
			out.FallbackURL = f
		}
	}
	out.Campaign = strings.Join(strings.Fields(in.Campaign), " ")
	if utf8.RuneCountInString(out.Campaign) > MaxCampaignRunes {
		errs["campaign"] = fmt.Sprintf("Campaign must be %d characters or fewer.", MaxCampaignRunes)
	}
	for _, r := range out.Campaign {
		if unicode.IsControl(r) {
			errs["campaign"] = "Campaign must not contain control characters."
			break
		}
	}
	if in.QRECC == "" {
		out.QRECC = "M"
	} else if !validECC(in.QRECC) {
		errs["qr_ecc"] = "Error correction must be L, M, Q or H."
	}
	return out, errs
}

func validECC(s string) bool { return s == "L" || s == "M" || s == "Q" || s == "H" }

func pointsAtSelf(raw, selfHost string) bool {
	if selfHost == "" {
		return false
	}
	u, err := url.Parse(raw)
	return err == nil && strings.EqualFold(u.Host, selfHost)
}

// ErrNotFound is returned when no link matches.
var ErrNotFound = errors.New("link not found")

// Store persists links.
type Store struct {
	DB      *sql.DB
	Now     func() time.Time
	GenCode func() (string, error) // overridable for tests; defaults to GenerateCode
}

func NewStore(d *sql.DB) *Store {
	return &Store{DB: d, Now: time.Now, GenCode: GenerateCode}
}

const cols = `id, code, label, destination_url, track_start, track_end, expiry_mode, fallback_url, qr_ecc, campaign, enabled, created_at, updated_at`

type rowScanner interface{ Scan(dest ...any) error }

func scan(r rowScanner) (*Link, error) {
	var l Link
	var start, end, created, updated string
	var mode string
	var enabled int
	if err := r.Scan(&l.ID, &l.Code, &l.Label, &l.DestinationURL, &start, &end, &mode,
		&l.FallbackURL, &l.QRECC, &l.Campaign, &enabled, &created, &updated); err != nil {
		return nil, err
	}
	var err error
	if l.TrackStart, err = db.ParseTS(start); err != nil {
		return nil, err
	}
	if l.TrackEnd, err = db.ParseTS(end); err != nil {
		return nil, err
	}
	if l.CreatedAt, err = db.ParseTS(created); err != nil {
		return nil, err
	}
	if l.UpdatedAt, err = db.ParseTS(updated); err != nil {
		return nil, err
	}
	l.ExpiryMode = ExpiryMode(mode)
	l.Enabled = enabled == 1
	return &l, nil
}

// Create inserts a link from validated input, retrying on the (astronomically
// unlikely) event that a generated code is already taken.
func (s *Store) Create(ctx context.Context, in Input) (*Link, error) {
	now := db.TS(s.Now())
	in.Campaign = s.canonicalCampaign(ctx, in.Campaign)
	for attempt := 0; attempt < 8; attempt++ {
		code, err := s.GenCode()
		if err != nil {
			return nil, err
		}
		res, err := s.DB.ExecContext(ctx, `INSERT INTO links
			(code, label, destination_url, track_start, track_end, expiry_mode, fallback_url, qr_ecc, campaign, enabled, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)`,
			code, in.Label, in.Destination, db.TS(in.Start), db.TS(in.End), string(in.ExpiryMode),
			in.FallbackURL, in.QRECC, in.Campaign, now, now)
		if err != nil {
			if strings.Contains(err.Error(), "UNIQUE constraint failed: links.code") {
				continue
			}
			return nil, err
		}
		id, _ := res.LastInsertId()
		return s.Get(ctx, id)
	}
	return nil, errors.New("could not generate a unique short code")
}

func (s *Store) Get(ctx context.Context, id int64) (*Link, error) {
	l, err := scan(s.DB.QueryRowContext(ctx, `SELECT `+cols+` FROM links WHERE id = ?`, id))
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	return l, err
}

func (s *Store) ByCode(ctx context.Context, code string) (*Link, error) {
	l, err := scan(s.DB.QueryRowContext(ctx, `SELECT `+cols+` FROM links WHERE code = ?`, code))
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	return l, err
}

// Update changes label, destination, window, expiry behaviour and QR level.
// The short code never changes, so printed QR codes keep working.
func (s *Store) Update(ctx context.Context, id int64, in Input) error {
	in.Campaign = s.canonicalCampaign(ctx, in.Campaign)
	res, err := s.DB.ExecContext(ctx, `UPDATE links SET label=?, destination_url=?, track_start=?, track_end=?,
		expiry_mode=?, fallback_url=?, qr_ecc=?, campaign=?, updated_at=? WHERE id=?`,
		in.Label, in.Destination, db.TS(in.Start), db.TS(in.End), string(in.ExpiryMode),
		in.FallbackURL, in.QRECC, in.Campaign, db.TS(s.Now()), id)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return ErrNotFound
	}
	return nil
}

func (s *Store) SetEnabled(ctx context.Context, id int64, enabled bool) error {
	v := 0
	if enabled {
		v = 1
	}
	res, err := s.DB.ExecContext(ctx, `UPDATE links SET enabled=?, updated_at=? WHERE id=?`, v, db.TS(s.Now()), id)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return ErrNotFound
	}
	return nil
}

// Delete removes a link and, via ON DELETE CASCADE, all of its scans.
func (s *Store) Delete(ctx context.Context, id int64) error {
	res, err := s.DB.ExecContext(ctx, `DELETE FROM links WHERE id=?`, id)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return ErrNotFound
	}
	return nil
}

// Filter narrows List to one campaign, or to links that have none.
type Filter struct {
	Campaign      string
	Uncategorised bool
}

func (f Filter) where() (string, []any) {
	switch {
	case f.Uncategorised:
		return ` WHERE campaign = ''`, nil
	case f.Campaign != "":
		return ` WHERE campaign = ? COLLATE NOCASE`, []any{f.Campaign}
	}
	return "", nil
}

// List returns one page of links, newest first, and the total matching count.
func (s *Store) List(ctx context.Context, page, perPage int, f Filter) ([]*Link, int, error) {
	if page < 1 {
		page = 1
	}
	where, args := f.where()
	var total int
	if err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM links`+where, args...).Scan(&total); err != nil {
		return nil, 0, err
	}
	rows, err := s.DB.QueryContext(ctx, `SELECT `+cols+` FROM links`+where+` ORDER BY id DESC LIMIT ? OFFSET ?`,
		append(args, perPage, (page-1)*perPage)...)
	if err != nil {
		return nil, 0, err
	}
	defer rows.Close()
	var out []*Link
	for rows.Next() {
		l, err := scan(rows)
		if err != nil {
			return nil, 0, err
		}
		out = append(out, l)
	}
	return out, total, rows.Err()
}

// Campaigns returns every distinct non-empty campaign name, alphabetically.
func (s *Store) Campaigns(ctx context.Context) ([]string, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT DISTINCT campaign FROM links WHERE campaign <> '' ORDER BY campaign COLLATE NOCASE`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var c string
		if err := rows.Scan(&c); err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

// canonicalCampaign returns the spelling already in use if name matches an
// existing campaign ignoring case, so "leaflet" and "Leaflet" never split one
// campaign into two rows of statistics.
func (s *Store) canonicalCampaign(ctx context.Context, name string) string {
	if name == "" {
		return ""
	}
	var existing string
	if err := s.DB.QueryRowContext(ctx, `SELECT campaign FROM links WHERE campaign = ? COLLATE NOCASE LIMIT 1`, name).Scan(&existing); err == nil {
		return existing
	}
	return name
}
