package auth

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"errors"
	"regexp"
	"strings"

	"golang.org/x/crypto/bcrypt"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

// ssoNameRe is what the support desk may call a person: letters, digits and a few separators.
var ssoNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._@-]{0,63}$`)

// SSOPrefix marks accounts that exist only because the support desk vouched for the person. They have no password anyone knows, so they can
// never be signed into through the password form, and their names cannot collide with accounts made here.
const SSOPrefix = "sso."

// ErrSSOIdentity means the support desk sent a name or role this tool will not accept.
var ErrSSOIdentity = errors.New("sso: unacceptable identity")

// SSOSignIn starts a session for someone the support desk has already checked. The account is made the first time (with a random password
// nobody is told) and its role follows the support desk on every sign-in. It returns the session token.
func (s *Service) SSOSignIn(ctx context.Context, username, role string) (string, error) {
	if !ssoNameRe.MatchString(username) || !ValidRole(role) {
		return "", ErrSSOIdentity
	}
	name := SSOPrefix + strings.ToLower(username)
	var id int64
	var have string
	err := s.DB.QueryRowContext(ctx, `SELECT id, role FROM users WHERE username = ? COLLATE NOCASE`, name).Scan(&id, &have)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		pw := make([]byte, 32)
		if _, err := rand.Read(pw); err != nil {
			return "", err
		}
		hash, err := bcrypt.GenerateFromPassword([]byte(hex.EncodeToString(pw)), s.Cost)
		if err != nil {
			return "", err
		}
		now := db.TS(s.Now())
		res, err := s.DB.ExecContext(ctx, `INSERT INTO users(username, password_hash, must_change_password, role, created_at, updated_at) VALUES (?, ?, 0, ?, ?, ?)`,
			name, string(hash), role, now, now)
		if err != nil {
			return "", err
		}
		if id, err = res.LastInsertId(); err != nil {
			return "", err
		}
		s.Audit(ctx, name, "user created", name, "first sign-in through the support desk, role "+role)
	case err != nil:
		return "", err
	case have != role:
		if _, err := s.DB.ExecContext(ctx, `UPDATE users SET role = ?, updated_at = ? WHERE id = ?`, role, db.TS(s.Now()), id); err != nil {
			return "", err
		}
		s.Audit(ctx, name, "role changed", name, have+" to "+role+" (follows the support desk)")
	}
	tok, err := s.newSession(ctx, id)
	if err != nil {
		return "", err
	}
	s.Audit(ctx, name, "sign in", name, "through the support desk")
	return tok, nil
}
