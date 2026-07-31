import 'package:flutter_test/flutter_test.dart';

import 'package:customer_app/main.dart';

void main() {
  testWidgets('App boots and shows the chat app bar', (WidgetTester tester) async {
    await tester.pumpWidget(const BlakeUkCustomerApp());
    await tester.pump();
    expect(find.text('Support'), findsOneWidget);
  });
}
