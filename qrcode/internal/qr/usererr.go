package qr

// userError is a message written for the person filling in the form (a colour
// that will not scan, a logo that is too big). It is shown to them verbatim, so
// it reads as a sentence rather than as a Go error string.
type userError string

func (e userError) Error() string { return string(e) }

func userErr(s string) error { return userError(s) }
