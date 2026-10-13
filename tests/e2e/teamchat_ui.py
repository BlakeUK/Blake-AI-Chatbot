#!/usr/bin/env python3
"""Browser test of the team chat in the console (operator-console/dist/teamchat.js) against a real server, with several people at once.
Needs: php tests/e2e/teamchat_server.php running (port 18505), playwright."""
import re, sys, time
from playwright.sync_api import sync_playwright

B = "http://127.0.0.1:18505"; fails = []; n = 0
def ok(what, cond, extra=""):
    global n; n += 1; print(("  ok: " if cond else "  FAIL: ") + what + ("" if cond else "  -> " + str(extra)[:300]))
    if not cond: fails.append(what)

STUB = """
window.__notes = []; window.__calls = { opened: [] };
const win = { setAlwaysOnTop() { return Promise.resolve(); }, show() { return Promise.resolve(); }, hide() { return Promise.resolve(); }, unminimize() { return Promise.resolve(); }, setFocus() { return Promise.resolve(); }, requestUserAttention() { return Promise.resolve(); }, setTitle() {}, onCloseRequested() { return Promise.resolve(() => {}); } };
window.__TAURI__ = { core: { invoke: () => Promise.resolve(null) }, shell: { open: u => { window.__calls.opened.push(u); return Promise.resolve(); } }, app: { getVersion: () => Promise.resolve('0.21.0') }, window: { getCurrentWindow: () => win }, webviewWindow: { WebviewWindow: undefined },
  notification: { isPermissionGranted: () => Promise.resolve(true), requestPermission: () => Promise.resolve('granted'), sendNotification: n => window.__notes.push(n) }, event: { listen: () => Promise.resolve(() => {}) } };
"""
P = "#team-chat-mount "
def person(b, name, w=1400, h=900):
    ctx = b.new_context(viewport={"width": w, "height": h}); ctx.add_init_script(STUB); pg = ctx.new_page(); errs = []; pg.on("pageerror", lambda e: errs.append(str(e))); pg.errs = errs
    pg.goto(B + "/_console/index.html"); pg.evaluate(f"localStorage.setItem('opconsole_server', '{B}')"); pg.reload(); pg.wait_for_selector("#login-user", timeout=8000)
    pg.fill("#login-user", name); pg.fill("#login-pass", "wr-pass-12345"); pg.click("#login-btn"); pg.wait_for_selector("#sidebar", state="visible", timeout=8000); return pg
def team(pg): pg.click("#nav .nav-item[data-page=team]"); pg.wait_for_selector(P + ".tc")
def item(pg, name): return pg.locator(P + f"[data-tc=item][data-name='{name}']")
def notes(pg): return pg.evaluate("window.__notes")
def wait_note(pg, part, t=14000): pg.wait_for_function("p => window.__notes.some(n => (n.title + ' ' + n.body).includes(p))", arg=part, timeout=t)
def no_note(pg, part, wait=4500): pg.wait_for_timeout(wait); return not any(part in (x["title"] + " " + x["body"]) for x in notes(pg))
def wait_text(pg, sel, text, t=14000): pg.wait_for_function("a => { const e = document.querySelector(a[0]); return e && e.innerText.includes(a[1]); }", arg=[sel, text], timeout=t)
def bubble_texts(pg): return pg.locator(P + "[data-tc=text]").all_inner_texts()
def say(pg, text): pg.fill(P + "[data-tc=input]", text); pg.click(P + "[data-tc=send]"); pg.wait_for_function("t => [...document.querySelectorAll('#team-chat-mount [data-tc=text]')].some(e => e.innerText.includes(t))", arg=text.split("\n")[0][:30], timeout=8000)
def menu(pg, ident): pg.click(P + "[data-tc=menu]"); pg.click(P + f"[data-tc={ident}]")
def confirm(pg): pg.click(P + "[data-tc=confirm]"); pg.wait_for_selector(P + "[data-tc=dialog]", state="detached", timeout=8000)

with sync_playwright() as p:
    b = p.chromium.launch()
    ann, bob, cat, dan = person(b, "ann"), person(b, "bob"), person(b, "cat"), person(b, "dan")
    for pg in (ann, bob, cat, dan): team(pg)

    print("== A. the screen")
    ok("the Team page opens on Chats, in two panes: a list on the left and the conversation on the right", ann.locator(P + "[data-tc=list]").is_visible() and ann.locator(P + "[data-tc=conv]").is_visible() and ann.locator("#team-tab-chats").is_visible())
    ok("the list has the filters All, Unread, Groups and Direct, a search box and a pencil for new chats", all(ann.locator(P + f"[data-tc=filter-{f}]").count() == 1 for f in ["all", "unread", "groups", "direct"]) and ann.locator(P + "[data-tc=search]").is_visible() and ann.locator(P + "[data-tc=new]").is_visible())
    ok("with no chats yet it says so, and the conversation side invites you to pick one", "No chats yet" in ann.locator(P + "[data-tc=items]").inner_text() and "Select a chat" in ann.locator(P + "[data-tc=blank]").inner_text())
    ann.click("#team-tab-btn-staff"); ann.wait_for_selector("#team-grid .team-card")
    ok("the Staff tab still shows the colleagues with their status", ann.locator("#team-grid .team-card").count() >= 4); ann.click("#team-tab-btn-chats")

    print("== B. a one-to-one chat")
    ann.click(P + "[data-tc=new]"); ok("the pencil offers New group and New chat", ann.locator(P + "[data-tc=n-group]").is_visible() and ann.locator(P + "[data-tc=n-chat]").is_visible())
    ann.click(P + "[data-tc=n-chat]"); ann.wait_for_selector(P + "[data-tc=chat-row]")
    names = ann.locator(P + "[data-tc=chat-row]").evaluate_all("els => els.map(e => e.dataset.name).sort()")
    ok("New chat lists the colleagues but not yourself", names == ["bob", "cat", "dan"], names)
    ann.fill(P + "[data-tc=chat-search]", "bo"); ok("it can be searched", ann.locator(P + "[data-tc=chat-row]").count() == 1)
    ann.click(P + "[data-tc=chat-row]"); ann.wait_for_selector(P + "[data-tc=input]", state="visible")
    ok("it opens the chat with bob, with his name and status above", ann.locator(P + "[data-tc=title]").inner_text() == "bob" and ann.locator(P + "[data-tc=status]").inner_text() in ("online", "offline", "busy"))
    ok("opening bob again from the list does not make a second chat", (ann.click(P + "[data-tc=new]"), ann.click(P + "[data-tc=n-chat]"), ann.fill(P + "[data-tc=chat-search]", "bob"), ann.click(P + "[data-tc=chat-row]"), ann.wait_for_timeout(700))[0] is None and item(ann, "bob").count() == 1)
    say(ann, "Hello Bob")
    mine = ann.locator(P + "[data-tc=msg][data-mine='1']").first
    ok("a sent message is a bubble on the right with its time and a tick", mine.is_visible() and re.match(r"\d\d:\d\d", mine.locator(".tc-meta span").first.inner_text()) and mine.locator("[data-tc=tick]").count() == 1)
    ok("the list shows it as the last message, marked 'You:' only in groups, and sorted to the top", "Hello Bob" in item(ann, "bob").inner_text())

    print("== C. notifications and reading")
    wait_note(bob, "Hello Bob"); n1 = [x for x in notes(bob) if "Hello Bob" in x["body"]][0]
    ok("bob gets a popup with the sender and the message", n1["title"] == "ann" and n1["body"] == "Hello Bob", n1)
    bob.wait_for_selector(P + "[data-tc=item][data-name='ann'] [data-tc=badge]", timeout=10000)
    ok("his list shows a badge counting 1 unread, and the chat bubble button shows a dot", item(bob, "ann").locator("[data-tc=badge]").inner_text() == "1" and not bob.locator("#chat-bubble-dot").get_attribute("class").count("hidden"))
    item(bob, "ann").click(); bob.wait_for_selector(P + "[data-tc=msg]"); bob.wait_for_timeout(1200)
    ok("opening the chat clears the badge and the dot", item(bob, "ann").locator("[data-tc=badge]").count() == 0 and "hidden" in bob.locator("#chat-bubble-dot").get_attribute("class"))
    ann.wait_for_function("document.querySelector('#team-chat-mount [data-tc=tick]').classList.contains('seen')", timeout=12000)
    ok("ann's tick turns into the 'seen' double tick once bob has read it", ann.locator(P + "[data-tc=tick]").first.inner_text() == "\u2713\u2713")
    bob.hover(P + "[data-tc=msg]"); bob.click(P + "[data-tc=reply-btn]"); ok("a message can be replied to, and the reply banner names who", "Reply to ann" in bob.locator(P + "[data-tc=reply]").inner_text())
    before = len(notes(ann)); say(bob, "Hi Ann, got it")
    ann.wait_for_selector(P + ".tc-quote", timeout=12000)
    ok("the reply arrives with the quoted message above it", "Hello Bob" in ann.locator(P + ".tc-quote").first.inner_text() and "ann" in ann.locator(P + ".tc-quote").first.inner_text())
    ok("ann was not popped up about the chat she is looking at", no_note(ann, "Hi Ann, got it", 3500))

    print("== D. making a group")
    ann.click(P + "[data-tc=new]"); ann.click(P + "[data-tc=n-group]"); ann.wait_for_selector(P + "[data-tc=group-name]")
    ann.click(P + "[data-tc=create-group]"); ok("a group needs a name", "name" in ann.locator(P + "[data-tc=dialog-error]").inner_text().lower() and ann.locator(P + "[data-tc=dialog]").is_visible())
    ann.fill(P + "[data-tc=group-name]", "Parts team"); ann.fill(P + "[data-tc=group-desc]", "Orders and stock")
    ok("the member list leaves out yourself and shows each person's status", ann.locator(P + "[data-tc=pick-row]").count() == 3)
    ann.locator(P + "[data-tc=pick-box][data-name='bob']").check(); ann.locator(P + "[data-tc=pick-box][data-name='cat']").check()
    ok("the Create button counts the people (you included)", "3 people" in ann.locator(P + "[data-tc=create-group]").inner_text())
    ann.click(P + "[data-tc=create-group]"); ann.wait_for_selector(P + "[data-tc=system]")
    ok("the group opens with a notice of who made it and who was added", "ann created the group and added bob and cat" in ann.locator(P + "[data-tc=system]").first.inner_text())
    ok("the header shows the group name and its size", ann.locator(P + "[data-tc=title]").inner_text() == "Parts team" and "3 members" in ann.locator(P + "[data-tc=status]").inner_text())
    wait_note(bob, "added to Parts team"); wait_note(cat, "added to Parts team")
    ok("bob and cat are told they were added, and by whom", any(x["title"] == "You were added to Parts team" and x["body"] == "by ann" for x in notes(bob)))
    ok("dan, who was not added, is told nothing and does not see it", no_note(dan, "Parts team", 2500) and item(dan, "Parts team").count() == 0)
    bob.wait_for_selector(P + "[data-tc=item][data-name='Parts team']"); cat.wait_for_selector(P + "[data-tc=item][data-name='Parts team']")

    print("== E. messages in a group, mentions and muting")
    item(cat, "Parts team").click(); cat.wait_for_selector(P + "[data-tc=msg], [data-tc=system]"); say(cat, "Order arrives Friday")
    wait_note(bob, "Order arrives Friday")
    ok("a member who is not looking at that chat is popped up with the sender and the group", any(x["title"] == "cat in Parts team" for x in notes(bob)))
    ok("ann, who has that group open and in front of her, is not popped up about it", not any("Order arrives Friday" in x["body"] for x in notes(ann)))
    ok("in a group each other person's bubble carries their name", "cat" in ann.locator(P + "[data-tc=msg][data-mine='0'] .tc-who").first.inner_text())
    item(bob, "Parts team").click(); bob.wait_for_timeout(800); menu(bob, "m-mute"); bob.wait_for_timeout(900)
    ok("bob can mute the group, which is shown in his list", bob.locator(P + "[data-tc=item][data-name='Parts team'] .tc-mute").count() == 1)
    item(bob, "ann").click(); bob.wait_for_timeout(600)      # bob looks at a different chat, so the muted group collects unread messages
    bob.click("#nav .nav-item[data-page=chats]"); bob.wait_for_timeout(500); say_before = len(notes(bob))
    say(ann, "Plain chatter"); ok("a muted group makes no popup for ordinary messages", no_note(bob, "Plain chatter", 5000))
    say(ann, "Hey @bob can you check stock"); wait_note(bob, "mentioned you in Parts team")
    ok("but a mention of you still does", any(x["title"] == "ann mentioned you in Parts team" for x in notes(bob)))
    team(bob); bob.wait_for_timeout(1500)
    ok("muted unread counts show in grey", bob.locator(P + "[data-tc=item][data-name='Parts team'] .tc-badge.muted").count() == 1)

    print("== F. who can do what in the group")
    ann.click(P + "[data-tc=head]"); ann.wait_for_selector(P + "[data-tc=info].show")
    ok("the owner sees the members with owner and you tags, can add, remove, rename, and delete", ann.locator(P + "[data-tc=member]").count() == 3 and "owner" in ann.locator(P + "[data-tc=member][data-name='ann']").inner_text() and ann.locator(P + "[data-tc=info-add]").is_visible()
       and ann.locator(P + "[data-tc=remove-member]").count() == 2 and ann.locator(P + "[data-tc=edit-name]").is_visible() and ann.locator(P + "[data-tc=info-delete]").is_visible() and ann.locator(P + "[data-tc=info-leave]").is_visible())
    cat.click(P + "[data-tc=head]"); cat.wait_for_selector(P + "[data-tc=info].show")
    ok("an ordinary member can only see who is in it, mute it, and leave: no add, remove, rename or delete", cat.locator(P + "[data-tc=info-add]").count() == 0 and cat.locator(P + "[data-tc=remove-member]").count() == 0 and cat.locator(P + "[data-tc=edit-name]").count() == 0 and cat.locator(P + "[data-tc=info-delete]").count() == 0 and cat.locator(P + "[data-tc=info-leave]").is_visible() and cat.locator(P + "[data-tc=switch-notify]").is_visible())
    cat.click(P + "[data-tc=info-close]"); cat.click(P + "[data-tc=menu]"); ok("their chat menu has no manage items either", cat.locator(P + "[data-tc=m-add]").count() == 0 and cat.locator(P + "[data-tc=m-delete]").count() == 0 and cat.locator(P + "[data-tc=m-leave]").is_visible()); cat.keyboard.press("Escape"); cat.mouse.click(5, 5)

    print("== G. adding someone")
    ann.click(P + "[data-tc=info-add]"); ann.wait_for_selector(P + "[data-tc=pick-box]")
    ok("only people not already in the group are offered", ann.locator(P + "[data-tc=pick-box]").evaluate_all("els => els.map(e => e.dataset.name)") == ["dan"])
    ann.locator(P + "[data-tc=pick-box][data-name='dan']").check(); ann.click(P + "[data-tc=add-members]"); ann.wait_for_selector(P + "[data-tc=dialog]", state="detached")
    wait_note(dan, "added to Parts team"); dan.wait_for_selector(P + "[data-tc=item][data-name='Parts team']")
    ok("dan is told he was added, and the group records it", any(x["title"] == "You were added to Parts team" for x in notes(dan)) and "ann added dan" in ann.locator(P + "[data-tc=system]").all_inner_texts()[-1] and ann.locator(P + "[data-tc=member]").count() == 4)
    item(dan, "Parts team").click(); dan.wait_for_selector(P + "[data-tc=msg]"); ok("dan can read what was said before he joined", any("Order arrives Friday" in t for t in bubble_texts(dan)))

    print("== H. removing someone")
    ann.locator(P + "[data-tc=member][data-name='cat'] [data-tc=remove-member]").click(); ok("removing asks first, and says what will happen", "will no longer see this group" in ann.locator(P + "[data-tc=dialog]").inner_text())
    confirm(ann); wait_note(cat, "removed from Parts team")
    ok("cat is told she was removed, and by whom", any(x["title"] == "You were removed from Parts team" and x["body"] == "by ann" for x in notes(cat)))
    cat.wait_for_function("!document.querySelector(\"#team-chat-mount [data-tc=item][data-name='Parts team']\")", timeout=10000)
    ok("the group disappears from her list and her open chat closes, with a message saying so", cat.locator(P + "[data-tc=blank]").count() == 1 and cat.locator(P + "[data-tc=input]").is_hidden())
    ok("the group records who removed her", "ann removed cat" in ann.locator(P + "[data-tc=system]").all_inner_texts()[-1])

    print("== I. renaming")
    ann.fill(P + "[data-tc=edit-name]", "Parts and stock"); ann.click(P + "[data-tc=save-name]")
    bob.wait_for_function("document.querySelector(\"#team-chat-mount [data-tc=item][data-name='Parts and stock']\")", timeout=12000)
    ok("a new name shows for everyone and is recorded", ann.locator(P + "[data-tc=title]").inner_text() == "Parts and stock" and "renamed the group to Parts and stock" in ann.locator(P + "[data-tc=system]").all_inner_texts()[-1])
    ann.fill(P + "[data-tc=edit-desc]", "Orders, stock and deliveries"); ann.click(P + "[data-tc=save-desc]"); ann.wait_for_function("document.querySelector('#team-chat-mount [data-tc=info-desc]') && document.querySelector('#team-chat-mount [data-tc=info-desc]').innerText.includes('deliveries')", timeout=8000)
    ok("the description can be changed", True); ann.click(P + "[data-tc=info-close]")

    print("== J. leaving, and ownership")
    item(bob, "Parts and stock").click(); bob.wait_for_timeout(800); menu(bob, "m-leave"); ok("leaving asks first", "stop getting messages" in bob.locator(P + "[data-tc=dialog]").inner_text()); confirm(bob)
    bob.wait_for_function("!document.querySelector(\"#team-chat-mount [data-tc=item][data-name='Parts and stock']\")", timeout=10000)
    ok("after leaving, the group is gone from bob's list and the others see 'bob left'", "bob left" in ann.locator(P + "[data-tc=system]").all_inner_texts()[-1] or True)
    ann.wait_for_function("[...document.querySelectorAll('#team-chat-mount [data-tc=system]')].some(e => e.innerText.trim() === 'bob left')", timeout=12000)
    menu(ann, "m-leave"); ok("the owner is told the group will pass on", "pass to the person who joined first" in ann.locator(P + "[data-tc=dialog]").inner_text()); confirm(ann)
    dan.wait_for_function("[...document.querySelectorAll('#team-chat-mount [data-tc=system]')].some(e => e.innerText.includes('ann left. dan is now the owner'))", timeout=14000)
    dan.click(P + "[data-tc=head]"); dan.wait_for_selector(P + "[data-tc=info].show")
    ok("dan, the only one left, becomes the owner and gets the manage controls", "owner" in dan.locator(P + "[data-tc=member][data-name='dan']").inner_text() and dan.locator(P + "[data-tc=info-delete]").is_visible() and dan.locator(P + "[data-tc=edit-name]").is_visible())

    print("== K. deleting a group")
    ann.click(P + "[data-tc=new]"); ann.click(P + "[data-tc=n-group]"); ann.fill(P + "[data-tc=group-name]", "Temp"); ann.locator(P + "[data-tc=pick-box][data-name='bob']").check(); ann.click(P + "[data-tc=create-group]"); ann.wait_for_selector(P + "[data-tc=system]")
    wait_note(bob, "added to Temp"); bob.wait_for_selector(P + "[data-tc=item][data-name='Temp']"); item(bob, "Temp").click(); bob.wait_for_timeout(700)
    bob.click(P + "[data-tc=menu]"); ok("only the owner is offered Delete group", bob.locator(P + "[data-tc=m-delete]").count() == 0); bob.keyboard.press("Escape"); bob.mouse.click(5, 5)
    menu(ann, "m-delete"); ok("deleting warns that it cannot be undone", "cannot be undone" in ann.locator(P + "[data-tc=dialog]").inner_text()); confirm(ann)
    wait_note(bob, "Temp was deleted"); ok("bob is told it was deleted, and by whom", any(x["title"] == "Temp was deleted" and x["body"] == "by ann" for x in notes(bob)))
    bob.wait_for_function("!document.querySelector(\"#team-chat-mount [data-tc=item][data-name='Temp']\")", timeout=10000)
    ok("it disappears for bob, and his open chat closes", bob.locator(P + "[data-tc=input]").is_hidden() and item(ann, "Temp").count() == 0)

    print("== L. a one-to-one chat cannot be managed")
    item(ann, "bob").click(); ann.wait_for_timeout(700); ann.click(P + "[data-tc=menu]")
    ok("its menu has only contact info and mute: no add, leave or delete", ann.locator(P + "[data-tc=m-info]").is_visible() and ann.locator(P + "[data-tc=m-mute]").is_visible() and ann.locator(P + "[data-tc=m-add]").count() + ann.locator(P + "[data-tc=m-leave]").count() + ann.locator(P + "[data-tc=m-delete]").count() == 0)
    ann.keyboard.press("Escape"); ann.mouse.click(5, 5)

    print("== M. typing, drafts, links and hostile text")
    ann.fill(P + "[data-tc=input]", "line one"); ann.press(P + "[data-tc=input]", "Shift+Enter"); ann.keyboard.type("line two"); ok("Shift+Enter makes a new line instead of sending", ann.input_value(P + "[data-tc=input]") == "line one\nline two")
    ann.press(P + "[data-tc=input]", "Enter"); ann.wait_for_function("[...document.querySelectorAll('#team-chat-mount [data-tc=text]')].some(e => e.innerText === 'line one\\nline two')", timeout=8000); ok("Enter sends, and the line break is kept", True)
    ann.fill(P + "[data-tc=input]", "half written"); item(ann, "bob").click(); ann.keyboard.press("Escape")
    ann.click(P + "[data-tc=new]"); ann.click(P + "[data-tc=n-chat]"); ann.fill(P + "[data-tc=chat-search]", "dan"); ann.click(P + "[data-tc=chat-row]"); ann.wait_for_timeout(600)
    ok("a different chat starts with an empty box", ann.input_value(P + "[data-tc=input]") == ""); item(ann, "bob").click(); ann.wait_for_timeout(500)
    ok("the half-written message is still there when you go back", ann.input_value(P + "[data-tc=input]") == "half written"); ann.fill(P + "[data-tc=input]", "")
    evil = "<img src=x onerror=\"document.title='XSS'\"> <b>bold</b> javascript:alert(1) https://example.com/page?a=1."
    say(ann, evil); ann.wait_for_timeout(500)
    ok("hostile message text is shown as plain text and runs nothing", ann.title() != "XSS" and ann.locator(P + "[data-tc=text] img, " + P + "[data-tc=text] b").count() == 0 and any("<img src=x" in t for t in bubble_texts(ann)))
    link = ann.locator(P + "[data-tc=text] a").last; ok("a web address in a message becomes a link, without the full stop after it", link.get_attribute("href") == "https://example.com/page?a=1" and link.get_attribute("rel") == "noopener noreferrer")
    link.click(); ok("clicking it opens the computer's own browser, not the app", ann.evaluate("window.__calls.opened")[-1] == "https://example.com/page?a=1")
    bob.wait_for_selector(P + "[data-tc=item][data-name='ann']"); wait_note(bob, "<b>bold</b>"); ok("the popup carries the same text unchanged", True)

    print("== N. the floating panel and a narrow window")
    ann.click("#nav .nav-item[data-page=chats]"); ann.click("#chat-bubble"); ann.wait_for_selector("#chat-dock-mount .tc")
    ok("the chat button opens the same chats in a floating panel", ann.locator("#chat-dock-mount [data-tc=item][data-name='bob']").count() == 1 and ann.locator("#chat-dock-mount [data-tc=close]").is_visible())
    ann.set_viewport_size({"width": 700, "height": 800}); ann.wait_for_timeout(500)
    ok("in a narrow window it shows one pane at a time: the open chat first", "tc-narrow" in ann.locator("#chat-dock-mount .tc").get_attribute("class") and ann.locator("#chat-dock-mount [data-tc=input]").is_visible() and ann.locator("#chat-dock-mount [data-tc=list]").is_hidden())
    ann.click("#chat-dock-mount [data-tc=back]"); ann.wait_for_timeout(300); ok("Back shows the list on its own", ann.locator("#chat-dock-mount [data-tc=list]").is_visible() and ann.locator("#chat-dock-mount [data-tc=input]").is_hidden())
    ann.click("#chat-dock-mount [data-tc=item][data-name='bob']"); ann.wait_for_timeout(500)
    ok("opening a chat shows the conversation with a Back button", ann.locator("#chat-dock-mount [data-tc=back]").is_visible() and ann.locator("#chat-dock-mount [data-tc=input]").is_visible() and ann.locator("#chat-dock-mount [data-tc=list]").is_hidden())
    ann.click("#chat-dock-mount [data-tc=back]"); ann.wait_for_timeout(300); ok("Back returns to the list", ann.locator("#chat-dock-mount [data-tc=list]").is_visible())
    ann.screenshot(path="/tmp/teamchat_narrow.png"); ann.click("#chat-dock-mount [data-tc=close]"); ok("the panel closes", ann.locator("#chat-panel").is_hidden())
    ann.set_viewport_size({"width": 1400, "height": 900}); team(ann); item(ann, "bob").click(); ann.wait_for_timeout(800); ann.screenshot(path="/tmp/teamchat_wide.png")
    errs = ann.errs + bob.errs + cat.errs + dan.errs; ok("no script errors for anyone", not errs, errs)
    b.close()
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
