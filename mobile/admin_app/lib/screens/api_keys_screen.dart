import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

const _kKnownServices = ['gemini', 'royalmail', 'dpd', 'dx'];

class ApiKeysScreen extends StatefulWidget {
  const ApiKeysScreen({super.key});

  @override
  State<ApiKeysScreen> createState() => _ApiKeysScreenState();
}

class _ApiKeysScreenState extends State<ApiKeysScreen> {
  bool _loading = true;
  List<dynamic> _rows = [];
  final _controllers = {for (final s in _kKnownServices) s: TextEditingController()};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/apikeys.php');
    setState(() {
      _rows = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  bool _configured(String service) => _rows.any((r) => r['service'] == service);

  Future<void> _save(String service) async {
    final value = _controllers[service]!.text.trim();
    if (value.isEmpty) return;
    final r = await ApiClient.post('/apikeys.php', {
      'csrf': ApiClient.csrf,
      'service': service,
      'value': value,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else {
      _controllers[service]!.clear();
      if (mounted) flash(context, '$service key saved');
      _load();
    }
  }

  Future<void> _delete(String service) async {
    final r = await ApiClient.delete('/apikeys.php', {'csrf': ApiClient.csrf, 'service': service});
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
          const Text(
            'Store API keys for the Gemini AI model and carrier tracking services. Keys are encrypted at rest and never shown again once saved.',
            style: TextStyle(color: Colors.grey, fontSize: 13),
          ),
          const SizedBox(height: 16),
          for (final service in _kKnownServices) ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(
                  children: [
                    Expanded(
                      flex: 2,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(service, style: const TextStyle(fontWeight: FontWeight.bold)),
                          const SizedBox(height: 4),
                          StatusBadge(
                            text: _configured(service) ? 'Configured' : 'Not set',
                            ok: _configured(service),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      flex: 3,
                      child: TextField(
                        controller: _controllers[service],
                        obscureText: true,
                        decoration: const InputDecoration(
                          isDense: true,
                          hintText: 'Paste key',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.save, color: kBrandBlue),
                      onPressed: () => _save(service),
                    ),
                    if (_configured(service))
                      IconButton(
                        icon: const Icon(Icons.delete, color: Colors.red),
                        onPressed: () => _delete(service),
                      ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 8),
          ],
        ],
      ),
    );
  }
}
