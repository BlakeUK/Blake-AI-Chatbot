import 'package:flutter/material.dart';

const kBrandBlue = Color(0xFF3D5A99);

// Same palette as the web admin panel's dark theme, so both platforms
// render identically rather than drifting into two different "dark modes".
const kBgDark = Color(0xFF2F343B);
const kSurfaceDark = Color(0xFF3A4048);
const kRecessedDark = Color(0xFF262B31);
const kTextDark = Color(0xFFE8E8EA);
const kMutedDark = Color(0xFFADB4BA);
const kBorderSubtleDark = Color(0xFF454C54);
const kBorderStrongDark = Color(0xFF6B7480);
const kAccentTextDark = Color(0xFF8FB0EC);

/// Formats a unix timestamp (seconds, as returned by the PHP API) the same
/// way the web admin panel's fmtDate() does.
String fmtDate(dynamic unixSeconds) {
  if (unixSeconds == null) return '—';
  final secs = unixSeconds is int ? unixSeconds : int.tryParse(unixSeconds.toString());
  if (secs == null) return '—';
  final d = DateTime.fromMillisecondsSinceEpoch(secs * 1000);
  String two(int n) => n.toString().padLeft(2, '0');
  return '${d.year}-${two(d.month)}-${two(d.day)} ${two(d.hour)}:${two(d.minute)}';
}

class StatTile extends StatelessWidget {
  final String value;
  final String label;
  const StatTile({super.key, required this.value, required this.label});

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(value,
                style: const TextStyle(
                    fontSize: 26, fontWeight: FontWeight.w800, color: kAccentTextDark)),
            const SizedBox(height: 4),
            Text(label, style: const TextStyle(fontSize: 12, color: kMutedDark)),
          ],
        ),
      ),
    );
  }
}

class StatusBadge extends StatelessWidget {
  final String text;
  final bool ok;
  const StatusBadge({super.key, required this.text, required this.ok});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: ok ? const Color(0xFFD4EDDA) : const Color(0xFFF8D7DA),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(text,
          style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: ok ? const Color(0xFF155724) : const Color(0xFF721C24))),
    );
  }
}

void flash(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      content: Text(message),
      backgroundColor: error ? Colors.red.shade700 : Colors.green.shade700,
    ),
  );
}
