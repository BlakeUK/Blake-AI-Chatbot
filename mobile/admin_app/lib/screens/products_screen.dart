import 'dart:async';
import 'package:flutter/material.dart';

import '../api_client.dart';

class ProductsScreen extends StatefulWidget {
  const ProductsScreen({super.key});

  @override
  State<ProductsScreen> createState() => _ProductsScreenState();
}

class _ProductsScreenState extends State<ProductsScreen> {
  bool _loading = true;
  List<dynamic> _rows = [];
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _search('');
  }

  Future<void> _search(String q) async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/products.php?q=${Uri.encodeComponent(q)}');
    setState(() {
      _rows = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  void _onChanged(String q) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () => _search(q));
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: TextField(
            decoration: const InputDecoration(
              labelText: 'Search products',
              prefixIcon: Icon(Icons.search),
              border: OutlineInputBorder(),
            ),
            onChanged: _onChanged,
          ),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _rows.isEmpty
                  ? const Center(child: Text('No products found.', style: TextStyle(color: Colors.grey)))
                  : ListView.builder(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      itemCount: _rows.length,
                      itemBuilder: (ctx, i) {
                        final r = _rows[i] as Map<String, dynamic>;
                        return Card(
                          child: ListTile(
                            title: Text(r['name']?.toString() ?? ''),
                            subtitle: Text(
                                '${r['product_code'] ?? ''} · ${r['stock_status'] ?? ''}'),
                            trailing: Text(
                              r['price_inc_vat'] != null ? '£${r['price_inc_vat']}' : '',
                              style: const TextStyle(fontWeight: FontWeight.bold),
                            ),
                          ),
                        );
                      },
                    ),
        ),
      ],
    );
  }
}
