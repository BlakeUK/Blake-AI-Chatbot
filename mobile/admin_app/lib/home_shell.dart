import 'package:flutter/material.dart';

import 'api_client.dart';
import 'login_screen.dart';
import 'screens/dashboard_screen.dart';
import 'screens/knowledge_screen.dart';
import 'screens/files_screen.dart';
import 'screens/products_screen.dart';
import 'screens/api_keys_screen.dart';
import 'screens/model_settings_screen.dart';
import 'screens/widget_clients_screen.dart';
import 'screens/users_screen.dart';
import 'screens/chat_logs_screen.dart';
import 'screens/tickets_screen.dart';
import 'screens/my_account_screen.dart';

class _NavItem {
  final String label;
  final IconData icon;
  final Widget Function() build;
  final bool adminOnly;
  const _NavItem(this.label, this.icon, this.build, {this.adminOnly = false});
}

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  late final _items = <_NavItem>[
    _NavItem('Dashboard', Icons.dashboard, () => const DashboardScreen()),
    _NavItem('Knowledge', Icons.menu_book, () => const KnowledgeScreen()),
    _NavItem('Files / RAG', Icons.folder, () => const FilesScreen()),
    _NavItem('Products', Icons.inventory_2, () => const ProductsScreen()),
    _NavItem('API Keys', Icons.vpn_key, () => const ApiKeysScreen(), adminOnly: true),
    _NavItem('Model Settings', Icons.smart_toy, () => const ModelSettingsScreen(),
        adminOnly: true),
    _NavItem('Widget Clients', Icons.widgets, () => const WidgetClientsScreen(),
        adminOnly: true),
    _NavItem('Users', Icons.people, () => const UsersScreen(), adminOnly: true),
    _NavItem('Chat Logs', Icons.chat, () => const ChatLogsScreen()),
    _NavItem('Support Tickets', Icons.confirmation_number, () => const TicketsScreen()),
    _NavItem('My Account', Icons.account_circle, () => const MyAccountScreen()),
  ];

  List<_NavItem> get _visibleItems =>
      _items.where((i) => !i.adminOnly || ApiClient.role == 'admin').toList();

  Future<void> _logout() async {
    await ApiClient.logout();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    final items = _visibleItems;
    final safeIndex = _index < items.length ? _index : 0;
    return Scaffold(
      appBar: AppBar(
        backgroundColor: const Color(0xFF2F343B),
        foregroundColor: Colors.white,
        title: Row(
          children: [
            Image.asset('assets/blake-uk-logo.png', height: 24),
            const SizedBox(width: 10),
            Text(items[safeIndex].label),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Log out',
            onPressed: _logout,
          ),
        ],
      ),
      drawer: Drawer(
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            const DrawerHeader(
              decoration: BoxDecoration(color: Color(0xFF2F343B)),
              child: Align(
                alignment: Alignment.bottomLeft,
                child: Text('Chatbot Admin',
                    style: TextStyle(color: Colors.white, fontSize: 18)),
              ),
            ),
            for (var i = 0; i < items.length; i++)
              ListTile(
                leading: Icon(items[i].icon),
                title: Text(items[i].label),
                selected: i == safeIndex,
                onTap: () {
                  setState(() => _index = i);
                  Navigator.of(context).pop();
                },
              ),
          ],
        ),
      ),
      body: IndexedStack(
        index: safeIndex,
        children: [for (final i in items) i.build()],
      ),
    );
  }
}
