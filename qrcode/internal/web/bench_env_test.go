package web

import "os"

func benchEnabled() bool { return os.Getenv("QRTRACK_BENCH") == "1" }
