import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

/// Base URL of the Blake UK chatbot backend. Update if the server moves
/// to a domain with HTTPS.
const String kApiBase = 'http://195.22.157.38';
const String kApi = '$kApiBase/api/admin';

/// Minimal cookie-jar backed HTTP client. The admin panel's auth is a PHP
/// session cookie (not a bearer token), so every request must carry
/// whatever Set-Cookie the server last issued — including after
/// session_regenerate_id() rotates the session id mid-login.
class ApiClient {
  static String? _cookie;
  static String csrf = '';
  static String role = 'user';

  static String? get cookieHeader => _cookie;

  static Future<void> restoreCookie() async {
    final prefs = await SharedPreferences.getInstance();
    _cookie = prefs.getString('buk_admin_cookie');
  }

  static Future<void> _saveCookie(String? cookie) async {
    _cookie = cookie;
    final prefs = await SharedPreferences.getInstance();
    if (cookie != null) {
      await prefs.setString('buk_admin_cookie', cookie);
    } else {
      await prefs.remove('buk_admin_cookie');
    }
  }

  static void _captureSetCookie(http.Response r) {
    final raw = r.headers['set-cookie'];
    if (raw == null) return;
    // A response may set multiple cookies separated by comma at the header
    // level, but http package folds them; take everything before the first
    // ';' of each piece. We only care about PHPSESSID here.
    final match = RegExp(r'PHPSESSID=[^;]+').firstMatch(raw);
    if (match != null) {
      _saveCookie(match.group(0));
    }
  }

  static Map<String, String> _headers({bool json = true}) => {
        if (json) 'Content-Type': 'application/json',
        if (_cookie != null) 'Cookie': _cookie!,
      };

  static Future<Map<String, dynamic>> get(String path) async {
    final r = await http.get(Uri.parse('$kApi$path'), headers: _headers(json: false));
    _captureSetCookie(r);
    return _decode(r);
  }

  static Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body) async {
    final r = await http.post(Uri.parse('$kApi$path'),
        headers: _headers(), body: jsonEncode(body));
    _captureSetCookie(r);
    return _decode(r);
  }

  static Future<Map<String, dynamic>> put(String path, Map<String, dynamic> body) async {
    final r = await http.put(Uri.parse('$kApi$path'),
        headers: _headers(), body: jsonEncode(body));
    _captureSetCookie(r);
    return _decode(r);
  }

  static Future<Map<String, dynamic>> delete(String path, Map<String, dynamic> body) async {
    final req = http.Request('DELETE', Uri.parse('$kApi$path'));
    req.headers.addAll(_headers());
    req.body = jsonEncode(body);
    final streamed = await req.send();
    final r = await http.Response.fromStream(streamed);
    _captureSetCookie(r);
    return _decode(r);
  }

  static Map<String, dynamic> _decode(http.Response r) {
    if (r.body.isEmpty) return {};
    final decoded = jsonDecode(r.body);
    if (decoded is List) return {'_list': decoded};
    return decoded is Map<String, dynamic> ? decoded : {};
  }

  static Future<void> logout() async {
    await http.delete(Uri.parse('$kApi/login.php'), headers: _headers(json: false));
    await _saveCookie(null);
    csrf = '';
    role = 'user';
  }
}
