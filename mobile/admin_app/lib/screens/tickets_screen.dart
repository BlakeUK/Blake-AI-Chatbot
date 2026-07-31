import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';
import 'chat_session_detail_screen.dart';

const _kStatuses = ['open', 'pending', 'closed'];

class TicketsScreen extends StatefulWidget {
  const TicketsScreen({super.key});

  @override
  State<TicketsScreen> createState() => _TicketsScreenState();
}

class _TicketsScreenState extends State<TicketsScreen> {
  bool _loading = true;
  List<dynamic> _rows = [];
  String _filter = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/tickets.php${_filter.isNotEmpty ? '?status=$_filter' : ''}');
    setState(() {
      _rows = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  Future<void> _openTicket(Map<String, dynamic> ticket) async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => _TicketDetailScreen(ticket: ticket),
    ));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: DropdownButtonFormField<String>(
            value: _filter,
            items: const [
              DropdownMenuItem(value: '', child: Text('All statuses')),
              DropdownMenuItem(value: 'open', child: Text('Open')),
              DropdownMenuItem(value: 'pending', child: Text('Pending')),
              DropdownMenuItem(value: 'closed', child: Text('Closed')),
            ],
            onChanged: (v) {
              setState(() => _filter = v ?? '');
              _load();
            },
            decoration: const InputDecoration(
              labelText: 'Filter by status',
              border: OutlineInputBorder(),
              isDense: true,
            ),
          ),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _load,
                  child: _rows.isEmpty
                      ? ListView(children: const [
                          Padding(
                            padding: EdgeInsets.all(24),
                            child: Center(
                                child: Text('No tickets.', style: TextStyle(color: Colors.grey))),
                          )
                        ])
                      : ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 12),
                          itemCount: _rows.length,
                          itemBuilder: (ctx, i) {
                            final t = _rows[i] as Map<String, dynamic>;
                            final status = t['status']?.toString() ?? '';
                            return Card(
                              child: ListTile(
                                title: Text(t['subject']?.toString() ?? 'Support ticket',
                                    maxLines: 1, overflow: TextOverflow.ellipsis),
                                subtitle: Text(t['customer_email']?.toString() ?? '—'),
                                trailing: StatusBadge(text: status, ok: status == 'open'),
                                onTap: () => _openTicket(t),
                              ),
                            );
                          },
                        ),
                ),
        ),
      ],
    );
  }
}

class _TicketDetailScreen extends StatefulWidget {
  final Map<String, dynamic> ticket;
  const _TicketDetailScreen({required this.ticket});

  @override
  State<_TicketDetailScreen> createState() => _TicketDetailScreenState();
}

class _TicketDetailScreenState extends State<_TicketDetailScreen> {
  late String _status = widget.ticket['status']?.toString() ?? 'open';
  late String? _notes = widget.ticket['notes']?.toString();
  final _noteCtrl = TextEditingController();

  bool get _canEdit => ApiClient.role == 'admin' || ApiClient.role == 'editor';

  Future<void> _updateStatus(String status) async {
    final r = await ApiClient.put('/tickets.php', {
      'csrf': ApiClient.csrf,
      'id': widget.ticket['id'],
      'status': status,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else {
      setState(() => _status = status);
      if (mounted) flash(context, 'Ticket status updated');
    }
  }

  Future<void> _addNote() async {
    final note = _noteCtrl.text.trim();
    if (note.isEmpty) return;
    final r = await ApiClient.post('/tickets.php', {
      'csrf': ApiClient.csrf,
      'id': widget.ticket['id'],
      'note': note,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    final ts = _fmtNow();
    setState(() {
      _notes = (_notes == null || _notes!.isEmpty) ? '[$ts] $note' : '$_notes\n\n[$ts] $note';
      _noteCtrl.clear();
    });
  }

  String _fmtNow() {
    final d = DateTime.now();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(d.day)}/${two(d.month)}/${d.year} ${two(d.hour)}:${two(d.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    final t = widget.ticket;
    final sessionId = t['session_id']?.toString();
    return Scaffold(
      appBar: AppBar(title: Text(t['subject']?.toString() ?? 'Support ticket')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text('Customer: ${t['customer_email'] ?? '—'} · Created ${fmtDate(t['created_at'])}',
              style: const TextStyle(color: Colors.grey, fontSize: 13)),
          const SizedBox(height: 16),
          if (_canEdit)
            Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    value: _status,
                    items: _kStatuses
                        .map((s) => DropdownMenuItem(value: s, child: Text(s)))
                        .toList(),
                    onChanged: (v) {
                      if (v != null) _updateStatus(v);
                    },
                    decoration: const InputDecoration(labelText: 'Status', border: OutlineInputBorder()),
                  ),
                ),
              ],
            )
          else
            StatusBadge(text: _status, ok: _status == 'open'),
          const SizedBox(height: 20),
          const Text('Notes', style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
          const SizedBox(height: 6),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(color: const Color(0xFFF8F8F8), borderRadius: BorderRadius.circular(6)),
            child: Text(_notes?.isNotEmpty == true ? _notes! : 'No notes yet.'),
          ),
          if (_canEdit) ...[
            const SizedBox(height: 8),
            TextField(
              controller: _noteCtrl,
              maxLines: 3,
              decoration: const InputDecoration(labelText: 'Add a note', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 8),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
              onPressed: _addNote,
              child: const Text('Add Note'),
            ),
          ],
          if (sessionId != null && sessionId.isNotEmpty) ...[
            const SizedBox(height: 20),
            OutlinedButton(
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => ChatSessionDetailScreen(sessionId: sessionId),
              )),
              child: const Text('View conversation'),
            ),
          ],
        ],
      ),
    );
  }
}
