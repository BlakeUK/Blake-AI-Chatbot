package auth

import (
	"context"
	"crypto/rand"
	"database/sql"
	"errors"
	"regexp"
	"time"

	"golang.org/x/crypto/bcrypt"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
)

const (
	RoleAdmin  = "admin"  // everything, including managing users
	RoleMember = "member" // links, campaigns, statistics; not users
)

var usernameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$`)

// ValidRole reports whether r is a role this system knows.
func ValidRole(r string) bool { return r == RoleAdmin || r == RoleMember }

// UserRow is one account as listed on the Users page.
type UserRow struct {
	ID         int64
	Username   string
	Role       string
	MustChange bool
	CreatedAt  time.Time
	Sessions   int // signed-in sessions right now
}

func policy(msg string) error { return &PolicyError{msg} }

// CreateUser adds an account. The password is the initial one: the person is
// forced to replace it at first sign-in, like the seeded admin.
func (s *Service) CreateUser(ctx context.Context, username, password, role string) (int64, error) {
	if !usernameRe.MatchString(username) {
		return 0, policy("User names are 3 to 32 characters: letters, numbers, dot, dash or underscore, starting with a letter or number.")
	}
	if !ValidRole(role) {
		return 0, policy("Choose a role.")
	}
	if err := CheckNewPassword(password); err != nil {
		return 0, err
	}
	var exists int
	if err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM users WHERE username = ? COLLATE NOCASE`, username).Scan(&exists); err != nil {
		return 0, err
	}
	if exists > 0 {
		return 0, policy("That user name is already taken.")
	}
	hash, err := bcrypt.GenerateFromPassword([]byte(password), s.Cost)
	if err != nil {
		return 0, err
	}
	now := db.TS(s.Now())
	res, err := s.DB.ExecContext(ctx, `INSERT INTO users(username, password_hash, must_change_password, role, created_at, updated_at)
		VALUES (?, ?, 1, ?, ?, ?)`, username, string(hash), role, now, now)
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

// ListUsers returns every account, oldest first.
func (s *Service) ListUsers(ctx context.Context) ([]UserRow, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT u.id, u.username, u.role, u.must_change_password, u.created_at,
			(SELECT COUNT(*) FROM sessions x WHERE x.user_id = u.id AND x.expires_at > ?)
		FROM users u ORDER BY u.id`, db.TS(s.Now()))
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []UserRow
	for rows.Next() {
		var u UserRow
		var must int
		var created string
		if err := rows.Scan(&u.ID, &u.Username, &u.Role, &must, &created, &u.Sessions); err != nil {
			return nil, err
		}
		u.MustChange = must == 1
		u.CreatedAt, _ = db.ParseTS(created)
		out = append(out, u)
	}
	return out, rows.Err()
}

func (s *Service) userByID(ctx context.Context, tx *sql.Tx, id int64) (username, role string, err error) {
	err = tx.QueryRowContext(ctx, `SELECT username, role FROM users WHERE id = ?`, id).Scan(&username, &role)
	if errors.Is(err, sql.ErrNoRows) {
		return "", "", policy("That user no longer exists.")
	}
	return
}

func adminCount(ctx context.Context, tx *sql.Tx) (n int, err error) {
	err = tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM users WHERE role = 'admin'`).Scan(&n)
	return
}

// DeleteUser removes an account and, by cascade, all its sessions. You cannot
// remove yourself, and the last remaining admin can never be removed, so the
// system can never be left with nobody able to manage it. It returns the
// removed user name for the audit trail.
func (s *Service) DeleteUser(ctx context.Context, id, actorID int64) (string, error) {
	if id == actorID {
		return "", policy("You cannot remove your own account. Ask another admin to do it.")
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	name, role, err := s.userByID(ctx, tx, id)
	if err != nil {
		return "", err
	}
	if role == RoleAdmin {
		if n, err := adminCount(ctx, tx); err != nil {
			return "", err
		} else if n <= 1 {
			return "", policy("That is the last admin account, so it cannot be removed.")
		}
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM users WHERE id = ?`, id); err != nil {
		return "", err
	}
	return name, tx.Commit()
}

// SetRole changes a user's role. The last admin cannot be demoted.
func (s *Service) SetRole(ctx context.Context, id int64, role string) (string, error) {
	if !ValidRole(role) {
		return "", policy("Choose a role.")
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	name, current, err := s.userByID(ctx, tx, id)
	if err != nil {
		return "", err
	}
	if current == RoleAdmin && role != RoleAdmin {
		if n, err := adminCount(ctx, tx); err != nil {
			return "", err
		} else if n <= 1 {
			return "", policy("That is the last admin account, so it cannot be made a member.")
		}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE users SET role = ?, updated_at = ? WHERE id = ?`, role, db.TS(s.Now()), id); err != nil {
		return "", err
	}
	// A demoted user must not keep the powers of an old session.
	if _, err := tx.ExecContext(ctx, `DELETE FROM sessions WHERE user_id = ?`, id); err != nil {
		return "", err
	}
	return name, tx.Commit()
}

// ResetPassword sets a new temporary password for someone else, forces them to
// change it at next sign-in, and signs them out everywhere. It returns the
// user name for the audit trail.
func (s *Service) ResetPassword(ctx context.Context, id int64, password string) (string, error) {
	if err := CheckNewPassword(password); err != nil {
		return "", err
	}
	hash, err := bcrypt.GenerateFromPassword([]byte(password), s.Cost)
	if err != nil {
		return "", err
	}
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return "", err
	}
	defer tx.Rollback()
	name, _, err := s.userByID(ctx, tx, id)
	if err != nil {
		return "", err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE users SET password_hash = ?, must_change_password = 1, updated_at = ? WHERE id = ?`,
		string(hash), db.TS(s.Now()), id); err != nil {
		return "", err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM sessions WHERE user_id = ?`, id); err != nil {
		return "", err
	}
	return name, tx.Commit()
}

// GeneratePassword returns a random temporary password of 16 characters drawn
// from an alphabet without look-alike characters (no 0/O, 1/l/I).
func GeneratePassword() (string, error) {
	const alphabet = "abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789"
	out := make([]byte, 0, 16)
	buf := make([]byte, 64)
	limit := 256 - (256 % len(alphabet)) // rejection sampling: every character equally likely
	for len(out) < 16 {
		if _, err := rand.Read(buf); err != nil {
			return "", err
		}
		for _, b := range buf {
			if int(b) >= limit {
				continue
			}
			out = append(out, alphabet[int(b)%len(alphabet)])
			if len(out) == 16 {
				break
			}
		}
	}
	return string(out), nil
}

// ---------- audit trail ----------

// AuditRow is one line of the activity log.
type AuditRow struct {
	At     time.Time
	Actor  string
	Action string
	Target string
	Detail string
}

// Audit records who did what. It never records addresses or passwords.
func (s *Service) Audit(ctx context.Context, actor, action, target, detail string) {
	s.DB.ExecContext(ctx, `INSERT INTO audit_log(at, actor, action, target, detail) VALUES (?, ?, ?, ?, ?)`,
		db.TS(s.Now()), actor, action, target, detail)
}

// RecentAudit returns the newest n audit lines.
func (s *Service) RecentAudit(ctx context.Context, n int) ([]AuditRow, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT at, actor, action, target, detail FROM audit_log ORDER BY id DESC LIMIT ?`, n)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []AuditRow
	for rows.Next() {
		var r AuditRow
		var at string
		if err := rows.Scan(&at, &r.Actor, &r.Action, &r.Target, &r.Detail); err != nil {
			return nil, err
		}
		r.At, _ = db.ParseTS(at)
		out = append(out, r)
	}
	return out, rows.Err()
}

// PurgeAudit deletes audit lines older than the cutoff.
func (s *Service) PurgeAudit(ctx context.Context, cutoff time.Time) error {
	_, err := s.DB.ExecContext(ctx, `DELETE FROM audit_log WHERE at < ?`, db.TS(cutoff))
	return err
}
