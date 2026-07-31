import 'package:flutter/material.dart';

import 'chat_screen.dart';

void main() {
  runApp(const BlakeUkCustomerApp());
}

class BlakeUkCustomerApp extends StatelessWidget {
  const BlakeUkCustomerApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Blake UK Support',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF3D5A99)),
        useMaterial3: true,
      ),
      home: const ChatScreen(),
    );
  }
}
