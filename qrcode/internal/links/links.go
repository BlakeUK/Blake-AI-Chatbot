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
	Kind           string // "dynamic" (tracked, editable) or "static" (the content itself is in the QR code)
	QRType         string // url, wifi, vcard ... (see package qrtypes)
	Data           string // the type's form fields as JSON, so the form can be re-filled on edit
	Content        string // static: exact text in the QR. dynamic vCard/event: the document served.
	Design         string // QR styling as JSON
	HasLogo        bool
	MaxScans       int
	ScanCount      int
	PasswordHash   string
	HasRules       bool
	Enabled        bool
	CreatedAt      time.Time
	UpdatedAt      time.Time
}

const (
	KindDynamic = "dynamic"
	KindStatic  = "static"
)

// IsStatic reports whether the code carries its content directly (untracked).
func (l *Link) IsStatic() bool { return l.Kind == KindStatic }

// Rule sends a visitor to URL when they match (Match is os, device, language or country).
type Rule struct {
	Match string
	Value string
	URL   string
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

	Kind     string // "" means dynamic
	QRType   string // "" means url
	Data     string // JSON of the type's form fields
	Content  string // static content, or a dynamic document (vCard / event)
	Design   string // JSON
	MaxScans int
	Rules    []Rule

	// PasswordHash is a bcrypt hash; KeepPassword (edits only) leaves the stored one alone.
	PasswordHash string
	KeepPassword bool
	// Logo is a processed PNG. On edit, LogoSet says whether to replace (or, with nil Logo, remove) it.
	Logo    []byte
	LogoSet bool
}

// Clean validates in and returns the normalised copy plus per-field messages.
// selfHost, if set, is the tracker's own host: destinations pointing back at
// it are refused because they would create a redirect loop.
func (in Input) Clean(selfHost string) (Input, map[string]string) {
	errs := map[string]string{}
	out := in
	if out.Kind == "" {
		out.Kind = KindDynamic
	}
	if out.QRType == "" {
		out.QRType = "url"
	}
	if out.Kind != KindDynamic && out.Kind != KindStatic {
		errs["kind"] = "Choose static or dynamic."
	}
	if out.Data == "" {
		out.Data = "{}"
	}
	out.Label = strings.TrimSpace(in.Label)
	switch n := utf8.RuneCountInString(out.Label); {
	case n == 0:
		errs["label"] = "Label is required."
	case n > MaxLabelRunes:
		errs["label"] = fmt.Sprintf("Label must be %d characters or fewer.", MaxLabelRunes)
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
	if out.Kind == KindStatic {
		// A static code is just its content: no redirect, window, limits or password.
		if out.Content == "" {
			errs["content"] = "There is nothing to put in the QR code."
		}
		out.Destination, out.FallbackURL, out.ExpiryMode = "", "", RedirectUntracked
		out.MaxScans, out.PasswordHash, out.Rules = 0, "", nil
		return out, errs
	}

	if in.Destination == "" && out.Content != "" {
		// a dynamic document (vCard, event): there is no web address to validate
	} else if d, err := ValidateURL(in.Destination); err != nil {
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
	if in.MaxScans < 0 || in.MaxScans > 10_000_000 {
		errs["max_scans"] = "The scan limit must be between 0 (no limit) and 10,000,000."
	}
	for i, r := range in.Rules {
		if _, err := ValidateURL(r.URL); err != nil || pointsAtSelf(r.URL, selfHost) {
			errs["rules"] = fmt.Sprintf("Rule %d has an invalid address.", i+1)
		}
	}
	if len(in.Rules) > 10 {
		errs["rules"] = "At most 10 rules."
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

// cols deliberately leaves out the logo image: it can be large and the
// redirect path, which reads this row on every scan, never needs it.
const cols = `id, code, label, destination_url, track_start, track_end, expiry_mode, fallback_url, qr_ecc, campaign,
	kind, qr_type, data, content, design, (logo IS NOT NULL), max_scans, scan_count, password_hash, has_rules, enabled, created_at, updated_at`

type rowScanner interface{ Scan(dest ...any) error }

func scan(r rowScanner) (*Link, error) {
	var l Link
	var start, end, created, updated, mode string
	var enabled, logo, rules int
	if err := r.Scan(&l.ID, &l.Code, &l.Label, &l.DestinationURL, &start, &end, &mode, &l.FallbackURL, &l.QRECC, &l.Campaign,
		&l.Kind, &l.QRType, &l.Data, &l.Content, &l.Design, &logo, &l.MaxScans, &l.ScanCount, &l.PasswordHash, &rules,
		&enabled, &created, &updated); err != nil {
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
	l.HasLogo = logo == 1
	l.HasRules = rules == 1
	return &l, nil
}

type execer interface {
	ExecContext(ctx context.Context, q string, args ...any) (sql.Result, error)
}

// insert writes one link (and its rules) using x, retrying on a code clash.
func (s *Store) insert(ctx context.Context, x execer, in Input, now string) (int64, error) {
	if in.Kind == "" {
		in.Kind = KindDynamic
	}
	if in.QRType == "" {
		in.QRType = "url"
	}
	if in.Data == "" {
		in.Data = "{}"
	}
	if in.ExpiryMode == "" {
		in.ExpiryMode = RedirectUntracked
	}
	in.Campaign = s.canonicalCampaign(ctx, in.Campaign)
	start, end := in.Start, in.End
	if in.Kind == KindStatic || start.IsZero() {
		start, end = s.Now(), s.Now() // a static code has no tracking window
	}
	var logo any
	if len(in.Logo) > 0 {
		logo = in.Logo
	}
	for attempt := 0; attempt < 8; attempt++ {
		code, err := s.GenCode()
		if err != nil {
			return 0, err
		}
		res, err := x.ExecContext(ctx, `INSERT INTO links
			(code, label, destination_url, track_start, track_end, expiry_mode, fallback_url, qr_ecc, campaign,
			 kind, qr_type, data, content, design, logo, max_scans, password_hash, has_rules, enabled, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)`,
			code, in.Label, in.Destination, db.TS(start), db.TS(end), string(in.ExpiryMode), in.FallbackURL, in.QRECC, in.Campaign,
			in.Kind, in.QRType, in.Data, in.Content, in.Design, logo, in.MaxScans, in.PasswordHash, b2i(len(in.Rules) > 0), now, now)
		if err != nil {
			if strings.Contains(err.Error(), "UNIQUE constraint failed: links.code") {
				continue
			}
			return 0, err
		}
		id, _ := res.LastInsertId()
		if err := writeRules(ctx, x, id, in.Rules); err != nil {
			return 0, err
		}
		return id, nil
	}
	return 0, errors.New("could not generate a unique short code")
}

func b2i(b bool) int {
	if b {
		return 1
	}
	return 0
}

func writeRules(ctx context.Context, x execer, id int64, rules []Rule) error {
	if _, err := x.ExecContext(ctx, `DELETE FROM link_rules WHERE link_id = ?`, id); err != nil {
		return err
	}
	for i, r := range rules {
		if _, err := x.ExecContext(ctx, `INSERT INTO link_rules(link_id, position, match, value, url) VALUES (?, ?, ?, ?, ?)`,
			id, i, r.Match, r.Value, r.URL); err != nil {
			return err
		}
	}
	return nil
}

// Create inserts a link from validated input.
func (s *Store) Create(ctx context.Context, in Input) (*Link, error) {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	id, err := s.insert(ctx, tx, in, db.TS(s.Now()))
	if err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return s.Get(ctx, id)
}

// CreateMany inserts every link or none: bulk creation is all-or-nothing.
func (s *Store) CreateMany(ctx context.Context, ins []Input) ([]*Link, error) {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	now := db.TS(s.Now())
	ids := make([]int64, 0, len(ins))
	for _, in := range ins {
		id, err := s.insert(ctx, tx, in, now)
		if err != nil {
			return nil, err
		}
		ids = append(ids, id)
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	out := make([]*Link, 0, len(ids))
	for _, id := range ids {
		l, err := s.Get(ctx, id)
		if err != nil {
			return nil, err
		}
		out = append(out, l)
	}
	return out, nil
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

// Update saves an edit. The short code never changes, so printed codes keep
// working. A static code's content can never change (it is printed in the code
// itself): only its label, campaign, look and error correction can.
func (s *Store) Update(ctx context.Context, id int64, in Input) error {
	cur, err := s.Get(ctx, id)
	if err != nil {
		return err
	}
	in.Campaign = s.canonicalCampaign(ctx, in.Campaign)
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	now := db.TS(s.Now())

	if cur.IsStatic() {
		if _, err := tx.ExecContext(ctx, `UPDATE links SET label=?, campaign=?, qr_ecc=?, design=?, updated_at=? WHERE id=?`,
			in.Label, in.Campaign, in.QRECC, in.Design, now, id); err != nil {
			return err
		}
	} else {
		pw, passwordSQL := in.PasswordHash, "password_hash=?,"
		if in.KeepPassword {
			passwordSQL, pw = "", ""
		}
		args := []any{in.Label, in.Destination, db.TS(in.Start), db.TS(in.End), string(in.ExpiryMode), in.FallbackURL, in.QRECC, in.Campaign,
			in.Data, in.Content, in.Design, in.MaxScans, b2i(len(in.Rules) > 0), now}
		q := `UPDATE links SET label=?, destination_url=?, track_start=?, track_end=?, expiry_mode=?, fallback_url=?, qr_ecc=?, campaign=?,
			data=?, content=?, design=?, max_scans=?, has_rules=?, ` + passwordSQL + ` updated_at=? WHERE id=?`
		if passwordSQL != "" {
			args = append(args[:13], pw, now, id)
			// order: ..., max_scans, has_rules, password_hash, updated_at, id
		} else {
			args = append(args[:13], now, id)
		}
		if _, err := tx.ExecContext(ctx, q, args...); err != nil {
			return err
		}
		if err := writeRules(ctx, tx, id, in.Rules); err != nil {
			return err
		}
	}
	if in.LogoSet {
		var logo any
		if len(in.Logo) > 0 {
			logo = in.Logo
		}
		if _, err := tx.ExecContext(ctx, `UPDATE links SET logo = ? WHERE id = ?`, logo, id); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// Rules returns a link's smart-routing rules in priority order.
func (s *Store) Rules(ctx context.Context, id int64) ([]Rule, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT match, value, url FROM link_rules WHERE link_id = ? ORDER BY position`, id)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Rule
	for rows.Next() {
		var r Rule
		if err := rows.Scan(&r.Match, &r.Value, &r.URL); err != nil {
			return nil, err
		}
		out = append(out, r)
	}
	return out, rows.Err()
}

// Logo returns a link's stored centre logo (a PNG), or nil.
func (s *Store) Logo(ctx context.Context, id int64) ([]byte, error) {
	var b []byte
	err := s.DB.QueryRowContext(ctx, `SELECT logo FROM links WHERE id = ?`, id).Scan(&b)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	return b, err
}

func (s *Store) SetEnabled(ctx context.Context, id int64, enabled bool) error {
	res, err := s.DB.ExecContext(ctx, `UPDATE links SET enabled=?, updated_at=? WHERE id=?`, b2i(enabled), db.TS(s.Now()), id)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return ErrNotFound
	}
	return nil
}

// Delete removes a link and, via ON DELETE CASCADE, its scans and rules.
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

// ---------- saved designs ----------

// Template is a saved QR design that can be applied to new codes.
type Template struct {
	ID      int64
	Name    string
	Design  string
	HasLogo bool
}

// SaveTemplate stores (or replaces, by name) a design template.
func (s *Store) SaveTemplate(ctx context.Context, name, design string, logo []byte) error {
	name = strings.Join(strings.Fields(name), " ")
	if name == "" || utf8.RuneCountInString(name) > 60 {
		return templateNameError{}
	}
	var l any
	if len(logo) > 0 {
		l = logo
	}
	_, err := s.DB.ExecContext(ctx, `INSERT INTO qr_templates(name, design, logo, created_at) VALUES (?, ?, ?, ?)
		ON CONFLICT(name) DO UPDATE SET design = excluded.design, logo = excluded.logo`, name, design, l, db.TS(s.Now()))
	return err
}

func (s *Store) Templates(ctx context.Context) ([]Template, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT id, name, design, (logo IS NOT NULL) FROM qr_templates ORDER BY name COLLATE NOCASE`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Template
	for rows.Next() {
		var t Template
		var logo int
		if err := rows.Scan(&t.ID, &t.Name, &t.Design, &logo); err != nil {
			return nil, err
		}
		t.HasLogo = logo == 1
		out = append(out, t)
	}
	return out, rows.Err()
}

// TemplateByID returns a template and its logo.
func (s *Store) TemplateByID(ctx context.Context, id int64) (Template, []byte, error) {
	var t Template
	var logo []byte
	err := s.DB.QueryRowContext(ctx, `SELECT id, name, design, logo FROM qr_templates WHERE id = ?`, id).Scan(&t.ID, &t.Name, &t.Design, &logo)
	if errors.Is(err, sql.ErrNoRows) {
		return t, nil, ErrNotFound
	}
	t.HasLogo = len(logo) > 0
	return t, logo, err
}

func (s *Store) DeleteTemplate(ctx context.Context, id int64) error {
	_, err := s.DB.ExecContext(ctx, `DELETE FROM qr_templates WHERE id = ?`, id)
	return err
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

// templateNameError is shown to the person naming a design, so it is a sentence.
type templateNameError struct{}

func (templateNameError) Error() string { return "A template name must be 1 to 60 characters." }
