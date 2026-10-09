package web

import (
	"io/fs"
	"net/http"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qrtypes"
)

// helpInfo is the short "What this is / How to use it" box shown at the top of
// a page, with a link to the matching section of the full Help page.
type helpInfo struct {
	What, How, Tracked, Anchor string // Tracked says what, if anything, is recorded by or about this page
}

// pageHelp is keyed by template name. render() attaches it automatically, so
// every page explains itself without each handler having to remember to.
var pageHelp = map[string]helpInfo{
	"links": {
		What:    "Every QR code you have made, newest first. Each row shows its type, whether it is static (untracked) or dynamic (tracked), its campaign, where it sends people and how many times it has been scanned.",
		How:     "Click a name to open the code, download it and see its statistics. Click a campaign to show only that campaign. Make a new one with New QR code.",
		Tracked: "Nothing about visitors is recorded by this page. The scan counts come from visits to dynamic codes; static codes are never tracked.",
		Anchor:  "codes",
	},
	"link_choose": {
		What:    "The first step of making a QR code. A dynamic code is tracked and can be changed after printing. A static code holds its content itself, is never tracked, and works for ever.",
		How:     "Read the comparison, then click a type. If you are unsure, choose dynamic and Website link: it is the most flexible.",
		Tracked: "A dynamic code records each scan: time, approximate place, device, language and a scrambled visitor token. A static code records nothing.",
		Anchor:  "static-dynamic",
	},
	"link_form": {
		What:    "Where you say what the code does and how it looks. The picture on the right is a live preview.",
		How:     "Work down the page: name it, enter what it should do, choose the look, then press Create. Always scan a printed test before you use a code for real.",
		Tracked: "A dynamic code records every scan while its tracking window is active (the full list is in Help). A static code records nothing. Saving is written to the activity log with your user name.",
		Anchor:  "creating",
	},
	"link_detail": {
		What:    "One QR code: its picture, where it goes and, for a dynamic code, every scan and what the statistics say.",
		How:     "Download the picture from the QR code panel. Use Edit to change anything, Disable to switch a code off without deleting it, and read the numbers below to see how it is doing.",
		Tracked: "This page only shows what was recorded when people scanned: time, approximate place, device, system, browser, language, where they were sent, referring site name and a scrambled visitor token. Never a name, an email address or an exact internet address.",
		Anchor:  "reading-stats",
	},
	"campaigns": {
		What:    "A campaign is a label for where a code is used, such as Leaflet, Exhibition stand or Product box. This page adds up the scans of every code in each campaign.",
		How:     "Give a code a campaign when you create or edit it. Click a campaign name to list just its codes, and use Export to take every scan into a spreadsheet.",
		Tracked: "Nothing extra. These are totals of the same scan records.",
		Anchor:  "campaigns",
	},
	"bulk": {
		What:    "Makes many tracked QR codes at once from a spreadsheet: one code for each row, returned as a ZIP of pictures plus a list of their addresses.",
		How:     "Save your spreadsheet as CSV with the headings label and url (and optionally campaign), upload it, choose the options and press the button. Either every row is made or none is.",
		Tracked: "Making codes in bulk is written to the activity log (who, and how many). Each code then records scans like any dynamic code.",
		Anchor:  "bulk",
	},
	"templates": {
		What:    "Saved designs: reusable looks (colours, dot and corner styles, frame, text and logo) so that every code you make can match your brand.",
		How:     "Press New design to make one, or Edit to change one. To use one, choose Use for a new code, or pick it in the Design box when making codes in bulk.",
		Tracked: "Saving, editing or deleting a design is written to the activity log. A design holds only the look, never visitor information.",
		Anchor:  "save-designs",
	},
	"template_form": {
		What:    "Makes or changes a saved design without making a QR code.",
		How:     "Set the look, type a name and press Save design. Saving under the name of an existing design replaces it.",
		Tracked: "Saving a design is written to the activity log. Nothing is recorded while you experiment.",
		Anchor:  "save-designs",
	},
	"pages": {
		What:    "Link pages are hosted pages that gather all of a company's links in one place, like Linktree. Each one has the company's logo, a theme, and a list of buttons. You can put the page address in a social profile, or make a QR code that opens it.",
		How:     "Press New link page, or start from an example for Blake UK, VisionPlus or Solwise. Open a page to see how many people viewed it and which buttons they pressed. Views is how many times the page was opened; Clicks is how many times a button on it was pressed (one visit can make several).",
		Tracked: "Each page counts views and button presses. Visitors' internet addresses are never stored, only a scrambled token that changes daily.",
		Anchor:  "link-pages",
	},
	"page_form": {
		What:    "Where you build a link page: choose the company and theme, write the heading, and list the buttons. The phone on the right is a live preview.",
		How:     "Work down the page. Everything you change shows in the preview straight away, but nothing is published until you press the button at the bottom.",
		Tracked: "Nothing is recorded while you edit or use the preview. Saving is written to the activity log.",
		Anchor:  "link-pages",
	},
	"page_detail": {
		What:    "One link page: its public address, the QR codes that open it, and how many people viewed it and pressed each button.",
		How:     "Views count each time the page is opened. Clicks count each press of a button, so one visit can make several, or none. Copy the address to share it, or make a QR code for it. Use Edit to change buttons or theme, and Switch off to take the page down without deleting it.",
		Tracked: "For each view: time, QR code or direct, approximate country, device, system, browser, language, referring site name and a scrambled token. For each click, also which button. Email and phone buttons cannot be tracked.",
		Anchor:  "link-pages",
	},
	"users": {
		What:    "The people who can sign in to this system. Admins can manage QR codes and people; members can manage QR codes only.",
		How:     "Add a person with a name, a role and a temporary password (or leave it blank to have one made). Use Reset password if someone is locked out. The activity log shows who did what.",
		Tracked: "Adding, removing, role changes and password resets are written to the activity log below (who, what, when, never a password). Sign-in attempts are kept in scrambled form for 24 hours to stop password guessing.",
		Anchor:  "users",
	},
	"password": {
		What:    "Where you choose your own password. You must do this the first time you sign in, and any time a password has been reset for you.",
		How:     "Type the temporary or current password, then your new one twice. It needs at least 12 characters; a few random words is a good choice.",
		Tracked: "The new password is stored only as a scrambled hash. Changing it signs out your other sessions.",
		Anchor:  "account",
	},
}

type helpPage struct {
	Types         []qrtypes.Spec
	IsAdmin       bool
	Features      []trackRow
	Tables        []tableDoc
	FullIP        bool
	RetentionDays int
}

func (s *Server) help(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	days := s.cfg.RetentionDays
	if days <= 0 {
		days = 365
	}
	s.render(w, http.StatusOK, "help", s.page(sess, "Help", helpPage{Types: qrtypes.All(), IsAdmin: sess.User.IsAdmin(),
		Features: trackingFeatures(days, s.cfg.StoreFullIP), Tables: trackedTables, FullIP: s.cfg.StoreFullIP, RetentionDays: days}))
}

// manual serves the PDF manual that is built into the binary. It needs a
// sign-in like everything else in the admin.
func (s *Server) manual(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	b, err := fs.ReadFile(s.assets, "web/manual/qrtrack-manual.pdf")
	if err != nil {
		s.errorPage(w, http.StatusNotFound, "Not available", "The PDF manual is not part of this build.")
		return
	}
	w.Header().Set("Content-Type", "application/pdf")
	w.Header().Set("Content-Disposition", `attachment; filename="Blake-UK-QR-Codes-and-Link-Pages-Manual.pdf"`)
	w.Header().Set("Cache-Control", "private, max-age=3600")
	w.Write(b)
}
