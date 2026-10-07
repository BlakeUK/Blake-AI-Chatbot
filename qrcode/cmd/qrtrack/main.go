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

	resolver, err := geo.Open(cfg.GeoIPDB)
	if err != nil {
		return fmt.Errorf("open GeoIP database: %w", err)
	}
	defer resolver.Close()

	london, err := time.LoadLocation("Europe/London")
	if err != nil {
		return err
	}

	writer := scans.NewWriter(d, 4096, log)
	writer.Start()

	srv, err := web.New(web.Config{BaseURL: cfg.BaseURL, TrustedProxies: cfg.TrustedProxies, Location: london}, web.Deps{
		DB: d, Links: links.NewStore(d), Auth: authSvc, Hasher: scans.NewHasher(d, time.Now),
		Writer: writer, Geo: resolver, Now: time.Now, Log: log, Assets: qrtrack.Web,
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
	go maintenance(stop, authSvc, cfg.RetentionDays, log)

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
	log.Info("stopped", "scans_dropped", writer.Dropped(), "scans_failed", writer.Failed())
	return nil
}

// maintenance purges expired data once an hour: old scans beyond the
// retention period, stale daily salts, expired sessions and login attempts.
func maintenance(stop <-chan struct{}, a *auth.Service, retentionDays int, log *slog.Logger) {
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
		if err := a.Purge(ctx); err != nil {
			log.Error("session purge", "err", err)
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
