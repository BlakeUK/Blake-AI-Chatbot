import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

class ChatLogsScreen extends StatefulWidget {
  const ChatLogsScreen({super.key});

  @override
  State<ChatLogsScreen> createState() => _ChatLogsScreenState();
}

class _ChatLogsScreenState extends State<ChatLogsScreen> {
  bool _loading = true;
  List<dynamic> _rows = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/chats.php?limit=100');
    setState(() {
      _rows = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    return RefreshIndicator(
      onRefresh: _load,
      child: _rows.isEmpty
          ? ListView(children: const [
              Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: Text('No chat sessions yet.', style: TextStyle(color: Colors.grey))),
              )
            ])
          : ListView.builder(
              padding: const EdgeInsets.all(12),
              itemCount: _rows.length,
              itemBuilder: (ctx, i) {
                final r = _rows[i] as Map<String, dynamic>;
                final id = r['id']?.toString() ?? '';
                return Card(
                  child: ListTile(
                    title: Text(
                      (r['page_url'] as String?)?.isNotEmpty == true
                          ? r['page_url'].toString()
                          : id.substring(0, id.length > 12 ? 12 : id.length),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    subtitle: Text(
                        '${r['product_code'] != null ? 'Product ${r['product_code']} · ' : ''}${r['msg_count']} messages'),
                    trailing: Text(fmtDate(r['updated_at']), style: const TextStyle(fontSize: 12)),
                  ),
                );
              },
            ),
    );
  }
}
