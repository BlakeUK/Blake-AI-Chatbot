// Command qrtrack is a self-contained QR-code link tracker: short links that
// redirect to a destination while counting scans in privacy-conscious form.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	_ "time/tzdata" // Europe/London must work even on a host with no tz database

	qrtrack "github.com/BlakeUK/Blake-AI-Chatbot/qrcode"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/auth"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/db"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/geo"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/links"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/pages"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/scans"
	"github.com/BlakeUK/Blake-AI-Chatbot/qrcode/internal/web"
)

type config struct {
	ListenAddr     string
	DBPath         string
	BaseURL        string
	AdminUser      string
	AdminPassword  string
	TrustedProxies []*net.IPNet
	GeoIPDB        string
	StoreFullIP    bool
	RetentionDays  int
	LogLevel       slog.Level
}

func loadConfig() (config, error) {
	get := func(k, def string) string {
		if v := strings.TrimSpace(os.Getenv(k)); v != "" {
			return v
		}
		return def
	}
	c := config{
		ListenAddr:    get("LISTEN_ADDR", "127.0.0.1:8080"),
		DBPath:        get("DB_PATH", "qrtrack.db"),
		BaseURL:       strings.TrimRight(get("BASE_URL", ""), "/"),
		AdminUser:     get("ADMIN_USER", ""),
		AdminPassword: os.Getenv("ADMIN_PASSWORD"),
		GeoIPDB:       get("GEOIP_DB", ""),
	}
	if c.BaseURL == "" {
		return c, errors.New("BASE_URL is required (the public origin the QR codes will point at, e.g. https://qr.example.com)")
	}
	switch strings.ToLower(get("STORE_FULL_IP", "false")) {
	case "1", "true", "yes", "on":
		c.StoreFullIP = true
	case "0", "false", "no", "off":
	default:
		return c, fmt.Errorf("STORE_FULL_IP must be true or false")
	}
	days, err := strconv.Atoi(get("RETENTION_DAYS", "365"))
	if err != nil || days < 1 {
		return c, fmt.Errorf("RETENTION_DAYS must be a positive integer")
	}
	c.RetentionDays = days
	if err := c.LogLevel.UnmarshalText([]byte(get("LOG_LEVEL", "info"))); err != nil {
		return c, fmt.Errorf("LOG_LEVEL: %w", err)
	}
	for _, p := range strings.Split(get("TRUSTED_PROXIES", "127.0.0.1/32,::1/128"), ",") {
		p = strings.TrimSpace(p)
		if p == "" {
			continue
		}
		if !strings.Contains(p, "/") {
			if strings.Contains(p, ":") {
				p += "/128"
			} else {
				p += "/32"
			}
		}
		_, n, err := net.ParseCIDR(p)
		if err != nil {
			return c, fmt.Errorf("TRUSTED_PROXIES entry %q: %w", p, err)
		}
		c.TrustedProxies = append(c.TrustedProxies, n)
	}
	return c, nil
}

func main() {
	// `qrtrack geocheck FILE [IP]` validates an IP-geolocation database. The
	// monthly updater runs it on a download before swapping the file in.
	if len(os.Args) >= 3 && os.Args[1] == "geocheck" {
		os.Exit(geocheck(os.Args[2:]))
	}
	// `qrtrack resetpassword NAME` is the way back in when nobody can sign in.
	// Run it on the server; it prints a one-time temporary password.
	if len(os.Args) >= 2 && os.Args[1] == "resetpassword" {
		os.Exit(resetPassword(os.Args[2:]))
	}
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "qrtrack:", err)
		os.Exit(1)
	}
}

func run() error {
	cfg, err := loadConfig()
	if err != nil {
		return err
	}
	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: cfg.LogLevel}))

	ctx := context.Background()
	d, err := db.Open(cfg.DBPath)
	if err != nil {
		return fmt.Errorf("open database: %w", err)
	}
	defer d.Close()
	if err := db.Migrate(ctx, d, qrtrack.Migrations, "migrations"); err != nil {
		return fmt.Errorf("migrate: %w", err)
	}

	key, err := db.Secret(ctx, d, "login_ip_key")
	if err != nil {
		return err
	}
	authSvc := auth.New(d, key)
	created, err := authSvc.Seed(ctx, cfg.AdminUser, cfg.AdminPassword)
	if err != nil {
		return fmt.Errorf("seed admin: %w", err)
	}
	if created {
		// The password itself is never logged.
		log.Info("created initial admin account; a password change is required at first login", "username", cfg.AdminUser)
	}

	// A missing or damaged GeoIP file must never stop QR codes redirecting:
	// log it and run without place names until a good file appears (the
	// hourly reload picks it up).
	resolver, err := geo.Open(cfg.GeoIPDB)
	if err != nil {
		log.Error("GeoIP database not usable; place names will be blank until it is fixed", "path", cfg.GeoIPDB, "err", err)
		resolver, _ = geo.Open("")
	} else if resolver.Active() {
		log.Info("GeoIP database loaded", "type", resolver.DatabaseType())
	}
	defer resolver.Close()
	if cfg.StoreFullIP {
		log.Warn("STORE_FULL_IP is on: visitor IP addresses are stored with each scan")
	}

	london, err := time.LoadLocation("Europe/London")
	if err != nil {
		return err
	}

	writer := scans.NewWriter(d, 4096, log)
	writer.Start()
	pageWriter := pages.NewWriter(d, 2048, log)
	pageWriter.Start()

	srv, err := web.New(web.Config{BaseURL: cfg.BaseURL, TrustedProxies: cfg.TrustedProxies, Location: london, StoreFullIP: cfg.StoreFullIP, RetentionDays: cfg.RetentionDays}, web.Deps{
		DB: d, Links: links.NewStore(d), Auth: authSvc, Hasher: scans.NewHasher(d, time.Now),
		Writer: writer, Geo: resolver, Now: time.Now, Log: log, Assets: qrtrack.Web,
		Pages: pages.NewStore(d), PageEvents: pageWriter,
	})
	if err != nil {
		return err
	}

	httpSrv := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           srv.Handler(),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
		MaxHeaderBytes:    16 << 10,
	}

	stop := make(chan struct{})
	go maintenance(stop, authSvc, resolver, cfg.RetentionDays, log)

	errCh := make(chan error, 1)
	go func() {
		log.Info("listening", "addr", cfg.ListenAddr, "base_url", cfg.BaseURL)
		if err := httpSrv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
		}
	}()

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGTERM, syscall.SIGINT)
	select {
	case s := <-sig:
		log.Info("shutting down", "signal", s.String())
	case err := <-errCh:
		return err
	}

	// Stop accepting requests first, so nothing can queue a scan afterwards,
	// then drain the scan queue before the database closes.
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := httpSrv.Shutdown(shutdownCtx); err != nil {
		log.Error("http shutdown", "err", err)
	}
	close(stop)
	if err := writer.Close(shutdownCtx); err != nil {
		log.Error("scan writer did not drain in time", "err", err)
	}
	if err := pageWriter.Close(shutdownCtx); err != nil {
		log.Error("page event writer did not drain in time", "err", err)
	}
	log.Info("stopped", "scans_dropped", writer.Dropped(), "scans_failed", writer.Failed())
	return nil
}

// maintenance purges expired data once an hour: old scans beyond the
// retention period, stale daily salts, expired sessions and login attempts.
func maintenance(stop <-chan struct{}, a *auth.Service, g *geo.Resolver, retentionDays int, log *slog.Logger) {
	t := time.NewTicker(time.Hour)
	defer t.Stop()
	run := func() {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
		defer cancel()
		now := time.Now()
		n, err := scans.Purge(ctx, a.DB, now.AddDate(0, 0, -retentionDays), now)
		if err != nil {
			log.Error("retention purge", "err", err)
		} else if n > 0 {
			log.Info("retention purge", "scans_deleted", n, "older_than_days", retentionDays)
		}
		cutoff := now.AddDate(0, 0, -retentionDays)
		if n, err := pages.Purge(ctx, a.DB, cutoff); err != nil {
			log.Error("link page retention purge", "err", err)
		} else if n > 0 {
			log.Info("link page retention purge", "events_deleted", n)
		}
		if err := a.PurgeAudit(ctx, cutoff); err != nil {
			log.Error("audit log purge", "err", err)
		}
		if err := a.Purge(ctx); err != nil {
			log.Error("session purge", "err", err)
		}
		// Pick up a freshly downloaded GeoIP database without a restart.
		if changed, err := g.Reload(); err != nil {
			log.Error("GeoIP reload rejected; keeping the previous database", "err", err)
		} else if changed {
			log.Info("GeoIP database reloaded", "type", g.DatabaseType())
		}
	}
	run()
	for {
		select {
		case <-t.C:
			run()
		case <-stop:
			return
		}
	}
}

// geocheck opens a GeoIP database and looks up a sample address (default
// 81.2.69.142, which a good City database places in London). Exit status 0
// means the file is usable.
func geocheck(args []string) int {
	g, err := geo.Open(args[0])
	if err != nil {
		fmt.Fprintln(os.Stderr, "geocheck: not a usable database:", err)
		return 1
	}
	defer g.Close()
	ip := "81.2.69.142"
	if len(args) > 1 {
		ip = args[1]
	}
	l := g.Lookup(ip)
	fmt.Printf("%s database; %s -> %s, %s, %s (%s)\n", g.DatabaseType(), ip, l.City, l.Region, l.Country, l.CountryISO)
	if l.CountryISO == "" {
		fmt.Fprintln(os.Stderr, "geocheck: the sample address resolved to nothing")
		return 1
	}
	return 0
}

// resetPassword gives a user a new temporary password and clears sign-in
// lockouts. It is the recovery path when every admin is locked out or has
// forgotten their password. The password goes to standard output once.
func resetPassword(args []string) int {
	if len(args) != 1 {
		fmt.Fprintln(os.Stderr, "usage: qrtrack resetpassword USERNAME")
		return 2
	}
	cfg, err := loadConfig()
	if err != nil {
		fmt.Fprintln(os.Stderr, "resetpassword:", err)
		return 1
	}
	ctx := context.Background()
	d, err := db.Open(cfg.DBPath)
	if err != nil {
		fmt.Fprintln(os.Stderr, "resetpassword: open database:", err)
		return 1
	}
	defer d.Close()
	if err := db.Migrate(ctx, d, qrtrack.Migrations, "migrations"); err != nil {
		fmt.Fprintln(os.Stderr, "resetpassword: migrate:", err)
		return 1
	}
	key, err := db.Secret(ctx, d, "login_ip_key")
	if err != nil {
		fmt.Fprintln(os.Stderr, "resetpassword:", err)
		return 1
	}
	pw, err := auth.New(d, key).RecoverPassword(ctx, args[0])
	if err != nil {
		fmt.Fprintln(os.Stderr, "resetpassword:", err)
		return 1
	}
	fmt.Printf("TEMPORARY PASSWORD for %s: %s\nThey must choose a new password at the next sign-in. Sign-in lockouts were cleared.\n", args[0], pw)
	return 0
}
