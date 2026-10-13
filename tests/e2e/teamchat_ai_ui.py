#!/usr/bin/env python3
"""Browser test of @ai and threads in the team chat. Same server as teamchat_ui.py (php tests/e2e/teamchat_server.php, port 18505; the AI is stubbed)."""
import sys
src = open(__file__.replace("teamchat_ai_ui.py", "teamchat_ui.py")).read().split("with sync_playwright() as p:")[0]
exec(src)

def ai_msgs(pg): return pg.locator(P + "[data-tc=msg].ai [data-tc=text]").all_inner_texts()
def thread_texts(pg): return pg.locator(P + "[data-tc=thread-msg] .tc-text").all_inner_texts()

with sync_playwright() as p:
    b = p.chromium.launch()
    ann, bob = person(b, "ann"), person(b, "bob")
    for pg in (ann, bob): team(pg)
    ann.click(P + "[data-tc=new]"); ann.click(P + "[data-tc=n-chat]"); ann.wait_for_selector(P + "[data-tc=chat-row]"); ann.click(P + "[data-tc=chat-row][data-name=bob]"); ann.wait_for_selector(P + "[data-tc=input]")
    say(ann, "hello team, anyone about?")
    item(bob, "ann").wait_for(timeout=15000); item(bob, "ann").click(); bob.wait_for_selector(P + "[data-tc=input]")

    print("== A. the AI stays quiet unless asked")
    wait_text(bob, P + "[data-tc=msgs]", "hello team, anyone about?")
    say(bob, "mail me at bob@ai.com please"); wait_text(ann, P + "[data-tc=msgs]", "bob@ai.com"); ann.wait_for_timeout(5000)
    ok("a normal message and an address containing ai.com get no answer from the AI", ai_msgs(ann) == [] and ai_msgs(bob) == [], ai_msgs(ann))

    print("== B. @ai answers, quoting the question")
    say(ann, "@ai what is a CAT6 patch lead?")
    wait_text(ann, P + "[data-tc=msgs]", "Max here: what is a CAT6 patch lead?"); wait_text(bob, P + "[data-tc=msgs]", "Max here: what is a CAT6 patch lead?")
    ok("the answer appears for both people, marked as the AI's", ai_msgs(ann) == ["Max here: what is a CAT6 patch lead?"] and ai_msgs(bob) == ai_msgs(ann), ai_msgs(ann))
    last = ann.locator(P + "[data-tc=msg].ai").last
    ok("it is shown as Max with an AI tag, not as the person who asked", "Max" in last.locator(".tc-who").inner_text() and "AI" in last.locator(".tc-who").inner_text() and "ann" not in last.locator(".tc-who").inner_text(), last.inner_text())
    ok("it quotes the question it answers", "what is a CAT6 patch lead?" in last.locator(".tc-quote").inner_text(), last.inner_text())
    ok("the thinking indicator is gone once it has answered", ann.locator(P + "[data-tc=ai-typing]").count() == 0)
    ok("@ai is highlighted as a mention", ann.locator(P + "[data-tc=msg][data-mine='1'] .tc-mention").last.inner_text() == "@ai")

    print("== C. reply to a line of chat, and the thread")
    first = bob.locator(P + "[data-tc=msg]", has_text="hello team, anyone about?").first
    first.hover(); first.locator("[data-tc=reply-btn]").click(force=True)
    ok("choosing reply shows the quoted line and its author above the box", "hello team" in bob.locator(P + "[data-tc=reply]").inner_text() and "ann" in bob.locator(P + "[data-tc=reply]").inner_text())
    say(bob, "I am about")
    wait_text(ann, P + "[data-tc=msgs]", "I am about")
    link = ann.locator(P + "[data-tc=msg]", has_text="I am about").locator("[data-tc=thread-link]"); link.wait_for(timeout=10000)
    ok("the reply quotes the line and its author, with a '1 reply' link", link.inner_text() == "1 reply" and "ann" in ann.locator(P + "[data-tc=msg]", has_text="I am about").locator(".tc-quote").inner_text(), link.inner_text())
    ok("the first message shows its own '1 reply' link", ann.locator(P + "[data-tc=msg]", has_text="hello team, anyone about?").locator("[data-tc=thread-root-link]").inner_text() == "1 reply")
    link.click(); ann.wait_for_selector(P + "[data-tc=thread]", state="visible")
    ok("the link opens the thread: the first message and the reply, with the count", thread_texts(ann) == ["hello team, anyone about?", "I am about"] and "1 reply" in ann.locator(P + "[data-tc=thread]").inner_text(), thread_texts(ann))
    ann.fill(P + "[data-tc=thread-input]", "Great, can you check stock?"); ann.click(P + "[data-tc=thread-send]")
    wait_text(ann, P + "[data-tc=thread-list]", "Great, can you check stock?")
    ok("a reply written in the thread joins it, and the count goes up", "2 replies" in ann.locator(P + "[data-tc=thread]").inner_text(), ann.locator(P + "[data-tc=thread]").inner_text())
    wait_text(bob, P + "[data-tc=msgs]", "Great, can you check stock?")
    ok("the other person sees it in the chat, quoting the first line, with '2 replies'", "2 replies" in bob.locator(P + "[data-tc=msg]", has_text="Great, can you check stock?").locator(".tc-quote").inner_text())

    print("== D. @ai inside a thread")
    ann.fill(P + "[data-tc=thread-input]", "@ai summarise this thread"); ann.click(P + "[data-tc=thread-send]")
    wait_text(ann, P + "[data-tc=thread-list]", "Max here: summarise this thread")
    ok("the answer lands inside the same thread, marked as the AI's", thread_texts(ann)[-1] == "Max here: summarise this thread" and ann.locator(P + "[data-tc=thread-msg].ai").count() == 1, thread_texts(ann))
    ann.click(P + "[data-tc=thread-close]"); ok("closing the thread hides it", not ann.locator(P + "[data-tc=thread]").is_visible())
    bob.wait_for_timeout(3500)
    ok("no script errors for anyone", not ann.errs and not bob.errs, ann.errs + bob.errs)
    b.close()
print(f"\n{n} checks, {len(fails)} failed"); sys.exit(1 if fails else 0)
