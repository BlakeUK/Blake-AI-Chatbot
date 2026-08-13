import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

// Opens the web-based sitemap/SEO checker (public/check/) in an embedded
// WebView rather than the phone's own browser app, so it stays inside
// Blake UK Admin. Pushed as its own route (not one of HomeShell's
// IndexedStack pages, which all stay mounted simultaneously) so it's
// only alive while actually open, and closes with a single explicit
// action rather than the app's usual drawer navigation - it has its own
// separate login, not part of this app's own session.
class CheckerWebViewScreen extends StatefulWidget {
  const CheckerWebViewScreen({super.key});

  @override
  State<CheckerWebViewScreen> createState() => _CheckerWebViewScreenState();
}

class _CheckerWebViewScreenState extends State<CheckerWebViewScreen> {
  late final WebViewController _controller;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (_) => setState(() => _loading = true),
          onPageFinished: (_) => setState(() => _loading = false),
        ),
      )
      ..loadRequest(Uri.parse('https://chat.blakegroup.uk/check/'));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        backgroundColor: const Color(0xFF2F343B),
        foregroundColor: Colors.white,
        title: const Text('Sitemap Checker'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Close',
          onPressed: () => Navigator.of(context).pop(),
        ),
      ),
      body: Stack(
        children: [
          WebViewWidget(controller: _controller),
          if (_loading) const Center(child: CircularProgressIndicator()),
        ],
      ),
    );
  }
}
