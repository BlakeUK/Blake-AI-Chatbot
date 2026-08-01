import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

class KnowledgeScreen extends StatefulWidget {
  const KnowledgeScreen({super.key});

  @override
  State<KnowledgeScreen> createState() => _KnowledgeScreenState();
}

class _KnowledgeScreenState extends State<KnowledgeScreen> {
  bool _loading = true;
  List<dynamic> _rows = [];

  bool get _canEdit => ApiClient.role == 'admin' || ApiClient.role == 'editor';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/knowledge.php');
    setState(() {
      _rows = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  Future<void> _delete(int id) async {
    final r = await ApiClient.delete('/knowledge.php', {'csrf': ApiClient.csrf, 'id': id});
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else {
      _load();
    }
  }

  Future<void> _openForm({Map<String, dynamic>? existing}) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _KnowledgeForm(existing: existing),
    );
    if (saved == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: _canEdit
          ? FloatingActionButton(
              backgroundColor: kBrandBlue,
              onPressed: () => _openForm(),
              child: const Icon(Icons.add, color: Colors.white),
            )
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _rows.isEmpty
                  ? ListView(children: const [
                      Padding(
                        padding: EdgeInsets.all(24),
                        child: Center(
                            child: Text('No entries yet.', style: TextStyle(color: Color(0xFFADB4BA)))),
                      )
                    ])
                  : ListView.builder(
                      padding: const EdgeInsets.all(12),
                      itemCount: _rows.length,
                      itemBuilder: (ctx, i) {
                        final r = _rows[i] as Map<String, dynamic>;
                        return Card(
                          child: ListTile(
                            title: Text(r['title']?.toString() ?? ''),
                            subtitle: Text(r['category']?.toString() ?? ''),
                            trailing: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                StatusBadge(text: r['active'] == 1 ? 'Active' : 'Inactive', ok: r['active'] == 1),
                                if (_canEdit)
                                  IconButton(
                                    icon: const Icon(Icons.delete, color: Color(0xFFF5A3A3)),
                                    onPressed: () => _delete(r['id'] as int),
                                  ),
                              ],
                            ),
                            onTap: _canEdit ? () => _openForm(existing: r) : null,
                          ),
                        );
                      },
                    ),
            ),
    );
  }
}

class _KnowledgeForm extends StatefulWidget {
  final Map<String, dynamic>? existing;
  const _KnowledgeForm({this.existing});

  @override
  State<_KnowledgeForm> createState() => _KnowledgeFormState();
}

class _KnowledgeFormState extends State<_KnowledgeForm> {
  late final _title = TextEditingController(text: widget.existing?['title']?.toString());
  late final _body = TextEditingController(text: widget.existing?['body']?.toString());
  late final _category = TextEditingController(text: widget.existing?['category']?.toString());
  late final _url = TextEditingController(text: widget.existing?['url']?.toString());
  late final _codes = TextEditingController(text: widget.existing?['product_codes']?.toString());
  bool _saving = false;

  Future<void> _save() async {
    if (_title.text.trim().isEmpty || _body.text.trim().isEmpty) {
      flash(context, 'Title and content required', error: true);
      return;
    }
    setState(() => _saving = true);
    final body = {
      'csrf': ApiClient.csrf,
      'title': _title.text.trim(),
      'body': _body.text.trim(),
      'category': _category.text.trim(),
      'url': _url.text.trim(),
      'product_codes': _codes.text.trim(),
    };
    final r = widget.existing != null
        ? await ApiClient.put('/knowledge.php', {...body, 'id': widget.existing!['id'], 'active': 1})
        : await ApiClient.post('/knowledge.php', body);
    setState(() => _saving = false);
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else if (mounted) {
      Navigator.of(context).pop(true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 16,
        right: 16,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom + 16,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(widget.existing != null ? 'Edit Knowledge Entry' : 'New Knowledge Entry',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            TextField(controller: _title, decoration: const InputDecoration(labelText: 'Title')),
            const SizedBox(height: 8),
            TextField(
                controller: _body,
                decoration: const InputDecoration(labelText: 'Content'),
                maxLines: 5),
            const SizedBox(height: 8),
            TextField(controller: _category, decoration: const InputDecoration(labelText: 'Category')),
            const SizedBox(height: 8),
            TextField(controller: _url, decoration: const InputDecoration(labelText: 'URL')),
            const SizedBox(height: 8),
            TextField(
                controller: _codes,
                decoration: const InputDecoration(labelText: 'Product codes (comma separated)')),
            const SizedBox(height: 16),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
              onPressed: _saving ? null : _save,
              child: Text(_saving ? 'Saving...' : 'Save'),
            ),
          ],
        ),
      ),
    );
  }
}
