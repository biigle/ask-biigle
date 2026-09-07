<?php

namespace Biigle\Modules\AskBiigle;

class ManualUrls
{
    /**
     * Base URL of the manual pages of the URL map.
     *
     * @var string
     */
    const BASE_URL = 'https://biigle.de/';

    /**
     * Patterns of the content that may contain a reference to a manual page.
     *
     * The alternatives are ordered by precedence, so code is matched (and left alone)
     * before the file names and URLs that it may contain. Links are matched before
     * bare URLs and file names, as the text of a link should not be replaced.
     *
     * @var string
     */
    const CONTENT_PATTERN = '/
        (?P<fence>```[\s\S]*?(?:```|\z)|~~~[\s\S]*?(?:~~~|\z))
        |(?P<code>`[^`\n]*`)
        |(?P<link>(?P<text>\[(?:[^\[\]\\\\]|\\\\.)*\])\(\s*<?(?P<target>[^\s()<>]*)>?(?P<title>\s+"[^"]*")?\s*\))
        |(?P<autolink><(?P<inner>[^\s<>]+)>)
        |(?P<url>https?:\/\/[^\s<>()\[\]"\'`]+)
        |(?P<file>[A-Za-z0-9][A-Za-z0-9_-]*\.html(?:\.md)?|[A-Za-z0-9][A-Za-z0-9_-]*\.md)
        /x';

    /**
     * Manual URL of each file name of the RAG index.
     *
     * @var array<string, string>|null
     */
    protected static $fileToUrl;

    /**
     * All manual URLs of the URL map, indexed by the URL itself.
     *
     * @var array<string, bool>|null
     */
    protected static $urls;

    /**
     * Get the manual URL of a source that the RAG index reported.
     *
     * @param string $title Title of the source, e.g. "manual_tutorials_about.html.md".
     * @return string|null
     */
    public static function forSource($title)
    {
        return static::forFile($title);
    }

    /**
     * Replace references to the files of the RAG index with manual URLs.
     *
     * The RAG index contains the scraped HTML pages of the manual, which the retrieval
     * service converts to Markdown. Their file names are all the LLM knows about a
     * page, so it links to something like "manual_tutorials_about.html.md" instead of
     * the URL of the page. The URL map of the scraper is used to repair these links.
     *
     * @param string $content
     * @param array<string, string> $sourceUrls Manual URL of each retrieval marker,
     * e.g. ['RREF1' => 'https://biigle.de/manual']. Used for links that point to a
     * marker instead of a file name.
     * @return string
     */
    public static function replaceInContent($content, array $sourceUrls = [])
    {
        $replaced = preg_replace_callback(
            static::CONTENT_PATTERN,
            fn ($match) => static::replaceMatch($match, $sourceUrls),
            $content
        );

        // preg_replace_callback() returns null if the content could not be processed
        // (e.g. because it is not valid UTF-8). Keep the original content in this case.
        return is_null($replaced) ? $content : $replaced;
    }

    /**
     * Replace a single match of the content pattern.
     *
     * @param array $match
     * @param array<string, string> $sourceUrls
     * @return string
     */
    protected static function replaceMatch(array $match, array $sourceUrls)
    {
        // Code is content and not a reference to a manual page.
        if (($match['fence'] ?? '') !== '' || ($match['code'] ?? '') !== '') {
            return $match[0];
        }

        if (($match['link'] ?? '') !== '') {
            $url = static::resolve($match['target'] ?? '', $sourceUrls);
            if (is_null($url)) {
                return $match[0];
            }

            return $match['text'].'('.$url.($match['title'] ?? '').')';
        }

        if (($match['autolink'] ?? '') !== '') {
            $url = static::resolve($match['inner'], $sourceUrls);

            return is_null($url) ? $match[0] : '<'.$url.'>';
        }

        if (($match['url'] ?? '') !== '') {
            // A URL at the end of a sentence should not swallow the punctuation.
            $trimmed = rtrim($match['url'], '.,;:!?');
            $url = static::resolve($trimmed, $sourceUrls);

            return is_null($url) ? $match[0] : $url.substr($match['url'], strlen($trimmed));
        }

        $url = static::forFile($match['file']);

        return is_null($url) ? $match[0] : $url;
    }

    /**
     * Get the manual URL that a link target refers to.
     *
     * @param string $target
     * @param array<string, string> $sourceUrls
     * @return string|null
     */
    protected static function resolve($target, array $sourceUrls = [])
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        // The fragment is kept, as it may point to a section of the manual page.
        $fragment = '';
        $position = strpos($target, '#');
        if ($position !== false) {
            $fragment = substr($target, $position);
            $target = substr($target, 0, $position);
        }

        // Some answers link to a retrieval marker instead of a file, e.g. "[Manual](RREF1)".
        if (preg_match('/^\[?((?:RREF|REF)\d+)\]?$/i', $target, $matches)) {
            $url = $sourceUrls[strtoupper($matches[1])] ?? null;

            return is_null($url) ? null : $url.$fragment;
        }

        $position = strpos($target, '?');
        if ($position !== false) {
            $target = substr($target, 0, $position);
        }

        // Links to other hosts must be left alone, even if they end with the name of an
        // indexed file.
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $target)) {
            $host = strtolower((string) parse_url($target, PHP_URL_HOST));
            if (!in_array($host, ['biigle.de', 'www.biigle.de'])) {
                return null;
            }

            $target = (string) parse_url($target, PHP_URL_PATH);
        }

        if ($target === '') {
            return null;
        }

        $url = static::forFile(basename($target));
        if (!is_null($url)) {
            return $url.$fragment;
        }

        // Links that use the path of the manual page but the extension of the indexed
        // file, e.g. "https://biigle.de/manual/tutorials/about.md".
        $path = trim(rawurldecode($target), '/');
        $path = (string) preg_replace('/\.(?:html|md)$/i', '', $path);
        $url = static::BASE_URL.$path;

        return isset(static::urls()[$url]) ? $url.$fragment : null;
    }

    /**
     * Get the manual URL of a file of the RAG index.
     *
     * @param string $name
     * @return string|null
     */
    protected static function forFile($name)
    {
        $name = (string) preg_replace('/\.md$/i', '', trim($name));
        if (!preg_match('/\.html$/i', $name)) {
            $name .= '.html';
        }

        // The file names are derived from the (lower case) URL paths, but the answer
        // may still spell them differently.
        return static::map()[strtolower($name)] ?? null;
    }

    /**
     * Get the manual URL of each file name of the RAG index.
     *
     * @return array<string, string>
     */
    protected static function map()
    {
        if (is_null(static::$fileToUrl)) {
            static::load();
        }

        return static::$fileToUrl;
    }

    /**
     * Get all manual URLs of the URL map, indexed by the URL itself.
     *
     * @return array<string, bool>
     */
    protected static function urls()
    {
        if (is_null(static::$urls)) {
            static::load();
        }

        return static::$urls;
    }

    /**
     * Read the URL map that was generated by the manual scraper.
     */
    protected static function load()
    {
        static::$fileToUrl = [];
        static::$urls = [];

        $path = __DIR__.'/resources/manual-url-map.json';
        if (!is_readable($path)) {
            return;
        }

        $map = json_decode((string) file_get_contents($path), true);
        if (!is_array($map)) {
            return;
        }

        foreach ((array) ($map['file_to_url'] ?? []) as $file => $url) {
            if (is_string($file) && is_string($url) && str_starts_with($url, static::BASE_URL)) {
                static::$fileToUrl[strtolower($file)] = $url;
                static::$urls[$url] = true;
            }
        }
    }
}
