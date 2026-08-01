import 'package:flutter/material.dart';

import 'chat_screen.dart';
import 'theme.dart';

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
        useMaterial3: true,
        brightness: Brightness.dark,
        scaffoldBackgroundColor: kBgDark,
        colorScheme: ColorScheme.fromSeed(
          seedColor: kBrandBlue,
          brightness: Brightness.dark,
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: kBgDark,
          foregroundColor: Colors.white,
        ),
        dividerColor: kBorderSubtleDark,
      ),
      home: const ChatScreen(),
    );
  }
}
