package auth

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/bcrypt"

	qrtrack "github.com/BlakeUK/Blake-AI-Chatbot/qrcode"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

type env struct {
	svc *Service
	now *time.Time
	db  *sql.DB
}

func setup(t *testing.T) *env {
	t.Helper()
	d, err := db.Open(filepath.Join(t.TempDir(), "t.db"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { d.Close() })
	if err := db.Migrate(context.Background(), d, qrtrack.Migrations, "migrations"); err != nil {
		t.Fatal(err)
	}
	now := time.Date(2026, 10, 1, 9, 0, 0, 0, time.UTC)
	s := New(d, []byte("test-key-test-key-test-key-12345"))
	s.Cost = bcrypt.MinCost
	s.Now = func() time.Time { return now }
	return &env{svc: s, now: &now, db: d}
}

const seedPW = "initial-pw"

func (e *env) seed(t *testing.T) {
	t.Helper()
	ok, err := e.svc.Seed(context.Background(), "admin", seedPW)
	if err != nil || !ok {
		t.Fatalf("seed: %v %v", ok, err)
	}
}

func TestSeedOnlyOnceAndHashesPassword(t *testing.T) {
	e := setup(t)
	e.seed(t)
	if ok, _ := e.svc.Seed(context.Background(), "admin", "different-pw"); ok {
		t.Error("Seed ran twice")
	}
	var hash string
	var must int
	e.db.QueryRow(`SELECT password_hash, must_change_password FROM users`).Scan(&hash, &must)
	if strings.Contains(hash, seedPW) || !strings.HasPrefix(hash, "$2") {
		t.Errorf("password not stored as a bcrypt hash: %q", hash)
	}
	if must != 1 {
		t.Error("seeded user must be flagged must_change_password")
	}
}

func TestLoginGenericErrorAndSessionLifecycle(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()

	if _, err := e.svc.Login(ctx, "admin", "wrong", "198.51.100.1"); !errors.Is(err, ErrInvalid) {
		t.Fatalf("wrong password: %v", err)
	}
	if _, err := e.svc.Login(ctx, "nobody", "wrong", "198.51.100.1"); !errors.Is(err, ErrInvalid) {
		t.Fatalf("unknown user: %v", err)
	}
	if ErrInvalid.Error() != "invalid username or password" {
		t.Error("error message must not distinguish user from password")
	}

	tok, err := e.svc.Login(ctx, "admin", seedPW, "198.51.100.1")
	if err != nil {
		t.Fatal(err)
	}
	var stored string
	e.db.QueryRow(`SELECT token_hash FROM sessions`).Scan(&stored)
	if stored == tok || len(stored) != 64 {
		t.Error("session token must be stored only as a SHA-256 hash")
	}
	sess, err := e.svc.Authenticate(ctx, tok)
	if err != nil || sess.User.Username != "admin" || !sess.User.MustChange || sess.CSRF == "" {
		t.Fatalf("authenticate: %v %+v", err, sess)
	}

	// idle expiry: 12h of silence kills the session...
	*e.now = e.now.Add(12*time.Hour + time.Minute)
	if _, err := e.svc.Authenticate(ctx, tok); !errors.Is(err, ErrNoSession) {
		t.Errorf("idle session still valid: %v", err)
	}
	// ...and the row is gone
	var n int
	e.db.QueryRow(`SELECT COUNT(*) FROM sessions`).Scan(&n)
	if n != 0 {
		t.Error("expired session row not deleted")
	}
}

func TestSessionSlidingIdleButAbsoluteCap(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()
	tok, _ := e.svc.Login(ctx, "admin", seedPW, "198.51.100.2")
	// Activity every 10h keeps the session alive past 12h of wall time...
	for i := 0; i < 5; i++ {
		*e.now = e.now.Add(10 * time.Hour)
		if _, err := e.svc.Authenticate(ctx, tok); err != nil {
			t.Fatalf("active session expired at step %d: %v", i, err)
		}
	}
	// ...but 7 days after login it dies regardless of activity.
	*e.now = e.now.Add(10 * time.Hour) // now 60h in
	for e.now.Sub(time.Date(2026, 10, 1, 9, 0, 0, 0, time.UTC)) < 7*24*time.Hour-10*time.Hour {
		*e.now = e.now.Add(10 * time.Hour)
		e.svc.Authenticate(ctx, tok)
	}
	*e.now = time.Date(2026, 10, 1, 9, 0, 0, 0, time.UTC).Add(7*24*time.Hour + time.Minute)
	if _, err := e.svc.Authenticate(ctx, tok); !errors.Is(err, ErrNoSession) {
		t.Errorf("session outlived the 7-day absolute limit: %v", err)
	}
}

func TestLogoutInvalidatesServerSide(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()
	tok, _ := e.svc.Login(ctx, "admin", seedPW, "198.51.100.3")
	e.svc.Logout(ctx, tok)
	if _, err := e.svc.Authenticate(ctx, tok); !errors.Is(err, ErrNoSession) {
		t.Errorf("token still valid after logout: %v", err)
	}
}

func TestLockout(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()
	ip := "198.51.100.9"

	for i := 0; i < 4; i++ {
		if _, err := e.svc.Login(ctx, "admin", "bad", ip); !errors.Is(err, ErrInvalid) {
			t.Fatalf("attempt %d: %v", i, err)
		}
		*e.now = e.now.Add(time.Minute)
	}
	// 4 failures: the correct password still works and clears the counter
	if _, err := e.svc.Login(ctx, "admin", seedPW, ip); err != nil {
		t.Fatalf("login after 4 failures should succeed: %v", err)
	}
	for i := 0; i < 5; i++ {
		e.svc.Login(ctx, "admin", "bad", ip)
		*e.now = e.now.Add(time.Minute)
	}
	// 5th failure happened 1 minute ago: locked, even for the correct password
	var le *LockedError
	if _, err := e.svc.Login(ctx, "admin", seedPW, ip); !errors.As(err, &le) {
		t.Fatalf("expected lockout, got %v", err)
	}
	// another address is unaffected
	if _, err := e.svc.Login(ctx, "admin", seedPW, "198.51.100.10"); err != nil {
		t.Errorf("lockout leaked to another IP: %v", err)
	}
	// attempts while locked must not extend the lock
	until := le.Until
	*e.now = e.now.Add(5 * time.Minute)
	e.svc.Login(ctx, "admin", seedPW, ip)
	if _, err := e.svc.Login(ctx, "admin", seedPW, ip); !errors.As(err, &le) || !le.Until.Equal(until) {
		t.Errorf("lock window moved: %v vs %v", le.Until, until)
	}
	// 15 minutes after the last failure the lock lifts
	*e.now = until.Add(time.Second)
	if _, err := e.svc.Login(ctx, "admin", seedPW, ip); err != nil {
		t.Errorf("still locked after the window: %v", err)
	}
}

func TestLockoutNeedsFailuresWithinWindow(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()
	// 5 failures spread over 40 minutes must not lock (never 5 inside 15 min)
	for i := 0; i < 5; i++ {
		e.svc.Login(ctx, "admin", "bad", "198.51.100.20")
		*e.now = e.now.Add(10 * time.Minute)
	}
	if _, err := e.svc.Login(ctx, "admin", seedPW, "198.51.100.20"); err != nil {
		t.Errorf("slow failures wrongly locked the address: %v", err)
	}
}

func TestLoginAttemptsDoNotStoreRawIP(t *testing.T) {
	e := setup(t)
	e.seed(t)
	e.svc.Login(context.Background(), "admin", "bad", "203.0.113.77")
	var ip string
	e.db.QueryRow(`SELECT ip FROM login_attempts`).Scan(&ip)
	if strings.Contains(ip, "203.0.113.77") || len(ip) != 64 {
		t.Errorf("login_attempts.ip = %q, expected a keyed hash", ip)
	}
}

func TestChangePassword(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()
	t1, _ := e.svc.Login(ctx, "admin", seedPW, "198.51.100.30")
	t2, _ := e.svc.Login(ctx, "admin", seedPW, "198.51.100.31")
	s1, _ := e.svc.Authenticate(ctx, t1)

	cases := []struct {
		name, cur, next string
	}{
		{"wrong current", "nope", "a-very-long-new-password"},
		{"too short", seedPW, "short-pw-11"}, // 11 chars
		{"same as current", seedPW, seedPW},
		{"too long for bcrypt", seedPW, strings.Repeat("x", 73)},
	}
	for _, c := range cases {
		if err := e.svc.ChangePassword(ctx, s1.User.ID, c.cur, c.next, s1.ID); err == nil {
			t.Errorf("%s: accepted", c.name)
		}
	}
	if err := e.svc.ChangePassword(ctx, s1.User.ID, seedPW, "exactly12chr", s1.ID); err != nil {
		t.Fatalf("12-char password rejected: %v", err)
	}
	if _, err := e.svc.Authenticate(ctx, t1); err != nil {
		t.Error("the changing session must survive")
	}
	if _, err := e.svc.Authenticate(ctx, t2); !errors.Is(err, ErrNoSession) {
		t.Error("other sessions must be revoked on password change")
	}
	s1b, _ := e.svc.Authenticate(ctx, t1)
	if s1b.User.MustChange {
		t.Error("must_change_password not cleared")
	}
	if _, err := e.svc.Login(ctx, "admin", seedPW, "198.51.100.32"); !errors.Is(err, ErrInvalid) {
		t.Error("old password still works")
	}
	if _, err := e.svc.Login(ctx, "admin", "exactly12chr", "198.51.100.32"); err != nil {
		t.Errorf("new password rejected: %v", err)
	}
	_ = fmt.Sprint
}

func TestUsernameIsCaseInsensitiveButPasswordIsNot(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()
	for _, u := range []string{"admin", "Admin", "ADMIN"} {
		if _, err := e.svc.Login(ctx, u, seedPW, "198.51.100.60"); err != nil {
			t.Errorf("username %q refused: %v", u, err)
		}
	}
	if _, err := e.svc.Login(ctx, "admin", "INITIAL-PW", "198.51.100.61"); !errors.Is(err, ErrInvalid) {
		t.Errorf("password must stay case-sensitive, got %v", err)
	}
}

// ---------- user management ----------

func policyMsg(err error) string {
	var pe *PolicyError
	if errors.As(err, &pe) {
		return pe.Msg
	}
	return ""
}

func TestCreateUserRulesAndFirstLogin(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()

	for name, user := range map[string]string{"too short": "ab", "starts with dash": "-bob", "space": "bo b", "slash": "a/b/c", "too long": strings.Repeat("a", 33), "empty": ""} {
		if _, err := e.svc.CreateUser(ctx, user, "a-long-enough-pw1", RoleMember); policyMsg(err) == "" {
			t.Errorf("%s: user name %q accepted", name, user)
		}
	}
	if _, err := e.svc.CreateUser(ctx, "alice", "short", RoleMember); policyMsg(err) == "" {
		t.Error("short password accepted")
	}
	if _, err := e.svc.CreateUser(ctx, "alice", "a-long-enough-pw1", "superuser"); policyMsg(err) == "" {
		t.Error("unknown role accepted")
	}
	id, err := e.svc.CreateUser(ctx, "alice", "a-long-enough-pw1", RoleMember)
	if err != nil || id == 0 {
		t.Fatalf("create: %v", err)
	}
	// names that differ only by case are the same person
	for _, dup := range []string{"alice", "Alice", "ALICE", "Admin", "ADMIN"} {
		if _, err := e.svc.CreateUser(ctx, dup, "a-long-enough-pw1", RoleMember); !strings.Contains(policyMsg(err), "already taken") {
			t.Errorf("duplicate %q: %v", dup, err)
		}
	}
	// the new person signs in with the initial password and is forced to change it
	tok, err := e.svc.Login(ctx, "alice", "a-long-enough-pw1", "198.51.100.80")
	if err != nil {
		t.Fatal(err)
	}
	s, _ := e.svc.Authenticate(ctx, tok)
	if !s.User.MustChange || s.User.Role != RoleMember || s.User.IsAdmin() {
		t.Errorf("new user session: %+v", s.User)
	}
	// stored as a hash only
	var hash string
	e.db.QueryRow(`SELECT password_hash FROM users WHERE username='alice'`).Scan(&hash)
	if strings.Contains(hash, "a-long-enough-pw1") {
		t.Error("initial password stored in plaintext")
	}
}

func TestLastAdminCanNeverBeRemovedOrDemoted(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()
	var adminID int64
	e.db.QueryRow(`SELECT id FROM users WHERE username='admin'`).Scan(&adminID)
	memberID, _ := e.svc.CreateUser(ctx, "mallory", "a-long-enough-pw1", RoleMember)

	if _, err := e.svc.DeleteUser(ctx, adminID, adminID); !strings.Contains(policyMsg(err), "own account") {
		t.Errorf("removing yourself: %v", err)
	}
	// a member (hypothetically acting) still cannot remove the only admin
	if _, err := e.svc.DeleteUser(ctx, adminID, memberID); !strings.Contains(policyMsg(err), "last admin") {
		t.Errorf("removing the last admin: %v", err)
	}
	if _, err := e.svc.SetRole(ctx, adminID, RoleMember); !strings.Contains(policyMsg(err), "last admin") {
		t.Errorf("demoting the last admin: %v", err)
	}
	// with a second admin, either can be removed or demoted, but never both
	second, _ := e.svc.CreateUser(ctx, "bob", "a-long-enough-pw1", RoleAdmin)
	if _, err := e.svc.SetRole(ctx, second, RoleMember); err != nil {
		t.Fatalf("demote one of two admins: %v", err)
	}
	if _, err := e.svc.SetRole(ctx, second, RoleAdmin); err != nil {
		t.Fatal(err)
	}
	if name, err := e.svc.DeleteUser(ctx, second, adminID); err != nil || name != "bob" {
		t.Fatalf("remove second admin: %q %v", name, err)
	}
	if _, err := e.svc.DeleteUser(ctx, adminID, memberID); !strings.Contains(policyMsg(err), "last admin") {
		t.Errorf("admin count must be back to one: %v", err)
	}
	if _, err := e.svc.DeleteUser(ctx, 9999, adminID); !strings.Contains(policyMsg(err), "no longer exists") {
		t.Errorf("missing user: %v", err)
	}
}

func TestRemoveResetAndDemoteEndSessions(t *testing.T) {
	e := setup(t)
	e.seed(t)
	ctx := context.Background()
	login := func(user, pw string) string {
		tok, err := e.svc.Login(ctx, user, pw, "198.51.100.90")
		if err != nil {
			t.Fatalf("login %s: %v", user, err)
		}
		return tok
	}
	idOf := func(name string) int64 {
		var id int64
		e.db.QueryRow(`SELECT id FROM users WHERE username=?`, name).Scan(&id)
		return id
	}
	e.svc.CreateUser(ctx, "carol", "carols-start-pw-1", RoleAdmin)
	e.svc.CreateUser(ctx, "dave", "daves-start-pw-1", RoleMember)
	e.svc.CreateUser(ctx, "erin", "erins-start-pw-1", RoleAdmin)
	carol, dave, erin := login("carol", "carols-start-pw-1"), login("dave", "daves-start-pw-1"), login("erin", "erins-start-pw-1")

	// reset: old password dead, session gone, must change again
	if _, err := e.svc.ResetPassword(ctx, idOf("dave"), "short"); policyMsg(err) == "" {
		t.Error("reset accepted a short password")
	}
	if name, err := e.svc.ResetPassword(ctx, idOf("dave"), "daves-NEW-temp-pw-2"); err != nil || name != "dave" {
		t.Fatalf("reset: %q %v", name, err)
	}
	if _, err := e.svc.Authenticate(ctx, dave); !errors.Is(err, ErrNoSession) {
		t.Error("reset must sign the person out everywhere")
	}
	if _, err := e.svc.Login(ctx, "dave", "daves-start-pw-1", "198.51.100.91"); !errors.Is(err, ErrInvalid) {
		t.Error("old password still works after reset")
	}
	tok := login("dave", "daves-NEW-temp-pw-2")
	if s, _ := e.svc.Authenticate(ctx, tok); !s.User.MustChange {
		t.Error("reset password must force a change at next sign-in")
	}

	// demote: powers of an old session are not kept
	if _, err := e.svc.SetRole(ctx, idOf("carol"), RoleMember); err != nil {
		t.Fatal(err)
	}
	if _, err := e.svc.Authenticate(ctx, carol); !errors.Is(err, ErrNoSession) {
		t.Error("demoted user kept an old session")
	}

	// remove: their sessions disappear with them
	if _, err := e.svc.DeleteUser(ctx, idOf("erin"), idOf("admin")); err != nil {
		t.Fatal(err)
	}
	if _, err := e.svc.Authenticate(ctx, erin); !errors.Is(err, ErrNoSession) {
		t.Error("removed user's session still valid")
	}
	if _, err := e.svc.Login(ctx, "erin", "erins-start-pw-1", "198.51.100.92"); !errors.Is(err, ErrInvalid) {
		t.Error("removed user can still sign in")
	}
	list, err := e.svc.ListUsers(ctx)
	if err != nil || len(list) != 3 { // admin, carol, dave
		t.Fatalf("list: %v %d", err, len(list))
	}
}

func TestGeneratedPasswordsMeetPolicyAndAreUnambiguous(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 500; i++ {
		p, err := GeneratePassword()
		if err != nil {
			t.Fatal(err)
		}
		if err := CheckNewPassword(p); err != nil || len(p) != 16 {
			t.Fatalf("generated password %q fails policy: %v", p, err)
		}
		if strings.ContainsAny(p, "0O1lI") {
			t.Fatalf("look-alike character in %q", p)
		}
		if seen[p] {
			t.Fatal("duplicate generated password")
		}
		seen[p] = true
	}
}

func TestAuditTrail(t *testing.T) {
	e := setup(t)
	ctx := context.Background()
	e.svc.Audit(ctx, "admin", "user.create", "alice", "role=member")
	*e.now = e.now.Add(time.Minute)
	e.svc.Audit(ctx, "admin", "user.delete", "alice", "")
	rows, err := e.svc.RecentAudit(ctx, 10)
	if err != nil || len(rows) != 2 || rows[0].Action != "user.delete" || rows[1].Detail != "role=member" {
		t.Fatalf("audit rows: %v %+v", err, rows)
	}
	*e.now = e.now.Add(48 * time.Hour)
	e.svc.PurgeAudit(ctx, e.now.Add(-24*time.Hour))
	if rows, _ := e.svc.RecentAudit(ctx, 10); len(rows) != 0 {
		t.Errorf("old audit rows not purged: %d", len(rows))
	}
}
