import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

class ModelSettingsScreen extends StatefulWidget {
  const ModelSettingsScreen({super.key});

  @override
  State<ModelSettingsScreen> createState() => _ModelSettingsScreenState();
}

class _ModelSettingsScreenState extends State<ModelSettingsScreen> {
  bool _loading = true;
  bool _refreshing = false;
  List<dynamic> _models = [];
  String? _chatModel;
  String? _extractModel;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadSettings();
    // This screen is mounted (and its initState run) once, up front, by the
    // IndexedStack in HomeShell — so if the Gemini key gets saved later from
    // the API Keys tab, this screen needs an explicit nudge to refresh
    // rather than relying on a fresh initState call.
    ApiClient.modelsRefreshTick.addListener(_refreshModels);
  }

  @override
  void dispose() {
    ApiClient.modelsRefreshTick.removeListener(_refreshModels);
    super.dispose();
  }

  Future<void> _loadSettings() async {
    setState(() => _loading = true);
    final s = await ApiClient.get('/settings.php');
    setState(() {
      _chatModel = s['gemini_chat_model']?.toString();
      _extractModel = s['gemini_extract_model']?.toString();
      _loading = false;
    });
    _refreshModels();
  }

  Future<void> _refreshModels() async {
    setState(() {
      _refreshing = true;
      _error = null;
    });
    final r = await ApiClient.get('/models.php');
    setState(() {
      _refreshing = false;
      if (r['error'] != null) {
        _error = r['error'].toString();
        _models = [];
      } else {
        _models = r['_list'] as List? ?? [];
      }
    });
  }

  Future<void> _save() async {
    final r = await ApiClient.post('/settings.php', {
      'csrf': ApiClient.csrf,
      if (_chatModel != null) 'gemini_chat_model': _chatModel,
      if (_extractModel != null) 'gemini_extract_model': _extractModel,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else if (mounted) {
      flash(context, 'Model settings saved');
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          children: [
            const Expanded(
              child: Text(
                'Choose which Gemini model handles chat replies and which handles document extraction.',
                style: TextStyle(color: Color(0xFFADB4BA), fontSize: 13),
              ),
            ),
            IconButton(
              icon: _refreshing
                  ? const SizedBox(
                      height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.refresh, color: kBrandBlue),
              onPressed: _refreshing ? null : _refreshModels,
            ),
          ],
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                  color: const Color(0xFFFFF3CD), borderRadius: BorderRadius.circular(4)),
              child: Text(_error!, style: const TextStyle(color: Color(0xFF856404))),
            ),
          ),
        const SizedBox(height: 16),
        const Text('Chat model', style: TextStyle(fontWeight: FontWeight.bold)),
        DropdownButtonFormField<String>(
          value: _models.any((m) => m['id'] == _chatModel) ? _chatModel : null,
          items: _models
              .map((m) => DropdownMenuItem<String>(
                    value: m['id'] as String,
                    child: Text(m['displayName']?.toString() ?? m['id'].toString()),
                  ))
              .toList(),
          onChanged: (v) => setState(() => _chatModel = v),
          decoration: const InputDecoration(border: OutlineInputBorder(), isDense: true),
        ),
        const SizedBox(height: 16),
        const Text('Document extraction model', style: TextStyle(fontWeight: FontWeight.bold)),
        DropdownButtonFormField<String>(
          value: _models.any((m) => m['id'] == _extractModel) ? _extractModel : null,
          items: _models
              .map((m) => DropdownMenuItem<String>(
                    value: m['id'] as String,
                    child: Text(m['displayName']?.toString() ?? m['id'].toString()),
                  ))
              .toList(),
          onChanged: (v) => setState(() => _extractModel = v),
          decoration: const InputDecoration(border: OutlineInputBorder(), isDense: true),
        ),
        const SizedBox(height: 20),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
          onPressed: _save,
          child: const Text('Save'),
        ),
      ],
    );
  }
}
