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
        ).copyWith(
          surface: kBgDark,
          onSurface: kTextDark,
          surfaceContainerHighest: kSurfaceDark,
          primary: kAccentTextDark,
          onPrimary: kBgDark,
          outline: kBorderStrongDark,
          outlineVariant: kBorderSubtleDark,
        ),
        textTheme: ThemeData(brightness: Brightness.dark).textTheme.apply(
              bodyColor: kTextDark,
              displayColor: kTextDark,
            ),
        cardTheme: const CardThemeData(
          color: kSurfaceDark,
          surfaceTintColor: Colors.transparent,
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: kBgDark,
          foregroundColor: Colors.white,
        ),
        dividerColor: kBorderSubtleDark,
        inputDecorationTheme: const InputDecorationTheme(
          filled: true,
          fillColor: kRecessedDark,
          border: OutlineInputBorder(borderSide: BorderSide(color: kBorderStrongDark)),
          enabledBorder: OutlineInputBorder(borderSide: BorderSide(color: kBorderStrongDark)),
          focusedBorder: OutlineInputBorder(borderSide: BorderSide(color: kAccentTextDark)),
          hintStyle: TextStyle(color: kMutedDark),
          labelStyle: TextStyle(color: kMutedDark),
        ),
      ),
      home: const ChatScreen(),
    );
  }
}
