import 'dart:convert';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../api_client.dart';
import '../widgets.dart';

class FilesScreen extends StatefulWidget {
  const FilesScreen({super.key});

  @override
  State<FilesScreen> createState() => _FilesScreenState();
}

class _FilesScreenState extends State<FilesScreen> {
  bool _loading = true;
  bool _uploading = false;
  List<dynamic> _rows = [];

  bool get _canEdit => ApiClient.role == 'admin' || ApiClient.role == 'editor';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/files.php');
    setState(() {
      _rows = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  Future<void> _pickAndUpload() async {
    final result = await FilePicker.platform.pickFiles(withData: true);
    if (result == null || result.files.isEmpty) return;
    final file = result.files.first;
    if (file.bytes == null) {
      if (mounted) flash(context, 'Could not read file', error: true);
      return;
    }
    setState(() => _uploading = true);
    try {
      final req = http.MultipartRequest('POST', Uri.parse('$kApi/files.php'));
      req.headers['X-CSRF-Token'] = ApiClient.csrf;
      req.headers['Cookie'] = ApiClient.cookieHeader ?? '';
      req.files.add(http.MultipartFile.fromBytes('file', file.bytes!, filename: file.name));
      final streamed = await req.send();
      final resp = await http.Response.fromStream(streamed);
      final body = resp.body.isNotEmpty ? jsonDecode(resp.body) : {};
      if (body is Map && body['error'] != null) {
        if (mounted) flash(context, body['error'].toString(), error: true);
      } else {
        if (mounted) flash(context, 'File uploaded');
        _load();
      }
    } catch (e) {
      if (mounted) flash(context, 'Upload failed: $e', error: true);
    } finally {
      setState(() => _uploading = false);
    }
  }

  Future<void> _delete(int id) async {
    final r = await ApiClient.delete('/files.php', {'csrf': ApiClient.csrf, 'id': id});
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
    } else {
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: _canEdit
          ? FloatingActionButton(
              backgroundColor: kBrandBlue,
              onPressed: _uploading ? null : _pickAndUpload,
              child: _uploading
                  ? const SizedBox(
                      height: 20, width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.upload_file, color: Colors.white),
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
                            child: Text('No files yet.', style: TextStyle(color: Colors.grey))),
                      )
                    ])
                  : ListView.builder(
                      padding: const EdgeInsets.all(12),
                      itemCount: _rows.length,
                      itemBuilder: (ctx, i) {
                        final r = _rows[i] as Map<String, dynamic>;
                        final status = r['status']?.toString() ?? '';
                        return Card(
                          child: ListTile(
                            title: Text(r['filename']?.toString() ?? ''),
                            subtitle: status == 'error'
                                ? Text(r['error']?.toString() ?? 'Error',
                                    style: const TextStyle(color: Colors.red))
                                : Text(r['mime_type']?.toString() ?? ''),
                            trailing: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                StatusBadge(text: status, ok: status == 'indexed'),
                                if (_canEdit)
                                  IconButton(
                                    icon: const Icon(Icons.delete, color: Colors.red),
                                    onPressed: () => _delete(r['id'] as int),
                                  ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
            ),
    );
  }
}
