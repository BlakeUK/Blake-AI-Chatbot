import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../api_client.dart';
import '../widgets.dart';

class MyAccountScreen extends StatefulWidget {
  const MyAccountScreen({super.key});

  @override
  State<MyAccountScreen> createState() => _MyAccountScreenState();
}

class _MyAccountScreenState extends State<MyAccountScreen> {
  final _currentPassCtrl = TextEditingController();
  final _newPassCtrl = TextEditingController();

  bool _loadingStatus = true;
  bool _enabled = false;

  // Enrollment-in-progress state
  String? _pendingSecret;
  String? _pendingUri;
  final _confirmCodeCtrl = TextEditingController();
  List<String>? _backupCodes;

  // Disable-flow state
  bool _showDisableForm = false;
  final _disablePassCtrl = TextEditingController();
  final _disableCodeCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadStatus();
  }

  Future<void> _loadStatus() async {
    setState(() => _loadingStatus = true);
    final r = await ApiClient.get('/twofactor.php');
    setState(() {
      _enabled = r['enabled'] == true;
      _loadingStatus = false;
    });
  }

  Future<void> _changePassword() async {
    if (_currentPassCtrl.text.isEmpty || _newPassCtrl.text.isEmpty) {
      flash(context, 'Both fields are required', error: true);
      return;
    }
    final r = await ApiClient.post('/account.php', {
      'csrf': ApiClient.csrf,
      'current_password': _currentPassCtrl.text,
      'new_password': _newPassCtrl.text,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else {
      _currentPassCtrl.clear();
      _newPassCtrl.clear();
      if (mounted) flash(context, 'Password changed');
    }
  }

  Future<void> _startEnrollment() async {
    final r = await ApiClient.post('/twofactor.php', {'csrf': ApiClient.csrf});
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    setState(() {
      _pendingSecret = r['secret']?.toString();
      _pendingUri = r['otpauth_uri']?.toString();
      _backupCodes = null;
    });
  }

  Future<void> _confirmEnrollment() async {
    final code = _confirmCodeCtrl.text.trim();
    if (code.isEmpty) return;
    final r = await ApiClient.put('/twofactor.php', {'csrf': ApiClient.csrf, 'code': code});
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    setState(() {
      _backupCodes = (r['backup_codes'] as List? ?? []).map((e) => e.toString()).toList();
      _pendingSecret = null;
      _pendingUri = null;
      _confirmCodeCtrl.clear();
      _enabled = true;
    });
  }

  Future<void> _disable() async {
    if (_disablePassCtrl.text.isEmpty || _disableCodeCtrl.text.trim().isEmpty) {
      flash(context, 'Password and code required', error: true);
      return;
    }
    final r = await ApiClient.delete('/twofactor.php', {
      'csrf': ApiClient.csrf,
      'password': _disablePassCtrl.text,
      'code': _disableCodeCtrl.text.trim(),
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    setState(() {
      _enabled = false;
      _showDisableForm = false;
      _disablePassCtrl.clear();
      _disableCodeCtrl.clear();
    });
    if (mounted) flash(context, '2FA disabled');
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text('Change Password', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        TextField(
          controller: _currentPassCtrl,
          obscureText: true,
          decoration:
              const InputDecoration(labelText: 'Current password', border: OutlineInputBorder()),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _newPassCtrl,
          obscureText: true,
          decoration: const InputDecoration(
              labelText: 'New password (min. 8 characters)', border: OutlineInputBorder()),
        ),
        const SizedBox(height: 12),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
          onPressed: _changePassword,
          child: const Text('Change Password'),
        ),
        const Divider(height: 40),
        const Text('Two-Factor Authentication',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        if (_loadingStatus)
          const Center(child: CircularProgressIndicator())
        else if (_backupCodes != null) ...[
          const Text('2FA enabled! Save these one-time backup codes somewhere safe:',
              style: TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: _backupCodes!
                .map((c) => Chip(label: Text(c, style: const TextStyle(fontFamily: 'monospace'))))
                .toList(),
          ),
        ] else if (_pendingSecret != null) ...[
          const Text('Scan this QR code into your authenticator app:'),
          const SizedBox(height: 12),
          Center(
            child: Container(
              padding: const EdgeInsets.all(12),
              color: Colors.white,
              child: QrImageView(data: _pendingUri!, size: 200),
            ),
          ),
          const SizedBox(height: 12),
          const Text('Or enter this key manually:', style: TextStyle(fontSize: 12, color: Colors.grey)),
          SelectableText(_pendingSecret!,
              style: const TextStyle(fontFamily: 'monospace', fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          TextField(
            controller: _confirmCodeCtrl,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
                labelText: 'Enter the 6-digit code from your app to confirm',
                border: OutlineInputBorder()),
          ),
          const SizedBox(height: 12),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
            onPressed: _confirmEnrollment,
            child: const Text('Confirm'),
          ),
        ] else if (_enabled) ...[
          const StatusBadge(text: '2FA Enabled', ok: true),
          const SizedBox(height: 12),
          if (!_showDisableForm)
            OutlinedButton(
              onPressed: () => setState(() => _showDisableForm = true),
              child: const Text('Disable 2FA'),
            )
          else ...[
            TextField(
              controller: _disablePassCtrl,
              obscureText: true,
              decoration:
                  const InputDecoration(labelText: 'Current password', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _disableCodeCtrl,
              decoration: const InputDecoration(
                  labelText: 'Authenticator or backup code', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: Colors.red),
              onPressed: _disable,
              child: const Text('Confirm Disable'),
            ),
          ],
        ] else ...[
          const StatusBadge(text: '2FA Disabled', ok: false),
          const SizedBox(height: 12),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
            onPressed: _startEnrollment,
            child: const Text('Enable 2FA'),
          ),
        ],
      ],
    );
  }
}
