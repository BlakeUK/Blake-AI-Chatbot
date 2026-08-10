import 'package:flutter/material.dart';

import '../api_client.dart';
import '../widgets.dart';

// A board is a top-level project (projects.php); its rows are that
// project's tasks (tasks.php), now carrying group_label/status/timeline/
// reviewer/tag/billable_hours (see scripts/schema_project_board.sql) -
// same shape the web admin's table/Kanban view and the operator console's
// task list render, so every client edits the same fields.
const _kTaskStatuses = ['to_do', 'in_progress', 'done'];
const _kStatusLabels = {'to_do': 'To do', 'in_progress': 'In progress', 'done': 'Done'};
const _kStatusColors = {
  'to_do': Color(0xFF8B5CF6),
  'in_progress': Color(0xFFD68910),
  'done': Color(0xFF27AE60),
};
const _kTagPalette = [
  Color(0xFF3D5A99),
  Color(0xFFC0392B),
  Color(0xFF27AE60),
  Color(0xFF8E44AD),
  Color(0xFFD68910),
  Color(0xFF16A085),
  Color(0xFF2980B9),
  Color(0xFFE67E22),
];

Color _hashColor(String s) {
  var h = 0;
  for (final code in s.codeUnits) {
    h = (h * 31 + code) & 0x7fffffff;
  }
  return _kTagPalette[h % _kTagPalette.length];
}

bool get _canEditPlanner => ApiClient.role == 'admin' || ApiClient.role == 'editor';

class ProjectsScreen extends StatefulWidget {
  const ProjectsScreen({super.key});

  @override
  State<ProjectsScreen> createState() => _ProjectsScreenState();
}

class _ProjectsScreenState extends State<ProjectsScreen> {
  bool _loading = true;
  List<dynamic> _boards = [];
  List<dynamic> _agents = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final results = await Future.wait([
      ApiClient.get('/projects.php'),
      ApiClient.get('/agents.php'),
    ]);
    if (!mounted) return;
    setState(() {
      _boards = (results[0]['_list'] as List? ?? [])
          .where((p) => (p as Map)['parent_id'] == null)
          .toList();
      _agents = results[1]['_list'] as List? ?? [];
      _loading = false;
    });
  }

  Future<void> _openBoard(Map<String, dynamic> board) async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => _BoardScreen(board: board, agents: _agents),
    ));
    _load();
  }

  Future<void> _createBoard() async {
    final nameCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('New board'),
        content: TextField(
          controller: nameCtrl,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Name'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Create')),
        ],
      ),
    );
    if (ok != true) return;
    final name = nameCtrl.text.trim();
    if (name.isEmpty) return;
    final r = await ApiClient.post('/projects.php', {'csrf': ApiClient.csrf, 'name': name});
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: _canEditPlanner
          ? FloatingActionButton(onPressed: _createBoard, child: const Icon(Icons.add))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _boards.isEmpty
                  ? ListView(children: const [
                      Padding(
                        padding: EdgeInsets.all(24),
                        child: Center(
                            child: Text('No boards yet. Create one to get started.',
                                style: TextStyle(color: Colors.grey))),
                      ),
                    ])
                  : ListView.builder(
                      padding: const EdgeInsets.all(12),
                      itemCount: _boards.length,
                      itemBuilder: (ctx, i) {
                        final b = _boards[i] as Map<String, dynamic>;
                        return Card(
                          child: ListTile(
                            title: Text(b['name']?.toString() ?? ''),
                            subtitle: Text('${b['open_task_count'] ?? 0} open item(s)'),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => _openBoard(b),
                          ),
                        );
                      },
                    ),
            ),
    );
  }
}

class _BoardScreen extends StatefulWidget {
  final Map<String, dynamic> board;
  final List<dynamic> agents;
  const _BoardScreen({required this.board, required this.agents});

  @override
  State<_BoardScreen> createState() => _BoardScreenState();
}

class _BoardScreenState extends State<_BoardScreen> {
  bool _loading = true;
  List<dynamic> _tasks = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/tasks.php?project_id=${widget.board['id']}');
    if (!mounted) return;
    setState(() {
      _tasks = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  List<MapEntry<String, List<dynamic>>> get _groups {
    final order = <String>[];
    final map = <String, List<dynamic>>{};
    for (final t in _tasks) {
      final label = (t as Map)['group_label']?.toString();
      final g = (label != null && label.isNotEmpty) ? label : 'Ungrouped';
      if (!map.containsKey(g)) {
        map[g] = [];
        order.add(g);
      }
      map[g]!.add(t);
    }
    return order.map((g) => MapEntry(g, map[g]!)).toList();
  }

  Future<void> _openTask(Map<String, dynamic> task) async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => _TaskDetailScreen(task: task, agents: widget.agents),
    ));
    _load();
  }

  Future<void> _setStatus(Map<String, dynamic> task, String status) async {
    final r = await ApiClient.put('/tasks.php', {
      'csrf': ApiClient.csrf,
      'id': task['id'],
      'status': status,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    _load();
  }

  Future<void> _addItem(String? groupLabel) async {
    final titleCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(groupLabel != null ? 'New item in "$groupLabel"' : 'New item'),
        content: TextField(
          controller: titleCtrl,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Title'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Add')),
        ],
      ),
    );
    if (ok != true) return;
    final title = titleCtrl.text.trim();
    if (title.isEmpty) return;
    final r = await ApiClient.post('/tasks.php', {
      'csrf': ApiClient.csrf,
      'project_id': widget.board['id'],
      'title': title,
      'group_label': groupLabel,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final groups = _groups;
    return Scaffold(
      appBar: AppBar(title: Text(widget.board['name']?.toString() ?? 'Board')),
      floatingActionButton: _canEditPlanner
          ? FloatingActionButton(onPressed: () => _addItem(null), child: const Icon(Icons.add))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: groups.isEmpty
                  ? ListView(children: const [
                      Padding(
                        padding: EdgeInsets.all(24),
                        child: Center(
                            child: Text('No items yet.', style: TextStyle(color: Colors.grey))),
                      ),
                    ])
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(12, 12, 12, 80),
                      children: [
                        for (final entry in groups) ...[
                          Padding(
                            padding: const EdgeInsets.only(top: 10, bottom: 4, left: 4),
                            child: Row(
                              children: [
                                Container(width: 4, height: 14, color: _hashColor(entry.key)),
                                const SizedBox(width: 8),
                                Text('${entry.key} (${entry.value.length})',
                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                              ],
                            ),
                          ),
                          for (final t in entry.value)
                            _TaskTile(
                              task: t as Map<String, dynamic>,
                              onTap: () => _openTask(t),
                              onStatusChanged: (s) => _setStatus(t, s),
                            ),
                          if (_canEditPlanner)
                            TextButton(
                              onPressed: () => _addItem(entry.key == 'Ungrouped' ? null : entry.key),
                              child: const Text('+ Add item'),
                            ),
                        ],
                      ],
                    ),
            ),
    );
  }
}

class _TaskTile extends StatelessWidget {
  final Map<String, dynamic> task;
  final VoidCallback onTap;
  final ValueChanged<String> onStatusChanged;
  const _TaskTile({required this.task, required this.onTap, required this.onStatusChanged});

  @override
  Widget build(BuildContext context) {
    final status = task['status']?.toString() ?? 'to_do';
    final statusColor = _kStatusColors[status] ?? Colors.grey;
    final assignees = ((task['assignees'] as List?) ?? [])
        .map((a) => (a as Map)['username']?.toString() ?? '')
        .where((s) => s.isNotEmpty)
        .join(', ');
    final tag = task['tag']?.toString();
    final billable = task['billable_hours'];
    final dueDate = task['due_date'];

    return Card(
      child: ListTile(
        title: Text(
          task['title']?.toString() ?? '',
          style: status == 'done'
              ? const TextStyle(decoration: TextDecoration.lineThrough, color: Colors.grey)
              : null,
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(
            spacing: 6,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              _chip(_kStatusLabels[status] ?? status, statusColor),
              if (tag != null && tag.isNotEmpty) _chip(tag, _hashColor(tag)),
              if (billable != null)
                Text('${billable}h', style: const TextStyle(fontSize: 11, color: Colors.grey)),
              if (dueDate != null)
                Text('Due ${fmtDate(dueDate).split(' ').first}',
                    style: const TextStyle(fontSize: 11, color: Colors.grey)),
              if (assignees.isNotEmpty)
                Text(assignees, style: const TextStyle(fontSize: 11, color: Colors.grey)),
            ],
          ),
        ),
        trailing: _canEditPlanner
            ? PopupMenuButton<String>(
                icon: const Icon(Icons.more_vert),
                onSelected: onStatusChanged,
                itemBuilder: (ctx) => _kTaskStatuses
                    .map((s) => PopupMenuItem(value: s, child: Text(_kStatusLabels[s] ?? s)))
                    .toList(),
              )
            : null,
        onTap: onTap,
      ),
    );
  }

  Widget _chip(String text, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(10)),
        child: Text(text,
            style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w600)),
      );
}

class _TaskDetailScreen extends StatefulWidget {
  final Map<String, dynamic> task;
  final List<dynamic> agents;
  const _TaskDetailScreen({required this.task, required this.agents});

  @override
  State<_TaskDetailScreen> createState() => _TaskDetailScreenState();
}

class _TaskDetailScreenState extends State<_TaskDetailScreen> {
  late final TextEditingController _titleCtrl =
      TextEditingController(text: widget.task['title']?.toString() ?? '');
  late final TextEditingController _groupCtrl =
      TextEditingController(text: widget.task['group_label']?.toString() ?? '');
  late final TextEditingController _tagCtrl =
      TextEditingController(text: widget.task['tag']?.toString() ?? '');
  late final TextEditingController _billableCtrl =
      TextEditingController(text: widget.task['billable_hours']?.toString() ?? '');
  late final TextEditingController _descCtrl =
      TextEditingController(text: widget.task['description']?.toString() ?? '');
  late String _status = widget.task['status']?.toString() ?? 'to_do';
  DateTime? _startDate;
  DateTime? _dueDate;
  late List<int> _assigneeIds = ((widget.task['assignees'] as List?) ?? [])
      .map<int>((a) => (a as Map)['id'] as int)
      .toList();
  int? _reviewerId;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    final sd = widget.task['start_date'];
    if (sd != null) _startDate = DateTime.fromMillisecondsSinceEpoch((sd as int) * 1000);
    final dd = widget.task['due_date'];
    if (dd != null) _dueDate = DateTime.fromMillisecondsSinceEpoch((dd as int) * 1000);
    final rev = widget.task['reviewer'];
    if (rev != null) _reviewerId = (rev as Map)['id'] as int?;
  }

  Future<void> _pickDate(bool isStart) async {
    final initial = (isStart ? _startDate : _dueDate) ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2020),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    setState(() {
      if (isStart) {
        _startDate = picked;
      } else {
        _dueDate = picked;
      }
    });
  }

  Future<void> _pickAssignees() async {
    final selected = Set<int>.from(_assigneeIds);
    final result = await showDialog<Set<int>>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Text('Assignees'),
          content: SizedBox(
            width: double.maxFinite,
            child: ListView(
              shrinkWrap: true,
              children: widget.agents.map((a) {
                final m = a as Map;
                final id = m['id'] as int;
                return CheckboxListTile(
                  value: selected.contains(id),
                  title: Text(m['username']?.toString() ?? ''),
                  onChanged: (v) => setDialogState(() {
                    if (v == true) {
                      selected.add(id);
                    } else {
                      selected.remove(id);
                    }
                  }),
                );
              }).toList(),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
            FilledButton(onPressed: () => Navigator.pop(ctx, selected), child: const Text('Save')),
          ],
        ),
      ),
    );
    if (result != null) setState(() => _assigneeIds = result.toList());
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    final r = await ApiClient.put('/tasks.php', {
      'csrf': ApiClient.csrf,
      'id': widget.task['id'],
      'title': _titleCtrl.text.trim(),
      'group_label': _groupCtrl.text.trim(),
      'status': _status,
      'start_date': _startDate != null ? _startDate!.millisecondsSinceEpoch ~/ 1000 : null,
      'due_date': _dueDate != null ? _dueDate!.millisecondsSinceEpoch ~/ 1000 : null,
      'reviewer_id': _reviewerId,
      'tag': _tagCtrl.text.trim(),
      'billable_hours':
          _billableCtrl.text.trim().isEmpty ? null : double.tryParse(_billableCtrl.text.trim()),
      'description': _descCtrl.text.trim(),
      'assignee_ids': _assigneeIds,
    });
    if (!mounted) return;
    setState(() => _saving = false);
    if (r['error'] != null) {
      flash(context, r['error'].toString(), error: true);
      return;
    }
    flash(context, 'Item saved');
    Navigator.of(context).pop();
  }

  Future<void> _delete() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete item?'),
        content: const Text('This cannot be undone.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red.shade700),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirm != true) return;
    final r = await ApiClient.delete('/tasks.php', {'csrf': ApiClient.csrf, 'id': widget.task['id']});
    if (!mounted) return;
    if (r['error'] != null) {
      flash(context, r['error'].toString(), error: true);
      return;
    }
    Navigator.of(context).pop();
  }

  String _fmtDatePicked(DateTime? d) => d == null
      ? 'Not set'
      : '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(widget.task['title']?.toString() ?? 'Item'),
          bottom: const TabBar(tabs: [Tab(text: 'Details'), Tab(text: 'Comments')]),
          actions: _canEditPlanner
              ? [IconButton(icon: const Icon(Icons.delete_outline), onPressed: _delete)]
              : null,
        ),
        body: TabBarView(
          children: [
            _buildDetailsTab(),
            _CommentsTab(taskId: widget.task['id'] as int),
          ],
        ),
      ),
    );
  }

  Widget _buildDetailsTab() {
    return AbsorbPointer(
      absorbing: !_canEditPlanner,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
              controller: _titleCtrl,
              decoration: const InputDecoration(labelText: 'Title', border: OutlineInputBorder())),
          const SizedBox(height: 12),
          TextField(
              controller: _groupCtrl,
              decoration: const InputDecoration(labelText: 'Group', border: OutlineInputBorder())),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _status,
            items: _kTaskStatuses
                .map((s) => DropdownMenuItem(value: s, child: Text(_kStatusLabels[s] ?? s)))
                .toList(),
            onChanged: (v) {
              if (v != null) setState(() => _status = v);
            },
            decoration: const InputDecoration(labelText: 'Status', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(
                child: OutlinedButton(
                    onPressed: () => _pickDate(true),
                    child: Text('Start: ${_fmtDatePicked(_startDate)}'))),
            const SizedBox(width: 8),
            Expanded(
                child: OutlinedButton(
                    onPressed: () => _pickDate(false),
                    child: Text('Due: ${_fmtDatePicked(_dueDate)}'))),
          ]),
          const SizedBox(height: 12),
          OutlinedButton(
              onPressed: _pickAssignees, child: Text('Assignees (${_assigneeIds.length})')),
          const SizedBox(height: 12),
          DropdownButtonFormField<int>(
            value: _reviewerId,
            items: [
              const DropdownMenuItem<int>(value: null, child: Text('Unassigned')),
              ...widget.agents.map((a) => DropdownMenuItem<int>(
                    value: (a as Map)['id'] as int,
                    child: Text(a['username']?.toString() ?? ''),
                  )),
            ],
            onChanged: (v) => setState(() => _reviewerId = v),
            decoration: const InputDecoration(labelText: 'Reviewer', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 12),
          TextField(
              controller: _tagCtrl,
              decoration: const InputDecoration(labelText: 'Tag', border: OutlineInputBorder())),
          const SizedBox(height: 12),
          TextField(
            controller: _billableCtrl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration:
                const InputDecoration(labelText: 'Billable hours', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 12),
          TextField(
              controller: _descCtrl,
              maxLines: 4,
              decoration:
                  const InputDecoration(labelText: 'Description', border: OutlineInputBorder())),
          if (_canEditPlanner) ...[
            const SizedBox(height: 20),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: kBrandBlue),
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(
                      height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('Save'),
            ),
          ],
        ],
      ),
    );
  }
}

class _CommentsTab extends StatefulWidget {
  final int taskId;
  const _CommentsTab({required this.taskId});

  @override
  State<_CommentsTab> createState() => _CommentsTabState();
}

class _CommentsTabState extends State<_CommentsTab> {
  bool _loading = true;
  List<dynamic> _comments = [];
  final _commentCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final r = await ApiClient.get('/project_comments.php?task_id=${widget.taskId}');
    if (!mounted) return;
    setState(() {
      _comments = r['_list'] as List? ?? [];
      _loading = false;
    });
  }

  Future<void> _addComment() async {
    final content = _commentCtrl.text.trim();
    if (content.isEmpty) return;
    final r = await ApiClient.post('/project_comments.php', {
      'csrf': ApiClient.csrf,
      'task_id': widget.taskId,
      'content': content,
    });
    if (r['error'] != null) {
      if (mounted) flash(context, r['error'].toString(), error: true);
      return;
    }
    _commentCtrl.clear();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    return Column(
      children: [
        Expanded(
          child: _comments.isEmpty
              ? const Center(child: Text('No comments yet.', style: TextStyle(color: Colors.grey)))
              : ListView.builder(
                  padding: const EdgeInsets.all(12),
                  itemCount: _comments.length,
                  itemBuilder: (ctx, i) {
                    final c = _comments[i] as Map<String, dynamic>;
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(children: [
                            Text(c['username']?.toString() ?? '',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                            const SizedBox(width: 6),
                            Text(fmtDate(c['created_at']),
                                style: const TextStyle(fontSize: 11, color: Colors.grey)),
                          ]),
                          const SizedBox(height: 2),
                          Text(c['content']?.toString() ?? ''),
                        ],
                      ),
                    );
                  },
                ),
        ),
        if (_canEditPlanner)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _commentCtrl,
                    decoration: const InputDecoration(
                        hintText: 'Add a comment…', border: OutlineInputBorder(), isDense: true),
                  ),
                ),
                const SizedBox(width: 8),
                FilledButton(onPressed: _addComment, child: const Text('Post')),
              ],
            ),
          ),
      ],
    );
  }
}
