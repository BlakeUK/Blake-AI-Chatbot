import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'api_client.dart';
import 'chat_message.dart';
import 'theme.dart';

const _kBrandDark = Color(0xFF2F343B);
const _kBrandBlue = Color(0xFF3D5A99);

class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key});

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final _messages = <ChatMessage>[];
  final _inputCtrl = TextEditingController();
  final _scrollCtrl = ScrollController();
  String? _sessionId;
  bool _sending = false;
  bool _initializing = true;

  @override
  void initState() {
    super.initState();
    _initSession();
  }

  Future<void> _initSession() async {
    final prefs = await SharedPreferences.getInstance();
    final existing = prefs.getString('buk_session');
    try {
      final r = await ApiClient.post('/api/chat/session.php', {
        if (existing != null) 'session_id': existing,
      });
      final id = r['session_id']?.toString();
      if (id != null) {
        await prefs.setString('buk_session', id);
        setState(() {
          _sessionId = id;
          _initializing = false;
          _messages.add(ChatMessage(
            role: 'assistant',
            text: 'Hello! How can I help you today?',
          ));
        });
      } else {
        setState(() => _initializing = false);
      }
    } catch (_) {
      setState(() {
        _initializing = false;
        _messages.add(ChatMessage(
          role: 'assistant',
          text: 'Unable to connect. Please try again shortly.',
        ));
      });
    }
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollCtrl.hasClients) {
        _scrollCtrl.animateTo(
          _scrollCtrl.position.maxScrollExtent,
          duration: const Duration(milliseconds: 200),
          curve: Curves.easeOut,
        );
      }
    });
  }

  Future<void> _send() async {
    final text = _inputCtrl.text.trim();
    if (text.isEmpty || _sessionId == null || _sending) return;
    _inputCtrl.clear();
    setState(() {
      _messages.add(ChatMessage(role: 'user', text: text));
      _sending = true;
    });
    _scrollToEnd();

    try {
      final r = await ApiClient.post('/api/chat/send.php', {
        'session_id': _sessionId,
        'message': text,
      });
      if (r['error'] != null) {
        setState(() => _messages.add(ChatMessage(
              role: 'assistant',
              text: 'Sorry, something went wrong. Please try again.',
            )));
      } else {
        final products = (r['products'] as List? ?? [])
            .map((p) => Product.fromJson(p as Map<String, dynamic>))
            .toList();
        setState(() => _messages.add(ChatMessage(
              role: 'assistant',
              text: r['answer']?.toString() ?? '',
              products: products,
            )));
        if (r['action'] == 'show_tracking_form') {
          setState(() => _messages.add(ChatMessage(
                role: 'assistant',
                text: '',
                kind: MessageKind.trackingForm,
                trackingNo: r['tracking_no']?.toString(),
                carrier: r['carrier']?.toString(),
              )));
        } else if (r['escalate'] == true) {
          setState(() {
            _messages.add(ChatMessage(
              role: 'assistant',
              text:
                  'Would you like me to raise a support ticket? A member of the team will get back to you.',
            ));
            _messages.add(ChatMessage(
              role: 'assistant',
              text: '',
              kind: MessageKind.escalateForm,
            ));
          });
        }
      }
    } catch (_) {
      setState(() => _messages.add(ChatMessage(
            role: 'assistant',
            text: 'Unable to reach the server. Please check your connection.',
          )));
    } finally {
      setState(() => _sending = false);
      _scrollToEnd();
    }
  }

  Future<void> _submitTracking(
      String trackingNo, String postcode, String? carrier) async {
    setState(() {
      // Remove the pending tracking-form bubble.
      _messages.removeWhere((m) => m.kind == MessageKind.trackingForm);
    });
    try {
      final r = await ApiClient.post('/api/chat/track.php', {
        'session_id': _sessionId,
        'tracking_no': trackingNo,
        'postcode': postcode,
        'carrier': carrier ?? '',
      });
      String text;
      if (r['status'] == 'found') {
        final events = (r['events'] as List? ?? [])
            .map((e) =>
                '• ${e['date'] ?? ''} ${e['description'] ?? ''}'.trim())
            .join('\n');
        text = '${r['carrier']} tracking ${r['tracking']}: ${r['current']}'
            '${events.isNotEmpty ? '\n$events' : ''}';
      } else {
        text = r['message']?.toString() ?? 'Unable to retrieve tracking information.';
      }
      setState(() => _messages.add(ChatMessage(role: 'assistant', text: text)));
    } catch (_) {
      setState(() => _messages.add(ChatMessage(
            role: 'assistant',
            text: 'Unable to reach the tracking service. Please try again shortly.',
          )));
    }
    _scrollToEnd();
  }

  Future<void> _submitEscalate(String email) async {
    setState(() {
      _messages.removeWhere((m) => m.kind == MessageKind.escalateForm);
    });
    try {
      final r = await ApiClient.post('/api/chat/escalate.php', {
        'session_id': _sessionId,
        'email': email,
      });
      final text = r['message']?.toString() ??
          'Your query has been passed to our support team.';
      setState(() => _messages.add(ChatMessage(role: 'assistant', text: text)));
    } catch (_) {
      setState(() => _messages.add(ChatMessage(
            role: 'assistant',
            text: 'Unable to reach the server. Please try again shortly.',
          )));
    }
    _scrollToEnd();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: kBgDark,
      appBar: AppBar(
        backgroundColor: _kBrandDark,
        foregroundColor: Colors.white,
        title: Row(
          children: [
            Image.asset('assets/blake-uk-logo.png', height: 24),
            const SizedBox(width: 10),
            const Text('Support'),
          ],
        ),
      ),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: _initializing
                  ? const Center(child: CircularProgressIndicator())
                  : ListView.builder(
                      controller: _scrollCtrl,
                      padding: const EdgeInsets.all(12),
                      itemCount: _messages.length,
                      itemBuilder: (ctx, i) => _buildMessage(_messages[i]),
                    ),
            ),
            if (_sending)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 4),
                child: SizedBox(
                  height: 16,
                  width: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ),
            _buildInputRow(),
          ],
        ),
      ),
    );
  }

  Widget _buildInputRow() {
    return Container(
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: kBgDark,
        border: Border(top: BorderSide(color: const Color(0xFF454C54))),
      ),
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: _inputCtrl,
              enabled: !_initializing,
              maxLength: 500,
              decoration: const InputDecoration(
                hintText: 'Ask a question...',
                border: OutlineInputBorder(),
                counterText: '',
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              ),
              onSubmitted: (_) => _send(),
            ),
          ),
          const SizedBox(width: 8),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: _kBrandBlue),
            onPressed: _initializing ? null : _send,
            child: const Text('Send'),
          ),
        ],
      ),
    );
  }

  Widget _buildMessage(ChatMessage m) {
    if (m.kind == MessageKind.trackingForm) {
      return _TrackingFormBubble(
        trackingNo: m.trackingNo,
        carrier: m.carrier,
        onSubmit: _submitTracking,
      );
    }
    if (m.kind == MessageKind.escalateForm) {
      return _EscalateFormBubble(onSubmit: _submitEscalate);
    }

    final isUser = m.role == 'user';
    return Align(
      alignment: isUser ? Alignment.centerRight : Alignment.centerLeft,
      child: Column(
        crossAxisAlignment:
            isUser ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          Container(
            margin: const EdgeInsets.symmetric(vertical: 4),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            constraints: BoxConstraints(
                maxWidth: MediaQuery.of(context).size.width * 0.75),
            decoration: BoxDecoration(
              color: isUser ? _kBrandBlue : const Color(0xFF3A4048),
              borderRadius: BorderRadius.only(
                topLeft: const Radius.circular(12),
                topRight: const Radius.circular(12),
                bottomLeft: Radius.circular(isUser ? 12 : 4),
                bottomRight: Radius.circular(isUser ? 4 : 12),
              ),
              border: isUser ? null : Border.all(color: const Color(0xFF454C54)),
            ),
            child: Text(
              m.text,
              style: TextStyle(color: isUser ? Colors.white : const Color(0xFFE8E8EA)),
            ),
          ),
          ...m.products.map((p) => _ProductCard(product: p)),
        ],
      ),
    );
  }
}

class _ProductCard extends StatelessWidget {
  final Product product;
  const _ProductCard({required this.product});

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: InkWell(
        onTap: product.url.isNotEmpty
            ? () => launchUrl(Uri.parse(product.url),
                mode: LaunchMode.externalApplication)
            : null,
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Row(
            children: [
              if (product.image != null && product.image!.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(right: 10),
                  child: Image.network(product.image!,
                      width: 44, height: 44, fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => const SizedBox()),
                ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(product.name,
                        style: const TextStyle(fontWeight: FontWeight.w600)),
                    if (product.price != null)
                      Text('£${product.price} inc VAT',
                          style: const TextStyle(color: Color(0xFF8FB0EC))),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _TrackingFormBubble extends StatefulWidget {
  final String? trackingNo;
  final String? carrier;
  final Future<void> Function(String trackingNo, String postcode, String? carrier)
      onSubmit;

  const _TrackingFormBubble({
    this.trackingNo,
    this.carrier,
    required this.onSubmit,
  });

  @override
  State<_TrackingFormBubble> createState() => _TrackingFormBubbleState();
}

class _TrackingFormBubbleState extends State<_TrackingFormBubble> {
  late final _trackCtrl = TextEditingController(text: widget.trackingNo ?? '');
  final _postcodeCtrl = TextEditingController();
  bool _submitting = false;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.all(12),
        constraints:
            BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.8),
        decoration: BoxDecoration(
          color: const Color(0xFF3A4048),
          border: Border.all(color: const Color(0xFF454C54)),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              controller: _trackCtrl,
              decoration: const InputDecoration(
                labelText: 'Tracking number',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _postcodeCtrl,
              decoration: const InputDecoration(
                labelText: 'Delivery postcode',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 8),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: _kBrandBlue),
              onPressed: _submitting
                  ? null
                  : () async {
                      final no = _trackCtrl.text.trim();
                      final pc = _postcodeCtrl.text.trim();
                      if (no.isEmpty || pc.isEmpty) return;
                      setState(() => _submitting = true);
                      await widget.onSubmit(no, pc, widget.carrier);
                    },
              child: Text(_submitting ? 'Checking...' : 'Track'),
            ),
          ],
        ),
      ),
    );
  }
}

class _EscalateFormBubble extends StatefulWidget {
  final Future<void> Function(String email) onSubmit;
  const _EscalateFormBubble({required this.onSubmit});

  @override
  State<_EscalateFormBubble> createState() => _EscalateFormBubbleState();
}

class _EscalateFormBubbleState extends State<_EscalateFormBubble> {
  final _emailCtrl = TextEditingController();
  bool _submitting = false;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.all(12),
        constraints:
            BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.8),
        decoration: BoxDecoration(
          color: const Color(0xFF3A4048),
          border: Border.all(color: const Color(0xFF454C54)),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              controller: _emailCtrl,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(
                labelText: 'Your email (optional)',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 8),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: _kBrandBlue),
              onPressed: _submitting
                  ? null
                  : () async {
                      setState(() => _submitting = true);
                      await widget.onSubmit(_emailCtrl.text.trim());
                    },
              child: Text(_submitting ? 'Raising...' : 'Raise Ticket'),
            ),
          ],
        ),
      ),
    );
  }
}
