// Blake UK Operator Console - Tauri 2 shell.
//
// Deliberately thin: the actual application (login, ticket list, polling,
// department routing, notes) lives in ../dist/index.html and talks to the
// existing PHP admin API directly via fetch() from the webview, exactly as
// a browser would. This file only handles what the frontend genuinely can't
// do itself - native notifications, a system tray so the app can sit in the
// background and still catch new tickets, and keeping the app running when its
// window is closed (an app whose whole job is "notify me later" shouldn't
// exit just because its window was closed).

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use tauri::{
    image::Image,
    menu::{Menu, MenuItem, PredefinedMenuItem},
    tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent},
    AppHandle, Manager, WindowEvent,
};

fn show_main(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
    }
}

// Self-update: called from the frontend once it's compared its own version
// against version.json and found something newer. Does the download + launch
// entirely on the Rust side: a custom command keeps the whole privileged
// operation (fetch a binary, write it to disk, execute it) in one place.
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
    // unattended: both need elevated permissions for a real install anyway.
    open::that(&dest).map_err(|e| format!("Downloaded, but couldn't launch the installer: {e}"))?;

    // The installer can't replace this app's own files while this process still
    // has them open. The brief delay is so the spawned installer is fully up
    // before its parent vanishes, rather than racing it.
    std::thread::sleep(std::time::Duration::from_millis(1500));
    std::process::exit(0);
}

fn build_tray(app: &tauri::App) -> tauri::Result<()> {
    let show = MenuItem::with_id(app, "show", "Show console", true, None::<&str>)?;
    let separator = PredefinedMenuItem::separator(app)?;
    let quit = MenuItem::with_id(app, "quit", "Quit", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&show, &separator, &quit])?;
    let icon = Image::from_bytes(include_bytes!("../icons/32x32.png"))?;

    TrayIconBuilder::new()
        .icon(icon)
        .tooltip("Blake UK Support Desk")
        .menu(&menu)
        .show_menu_on_left_click(false)
        .on_menu_event(|app, event| match event.id.as_ref() {
            "quit" => app.exit(0),
            "show" => show_main(app),
            _ => {}
        })
        // Left-click the tray icon itself: same as picking "Show console".
        .on_tray_icon_event(|tray, event| {
            if let TrayIconEvent::Click {
                button: MouseButton::Left,
                button_state: MouseButtonState::Up,
                ..
            } = event
            {
                show_main(tray.app_handle());
            }
        })
        .build(app)?;
    Ok(())
}

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_shell::init())
        .invoke_handler(tauri::generate_handler![download_and_install])
        .setup(|app| {
            // A missing tray (some Linux desktops, including a stock GNOME, have none)
            // must never stop the app from starting.
            if let Err(e) = build_tray(app) {
                eprintln!("The tray icon could not be created ({e}); carrying on without it.");
            }
            Ok(())
        })
        .on_window_event(|window, event| {
            if let WindowEvent::CloseRequested { api, .. } = event {
                // Only the main window stays alive when closed; the tray's "Quit" is the real
                // exit, so notifications keep working after the X is clicked.
                if window.label() == "main" {
                    api.prevent_close();
                    // Linux: a stock Debian GNOME has no tray icon to bring a hidden window back
                    // from, so the window is minimised instead and stays reachable from the dock.
                    #[cfg(target_os = "linux")]
                    let _ = window.minimize();
                    #[cfg(not(target_os = "linux"))]
                    let _ = window.hide();
                }
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running the operator console");
}
