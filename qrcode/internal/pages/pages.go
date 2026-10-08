package pages

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
)

const (
	MaxItems        = 30
	MaxTitleRunes   = 60
	MaxDescRunes    = 80
	MaxHeadingRunes = 80
	MaxSubRunes     = 160
	MaxNameRunes    = 100
)

// userError is a message written for the person editing the page, shown as is.
type userError string

func (e userError) Error() string { return string(e) }

// ErrSlugTaken is returned when another page already uses the address.
var ErrSlugTaken error = userError("That page address is already used by another page. Choose a different one.")

// ErrNotFound is returned when no page or button matches.
var ErrNotFound = errors.New("page not found")

// Item is one button on a page.
type Item struct {
	ID          int64
	Position    int
	Title       string
	URL         string
	Description string
	Icon        string // a concrete icon name (never "auto" once stored resolved)
}

// Page is a link page and its buttons.
type Page struct {
	ID          int64
	Slug        string
	Name        string
	Brand       string
	Theme       string
	Accent      string // "" = the brand's own colour
	Title       string
	Subtitle    string
	ShowURLs    bool
	ShowSocials bool
	Enabled     bool
	CreatedAt   time.Time
	UpdatedAt   time.Time
	Items       []Item
}

// ItemInput is a button as submitted by the form.
type ItemInput struct {
	ID          int64 // 0 for a new button; an existing id keeps its click history
	Row         int   // the row on the form, so an error can be shown beside it
	Title       string
	URL         string
	Description string
	Icon        string
}

// Input is a whole page as submitted by the form.
type Input struct {
	Slug        string
	Name        string
	Brand       string
	Theme       string
	Accent      string
	Title       string
	Subtitle    string
	ShowURLs    bool
	ShowSocials bool
	Items       []ItemInput
}

var (
	slugRe  = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$`)
	mailRe  = regexp.MustCompile(`^[^@\s]+@[^@\s]+\.[^@\s]+$`)
	phoneRe = regexp.MustCompile(`^\+?[0-9][0-9 ()./-]{3,24}$`)
)

// Slugify turns a name into a suggested page address.
func Slugify(name string) string {
	var b strings.Builder
	dash := false
	for _, r := range strings.ToLower(name) {
		switch {
		case r >= 'a' && r <= 'z', r >= '0' && r <= '9':
			b.WriteRune(r)
			dash = false
		case !dash && b.Len() > 0:
			b.WriteByte('-')
			dash = true
		}
	}
	s := strings.Trim(b.String(), "-")
	if len(s) > 40 {
		s = strings.Trim(s[:40], "-")
	}
	return s
}

// CleanLinkTarget validates a button's address: a web address, mailto: or tel:.
func CleanLinkTarget(raw string) (string, error) {
	raw = strings.TrimSpace(raw)
	l := strings.ToLower(raw)
	switch {
	case strings.HasPrefix(l, "mailto:"):
		addr := strings.TrimSpace(raw[len("mailto:"):])
		if !mailRe.MatchString(addr) || len(addr) > 120 || strings.ContainsFunc(addr, func(r rune) bool { return unicode.IsControl(r) || strings.ContainsRune(`<>"\'`, r) }) {
			return "", userError("enter a valid email address after mailto:")
		}
		return "mailto:" + addr, nil
	case strings.HasPrefix(l, "tel:"):
		num := strings.TrimSpace(raw[len("tel:"):])
		var b strings.Builder
		digits := 0
		for i, r := range num {
			if (r >= '0' && r <= '9') || (i == 0 && r == '+') {
				b.WriteRune(r)
				if r != '+' {
					digits++
				}
			}
		}
		// judge the cleaned number, so what is stored is always accepted again when the page is edited
		if !phoneRe.MatchString(num) || digits < 5 || digits > 20 {
			return "", userError("enter a phone number after tel:, for example tel:+441142235000")
		}
		return "tel:" + b.String(), nil
	}
	u, err := links.ValidateURL(raw)
	if err != nil {
		return "", userError("the address " + err.Error())
	}
	return u, nil
}

// Clean validates a page and returns the normalised copy plus a message for
// every problem, keyed by field (item problems are "item<row>_title" and so on).
func (in Input) Clean() (Input, map[string]string) {
	errs := map[string]string{}
	out := in

	out.Slug = strings.ToLower(strings.TrimSpace(in.Slug))
	if !slugRe.MatchString(out.Slug) {
		errs["slug"] = "The page address must be 3 to 40 characters: lower-case letters, numbers and dashes, not starting or ending with a dash."
	}
	out.Name = strings.TrimSpace(in.Name)
	if n := utf8.RuneCountInString(out.Name); n == 0 {
		errs["name"] = "Give the page a name so you can find it later."
	} else if n > MaxNameRunes {
		errs["name"] = fmt.Sprintf("The name must be %d characters or fewer.", MaxNameRunes)
	}
	brand, okBrand := BrandByID(in.Brand)
	if !okBrand {
		errs["brand"] = "Choose a company."
	}
	theme, okTheme := ThemeByID(in.Theme)
	if !okTheme {
		errs["theme"] = "Choose a theme."
	}
	out.Title = strings.TrimSpace(in.Title)
	if utf8.RuneCountInString(out.Title) > MaxHeadingRunes {
		errs["title"] = fmt.Sprintf("The heading must be %d characters or fewer.", MaxHeadingRunes)
	}
	out.Subtitle = strings.TrimSpace(in.Subtitle)
	if utf8.RuneCountInString(out.Subtitle) > MaxSubRunes {
		errs["subtitle"] = fmt.Sprintf("The subheading must be %d characters or fewer.", MaxSubRunes)
	}
	for _, s := range []string{out.Title, out.Subtitle, out.Name} {
		for _, r := range s {
			if unicode.IsControl(r) {
				errs["title"] = "Headings must not contain control characters."
			}
		}
	}
	out.Accent = strings.ToLower(strings.TrimSpace(in.Accent))
	if out.Accent != "" && okBrand && okTheme {
		if _, err := Resolve(brand, theme, out.Accent); err != nil {
			errs["accent"] = err.Error()
		}
	}

	var items []ItemInput
	for _, it := range in.Items {
		title, target, desc := strings.TrimSpace(it.Title), strings.TrimSpace(it.URL), strings.TrimSpace(it.Description)
		if title == "" && target == "" && desc == "" {
			continue // a blank row on the form
		}
		key := func(f string) string { return fmt.Sprintf("item%d_%s", it.Row, f) }
		clean := ItemInput{ID: it.ID, Row: it.Row, Title: title, Description: desc}
		if n := utf8.RuneCountInString(title); n == 0 {
			errs[key("title")] = "Give the button a title."
		} else if n > MaxTitleRunes {
			errs[key("title")] = fmt.Sprintf("The title must be %d characters or fewer.", MaxTitleRunes)
		}
		if t, err := CleanLinkTarget(target); err != nil {
			errs[key("url")] = "Check " + err.Error() + "."
		} else {
			clean.URL = t
		}
		if utf8.RuneCountInString(desc) > MaxDescRunes {
			errs[key("desc")] = fmt.Sprintf("The description must be %d characters or fewer.", MaxDescRunes)
		}
		icon := strings.TrimSpace(it.Icon)
		if icon == "" {
			icon = "auto"
		}
		valid := false
		for _, n := range IconNames {
			if n == icon {
				valid = true
			}
		}
		if !valid {
			errs[key("icon")] = "Choose an icon from the list."
			icon = "auto"
		}
		if icon == "auto" && clean.URL != "" {
			icon = DetectIcon(clean.URL)
		}
		clean.Icon = icon
		items = append(items, clean)
	}
	if len(items) == 0 {
		errs["items"] = "Add at least one button."
	}
	if len(items) > MaxItems {
		errs["items"] = fmt.Sprintf("A page can have at most %d buttons.", MaxItems)
	}
	out.Items = items
	return out, errs
}

// ---------- colours ----------

func hexRGB(s string) ([3]uint8, bool) {
	var z [3]uint8
	if len(s) != 7 || s[0] != '#' {
		return z, false
	}
	for i := 0; i < 3; i++ {
		v, err := strconv.ParseUint(s[1+2*i:3+2*i], 16, 8)
		if err != nil {
			return z, false
		}
		z[i] = uint8(v)
	}
	return z, true
}

func hexOf(c [3]uint8) string { return fmt.Sprintf("#%02x%02x%02x", c[0], c[1], c[2]) }

func lin(v uint8) float64 {
	f := float64(v) / 255
	if f <= 0.03928 {
		return f / 12.92
	}
	return math.Pow((f+0.055)/1.055, 2.4)
}

func luminance(c [3]uint8) float64 { return 0.2126*lin(c[0]) + 0.7152*lin(c[1]) + 0.0722*lin(c[2]) }

func contrast(a, b [3]uint8) float64 {
	la, lb := luminance(a), luminance(b)
	if la < lb {
		la, lb = lb, la
	}
	return (la + 0.05) / (lb + 0.05)
}

func mix(a, b [3]uint8, t float64) [3]uint8 {
	var o [3]uint8
	for i := range o {
		o[i] = uint8(math.Round(float64(a[i])*(1-t) + float64(b[i])*t))
	}
	return o
}

var (
	white     = [3]uint8{255, 255, 255}
	black     = [3]uint8{0, 0, 0}
	nearBlack = [3]uint8{17, 20, 26}
	midnight  = [3]uint8{14, 22, 33} // the Midnight theme's page colour
)

// Look is a brand and a theme resolved into concrete colours and a logo.
type Look struct {
	Brand  Brand
	Theme  Theme
	Accent string // the colour of icons, bars and buttons
	Ink    string // readable text on top of the accent colour
	Logo   string
	BG1    string // page gradient, top
	BG2    string // page gradient, bottom
}

// Resolve turns a brand, a theme and an optional accent override into colours.
// An accent that would not be readable on the theme's background is refused.
func Resolve(b Brand, t Theme, override string) (Look, error) {
	accent := b.Accent
	if t.Dark {
		accent = b.AccentOnDark
	}
	if override != "" {
		accent = override
	}
	a, ok := hexRGB(accent)
	if !ok {
		return Look{}, userError("The accent colour must be a hex colour such as #dd9833.")
	}
	l := Look{Brand: b, Theme: t, Accent: hexOf(a), Logo: b.LogoOnLight}
	if t.Dark {
		l.Logo = b.LogoOnDark
	}
	if contrast(a, white) >= contrast(a, nearBlack) {
		l.Ink = "#ffffff"
	} else {
		l.Ink = hexOf(nearBlack)
	}
	var page [3]uint8
	switch t.ID {
	case "midnight":
		page = midnight
		l.BG1, l.BG2 = hexOf(mix(midnight, white, 0.05)), hexOf(mix(midnight, black, 0.35))
	case "bold":
		page = mix(a, black, 0.80)
		l.BG1, l.BG2 = hexOf(mix(a, black, 0.74)), hexOf(mix(a, black, 0.88))
	default: // daylight
		page = white
		l.BG1, l.BG2 = hexOf(mix(a, white, 0.93)), "#ffffff"
	}
	if override != "" && contrast(a, page) < 3 {
		return Look{}, userError(fmt.Sprintf("That accent colour is too close to the %s theme's background to read (contrast %.1f, needs 3). Choose a stronger colour.", t.Name, contrast(a, page)))
	}
	return l, nil
}

// CSS returns the page's colour variables as a stylesheet.
func (l Look) CSS() string {
	return fmt.Sprintf(":root{--accent:%s;--ink:%s;--bg1:%s;--bg2:%s;}\n", l.Accent, l.Ink, l.BG1, l.BG2)
}

// ---------- storage ----------

// Store persists pages.
type Store struct {
	DB  *sql.DB
	Now func() time.Time
}

func NewStore(d *sql.DB) *Store { return &Store{DB: d, Now: time.Now} }

const pageCols = `id, slug, name, brand, theme, accent, title, subtitle, show_urls, show_socials, enabled, created_at, updated_at`

type scanner interface{ Scan(dest ...any) error }

func scanPage(r scanner) (*Page, error) {
	var p Page
	var urls, socials, enabled int
	var created, updated string
	if err := r.Scan(&p.ID, &p.Slug, &p.Name, &p.Brand, &p.Theme, &p.Accent, &p.Title, &p.Subtitle, &urls, &socials, &enabled, &created, &updated); err != nil {
		return nil, err
	}
	p.ShowURLs, p.ShowSocials, p.Enabled = urls == 1, socials == 1, enabled == 1
	p.CreatedAt, _ = db.ParseTS(created)
	p.UpdatedAt, _ = db.ParseTS(updated)
	return &p, nil
}

func b2i(b bool) int {
	if b {
		return 1
	}
	return 0
}

func (s *Store) items(ctx context.Context, id int64) ([]Item, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT id, position, title, url, description, icon FROM link_page_items WHERE page_id = ? ORDER BY position, id`, id)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []Item
	for rows.Next() {
		var it Item
		if err := rows.Scan(&it.ID, &it.Position, &it.Title, &it.URL, &it.Description, &it.Icon); err != nil {
			return nil, err
		}
		out = append(out, it)
	}
	return out, rows.Err()
}

func (s *Store) withItems(ctx context.Context, p *Page, err error) (*Page, error) {
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	p.Items, err = s.items(ctx, p.ID)
	return p, err
}

func (s *Store) Get(ctx context.Context, id int64) (*Page, error) {
	p, err := scanPage(s.DB.QueryRowContext(ctx, `SELECT `+pageCols+` FROM link_pages WHERE id = ?`, id))
	return s.withItems(ctx, p, err)
}

func (s *Store) BySlug(ctx context.Context, slug string) (*Page, error) {
	p, err := scanPage(s.DB.QueryRowContext(ctx, `SELECT `+pageCols+` FROM link_pages WHERE slug = ? COLLATE NOCASE`, slug))
	return s.withItems(ctx, p, err)
}

// List returns every page, newest first, without their buttons.
func (s *Store) List(ctx context.Context) ([]*Page, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+pageCols+` FROM link_pages ORDER BY id DESC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*Page
	for rows.Next() {
		p, err := scanPage(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

func isSlugClash(err error) bool {
	return err != nil && strings.Contains(err.Error(), "UNIQUE constraint failed: link_pages.slug")
}

// Create stores a validated page.
func (s *Store) Create(ctx context.Context, in Input) (*Page, error) {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	now := db.TS(s.Now())
	res, err := tx.ExecContext(ctx, `INSERT INTO link_pages (slug, name, brand, theme, accent, title, subtitle, show_urls, show_socials, enabled, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)`, in.Slug, in.Name, in.Brand, in.Theme, in.Accent, in.Title, in.Subtitle, b2i(in.ShowURLs), b2i(in.ShowSocials), now, now)
	if err != nil {
		if isSlugClash(err) {
			return nil, ErrSlugTaken
		}
		return nil, err
	}
	id, _ := res.LastInsertId()
	for i, it := range in.Items {
		if _, err := tx.ExecContext(ctx, `INSERT INTO link_page_items (page_id, position, title, url, description, icon) VALUES (?, ?, ?, ?, ?, ?)`,
			id, i, it.Title, it.URL, it.Description, it.Icon); err != nil {
			return nil, err
		}
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return s.Get(ctx, id)
}

// Update saves an edit. Buttons that keep their id are updated in place, so
// their click history stays attached; buttons no longer submitted are removed.
func (s *Store) Update(ctx context.Context, id int64, in Input) error {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	res, err := tx.ExecContext(ctx, `UPDATE link_pages SET slug=?, name=?, brand=?, theme=?, accent=?, title=?, subtitle=?, show_urls=?, show_socials=?, updated_at=? WHERE id=?`,
		in.Slug, in.Name, in.Brand, in.Theme, in.Accent, in.Title, in.Subtitle, b2i(in.ShowURLs), b2i(in.ShowSocials), db.TS(s.Now()), id)
	if err != nil {
		if isSlugClash(err) {
			return ErrSlugTaken
		}
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return ErrNotFound
	}
	owned := map[int64]bool{}
	rows, err := tx.QueryContext(ctx, `SELECT id FROM link_page_items WHERE page_id = ?`, id)
	if err != nil {
		return err
	}
	for rows.Next() {
		var iid int64
		rows.Scan(&iid)
		owned[iid] = true
	}
	rows.Close()
	keep := map[int64]bool{}
	for i, it := range in.Items {
		if it.ID > 0 && owned[it.ID] { // only this page's own buttons can be updated
			if _, err := tx.ExecContext(ctx, `UPDATE link_page_items SET position=?, title=?, url=?, description=?, icon=? WHERE id=? AND page_id=?`,
				i, it.Title, it.URL, it.Description, it.Icon, it.ID, id); err != nil {
				return err
			}
			keep[it.ID] = true
			continue
		}
		r, err := tx.ExecContext(ctx, `INSERT INTO link_page_items (page_id, position, title, url, description, icon) VALUES (?, ?, ?, ?, ?, ?)`,
			id, i, it.Title, it.URL, it.Description, it.Icon)
		if err != nil {
			return err
		}
		nid, _ := r.LastInsertId()
		keep[nid] = true
	}
	for iid := range owned {
		if !keep[iid] {
			if _, err := tx.ExecContext(ctx, `DELETE FROM link_page_items WHERE id = ? AND page_id = ?`, iid, id); err != nil {
				return err
			}
		}
	}
	return tx.Commit()
}

func (s *Store) SetEnabled(ctx context.Context, id int64, enabled bool) error {
	res, err := s.DB.ExecContext(ctx, `UPDATE link_pages SET enabled=?, updated_at=? WHERE id=?`, b2i(enabled), db.TS(s.Now()), id)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return ErrNotFound
	}
	return nil
}

// Delete removes a page, its buttons and its history.
func (s *Store) Delete(ctx context.Context, id int64) error {
	res, err := s.DB.ExecContext(ctx, `DELETE FROM link_pages WHERE id = ?`, id)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return ErrNotFound
	}
	return nil
}

// ItemOf returns one button of a page.
func (s *Store) ItemOf(ctx context.Context, pageID, itemID int64) (Item, error) {
	var it Item
	err := s.DB.QueryRowContext(ctx, `SELECT id, position, title, url, description, icon FROM link_page_items WHERE id = ? AND page_id = ?`, itemID, pageID).
		Scan(&it.ID, &it.Position, &it.Title, &it.URL, &it.Description, &it.Icon)
	if errors.Is(err, sql.ErrNoRows) {
		return it, ErrNotFound
	}
	return it, err
}

// Example returns suggested starting buttons for a brand.
func Example(brandID string) []ItemInput {
	b, ok := BrandByID(brandID)
	if !ok {
		return nil
	}
	out := make([]ItemInput, len(b.ExampleLinks))
	copy(out, b.ExampleLinks)
	return out
}
