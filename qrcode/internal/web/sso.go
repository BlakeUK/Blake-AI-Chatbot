package web

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"

	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
)

// Signing in through the support desk. A person who is already signed in to the support desk (the Windows or Linux program, or the admin
// website) is sent here with a one-time code. This tool never trusts the code itself: it asks the support desk, over the verify address in
// its settings, who the code belongs to, and the support desk answers once and forgets the code. The person is then signed in without
// typing a password. When SSO_VERIFY_URL is not set, none of this exists (the address answers 404).

var (
	ssoTokenRe     = regexp.MustCompile(`^[A-Za-z0-9_-]{20,120}$`)
	frameOriginRe  = regexp.MustCompile(`^(https?://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[0-9]{1,5})?|tauri://localhost)$`)
	errSSOInvalid  = errors.New("the support desk does not recognise that sign-in code")
	errSSOUnusable = errors.New("the support desk could not be reached")
)

type ssoClaims struct {
	OK       bool   `json:"ok"`
	Username string `json:"username"`
	Role     string `json:"role"`
}

// validateSSOConfig checks the verify address and the framing origins at start-up, so a typo stops the service instead of weakening it.
func validateSSOConfig(c Config) error {
	if c.SSOVerifyURL != "" {
		u, err := url.Parse(c.SSOVerifyURL)
		if err != nil || u.Host == "" || u.User != nil || u.Fragment != "" {
			return fmt.Errorf("SSO_VERIFY_URL is not a valid address")
		}
		loopback := false
		if ip := net.ParseIP(u.Hostname()); ip != nil {
			loopback = ip.IsLoopback()
		}
		if u.Scheme != "https" && !(u.Scheme == "http" && loopback) {
			return fmt.Errorf("SSO_VERIFY_URL must be https (http is allowed only to a loopback address)")
		}
	}
	for _, o := range c.FrameAncestors {
		if !frameOriginRe.MatchString(o) {
			return fmt.Errorf("FRAME_ANCESTORS has an invalid origin %q (use values like https://example.com or tauri://localhost)", o)
		}
	}
	return nil
}

// framable says whether a page may be shown inside another site's frame: only the signed-in admin pages and the sign-in step, and only by the
// origins listed in the settings. Everything the public sees (scan redirects, link pages) can never be framed.
func (s *Server) framable(path string) bool {
	return len(s.cfg.FrameAncestors) > 0 && (path == "/admin" || strings.HasPrefix(path, "/admin/") || path == "/sso")
}

func (s *Server) verifySSO(ctx context.Context, code string) (ssoClaims, error) {
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, s.cfg.SSOVerifyURL, strings.NewReader(url.Values{"t": {code}}.Encode()))
	if err != nil {
		return ssoClaims{}, errSSOUnusable
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")
	client := &http.Client{Timeout: 5 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := client.Do(req)
	if err != nil {
		return ssoClaims{}, errSSOUnusable
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	if resp.StatusCode == http.StatusNotFound || resp.StatusCode == http.StatusForbidden || resp.StatusCode == http.StatusUnauthorized {
		return ssoClaims{}, errSSOInvalid
	}
	if resp.StatusCode != http.StatusOK {
		return ssoClaims{}, errSSOUnusable
	}
	var c ssoClaims
	if json.Unmarshal(body, &c) != nil || !c.OK {
		return ssoClaims{}, errSSOInvalid
	}
	return c, nil
}

// sso is GET /sso?t=<one-time code>.
func (s *Server) sso(w http.ResponseWriter, r *http.Request) {
	if s.cfg.SSOVerifyURL == "" {
		http.NotFound(w, r)
		return
	}
	code := r.URL.Query().Get("t")
	if !ssoTokenRe.MatchString(code) {
		s.errorPage(w, http.StatusBadRequest, "Sign-in link not valid", "That sign-in link is not valid. Go back to the support desk and open this again, or sign in with a password.")
		return
	}
	claims, err := s.verifySSO(r.Context(), code)
	switch {
	case errors.Is(err, errSSOInvalid):
		s.errorPage(w, http.StatusForbidden, "Sign-in link expired", "That sign-in link has expired or was already used. Go back to the support desk and open this again, or sign in with a password.")
		return
	case err != nil:
		s.log.Error("support desk sign-in could not be checked", "err", err)
		s.errorPage(w, http.StatusBadGateway, "Sign-in unavailable", "The support desk could not be reached to check your sign-in. Try again in a moment, or sign in with a password.")
		return
	}
	token, err := s.auth.SSOSignIn(r.Context(), claims.Username, claims.Role)
	if err != nil {
		if !errors.Is(err, auth.ErrSSOIdentity) {
			s.log.Error("support desk sign-in failed", "err", err)
		}
		s.errorPage(w, http.StatusForbidden, "Sign-in refused", "The support desk sent details this tool will not accept. Sign in with a password instead.")
		return
	}
	// SameSite=None, because the program shows this tool inside its own window, which a browser treats as another site. The session cookie is the
	// only thing that changes; every form still carries a per-session security token that is checked on each POST.
	s.setCookieMode(w, sessionCookie, token, "/", s.now().Add(s.auth.AbsTTL), true)
	http.Redirect(w, r, "/admin/", http.StatusSeeOther)
}
