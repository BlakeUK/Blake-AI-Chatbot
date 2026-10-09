/* The writing assistant's text handling, kept apart from the page so it can be tested on its own.
 *
 * Formatting travels as a light text format that the editing prompt knows about:
 *   blank line between paragraphs, **bold**, *italic*, "- " bullets, "1. " numbered lines, [text](address) links.
 * Pasted formatting (Outlook, Word, web mail) is turned into that format, and the finished message is turned back
 * into real formatting on copy. Everything shown on the page is built here from escaped text, never from raw pasted HTML.
 */
(function (global) {
  'use strict';

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var SKIP = { SCRIPT: 1, STYLE: 1, HEAD: 1, TITLE: 1, META: 1, LINK: 1, NOSCRIPT: 1, TEMPLATE: 1 };
  var SAFE_HREF = /^(https?:\/\/|mailto:)[^\s"'<>]+$/i;

  function isBold(el) {
    var t = el.tagName;
    if (t === 'B' || t === 'STRONG') return true;
    var w = el.style && el.style.fontWeight;
    return w === 'bold' || w === 'bolder' || (w && parseInt(w, 10) >= 600);
  }
  function isItalic(el) {
    var t = el.tagName;
    return t === 'I' || t === 'EM' || (el.style && el.style.fontStyle === 'italic');
  }

  // Wrap each non-empty line separately, keeping surrounding spaces outside the marks.
  function mark(inner, m) {
    return inner.split('\n').map(function (line) {
      var parts = /^(\s*)([\s\S]*?)(\s*)$/.exec(line);
      return parts[2] === '' ? line : parts[1] + m + parts[2] + m + parts[3];
    }).join('\n');
  }

  function walk(node, st) {
    if (node.nodeType === 3) {
      return node.nodeValue.replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').replace(/\\/g, '\\\\').replace(/\*/g, '\\*');
    }
    if (node.nodeType !== 1 || SKIP[node.tagName]) return '';
    var tag = node.tagName;
    if (tag === 'BR') return '\n';

    var bold = isBold(node) && !st.bold, italic = isItalic(node) && !st.italic;
    var inner = Array.prototype.map.call(node.childNodes, function (c) {
      return walk(c, { bold: st.bold || bold, italic: st.italic || italic });
    }).join('');

    if (tag === 'UL' || tag === 'OL') {
      var n = 0;
      var items = Array.prototype.filter.call(node.childNodes, function (c) { return c.nodeType === 1 && c.tagName === 'LI'; }).map(function (li) {
        n++;
        var text = walk(li, st).replace(/^\s+|\s+$/g, '').replace(/\n+/g, ' ');
        return (tag === 'UL' ? '- ' : n + '. ') + text;
      });
      return '\n\n' + items.join('\n') + '\n\n';
    }
    if (tag === 'LI') return inner; // handled by its list; a stray one is just its text
    if (tag === 'A') {
      var href = node.getAttribute && node.getAttribute('href');
      var label = inner.replace(/^\s+|\s+$/g, '');
      if (href && SAFE_HREF.test(href) && label !== '') {
        var plain = label.replace(/\\\*/g, '*');
        return plain === href || plain === href.replace(/^mailto:/i, '') ? plain : '[' + label.replace(/\]/g, '\\]') + '](' + href + ')';
      }
      return inner;
    }
    if (bold) inner = mark(inner, '**');
    if (italic) inner = mark(inner, '*');
    if (tag === 'P' || tag === 'H1' || tag === 'H2' || tag === 'H3' || tag === 'H4' || tag === 'H5' || tag === 'H6' || tag === 'BLOCKQUOTE' || tag === 'PRE' || tag === 'TABLE') {
      return '\n\n' + inner + '\n\n';
    }
    if (tag === 'DIV' || tag === 'TR' || tag === 'SECTION' || tag === 'ARTICLE' || tag === 'HEADER' || tag === 'FOOTER') return '\n' + inner + '\n';
    if (tag === 'TD' || tag === 'TH') return inner + ' ';
    return inner;
  }

  function htmlToText(root) {
    var s = walk(root, { bold: false, italic: false });
    s = s.split('\n').map(function (l) { return l.replace(/[ \t]+/g, ' ').trim(); }).join('\n');
    return s.replace(/\n{3,}/g, '\n\n').replace(/^\n+|\n+$/g, '');
  }

  // Inline marks, links and escapes -> safe HTML.
  function inlineHtml(line) {
    var stash = [];
    var s = line.replace(/\\([\\*\]])/g, function (m, c) { stash.push(c); return '\u0001' + (stash.length - 1) + '\u0002'; });
    s = esc(s);
    s = s.replace(/\[([^\]\n]+)\]\(((?:https?:\/\/|mailto:)[^\s)"'<>]+)\)/g, function (m, t, u) { return '<a href="' + u + '">' + t + '</a>'; });
    s = s.replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*])\*([^*\s][^*\n]*?)\*(?!\*)/g, '$1<em>$2</em>');
    return s.replace(/\u0001(\d+)\u0002/g, function (m, i) { return esc(stash[+i]); });
  }

  function blocks(text) {
    var out = [], cur = null;
    String(text).replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
      if (line.trim() === '') { cur = null; return; }
      var ul = /^[-•] +(.*)$/.exec(line), ol = /^(\d+)[.)] +(.*)$/.exec(line);
      if (ul || ol) {
        var type = ul ? 'ul' : 'ol';
        if (!cur || cur.type !== type) { cur = { type: type, items: [] }; out.push(cur); }
        cur.items.push(ul ? ul[1] : ol[2]);
      } else if (cur && cur.type === 'p') {
        cur.lines.push(line);
      } else {
        cur = { type: 'p', lines: [line] };
        out.push(cur);
      }
    });
    return out;
  }

  var P = ' style="margin:0 0 1em 0"';
  function mdToHtml(text) {
    return blocks(text).map(function (b) {
      if (b.type === 'p') return '<p' + P + '>' + b.lines.map(inlineHtml).join('<br>') + '</p>';
      var tag = b.type;
      return '<' + tag + P + '>' + b.items.map(function (i) { return '<li>' + inlineHtml(i) + '</li>'; }).join('') + '</' + tag + '>';
    }).join('');
  }

  // Typed or plain-text pasted text: shown as it is, with no marks interpreted.
  function plainToHtml(text) {
    return String(text).replace(/\r\n?/g, '\n').split(/\n{2,}/).map(function (p) {
      p = p.replace(/^\n+|\n+$/g, '');
      return p === '' ? '' : '<p' + P + '>' + esc(p).replace(/\n/g, '<br>') + '</p>';
    }).join('');
  }

  // The light format as ordinary plain text: marks removed, bullets and numbers kept, links as "text (address)".
  function mdToPlain(text) {
    return String(text).replace(/\r\n?/g, '\n').split('\n').map(function (line) {
      var stash = [];
      var s = line.replace(/\\([\\*\]])/g, function (m, c) { stash.push(c); return '\u0001' + (stash.length - 1) + '\u0002'; });
      s = s.replace(/\[([^\]\n]+)\]\(((?:https?:\/\/|mailto:)[^\s)"'<>]+)\)/g, '$1 ($2)');
      s = s.replace(/\*\*([^*\n]+?)\*\*/g, '$1').replace(/(^|[^*])\*([^*\s][^*\n]*?)\*(?!\*)/g, '$1$2');
      return s.replace(/\u0001(\d+)\u0002/g, function (m, i) { return stash[+i]; });
    }).join('\n');
  }

  // Word-level comparison, shown with insertions and deletions marked. Returns safe HTML.
  function diffHtml(a, b) {
    var ta = String(a).split(/(\s+)/).filter(Boolean), tb = String(b).split(/(\s+)/).filter(Boolean);
    if (ta.length * tb.length > 4000000) return null; // too big to compare comfortably
    var n = ta.length, m = tb.length, w = m + 1, dp = new Uint16Array((n + 1) * w), i, j;
    for (i = n - 1; i >= 0; i--) {
      for (j = m - 1; j >= 0; j--) {
        dp[i * w + j] = ta[i] === tb[j] ? dp[(i + 1) * w + j + 1] + 1 : Math.max(dp[(i + 1) * w + j], dp[i * w + j + 1]);
      }
    }
    var out = [], kind = null, buf = '';
    function push(k, s) {
      if (k !== kind && buf) { out.push(wrap(kind, buf)); buf = ''; }
      kind = k; buf += s;
    }
    function wrap(k, s) {
      if (!s.trim()) return esc(s).replace(/\n/g, '<br>');
      var h = esc(s).replace(/\n/g, '<br>');
      return k === 'ins' ? '<ins>' + h + '</ins>' : k === 'del' ? '<del>' + h + '</del>' : h;
    }
    i = 0; j = 0;
    while (i < n && j < m) {
      if (ta[i] === tb[j]) { push('same', tb[j]); i++; j++; }
      else if (dp[(i + 1) * w + j] >= dp[i * w + j + 1]) { push('del', ta[i]); i++; }
      else { push('ins', tb[j]); j++; }
    }
    while (i < n) push('del', ta[i++]);
    while (j < m) push('ins', tb[j++]);
    if (buf) out.push(wrap(kind, buf));
    return out.join('');
  }

  global.WriterLib = { esc: esc, htmlToText: htmlToText, mdToHtml: mdToHtml, plainToHtml: plainToHtml, mdToPlain: mdToPlain, diffHtml: diffHtml };
})(window);
