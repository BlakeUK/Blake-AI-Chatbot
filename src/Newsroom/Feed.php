<?php
// src/Newsroom/Feed.php: reads an RSS or Atom feed into a plain list of stories, and cleans everything that came from the outside.
// Titles and other plain text have every angle bracket removed. Summaries and bodies keep only a short list of harmless tags and attributes
// (no scripts, no event handlers, no javascript: addresses, no styles, no frames), so nothing a news site sends can run in the page.

declare(strict_types=1);

namespace Newsroom;

final class Feed
{
    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const NS_DC = 'http://purl.org/dc/elements/1.1/';
    private const NS_MEDIA = 'http://search.yahoo.com/mrss/';
    private const NS_ATOM = 'http://www.w3.org/2005/Atom';
    private const TAGS = ['p', 'br', 'a', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'figure', 'figcaption', 'code', 'pre', 'hr', 'span', 'div', 'sup', 'sub'];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'noscript', 'template', 'svg', 'math', 'audio', 'video', 'canvas', 'frame', 'frameset'];

    /** Plain text: tags stripped, entities decoded, and no angle brackets left to be mistaken for markup. */
    public static function text(?string $s, int $max = 300): string
    {
        $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x00-\x1f\x7f<>]+/u', ' ', $s) ?? '';
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
    }

    /** An http(s) address, or an empty string. */
    public static function url(?string $u): string
    {
        $u = trim((string)$u);
        return (strlen($u) <= 2000 && preg_match('~^https?://[^\s<>"\']+$~i', $u)) ? $u : '';
    }

    /** HTML reduced to a short list of harmless tags and attributes. */
    public static function html(?string $html, int $max = 60000, array $alsoDrop = []): string
    {
        $html = mb_substr((string)$html, 0, $max * 2);
        if (trim($html) === '') return '';
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div id="__root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root');
        if (!$root) return '';
        self::clean($root, $alsoDrop);
        $out = '';
        foreach ($root->childNodes as $n) $out .= $doc->saveHTML($n);
        return mb_substr($out, 0, $max);
    }

    private static function clean(\DOMNode $node, array $alsoDrop = []): void
    {
        foreach (iterator_to_array($node->childNodes) as $c) {
            if ($c instanceof \DOMElement) {
                $name = strtolower($c->tagName);
                if (in_array($name, self::DROP_WITH_CONTENT, true) || in_array($name, $alsoDrop, true)) { $node->removeChild($c); continue; }
                self::clean($c, $alsoDrop);
                if (!in_array($name, self::TAGS, true)) {          // an unknown tag: keep its text, lose the tag
                    while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                    $node->removeChild($c); continue;
                }
                foreach (iterator_to_array($c->attributes) as $a) {
                    $an = strtolower($a->name);
                    $ok = ($name === 'a' && $an === 'href' && preg_match('~^(https?:|mailto:)~i', trim($a->value)))
                       || ($name === 'img' && $an === 'src' && preg_match('~^https?://~i', trim($a->value)))
                       || ($name === 'img' && $an === 'alt');
                    if (!$ok) $c->removeAttribute($a->name);
                    elseif ($an === 'alt') $c->setAttribute('alt', self::text($a->value, 200));
                }
                if ($name === 'a') { $c->setAttribute('rel', 'noopener noreferrer'); $c->setAttribute('target', '_blank'); }
            } elseif ($c instanceof \DOMComment || $c instanceof \DOMProcessingInstruction || $c instanceof \DOMCdataSection) {
                $node->removeChild($c);
            }
        }
    }

    private static function firstImage(string $html): string
    {
        return preg_match('~<img[^>]+src=["\']([^"\']+)["\']~i', $html, $m) ? self::url(html_entity_decode($m[1])) : '';
    }

    /** Reads RSS (0.9x, 1.0, 2.0) or Atom into the list the Newsroom page expects. Returns null if it is not a feed. @return ?array{status:string,feed:array{title:string},items:list<array<string,mixed>>} */
    public static function parse(string $xml, int $count = 20): ?array
    {
        if (strlen($xml) > 3_000_000 || stripos($xml, '<!ENTITY') !== false) return null;     // no entity tricks
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string(trim($xml), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        if (!$x) return null;
        $root = strtolower($x->getName()); $items = []; $title = '';
        if ($root === 'rss' || $root === 'rdf') {
            $title = self::text((string)($x->channel->title ?? ''), 200);
            $list = $root === 'rss' ? $x->channel->item : $x->item;
            foreach ($list as $it) {
                $c = $it->children(self::NS_CONTENT); $dc = $it->children(self::NS_DC); $m = $it->children(self::NS_MEDIA);
                $desc = (string)$it->description; $body = (string)($c->encoded ?? '');
                $thumb = ''; $enc = null;
                if (isset($m->thumbnail)) $thumb = self::url((string)$m->thumbnail->attributes()['url']);
                if (!$thumb && isset($m->content)) foreach ($m->content as $mc) { $u = self::url((string)$mc->attributes()['url']); $t = (string)$mc->attributes()['type']; if ($u && (str_starts_with($t, 'image') || (string)$mc->attributes()['medium'] === 'image' || !$t)) { $thumb = $u; break; } }
                if (isset($it->enclosure)) { $u = self::url((string)$it->enclosure->attributes()['url']); if ($u) $enc = ['link' => $u, 'type' => self::text((string)$it->enclosure->attributes()['type'], 60)]; }
                if (!$thumb && $enc && str_starts_with($enc['type'], 'image')) $thumb = $enc['link'];
                if (!$thumb) $thumb = self::firstImage($body) ?: self::firstImage($desc);
                $link = self::url((string)$it->link) ?: self::url((string)$it->guid);
                $cats = []; foreach ($it->category as $cat) $cats[] = self::text((string)$cat, 60);
                $items[] = ['title' => self::text((string)$it->title, 300), 'pubDate' => self::text((string)($it->pubDate ?: ($dc->date ?? '')), 60), 'link' => $link, 'guid' => self::text((string)$it->guid, 500) ?: $link,
                    'author' => self::text((string)($dc->creator ?: $it->author), 120), 'thumbnail' => $thumb, 'description' => self::html($desc ?: $body), 'content' => self::html($body), 'enclosure' => $enc ?: new \stdClass(), 'categories' => array_slice($cats, 0, 8)];
                if (count($items) >= $count) break;
            }
        } elseif ($root === 'feed') {
            $x->registerXPathNamespace('a', self::NS_ATOM);
            $title = self::text((string)$x->title, 200);
            foreach ($x->entry as $en) {
                $link = '';
                foreach ($en->link as $l) { $rel = (string)$l->attributes()['rel']; if ($rel === '' || $rel === 'alternate') { $link = self::url((string)$l->attributes()['href']); break; } }
                $m = $en->children(self::NS_MEDIA); $thumb = isset($m->thumbnail) ? self::url((string)$m->thumbnail->attributes()['url']) : '';
                $body = (string)$en->content; $desc = (string)$en->summary;
                if (!$thumb) $thumb = self::firstImage($body) ?: self::firstImage($desc);
                $items[] = ['title' => self::text((string)$en->title, 300), 'pubDate' => self::text((string)($en->published ?: $en->updated), 60), 'link' => $link, 'guid' => self::text((string)$en->id, 500) ?: $link,
                    'author' => self::text((string)$en->author->name, 120), 'thumbnail' => $thumb, 'description' => self::html($desc ?: $body), 'content' => self::html($body), 'enclosure' => new \stdClass(), 'categories' => []];
                if (count($items) >= $count) break;
            }
        } else return null;
        return ['status' => 'ok', 'feed' => ['title' => $title], 'items' => $items];
    }
}
