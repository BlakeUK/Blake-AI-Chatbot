import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

class ChatSessionDetailScreen extends StatefulWidget {
  final String sessionId;
  const ChatSessionDetailScreen({super.key, required this.sessionId});

  @override
  State<ChatSessionDetailScreen> createState() => _ChatSessionDetailScreenState();
}

class _ChatSessionDetailScreenState extends State<ChatSessionDetailScreen> {
  bool _loading = true;
  List<dynamic> _messages = [];

  bool get _canEdit => ApiClient.role == 'admin' || ApiClient.role == 'editor';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/chats.php?session_id=${Uri.encodeComponent(widget.sessionId)}');
    setState(() {
      _messages = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  Future<void> _openCorrectionSheet(Map<String, dynamic> message) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _CorrectionSheet(messageId: message['id'] as int),
    );
    if (saved == true && mounted) flash(context, 'Correction saved');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Conversation')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _messages.isEmpty
              ? const Center(child: Text('No messages in this session.', style: TextStyle(color: Colors.grey)))
              : ListView.builder(
                  padding: const EdgeInsets.all(12),
                  itemCount: _messages.length,
                  itemBuilder: (ctx, i) {
                    final m = _messages[i] as Map<String, dynamic>;
                    final isUser = m['role'] == 'user';
                    final confidence = (m['confidence'] as num?)?.toDouble();
                    final escalated = m['escalated'] == 1;
                    final lowConf = !isUser && confidence != null && confidence < 0.6;
                    final sources = (m['sources'] as List? ?? [])
                        .where((s) => (s['url'] as String?)?.isNotEmpty == true)
                        .toList();
                    return Align(
                      alignment: isUser ? Alignment.centerRight : Alignment.centerLeft,
                      child: Container(
                        margin: const EdgeInsets.symmetric(vertical: 4),
                        padding: const EdgeInsets.all(10),
                        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.82),
                        decoration: BoxDecoration(
                          color: isUser ? const Color(0xFFEEF1F8) : const Color(0xFFF8F8F8),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${isUser ? 'Customer' : 'Assistant'} · ${fmtDate(m['created_at'])}'
                              '${escalated ? ' · Escalated' : ''}',
                              style: const TextStyle(fontSize: 11, color: Colors.grey),
                            ),
                            const SizedBox(height: 4),
                            Text(m['content']?.toString() ?? ''),
                            for (final s in sources)
                              Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: Text(s['url'].toString(),
                                    style: const TextStyle(fontSize: 11, color: kBrandBlue)),
                              ),
                            if (!isUser && _canEdit)
                              Padding(
                                padding: const EdgeInsets.only(top: 6),
                                child: TextButton(
                                  style: TextButton.styleFrom(padding: EdgeInsets.zero),
                                  onPressed: () => _openCorrectionSheet(m),
                                  child: Text(lowConf ? '⚠ Correct this answer' : 'Correct this answer'),
                                ),
                              ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
    );
  }
}

class _CorrectionSheet extends StatefulWidget {
  final int messageId;
  const _CorrectionSheet({required this.messageId});

  @override
  State<_CorrectionSheet> createState() => _CorrectionSheetState();
}

class _CorrectionSheetState extends State<_CorrectionSheet> {
  final _textCtrl = TextEditingController();
  bool _promote = false;
  bool _saving = false;

  Future<void> _save() async {
    final corrected = _textCtrl.text.trim();
    if (corrected.isEmpty) {
      flash(context, 'Enter a corrected answer', error: true);
      return;
    }
    setState(() => _saving = true);
    final r = await ApiClient.post('/corrections.php', {
      'csrf': ApiClient.csrf,
      'message_id': widget.messageId,
      'corrected': corrected,
      'promote': _promote,
    });
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
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Correct this answer', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          TextField(
            controller: _textCtrl,
            maxLines: 4,
            decoration: const InputDecoration(labelText: 'Corrected answer', border: OutlineInputBorder()),
          ),
          CheckboxListTile(
            value: _promote,
            onChanged: (v) => setState(() => _promote = v ?? false),
            title: const Text('Add to knowledge base'),
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
          ),
          const SizedBox(height: 8),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
            onPressed: _saving ? null : _save,
            child: Text(_saving ? 'Saving...' : 'Save Correction'),
          ),
        ],
      ),
    );
  }
}
