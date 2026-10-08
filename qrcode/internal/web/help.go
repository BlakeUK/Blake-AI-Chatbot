package web

import (
	"net/http"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/qrtypes"
)

// helpInfo is the short "What this is / How to use it" box shown at the top of
// a page, with a link to the matching section of the full Help page.
type helpInfo struct {
	What, How, Anchor string
}

// pageHelp is keyed by template name. render() attaches it automatically, so
// every page explains itself without each handler having to remember to.
var pageHelp = map[string]helpInfo{
	"links": {
		What:   "Every QR code you have made, newest first. Each row shows its type, whether it is static (untracked) or dynamic (tracked), its campaign, where it sends people and how many times it has been scanned.",
		How:    "Click a name to open the code, download it and see its statistics. Click a campaign to show only that campaign. Make a new one with New QR code.",
		Anchor: "codes",
	},
	"link_choose": {
		What:   "The first step of making a QR code. A dynamic code is tracked and can be changed after printing. A static code holds its content itself, is never tracked, and works for ever.",
		How:    "Read the comparison, then click a type. If you are unsure, choose dynamic and Website link: it is the most flexible.",
		Anchor: "static-dynamic",
	},
	"link_form": {
		What:   "Where you say what the code does and how it looks. The picture on the right is a live preview.",
		How:    "Work down the page: name it, enter what it should do, choose the look, then press Create. Always scan a printed test before you use a code for real.",
		Anchor: "creating",
	},
	"link_detail": {
		What:   "One QR code: its picture, where it goes and, for a dynamic code, every scan and what the statistics say.",
		How:    "Download the picture from the QR code panel. Use Edit to change anything, Disable to switch a code off without deleting it, and read the numbers below to see how it is doing.",
		Anchor: "reading-stats",
	},
	"campaigns": {
		What:   "A campaign is a label for where a code is used, such as Leaflet, Exhibition stand or Product box. This page adds up the scans of every code in each campaign.",
		How:    "Give a code a campaign when you create or edit it. Click a campaign name to list just its codes, and use Export to take every scan into a spreadsheet.",
		Anchor: "campaigns",
	},
	"bulk": {
		What:   "Makes many tracked QR codes at once from a spreadsheet: one code for each row, returned as a ZIP of pictures plus a list of their addresses.",
		How:    "Save your spreadsheet as CSV with the headings label and url (and optionally campaign), upload it, choose the options and press the button. Either every row is made or none is.",
		Anchor: "bulk",
	},
	"templates": {
		What:   "Saved designs: reusable looks (colours, dot and corner styles, frame, text and logo) so that every code you make can match your brand.",
		How:    "Press New design to make one, or Edit to change one. To use one, choose Use for a new code, or pick it in the Design box when making codes in bulk.",
		Anchor: "save-designs",
	},
	"template_form": {
		What:   "Makes or changes a saved design without making a QR code.",
		How:    "Set the look, type a name and press Save design. Saving under the name of an existing design replaces it.",
		Anchor: "save-designs",
	},
	"users": {
		What:   "The people who can sign in to this system. Admins can manage QR codes and people; members can manage QR codes only.",
		How:    "Add a person with a name, a role and a temporary password (or leave it blank to have one made). Use Reset password if someone is locked out. The activity log shows who did what.",
		Anchor: "users",
	},
	"password": {
		What:   "Where you choose your own password. You must do this the first time you sign in, and any time a password has been reset for you.",
		How:    "Type the temporary or current password, then your new one twice. It needs at least 12 characters; a few random words is a good choice.",
		Anchor: "account",
	},
}

type helpPage struct {
	Types   []qrtypes.Spec
	IsAdmin bool
}

func (s *Server) help(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	s.render(w, http.StatusOK, "help", s.page(sess, "Help", helpPage{Types: qrtypes.All(), IsAdmin: sess.User.IsAdmin()}))
}
