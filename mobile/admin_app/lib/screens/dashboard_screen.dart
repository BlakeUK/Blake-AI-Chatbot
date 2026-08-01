import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  bool _loading = true;
  int _knowledge = 0, _files = 0, _products = 0, _chats = 0;
  List<dynamic> _recent = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final k = await ApiClient.get('/knowledge.php');
    final f = await ApiClient.get('/files.php');
    final p = await ApiClient.get('/products.php');
    final c = await ApiClient.get('/chats.php');
    final files = (f['_list'] as List? ?? []);
    setState(() {
      _knowledge = (k['_list'] as List? ?? []).length;
      _files = files.where((r) => r['status'] == 'indexed').length;
      _products = (p['_list'] as List? ?? []).length;
      _chats = (c['_list'] as List? ?? []).length;
      _recent = c['_list'] as List? ?? [];
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            childAspectRatio: 1.6,
            children: [
              StatTile(value: '$_knowledge', label: 'Knowledge Entries'),
              StatTile(value: '$_files', label: 'Indexed Files'),
              StatTile(value: '$_products', label: 'Products'),
              StatTile(value: '$_chats', label: 'Chat Sessions'),
            ],
          ),
          const SizedBox(height: 20),
          const Text('Recent Chat Sessions',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          if (_recent.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('No sessions yet.', style: TextStyle(color: Color(0xFFADB4BA))),
            )
          else
            ..._recent.map((r) => Card(
                  child: ListTile(
                    title: Text((r['page_url'] ?? 'Unknown page').toString(),
                        maxLines: 1, overflow: TextOverflow.ellipsis),
                    subtitle: Text('${r['msg_count']} messages'),
                    trailing: Text(fmtDate(r['updated_at'])),
                  ),
                )),
        ],
      ),
    );
  }
}
