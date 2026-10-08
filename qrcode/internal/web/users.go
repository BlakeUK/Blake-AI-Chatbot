package web

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
)

// adminOnly wraps a handler so only admins reach it. Members can manage QR
// codes but not people.
func (s *Server) adminOnly(h authedHandler) http.HandlerFunc {
	return s.authed(func(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
		if !sess.User.IsAdmin() {
			s.errorPage(w, http.StatusForbidden, "Admins only", "Only an admin can manage users.")
			return
		}
		h(w, r, sess)
	})
}

type usersPage struct {
	Users    []auth.UserRow
	Audit    []auth.AuditRow
	Me       int64
	Reset    *resetResult
	FormUser string
	FormRole string
}

// resetResult is shown once, on the response to a password reset, and is never stored.
type resetResult struct{ User, Password string }

func (s *Server) renderUsers(w http.ResponseWriter, r *http.Request, sess *auth.Session, status int, p usersPage, errMsg string) {
	users, err := s.auth.ListUsers(r.Context())
	if err != nil {
		s.serverError(w, "list users", err)
		return
	}
	p.Users, p.Me = users, sess.User.ID
	p.Audit, _ = s.auth.RecentAudit(r.Context(), 40)
	pd := s.page(sess, "Users", p)
	pd.Error = errMsg
	s.render(w, status, "users", pd)
}

func (s *Server) usersList(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	s.renderUsers(w, r, sess, http.StatusOK, usersPage{FormRole: auth.RoleMember}, "")
}

// policyOr returns the person-readable text of a policy error, or logs and
// returns a generic message for anything unexpected.
func (s *Server) policyOr(err error) string {
	var pe *auth.PolicyError
	if errors.As(err, &pe) {
		return pe.Msg
	}
	s.log.Error("user admin failed", "err", err)
	return "That could not be done. Please try again."
}

func (s *Server) userCreate(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	name, role := r.PostFormValue("username"), r.PostFormValue("role")
	pw := r.PostFormValue("password")
	generated := false
	if pw == "" {
		var err error
		if pw, err = auth.GeneratePassword(); err != nil {
			s.serverError(w, "generate password", err)
			return
		}
		generated = true
	}
	if _, err := s.auth.CreateUser(r.Context(), name, pw, role); err != nil {
		s.renderUsers(w, r, sess, http.StatusUnprocessableEntity, usersPage{FormUser: name, FormRole: role}, s.policyOr(err))
		return
	}
	s.auth.Audit(r.Context(), sess.User.Username, "user.create", name, "role="+role)
	p := usersPage{FormRole: auth.RoleMember}
	if generated {
		p.Reset = &resetResult{User: name, Password: pw}
	}
	s.renderUsers(w, r, sess, http.StatusOK, p, "")
}

func (s *Server) userID(r *http.Request) int64 {
	id, _ := strconv.ParseInt(r.PathValue("id"), 10, 64)
	return id
}

func (s *Server) userReset(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	id := s.userID(r)
	pw := r.PostFormValue("password")
	if pw == "" {
		var err error
		if pw, err = auth.GeneratePassword(); err != nil {
			s.serverError(w, "generate password", err)
			return
		}
	}
	name, err := s.auth.ResetPassword(r.Context(), id, pw)
	if err != nil {
		s.renderUsers(w, r, sess, http.StatusUnprocessableEntity, usersPage{FormRole: auth.RoleMember}, s.policyOr(err))
		return
	}
	s.auth.Audit(r.Context(), sess.User.Username, "user.reset_password", name, "")
	s.renderUsers(w, r, sess, http.StatusOK, usersPage{FormRole: auth.RoleMember, Reset: &resetResult{User: name, Password: pw}}, "")
}

func (s *Server) userDelete(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	name, err := s.auth.DeleteUser(r.Context(), s.userID(r), sess.User.ID)
	if err != nil {
		s.renderUsers(w, r, sess, http.StatusUnprocessableEntity, usersPage{FormRole: auth.RoleMember}, s.policyOr(err))
		return
	}
	s.auth.Audit(r.Context(), sess.User.Username, "user.delete", name, "")
	http.Redirect(w, r, "/admin/users", http.StatusSeeOther)
}

func (s *Server) userRole(w http.ResponseWriter, r *http.Request, sess *auth.Session) {
	role := r.PostFormValue("role")
	name, err := s.auth.SetRole(r.Context(), s.userID(r), role)
	if err != nil {
		s.renderUsers(w, r, sess, http.StatusUnprocessableEntity, usersPage{FormRole: auth.RoleMember}, s.policyOr(err))
		return
	}
	s.auth.Audit(r.Context(), sess.User.Username, "user.role", name, "role="+role)
	http.Redirect(w, r, "/admin/users", http.StatusSeeOther)
}
