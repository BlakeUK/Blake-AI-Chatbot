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

// Self-update: called from the frontend once it's compared its own
// app.getVersion() against version.json and found something newer. Does the
// download + launch entirely on the Rust side rather than via the JS-exposed
// http/fs/shell allowlist - a custom command isn't allowlist-gated the way
// built-in Tauri APIs are, and this keeps the whole privileged operation
// (fetch a binary, write it to disk, execute it) in one place with no
// ambiguity about what URL-open patterns the shell allowlist would accept
// for a local temp-file path.
#[tauri::command]
fn download_and_install(url: String) -> Result<(), String> {
    // Hardcoded, not just validated: this only ever fetches from the app's
    // own publish location, regardless of what the frontend passes in.
    if !url.starts_with("https://blakegroup.uk/downloads/") {
        return Err("Refusing to fetch from an unexpected host".into());
    }

    let filename = url.rsplit('/').next().unwrap_or("blake-uk-operator-console-update");
    let dest = std::env::temp_dir().join(filename);

    let resp = ureq::get(&url).call().map_err(|e| format!("Download failed: {e}"))?;
    let mut file = std::fs::File::create(&dest).map_err(|e| format!("Couldn't save installer: {e}"))?;
    std::io::copy(&mut resp.into_reader(), &mut file)
        .map_err(|e| format!("Couldn't save installer: {e}"))?;
    drop(file);

    // Hands off to the OS's own installer UI (the NSIS setup wizard for .exe,
    // the desktop's package handler for .deb) rather than trying to run it
    // unattended - both platforms need elevated permissions for a real
    // install anyway, so a silent/headless install isn't actually available
    // to a normal desktop app without a lot more infrastructure than this
    // warrants right now.
    open::that(&dest).map_err(|e| format!("Downloaded, but couldn't launch the installer: {e}"))?;

    // The installer can't replace this app's own exe/dlls while this process
    // still has them open - that's exactly the "files in use" prompt this
    // exists to prevent, and on Windows it won't proceed until this app is
    // gone. The brief delay isn't for the installer's benefit (it'll wait as
    // long as it needs to); it's so the spawned installer process is fully
    // up before its parent vanishes, rather than racing it.
    std::thread::sleep(std::time::Duration::from_millis(1500));
    std::process::exit(0);
}

fn main() {
    let show = CustomMenuItem::new("show".to_string(), "Show console");
    let quit = CustomMenuItem::new("quit".to_string(), "Quit");
    let tray_menu = SystemTrayMenu::new()
        .add_item(show)
        .add_native_item(SystemTrayMenuItem::Separator)
        .add_item(quit);

    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![download_and_install])
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
                let window = event.window();
                // Only the main window hides-instead-of-closes (the tray's
                // "Quit" item is the real exit, so notifications keep
                // working after the X is clicked).
                if window.label() == "main" {
                    let _ = window.hide();
                    api.prevent_close();
                }
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running the operator console");
}
