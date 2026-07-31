import 'package:flutter/material.dart';

import 'api_client.dart';
import 'home_shell.dart';
import 'login_screen.dart';

void main() {
  runApp(const BlakeUkAdminApp());
}

class BlakeUkAdminApp extends StatelessWidget {
  const BlakeUkAdminApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Blake UK Admin',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF3D5A99)),
        useMaterial3: true,
      ),
      home: const _SessionGate(),
    );
  }
}

/// Restores a previously-saved session cookie (if any) and checks with the
/// server whether it's still valid, so the app doesn't force a fresh login
/// every time it's reopened while the PHP session is still alive.
class _SessionGate extends StatefulWidget {
  const _SessionGate();

  @override
  State<_SessionGate> createState() => _SessionGateState();
}

class _SessionGateState extends State<_SessionGate> {
  bool _checking = true;
  bool _loggedIn = false;

  @override
  void initState() {
    super.initState();
    _check();
  }

  Future<void> _check() async {
    await ApiClient.restoreCookie();
    final r = await ApiClient.get('/session.php');
    if (r['ok'] == true) {
      ApiClient.csrf = r['csrf']?.toString() ?? '';
      ApiClient.role = r['role']?.toString() ?? 'user';
    }
    setState(() {
      _loggedIn = r['ok'] == true;
      _checking = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_checking) {
      return const Scaffold(
        backgroundColor: Color(0xFF2F343B),
        body: Center(child: CircularProgressIndicator(color: Colors.white)),
      );
    }
    return _loggedIn ? const HomeShell() : const LoginScreen();
  }
}
