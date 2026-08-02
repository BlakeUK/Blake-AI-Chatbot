// Blake UK Operator Console - Tauri shell.
//
// Deliberately thin: the actual application (login, ticket list, polling,
// department routing, notes) lives in ../dist/index.html and talks to the
// existing PHP admin API directly via fetch() from the webview, exactly as
// a browser would. This file only handles what the frontend genuinely can't
// do itself - native notifications, a system tray so the app can sit in the
// background and still catch new tickets, and making the window-close
// button hide rather than quit (an app whose whole job is "notify me later"
// shouldn't exit just because its window was closed).

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use tauri::{
    CustomMenuItem, Manager, SystemTray, SystemTrayEvent, SystemTrayMenu, SystemTrayMenuItem,
    WindowEvent,
};

fn main() {
    let show = CustomMenuItem::new("show".to_string(), "Show console");
    let quit = CustomMenuItem::new("quit".to_string(), "Quit");
    let tray_menu = SystemTrayMenu::new()
        .add_item(show)
        .add_native_item(SystemTrayMenuItem::Separator)
        .add_item(quit);

    tauri::Builder::default()
        .system_tray(SystemTray::new().with_menu(tray_menu))
        .on_system_tray_event(|app, event| match event {
            // Left-click the tray icon itself: same as picking "Show console".
            SystemTrayEvent::LeftClick { .. } => {
                if let Some(window) = app.get_window("main") {
                    let _ = window.show();
                    let _ = window.set_focus();
                }
            }
            SystemTrayEvent::MenuItemClick { id, .. } => match id.as_str() {
                "quit" => {
                    std::process::exit(0);
                }
                "show" => {
                    if let Some(window) = app.get_window("main") {
                        let _ = window.show();
                        let _ = window.set_focus();
                    }
                }
                _ => {}
            },
            _ => {}
        })
        .on_window_event(|event| {
            if let WindowEvent::CloseRequested { api, .. } = event.event() {
                // Hide instead of closing - the tray's "Quit" item is the
                // real exit. Prevents "I clicked X and now I stop getting
                // notified" as a surprise.
                let _ = event.window().hide();
                api.prevent_close();
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running the operator console");
}
