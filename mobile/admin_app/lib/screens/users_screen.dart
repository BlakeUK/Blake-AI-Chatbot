import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

const _kRoles = ['admin', 'editor', 'user'];

class UsersScreen extends StatefulWidget {
  const UsersScreen({super.key});

  @override
  State<UsersScreen> createState() => _UsersScreenState();
}

class _UsersScreenState extends State<UsersScreen> {
  bool _loading = true;
  List<dynamic> _rows = [];

  final _nameCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  String _newRole = 'user';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/users.php');
    setState(() {
      _rows = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  Future<void> _create() async {
    final username = _nameCtrl.text.trim();
    final password = _passCtrl.text;
    if (username.isEmpty || password.isEmpty) {
      flash(context, 'Username and password required', error: true);
      return;
    }
    if (password.length < 8) {
      flash(context, 'Password must be at least 8 characters', error: true);
      return;
    }
    final r = await ApiClient.post('/users.php', {
      'csrf': ApiClient.csrf,
      'username': username,
      'password': password,
      'role': _newRole,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else {
      _nameCtrl.clear();
      _passCtrl.clear();
      if (mounted) flash(context, 'User created');
      _load();
    }
  }

  Future<void> _updateRole(int id, String role) async {
    final r = await ApiClient.put('/users.php', {'csrf': ApiClient.csrf, 'id': id, 'role': role});
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      _load();
    } else {
      if (mounted) flash(context, 'Role updated');
      _load();
    }
  }

  Future<void> _resetTwoFactor(int id) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Reset 2FA?'),
        content: const Text('This user will need to set up 2FA again.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Reset')),
        ],
      ),
    );
    if (confirmed != true) return;
    final r = await ApiClient.put('/users.php', {'csrf': ApiClient.csrf, 'id': id, 'reset_2fa': true});
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else {
      if (mounted) flash(context, '2FA reset');
      _load();
    }
  }

  Future<void> _delete(int id) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete this user?'),
        content: const Text('This cannot be undone.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Delete')),
        ],
      ),
    );
    if (confirmed != true) return;
    final r = await ApiClient.delete('/users.php', {'csrf': ApiClient.csrf, 'id': id});
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else {
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text('Add User', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          TextField(
            controller: _nameCtrl,
            decoration: const InputDecoration(labelText: 'Username', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _passCtrl,
            obscureText: true,
            decoration: const InputDecoration(labelText: 'Password', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 8),
          DropdownButtonFormField<String>(
            value: _newRole,
            items: _kRoles
                .map((r) => DropdownMenuItem(value: r, child: Text(r)))
                .toList(),
            onChanged: (v) => setState(() => _newRole = v ?? 'user'),
            decoration: const InputDecoration(labelText: 'Role', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 12),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
            onPressed: _create,
            child: const Text('Create'),
          ),
          const SizedBox(height: 24),
          const Text('Users', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          if (_rows.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('No users yet.', style: TextStyle(color: Color(0xFFADB4BA))),
            )
          else
            ..._rows.map((r) {
              final id = r['id'] as int;
              final totpEnabled = r['totp_enabled'] == 1;
              return Card(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(r['username']?.toString() ?? '',
                                style: const TextStyle(fontWeight: FontWeight.bold)),
                          ),
                          IconButton(
                            icon: const Icon(Icons.delete, color: Color(0xFFF5A3A3)),
                            onPressed: () => _delete(id),
                          ),
                        ],
                      ),
                      Wrap(
                        crossAxisAlignment: WrapCrossAlignment.center,
                        spacing: 8,
                        children: [
                          DropdownButton<String>(
                            value: r['role']?.toString(),
                            items: _kRoles
                                .map((role) => DropdownMenuItem(value: role, child: Text(role)))
                                .toList(),
                            onChanged: (v) {
                              if (v != null) _updateRole(id, v);
                            },
                          ),
                          StatusBadge(text: totpEnabled ? '2FA On' : '2FA Off', ok: totpEnabled),
                          if (totpEnabled)
                            TextButton(
                              onPressed: () => _resetTwoFactor(id),
                              child: const Text('Reset 2FA'),
                            ),
                        ],
                      ),
                      Text(
                        'Created ${fmtDate(r['created_at'])} · Last login ${r['last_login'] != null ? fmtDate(r['last_login']) : 'Never'}',
                        style: const TextStyle(fontSize: 11, color: Color(0xFFADB4BA)),
                      ),
                    ],
                  ),
                ),
              );
            }),
        ],
      ),
    );
  }
}
