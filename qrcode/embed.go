// Package qrtrack carries the assets that are compiled into the binary:
// SQL migrations, HTML templates and static files.
package qrtrack

import "embed"

//go:embed migrations/*.sql
var Migrations embed.FS

//go:embed web/templates/*.html web/static/* web/manual/*
var Web embed.FS
