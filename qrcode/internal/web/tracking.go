package web

import "fmt"

// This file is the single source of truth for "what does this system record?".
// The Help page and the PDF manual both show it, and a test compares it with
// the real database columns, so a field or table cannot be added without
// being described here.

// trackRow describes one thing a person might do, and what it leaves behind.
type trackRow struct {
	Feature     string
	Recorded    string
	NotRecorded string
	Who         string
	Kept        string
}

// fieldDoc describes one stored column in plain English.
type fieldDoc struct{ Column, Meaning string }

// tableDoc describes one table that holds information about people's activity.
type tableDoc struct {
	Table  string
	Title  string
	Note   string
	Fields []fieldDoc
}

// trackingFeatures lists, for each action, what is and is not recorded.
func trackingFeatures(retentionDays int, fullIP bool) []trackRow {
	kept := fmt.Sprintf("%d days, then deleted automatically.", retentionDays)
	ipNote := "Their internet (IP) address is not stored: it is used only to work out the approximate place and the scrambled token, then discarded."
	if fullIP {
		ipNote = "Their full internet (IP) address IS stored with each scan, because full-address storage is switched on for this system."
	}
	return []trackRow{
		{
			Feature:     "Someone scans a dynamic QR code",
			Recorded:    "The exact time. Which code and campaign. Where that visitor was sent. An approximate country, region and town (worked out from their internet address). Device type, operating system and browser name. Their language setting. The name of the website or app they came from. A scrambled visitor token. Whether it looks like a robot. The browser's full identification text (its \"user agent\", which can include the phone model and software versions). Only while the code's tracking window is active.",
			NotRecorded: ipNote + " Also not recorded: names, email addresses, tracking cookies (none are used), the full address of the page they came from (only its website name), or anything they type.",
			Who:         "Every signed-in user, admins and members. Never visitors.",
			Kept:        kept,
		},
		{
			Feature:     "Someone scans a static QR code",
			Recorded:    "Nothing. A static code holds its content itself, so scanning it never contacts this system. (What you typed to make the code, such as a Wi-Fi password, is stored here so you can rename and re-download it.)",
			NotRecorded: "Everything about the person scanning.",
			Who:         "Not applicable.",
			Kept:        "The code's details are kept until you delete the code.",
		},
		{
			Feature:     "Someone opens a password-protected code",
			Recorded:    "A scan is recorded only after the right password is entered, and then like any other scan. The password itself is stored only in scrambled (hashed) form.",
			NotRecorded: "Opening the password page and wrong guesses are not recorded as scans. Wrong guesses are only slowed down (six a minute), using a short-lived count held in memory. The one cookie a visitor can ever receive is a security token on this password page: it lasts 30 minutes, makes the form safe to submit, and holds nothing about the visitor.",
			Who:         "Every signed-in user.",
			Kept:        kept,
		},
		{
			Feature:     "Someone opens a link page",
			Recorded:    "The time. Which page. Whether they arrived from one of its QR codes or another way. An approximate country. Device type, operating system and browser name. Their language setting. The name of the website or app they came from. A scrambled visitor token. Whether it looks like a robot. Whether it is their first visit that day.",
			NotRecorded: "Their internet address, region or town, the browser's full identification text, names, email addresses, cookies of any kind, or the full address of the page they came from.",
			Who:         "Every signed-in user.",
			Kept:        kept,
		},
		{
			Feature:     "Someone presses a web button on a link page",
			Recorded:    "The same details as opening the page, plus which button was pressed.",
			NotRecorded: "Where they go next, once they have left this system.",
			Who:         "Every signed-in user.",
			Kept:        kept,
		},
		{
			Feature:     "Someone presses an email or phone button on a link page",
			Recorded:    "Nothing. These open the visitor's own email or phone app directly.",
			NotRecorded: "Everything. They cannot be counted.",
			Who:         "Not applicable.",
			Kept:        "Not applicable.",
		},
		{
			Feature:     "You use the live preview, or the page and QR design previews",
			Recorded:    "Nothing. Nothing is saved or counted, and buttons in a preview open in a new tab.",
			NotRecorded: "Everything.",
			Who:         "Not applicable.",
			Kept:        "Not applicable.",
		},
		{
			Feature:     "Someone signs in to this admin",
			Recorded:    "For each attempt: the time, whether it worked, and a scrambled, keyed form of the address it came from, used only to stop password guessing. For each sign-in: a scrambled session token, which user, and when it started, was last used and expires.",
			NotRecorded: "The user name typed in a failed attempt, the password (only a scrambled hash of each user's own password exists), and the visitor's real address.",
			Who:         "Nobody sees these in the admin. They work in the background.",
			Kept:        "Attempts: 24 hours. Sessions: until they expire (12 hours without use, 7 days at most) or you sign out.",
		},
		{
			Feature:     "You or a colleague do something in the admin",
			Recorded:    "The activity log: who (user name), what (for example created a QR code, saved a design, reset a password, created a page), which item, and when.",
			NotRecorded: "Passwords, and anything typed into a form beyond the name of the item.",
			Who:         "Admins, on the Users page.",
			Kept:        kept,
		},
		{
			Feature:     "Someone downloads an export (CSV)",
			Recorded:    "The download itself is not logged. The file contains every field recorded for each scan, including the scrambled visitor token and the browser's full identification text. The address column is empty unless full-address storage is on.",
			NotRecorded: "Not applicable.",
			Who:         "Every signed-in user can download exports, so treat the files as personal data.",
			Kept:        "The file is yours once downloaded: store and delete it as you would any personal data.",
		},
		{
			Feature:     "Any request to the website, in the web server's own log",
			Recorded:    "The time, the address requested (including anything after a question mark), the response code and size, the browser's identification text and the language setting.",
			NotRecorded: "Visitor internet addresses, cookies, passwords and the address of the page the visitor came from are removed before anything is written.",
			Who:         "Only people with access to the server.",
			Kept:        "By the server's own system log, under the server's settings. This application's retention period does not control it.",
		},
	}
}

// trackedTables describes every table that holds information about activity.
var trackedTables = []tableDoc{
	{
		Table: "scans", Title: "Each scan of a dynamic QR code",
		Note: "One row per scan. Shown in a code's statistics, and in its CSV export.",
		Fields: []fieldDoc{
			{"id", "An internal row number."},
			{"link_id", "Which QR code was scanned."},
			{"scanned_at", "The exact time, in UTC."},
			{"ip_hash", "A scrambled visitor token: a one-way scramble of the internet address and a secret that changes every day. It cannot be turned back into an address, and cannot be matched from one day to the next."},
			{"ip", "The visitor's actual internet address. Empty unless full-address storage has been switched on by the system administrator."},
			{"country", "Two-letter country code, estimated from the internet address."},
			{"country_name", "Country name, estimated."},
			{"region", "Region or county, estimated. Often wrong on mobile networks."},
			{"city", "Town or city, estimated. Treat as a guide only."},
			{"language", "The language setting of the visitor's phone or browser, such as en-GB."},
			{"destination_url", "Where this visitor was actually sent (smart routing can differ per visitor)."},
			{"device_class", "mobile, tablet, desktop, or bot."},
			{"os", "Operating system name, such as iOS or Android."},
			{"browser", "Browser name, such as Safari or Chrome."},
			{"referer_host", "The name of the website or app they came from (never the full address)."},
			{"user_agent", "The browser's full identification text, up to 512 characters. It can include the phone model and software versions."},
			{"is_bot", "Whether it looks like a robot or link-preview fetcher. Robots are left out of the headline numbers."},
			{"is_unique", "Whether this is the first time that scrambled token was seen on this code in 24 hours."},
		},
	},
	{
		Table: "page_events", Title: "Each view of a link page and each button press",
		Note: "One row per view or click. Shown in a link page's statistics. Link pages record less than QR scans: no region, town or browser identification text.",
		Fields: []fieldDoc{
			{"id", "An internal row number."},
			{"page_id", "Which link page."},
			{"item_id", "Which button was pressed (0 for a page view)."},
			{"at", "The exact time, in UTC."},
			{"kind", "view or click."},
			{"source", "qr if they arrived through one of the page's QR codes, otherwise direct."},
			{"ip_hash", "A scrambled visitor token, as for scans."},
			{"country", "Two-letter country code, estimated."},
			{"country_name", "Country name, estimated."},
			{"device_class", "mobile, tablet, desktop, or bot."},
			{"os", "Operating system name."},
			{"browser", "Browser name."},
			{"language", "The language setting of the visitor's phone or browser."},
			{"referer_host", "The name of the website or app they came from."},
			{"is_bot", "Whether it looks like a robot."},
			{"is_unique", "For a view: first time that scrambled token was seen on this page in 24 hours."},
		},
	},
	{
		Table: "login_attempts", Title: "Sign-in attempts",
		Note: "Kept for 24 hours. Used only to lock out repeated wrong guesses.",
		Fields: []fieldDoc{
			{"ip", "A scrambled, keyed form of the address the attempt came from (never the address itself)."},
			{"attempted_at", "The time of the attempt."},
			{"success", "Whether the attempt worked."},
		},
	},
	{
		Table: "sessions", Title: "Signed-in sessions",
		Note: "One row per browser that is signed in.",
		Fields: []fieldDoc{
			{"id", "An internal row number."},
			{"user_id", "Which user."},
			{"token_hash", "A scrambled form of the secret held by the browser. The secret itself is not stored."},
			{"csrf_token", "A random value that protects forms from being submitted by other websites."},
			{"created_at", "When the session started."},
			{"last_seen_at", "When it was last used (a session ends after 12 hours without use)."},
			{"expires_at", "The latest time it can last (7 days after it started)."},
		},
	},
	{
		Table: "audit_log", Title: "The activity log",
		Note: "Shown to admins on the Users page.",
		Fields: []fieldDoc{
			{"id", "An internal row number."},
			{"at", "When it happened."},
			{"actor", "Who did it: a user name (or \"server\" for a recovery done on the server)."},
			{"action", "What they did, such as qr.create or user.reset_password."},
			{"target", "What it was done to: a code, a page, a design or a user name."},
			{"detail", "A short note. Never a password."},
		},
	},
	{
		Table: "daily_salts", Title: "Daily secrets",
		Note: "Used to make the scrambled visitor tokens. Deleted after 3 days, which is why tokens cannot be matched across days.",
		Fields: []fieldDoc{
			{"day", "Which day the secret is for."},
			{"salt", "The random secret."},
		},
	},
	{
		Table: "users", Title: "User accounts",
		Note: "The people who can sign in.",
		Fields: []fieldDoc{
			{"id", "An internal row number."},
			{"username", "The sign-in name."},
			{"password_hash", "A scrambled (bcrypt) form of the password. The password itself cannot be recovered, by anyone."},
			{"must_change_password", "Whether they must choose a new password at next sign-in."},
			{"role", "admin or member."},
			{"created_at", "When the account was made."},
			{"updated_at", "When it was last changed."},
		},
	},
}

// otherTables are tables that hold what you create (codes, pages, designs) or
// system housekeeping, and nothing about visitors. A new table must be added
// either here or to trackedTables; a test insists.
var otherTables = map[string]string{
	"links":             "Your QR codes: name, campaign, type, destination, design, limits and the scrambled password hash.",
	"link_rules":        "Smart-routing rules of a QR code.",
	"qr_templates":      "Saved designs.",
	"link_pages":        "Your link pages.",
	"link_page_items":   "The buttons on your link pages.",
	"settings":          "Internal settings such as a secret key for scrambling.",
	"schema_migrations": "Which database upgrades have been applied.",
	"sqlite_sequence":   "SQLite's own row counters.",
}
