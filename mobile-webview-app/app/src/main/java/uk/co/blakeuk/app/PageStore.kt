package uk.co.blakeuk.app

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

/**
 * Local-only favourites and recently-viewed lists, stored as JSON in
 * SharedPreferences. Entries are page title + URL pairs taken from the
 * WebView as the customer browses — no server round trip, no account
 * required.
 */
class PageStore(context: Context) {

    data class Entry(val title: String, val url: String)

    private val prefs = context.getSharedPreferences("blake_uk_pages", Context.MODE_PRIVATE)

    private fun read(key: String): MutableList<Entry> {
        val raw = prefs.getString(key, null) ?: return mutableListOf()
        val arr = JSONArray(raw)
        val out = mutableListOf<Entry>()
        for (i in 0 until arr.length()) {
            val o = arr.getJSONObject(i)
            out.add(Entry(o.getString("title"), o.getString("url")))
        }
        return out
    }

    private fun write(key: String, entries: List<Entry>) {
        val arr = JSONArray()
        entries.forEach {
            val o = JSONObject()
            o.put("title", it.title)
            o.put("url", it.url)
            arr.put(o)
        }
        prefs.edit().putString(key, arr.toString()).apply()
    }

    fun favourites(): List<Entry> = read(KEY_FAV)

    fun isFavourite(url: String): Boolean = read(KEY_FAV).any { it.url == url }

    fun toggleFavourite(title: String, url: String): Boolean {
        val list = read(KEY_FAV)
        val existing = list.indexOfFirst { it.url == url }
        return if (existing >= 0) {
            list.removeAt(existing)
            write(KEY_FAV, list)
            false
        } else {
            list.add(0, Entry(title.ifBlank { url }, url))
            write(KEY_FAV, list)
            true
        }
    }

    fun recent(): List<Entry> = read(KEY_RECENT)

    fun recordVisit(title: String, url: String) {
        if (title.isBlank() || url.isBlank()) return
        val list = read(KEY_RECENT)
        list.removeAll { it.url == url }
        list.add(0, Entry(title, url))
        while (list.size > MAX_RECENT) list.removeAt(list.size - 1)
        write(KEY_RECENT, list)
    }

    companion object {
        private const val KEY_FAV = "favourites"
        private const val KEY_RECENT = "recent"
        private const val MAX_RECENT = 25
    }
}
