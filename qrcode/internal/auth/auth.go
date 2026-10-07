// Package auth implements the single-admin login: bcrypt passwords, opaque
// server-side sessions (only a SHA-256 of the cookie token is stored),
// per-session CSRF tokens and per-IP brute-force lockout.
package auth

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"time"
	"unicode/utf8"

	"golang.org/x/crypto/bcrypt"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

const (
	MinPasswordRunes = 12
	maxPasswordBytes = 72 // bcrypt ignores everything past 72 bytes, so refuse longer input

	MaxFailures     = 5
	FailureWindow   = 15 * time.Minute
	LockoutDuration = 15 * time.Minute
)

var (
	// ErrInvalid is returned for every credential failure, whether the user
	// exists or not, so the response never reveals which half was wrong.
	ErrInvalid   = errors.New("invalid username or password")
	ErrNoSession = errors.New("no valid session")
)

// PolicyError is a rejection whose message is written for the person at the
// keyboard and is safe to show verbatim (password rules, wrong current
// password). Anything that is not a PolicyError is an internal fault.
type PolicyError struct{ Msg string }

func (e *PolicyError) Error() string { return e.Msg }

// LockedError is returned while an address is locked out.
type LockedError struct{ Until time.Time }

func (e *LockedError) Error() string { return "too many failed sign-in attempts" }

type User struct {
	ID         int64
	Username   string
	MustChange bool
}

type Session struct {
	ID   int64
	User User
	CSRF string
}

type Service struct {
	DB      *sql.DB
	Now     func() time.Time
	Cost    int           // bcrypt cost
	Key     []byte        // keys the HMAC used to store login-attempt addresses
	IdleTTL time.Duration // sliding idle timeout
	AbsTTL  time.Duration // hard maximum session age

	dummy []byte // valid hash compared against when the user does not exist
}

func New(d *sql.DB, key []byte) *Service {
	return &Service{DB: d, Now: time.Now, Cost: 12, Key: key, IdleTTL: 12 * time.Hour, AbsTTL: 7 * 24 * time.Hour}
}

func (s *Service) dummyHash() []byte {
	if s.dummy == nil {
		s.dummy, _ = bcrypt.GenerateFromPassword([]byte("not-a-real-password"), s.Cost)
	}
	return s.dummy
}

// CheckNewPassword enforces the password policy.
func CheckNewPassword(pw string) error {
	if utf8.RuneCountInString(pw) < MinPasswordRunes {
		return &PolicyError{fmt.Sprintf("The new password must be at least %d characters.", MinPasswordRunes)}
	}
	if len(pw) > maxPasswordBytes {
		return &PolicyError{fmt.Sprintf("The new password must be at most %d bytes.", maxPasswordBytes)}
	}
	return nil
}

// Seed creates the first admin account, but only if no user exists yet, and
// flags it must-change-password. It stores a bcrypt hash and never the
// plaintext, and never logs it.
func (s *Service) Seed(ctx context.Context, username, password string) (bool, error) {
	if username == "" || password == "" {
		return false, nil
	}
	if len(password) > maxPasswordBytes {
		return false, errors.New("seed password longer than 72 bytes")
	}
	var n int
	if err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM users`).Scan(&n); err != nil {
		return false, err
	}
	if n > 0 {
		return false, nil
	}
	hash, err := bcrypt.GenerateFromPassword([]byte(password), s.Cost)
	if err != nil {
		return false, err
	}
	now := db.TS(s.Now())
	_, err = s.DB.ExecContext(ctx, `INSERT INTO users(username, password_hash, must_change_password, created_at, updated_at)
		VALUES (?, ?, 1, ?, ?)`, username, string(hash), now, now)
	return err == nil, err
}

func (s *Service) ipKey(ip string) string {
	m := hmac.New(sha256.New, s.Key)
	m.Write([]byte(ip))
	return hex.EncodeToString(m.Sum(nil))
}

// LockedUntil reports whether ip is currently locked out. An address is
// locked for LockoutDuration after the latest of any run of MaxFailures
// failures that all fell within FailureWindow.
func (s *Service) LockedUntil(ctx context.Context, ip string) (time.Time, bool, error) {
	now := s.Now()
	rows, err := s.DB.QueryContext(ctx, `SELECT attempted_at FROM login_attempts
		WHERE ip = ? AND success = 0 AND attempted_at > ? ORDER BY attempted_at`,
		s.ipKey(ip), db.TS(now.Add(-(FailureWindow + LockoutDuration))))
	if err != nil {
		return time.Time{}, false, err
	}
	defer rows.Close()
	var ts []time.Time
	for rows.Next() {
		var a string
		if err := rows.Scan(&a); err != nil {
			return time.Time{}, false, err
		}
		t, err := db.ParseTS(a)
		if err != nil {
			continue
		}
		ts = append(ts, t)
	}
	var until time.Time
	for i := MaxFailures - 1; i < len(ts); i++ {
		if ts[i].Sub(ts[i-MaxFailures+1]) <= FailureWindow {
			if u := ts[i].Add(LockoutDuration); u.After(until) {
				until = u
			}
		}
	}
	return until, now.Before(until), rows.Err()
}

func (s *Service) record(ctx context.Context, ip string, ok bool) {
	v := 0
	if ok {
		v = 1
	}
	s.DB.ExecContext(ctx, `INSERT INTO login_attempts(ip, attempted_at, success) VALUES (?, ?, ?)`,
		s.ipKey(ip), db.TS(s.Now()), v)
}

func randToken() (string, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(b), nil
}

func hashToken(tok string) string {
	h := sha256.Sum256([]byte(tok))
	return hex.EncodeToString(h[:])
}

// Login checks the credentials and, on success, returns a new session token.
func (s *Service) Login(ctx context.Context, username, password, ip string) (string, error) {
	if until, locked, err := s.LockedUntil(ctx, ip); err != nil {
		return "", err
	} else if locked {
		return "", &LockedError{Until: until}
	}

	var u User
	var hash string
	var must int
	err := s.DB.QueryRowContext(ctx, `SELECT id, username, password_hash, must_change_password FROM users WHERE username = ? COLLATE NOCASE`,
		username).Scan(&u.ID, &u.Username, &hash, &must)
	found := err == nil
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return "", err
	}
	if !found || len(password) > maxPasswordBytes {
		// Burn the same bcrypt time as a real check so response time does not
		// reveal whether the username exists.
		bcrypt.CompareHashAndPassword(s.dummyHash(), []byte(password))
		s.record(ctx, ip, false)
		return "", ErrInvalid
	}
	if bcrypt.CompareHashAndPassword([]byte(hash), []byte(password)) != nil {
		s.record(ctx, ip, false)
		return "", ErrInvalid
	}

	s.DB.ExecContext(ctx, `DELETE FROM login_attempts WHERE ip = ? AND success = 0`, s.ipKey(ip))
	s.record(ctx, ip, true)

	tok, err := randToken()
	if err != nil {
		return "", err
	}
	csrf, err := randToken()
	if err != nil {
		return "", err
	}
	now := s.Now()
	if _, err := s.DB.ExecContext(ctx, `INSERT INTO sessions(user_id, token_hash, csrf_token, created_at, last_seen_at, expires_at)
		VALUES (?, ?, ?, ?, ?, ?)`, u.ID, hashToken(tok), csrf, db.TS(now), db.TS(now), db.TS(now.Add(s.AbsTTL))); err != nil {
		return "", err
	}
	return tok, nil
}

// Authenticate resolves a cookie token to its session, enforcing both the
// idle and the absolute expiry, and slides the idle timer forward.
func (s *Service) Authenticate(ctx context.Context, token string) (*Session, error) {
	if token == "" {
		return nil, ErrNoSession
	}
	var sess Session
	var seen, expires string
	var must int
	err := s.DB.QueryRowContext(ctx, `SELECT s.id, s.csrf_token, s.last_seen_at, s.expires_at, u.id, u.username, u.must_change_password
		FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ?`, hashToken(token)).
		Scan(&sess.ID, &sess.CSRF, &seen, &expires, &sess.User.ID, &sess.User.Username, &must)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, ErrNoSession
	}
	if err != nil {
		return nil, err
	}
	now := s.Now()
	exp, err1 := db.ParseTS(expires)
	last, err2 := db.ParseTS(seen)
	if err1 != nil || err2 != nil || !now.Before(exp) || now.Sub(last) > s.IdleTTL {
		s.DB.ExecContext(ctx, `DELETE FROM sessions WHERE id = ?`, sess.ID)
		return nil, ErrNoSession
	}
	if now.Sub(last) > time.Minute { // avoid a write on every request
		s.DB.ExecContext(ctx, `UPDATE sessions SET last_seen_at = ? WHERE id = ?`, db.TS(now), sess.ID)
	}
	sess.User.MustChange = must == 1
	return &sess, nil
}

// Logout deletes the session server-side, so a copied cookie stops working.
func (s *Service) Logout(ctx context.Context, token string) {
	s.DB.ExecContext(ctx, `DELETE FROM sessions WHERE token_hash = ?`, hashToken(token))
}

var ErrWrongPassword error = &PolicyError{"The current password is incorrect."}

// ChangePassword verifies the current password, applies the policy to the new
// one, clears the must-change flag and revokes every other session.
func (s *Service) ChangePassword(ctx context.Context, userID int64, current, next string, keepSessionID int64) error {
	var hash string
	if err := s.DB.QueryRowContext(ctx, `SELECT password_hash FROM users WHERE id = ?`, userID).Scan(&hash); err != nil {
		return err
	}
	if len(current) > maxPasswordBytes || bcrypt.CompareHashAndPassword([]byte(hash), []byte(current)) != nil {
		return ErrWrongPassword
	}
	if err := CheckNewPassword(next); err != nil {
		return err
	}
	if next == current {
		return &PolicyError{"The new password must be different from the current one."}
	}
	nh, err := bcrypt.GenerateFromPassword([]byte(next), s.Cost)
	if err != nil {
		return err
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx, `UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?`,
		string(nh), db.TS(s.Now()), userID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM sessions WHERE user_id = ? AND id <> ?`, userID, keepSessionID); err != nil {
		return err
	}
	return tx.Commit()
}

// Purge removes expired sessions and stale login-attempt rows.
func (s *Service) Purge(ctx context.Context) error {
	now := s.Now()
	if _, err := s.DB.ExecContext(ctx, `DELETE FROM sessions WHERE expires_at <= ? OR last_seen_at < ?`,
		db.TS(now), db.TS(now.Add(-s.IdleTTL))); err != nil {
		return err
	}
	_, err := s.DB.ExecContext(ctx, `DELETE FROM login_attempts WHERE attempted_at < ?`, db.TS(now.Add(-24*time.Hour)))
	return err
}
