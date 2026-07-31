import 'dart:convert';
import 'package:http/http.dart' as http;

/// Base URL of the Blake UK chatbot backend. Update if the server moves
/// to a domain with HTTPS.
const String kApiBase = 'http://195.22.157.38';

class ApiClient {
  static Future<Map<String, dynamic>> post(
    String path,
    Map<String, dynamic> body,
  ) async {
    final r = await http.post(
      Uri.parse('$kApiBase$path'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode(body),
    );
    final decoded = jsonDecode(r.body);
    return decoded is Map<String, dynamic> ? decoded : {};
  }
}
