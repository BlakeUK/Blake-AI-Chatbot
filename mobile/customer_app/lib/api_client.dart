import 'dart:convert';
import 'package:http/http.dart' as http;

/// Base URL of the Blake UK chatbot backend. Uses the same HTTPS domain
/// the web widget is served from (see Caddyfile/README) rather than the
/// raw VPS IP over plain HTTP, which has no TLS cert of its own.
const String kApiBase = 'https://chat.blakegroup.uk';

/// Identifies this app as a first-party caller to public/api/chat/session.php,
/// which otherwise has no browser Origin header to check for a native HTTP
/// client — see CFG['mobile_app_key'] in config/config.example.php for the
/// server-side half and why this isn't meant to be a strong secret. Must
/// match the value the backend was deployed with.
const String kAppKey = 'CHANGE_ME_MOBILE_APP_KEY';

class ApiClient {
  static Future<Map<String, dynamic>> post(
    String path,
    Map<String, dynamic> body,
  ) async {
    final r = await http.post(
      Uri.parse('$kApiBase$path'),
      headers: {'Content-Type': 'application/json', 'X-App-Key': kAppKey},
      body: jsonEncode(body),
    );
    final decoded = jsonDecode(r.body);
    return decoded is Map<String, dynamic> ? decoded : {};
  }
}
