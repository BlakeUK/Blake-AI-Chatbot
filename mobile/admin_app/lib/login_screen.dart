import 'package:flutter/material.dart';

import 'api_client.dart';
import 'home_shell.dart';

const kBrandDark = Color(0xFF2F343B);
const kBrandBlue = Color(0xFF3D5A99);

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  final _codeCtrl = TextEditingController();
  bool _needs2fa = false;
  bool _busy = false;
  String? _error;

  Future<void> _submitPassword() async {
    if (_userCtrl.text.trim().isEmpty || _passCtrl.text.isEmpty) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final r = await ApiClient.post('/login.php', {
      'username': _userCtrl.text.trim(),
      'password': _passCtrl.text,
    });
    setState(() => _busy = false);
    if (r['ok'] == true) {
      ApiClient.csrf = r['csrf']?.toString() ?? '';
      ApiClient.role = r['role']?.toString() ?? 'user';
      _goHome();
    } else if (r['requires_2fa'] == true) {
      setState(() => _needs2fa = true);
    } else {
      setState(() => _error = r['error']?.toString() ?? 'Login failed');
    }
  }

  Future<void> _submitCode() async {
    if (_codeCtrl.text.trim().isEmpty) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final r = await ApiClient.post('/login.php', {'code': _codeCtrl.text.trim()});
    setState(() => _busy = false);
    if (r['ok'] == true) {
      ApiClient.csrf = r['csrf']?.toString() ?? '';
      ApiClient.role = r['role']?.toString() ?? 'user';
      _goHome();
    } else {
      setState(() => _error = r['error']?.toString() ?? 'Invalid code');
    }
  }

  void _goHome() {
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const HomeShell()),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: kBrandDark,
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 360),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Image.asset('assets/blake-uk-logo.png', height: 40),
                    const SizedBox(height: 16),
                    Text(_needs2fa ? 'Verify' : 'Admin',
                        style: const TextStyle(
                            fontSize: 20, fontWeight: FontWeight.bold, color: Color(0xFF8FB0EC))),
                    const SizedBox(height: 16),
                    if (_error != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: const Color(0xFFF8D7DA),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(_error!,
                              style: const TextStyle(color: Color(0xFF721C24))),
                        ),
                      ),
                    if (!_needs2fa) ...[
                      TextField(
                        controller: _userCtrl,
                        decoration: const InputDecoration(labelText: 'Username'),
                        textInputAction: TextInputAction.next,
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _passCtrl,
                        decoration: const InputDecoration(labelText: 'Password'),
                        obscureText: true,
                        onSubmitted: (_) => _submitPassword(),
                      ),
                      const SizedBox(height: 20),
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton(
                          style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
                          onPressed: _busy ? null : _submitPassword,
                          child: Text(_busy ? 'Logging in...' : 'Log in'),
                        ),
                      ),
                    ] else ...[
                      TextField(
                        controller: _codeCtrl,
                        decoration: const InputDecoration(
                            labelText: 'Authenticator code or backup code'),
                        keyboardType: TextInputType.text,
                        onSubmitted: (_) => _submitCode(),
                      ),
                      const SizedBox(height: 20),
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton(
                          style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
                          onPressed: _busy ? null : _submitCode,
                          child: Text(_busy ? 'Verifying...' : 'Verify'),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
