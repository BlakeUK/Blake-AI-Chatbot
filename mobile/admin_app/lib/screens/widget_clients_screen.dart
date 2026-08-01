import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

final _domainRe = RegExp(r'^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$',
    caseSensitive: false);

class WidgetClientsScreen extends StatefulWidget {
  const WidgetClientsScreen({super.key});

  @override
  State<WidgetClientsScreen> createState() => _WidgetClientsScreenState();
}

class _WidgetClientsScreenState extends State<WidgetClientsScreen> {
  bool _loading = true;
  List<dynamic> _rows = [];

  final _nameCtrl = TextEditingController();
  final _ipsCtrl = TextEditingController();
  final _originsCtrl = TextEditingController();
  String? _createdKey;
  String? _createdName;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/clients.php');
    setState(() {
      _rows = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  Future<void> _onNameBlur() async {
    final raw = _nameCtrl.text.trim();
    final domain = raw.replaceFirst(RegExp(r'^https?://', caseSensitive: false), '').split('/').first;
    if (!_domainRe.hasMatch(domain)) return;
    final r = await ApiClient.get('/resolve.php?domain=${Uri.encodeComponent(domain)}');
    if (r['ip'] != null) {
      if (_ipsCtrl.text.trim().isEmpty) _ipsCtrl.text = r['ip'].toString();
      if (_originsCtrl.text.trim().isEmpty) _originsCtrl.text = 'https://$domain';
    }
  }

  Future<void> _create() async {
    final name = _nameCtrl.text.trim();
    if (name.isEmpty) {
      flash(context, 'Client name required', error: true);
      return;
    }
    final r = await ApiClient.post('/clients.php', {
      'csrf': ApiClient.csrf,
      'name': name,
      'allowed_ips': _ipsCtrl.text.trim(),
      'allowed_origins': _originsCtrl.text.trim(),
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    setState(() {
      _createdKey = r['api_key']?.toString();
      _createdName = r['name']?.toString();
    });
    _nameCtrl.clear();
    _ipsCtrl.clear();
    _originsCtrl.clear();
    _load();
  }

  Future<void> _revoke(int id) async {
    final r = await ApiClient.delete('/clients.php', {'csrf': ApiClient.csrf, 'id': id});
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
          const Text('Create External Widget Client',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          const Text(
              'Each client gets a unique API key. Access can be locked to specific server IP addresses and/or origin domains.',
              style: TextStyle(color: Colors.grey, fontSize: 12)),
          const SizedBox(height: 12),
          TextField(
            controller: _nameCtrl,
            decoration: const InputDecoration(
              labelText: 'Client Name',
              hintText: 'e.g. Partner Site A or partnersite.co.uk',
              border: OutlineInputBorder(),
            ),
            onEditingComplete: _onNameBlur,
            onTapOutside: (_) => _onNameBlur(),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _ipsCtrl,
            decoration: const InputDecoration(
              labelText: 'Allowed IPs (comma or newline — empty = allow all)',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _originsCtrl,
            decoration: const InputDecoration(
              labelText: 'Allowed Origins (empty = allow all)',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
            onPressed: _create,
            child: const Text('Create'),
          ),
          if (_createdKey != null)
            Container(
              margin: const EdgeInsets.only(top: 12),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                  color: const Color(0xFFD4EDDA), borderRadius: BorderRadius.circular(4)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Client $_createdName created.',
                      style: const TextStyle(color: Color(0xFF155724))),
                  const SizedBox(height: 4),
                  SelectableText(_createdKey!,
                      style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace')),
                  const Text('Copy this now — it will not be shown again.',
                      style: TextStyle(fontSize: 11, color: Colors.grey)),
                ],
              ),
            ),
          const SizedBox(height: 24),
          const Text('Widget Clients', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          if (_rows.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('No clients configured.', style: TextStyle(color: Colors.grey)),
            )
          else
            ..._rows.map((r) {
              final active = r['active'] == 1;
              return Card(
                child: ListTile(
                  title: Text(r['name']?.toString() ?? ''),
                  subtitle: Text(r['api_key_masked']?.toString() ?? ''),
                  trailing: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      StatusBadge(text: active ? 'Active' : 'Revoked', ok: active),
                      if (active)
                        IconButton(
                          icon: const Icon(Icons.block, color: Colors.red),
                          onPressed: () => _revoke(r['id'] as int),
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
