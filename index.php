<?php

/**
 * MIT License
 *
 * Copyright (c) 2024 René Coignard
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

class Weblog {
    private static $config = [];
    private static $rewrites = [];
    private const VERSION = '1.8.0';
    private const CONFIG_PATH = __DIR__ . '/config.ini';
    private const DEFAULT_LINE_WIDTH = 72;
    private const DEFAULT_PREFIX_LENGTH = 3;
    private const DEFAULT_WEBLOG_DIR = __DIR__ . '/weblog/';
    private const DEFAULT_DOMAIN = 'localhost';
    private const DEFAULT_SHOW_POWERED_BY = true;
    private const DEFAULT_SHOW_URLS = 'Full';
    private const DEFAULT_SHOW_CATEGORY = true;
    private const DEFAULT_SHOW_DATE = true;
    private const DEFAULT_SHOW_COPYRIGHT = true;
    private const DEFAULT_SHOW_SEPARATOR = false;

    /**
     * Main function to run the Weblog.
     */
    public static function run() {
        self::loadConfig();
        header('Content-Type: text/plain; charset=utf-8');

        $requestedPost = self::getRequestedPost();

        if ($requestedPost) {
            echo "\n\n\n";
            self::renderPost($requestedPost);
            echo (self::$config['show_powered_by'] ? "\n\n\n\n" : "\n\n\n");
            self::renderFooter(date("Y", $requestedPost->getMTime()));
        } else {
            if (isset($_GET['go'])) {
                $go = $_GET['go'];
                if ($go === 'sitemap.xml') {
                    header('Content-Type: application/xml; charset=utf-8');
                    echo self::renderSitemap();
                    exit;
                } else if (preg_match('#^rss/([\w-]+)$#', $go, $matches)) {
                    $category = $matches[1];
                    header('Content-Type: application/xml; charset=utf-8');
                    echo self::generateCategoryRSS($category);
                    exit;
                } else if ($go === 'rss') {
                    header('Content-Type: application/xml; charset=utf-8');
                    echo self::generateRSS();
                    exit;
                } else if ($go === 'random') {
                    self::renderRandomPost();
                    exit;
                } else if (preg_match('#^\d{4}(?:/\d{2}(?:/\d{2})?)?/?$#', $go)) {
                    self::renderPostsByDate($go);
                    exit;
                } else if (self::renderPostsByCategory($go)) {
                    exit;
                }
                self::handleNotFound();
                exit;
            } else {
                self::renderHome();
            }
        }
    }

    /**
     * Loads configuration from config.ini file. Parses the file line-by-line and populates the config array.
     */
    private static function loadConfig() {
        self::$config = parse_ini_file(self::CONFIG_PATH, true)['Weblog'];
        self::$config['line_width'] ??= self::DEFAULT_LINE_WIDTH;
        self::$config['prefix_length'] ??= self::DEFAULT_PREFIX_LENGTH;
        self::$config['weblog_dir'] ??= self::DEFAULT_WEBLOG_DIR;
        self::$config['show_powered_by'] ??= self::DEFAULT_SHOW_POWERED_BY;
        self::$config['show_urls'] ??= self::DEFAULT_SHOW_URLS;
        self::$config['show_category'] ??= self::DEFAULT_SHOW_CATEGORY;
        self::$config['show_date'] ??= self::DEFAULT_SHOW_DATE;
        self::$config['show_copyright'] ??= self::DEFAULT_SHOW_COPYRIGHT;
        self::$config['show_separator'] ??= self::DEFAULT_SHOW_SEPARATOR;

       	self::$rewrites = parse_ini_file(self::CONFIG_PATH, true)['Rewrites'];

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        self::$config['domain'] ??= self::DEFAULT_DOMAIN;
        self::$config['url'] = rtrim($protocol . self::$config['domain'], '/');

        if (self::isMobileDevice()) {
            self::$config['line_width'] = (int)(self::$config['line_width'] / 2) - 1;
            self::$config['show_category'] = false;
            self::$config['show_date'] = false;
            self::$config['show_copyright'] = false;
            self::$config['show_urls'] = false;
            if (isset(self::$config['about_text_alt'])) {
                self::$config['about_text'] = str_replace("\\n", "\n", self::$config['about_text_alt']);
            } else {
                self::$config['about_text'] = str_replace("\\n", "\n", self::$config['about_text']);
            }
        } else {
            if (isset(self::$config['about_text'])) {
                self::$config['about_text'] = str_replace("\\n", "\n", self::$config['about_text']);
            }
        }
    }

    /**
     * Checks if the current user agent corresponds to a mobile device.
     *
     * @return bool Returns true if the user agent is identified as a mobile device, otherwise false.
     */
    private static function isMobileDevice() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return preg_match('/Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
    }

    /**
     * Centers text within the configured line width.
     * @param string $text The text to be centered.
     * @return string The centered text.
     */
    private static function centerText($text) {
        $lineWidth = self::$config['line_width'];
        $leftPadding = ($lineWidth - mb_strlen($text)) / 2;
        return str_repeat(' ', floor($leftPadding)) . $text;
    }

    /**
     * Formats a paragraph to fit within the configured line width, using a specified prefix length.
     * @param string $text The text of the paragraph.
     * @return string The formatted paragraph.
     */
    private static function formatParagraph($text) {
        $lineWidth = self::$config['line_width'];
        $prefixLength = self::$config['prefix_length'];
        $linePrefix = str_repeat(' ', $prefixLength);
        $words = explode(' ', $text);
        $line = $linePrefix;
        $result = '';

        foreach ($words as $word) {
            if (mb_strlen($line . $word) > $lineWidth) {
                $result .= rtrim($line) . "\n";
                $line = $linePrefix . $word . ' ';
            } else {
                $line .= $word . ' ';
            }
        }

        return $result . rtrim($line);
    }

    /**
     * Renders all posts sorted by modification date in descending order.
     */
    private static function renderAllPosts() {
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $files = iterator_to_array($iterator);

        usort($files, function($a, $b) {
            return $b->getMTime() - $a->getMTime();
        });

        $lastIndex = count($files) - 1;
        foreach ($files as $index => $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                self::renderPost($file, true);
                if ($index !== $lastIndex) {
                    echo "\n\n\n\n";
                }
            }
        }

        echo (self::$config['show_powered_by'] ? "\n\n\n\n" : "\n\n\n");
    }

    /**
     * Retrieves the requested post based on the GET parameter, converting the title to a slug and handling .txt extension.
     * @return SplFileInfo|null The file info of the requested post or null if not found.
     */
    private static function getRequestedPost() {
        $postSlug = $_GET['go'] ?? '';
        if (isset(self::$rewrites[rtrim($postSlug, '/')])) {
            $redirectUrl = self::$rewrites[$postSlug];

            if (strpos($redirectUrl, 'http://') === 0 || strpos($redirectUrl, 'https://') === 0) {
                header('Location: ' . $redirectUrl, true, 301);
            } else {
                header('Location: ' . self::$config['url'] . '/' . $redirectUrl . '/', true, 301);
            }
            exit;
        }
        $postSlug = preg_replace('/\.txt$/', '', $postSlug);
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $slug = self::slugify(basename($file->getFilename(), '.txt'));
                if ($slug === $postSlug) {
                    return $file;
                }
            }
        }
        return null;
    }

    /**
     * Retrieves the range of years (earliest and latest) from all posts.
     * @return array Associative array with keys 'min' and 'max' indicating the minimum and maximum years.
     */
    private static function getPostYearsRange() {
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $minYear = PHP_INT_MAX;
        $maxYear = 0;

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $fileYear = date("Y", $file->getMTime());
                if ($fileYear < $minYear) {
                    $minYear = $fileYear;
                }
                if ($fileYear > $maxYear) {
                    $maxYear = $fileYear;
                }
            }
        }

        return ['min' => $minYear, 'max' => $maxYear];
    }

    /**
     * Fetches all posts for the RSS feed, sorted from newest to oldest.
     * @return array An array of posts with necessary data for the RSS feed.
     */
    private static function fetchAllPosts() {
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $posts = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $relativePath = str_replace(self::$config['weblog_dir'], '', $file->getPathname());
                $pathParts = explode('/', trim($relativePath, '/'));
                $category = (count($pathParts) > 1) ? ucfirst($pathParts[0]) : "Misc";

                $slug = self::slugify(basename($file->getFilename(), '.txt'));
                $posts[] = [
                    'title' => basename($file->getFilename(), '.txt'),
                    'slug' => $slug,
                    'date' => $file->getMTime(),
                    'content' => file_get_contents($file->getPathname()),
                    'path' => $file->getPathname(),
                    'category' => $category
                ];
            }
        }
        usort($posts, function($a, $b) {
            return $b['date'] - $a['date'];
        });
        return $posts;
    }

    /**
     * Converts a string to a URL-friendly slug, ensuring non-ASCII characters are appropriately replaced.
     * @param string $title The string to slugify.
     * @return string The slugified string.
     */
    private static function slugify($title) {
        $title = mb_strtolower($title, 'UTF-8');
        $replacements = [
            '/а/u' => 'a',  '/б/u' => 'b',   '/в/u' => 'v',  '/г/u' => 'g',  '/д/u' => 'd',
            '/е/u' => 'e',  '/ё/u' => 'yo',  '/ж/u' => 'zh', '/з/u' => 'z',  '/и/u' => 'i',
            '/й/u' => 'y',  '/к/u' => 'k',   '/л/u' => 'l',  '/м/u' => 'm',  '/н/u' => 'n',
            '/о/u' => 'o',  '/п/u' => 'p',   '/р/u' => 'r',  '/с/u' => 's',  '/т/u' => 't',
            '/у/u' => 'u',  '/ф/u' => 'f',   '/х/u' => 'h',  '/ц/u' => 'ts', '/ч/u' => 'ch',
            '/ш/u' => 'sh', '/щ/u' => 'sch', '/ъ/u' => '',   '/ы/u' => 'y',  '/ь/u' => '',
            '/э/u' => 'e',  '/ю/u' => 'yu',  '/я/u' => 'ya',
        ];
        $title = preg_replace(array_keys($replacements), array_values($replacements), $title);
        $title = iconv('UTF-8', 'ASCII//TRANSLIT', $title);
        $title = preg_replace('/[^a-z0-9\s-]/', '', $title);
        $title = preg_replace('/\s+/', '-', $title);
        return trim($title, '-');
    }

    /**
     * Renders a single post, including its header, content, and optionally a URL.
     * @param SplFileInfo $file The file information object for the post.
     * @param bool $show_urls Indicates if we should append URLs to each post.
     */
    private static function renderPost($file, $show_urls = false) {
        $relativePath = str_replace(self::$config['weblog_dir'], '', $file->getPathname());

        $pathParts = explode('/', trim($relativePath, '/'));
        $category = (count($pathParts) > 1) ? ucfirst($pathParts[0]) : "Misc";

        $title = basename($file->getFilename(), '.txt');
        $date = date("d F Y", $file->getMTime());

        if (self::$config['show_category'] && self::$config['show_date']) {
            $header = self::formatPostHeader($title, $category, $date);
        } else {
            $header = self::formatPostHeader($title);
        }
        echo $header . "\n\n\n";

        $content = file_get_contents($file->getPathname());
        echo self::formatPostContent($content);

        if ($show_urls && self::$config['show_urls']) {
            $slug = self::slugify(basename($file->getFilename(), '.txt'));
            $url = self::$config['show_urls'] === 'Full' ? self::$config['url'] . '/' . $slug . '/' : '/' . $slug;
            echo "\n   " . $url . "\n\n";
        }
    }

    /**
     * Renders posts filtered by date.
     * @param string $datePath Date path from URL in format yyyy/mm/dd.
     */
    private static function renderPostsByDate($datePath) {
        $dateComponents = explode('/', trim($datePath, '/'));
        $year = $dateComponents[0] ?? null;
        $month = $dateComponents[1] ?? null;
        $day = $dateComponents[2] ?? null;

        if (!$year) {
            self::handleNotFound();
            exit;
        }

        $posts = self::fetchAllPosts();
        $filteredPosts = array_filter($posts, function($post) use ($year, $month, $day) {
            $postDate = getdate($post['date']);
            if ($postDate['year'] != $year) return false;
            if ($month && $postDate['mon'] != intval($month)) return false;
            if ($day && $postDate['mday'] != intval($day)) return false;
            return true;
        });

        if (empty($filteredPosts)) {
            self::handleNotFound();
            exit;
        }

        $isFirst = true;
        foreach ($filteredPosts as $post) {
            if ($isFirst) {
                echo "\n\n\n";
                $isFirst = false;
            } else {
                echo "\n\n\n\n";
            }
            self::renderPost(new SplFileInfo($post['path']), true);
        }

        echo (self::$config['show_powered_by'] ? "\n\n\n\n" : "\n\n\n");
        self::renderFooter($year);
    }

    /**
     * Renders posts filtered by category.
     * @param string $category Category name from URL.
     * @return bool Returns true if posts are found and rendered, false otherwise.
     */
    private static function renderPostsByCategory($category) {
        $weblogDir = self::$config['weblog_dir'];
        $categoryPath = $weblogDir . ($category !== 'misc' ? '/' . $category : '');

        if (!is_dir($categoryPath)) {
            self::handleNotFound();
            exit;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $minYear = PHP_INT_MAX;
        $maxYear = 0;

        $posts = [];
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $filePath = $file->getPathname();
                $relativePath = str_replace($weblogDir, '', $filePath);
                $firstDir = trim(strstr($relativePath, '/', true), '/');

                if (($category === 'misc' && (empty($firstDir) || $firstDir === 'misc')) || $firstDir === $category) {
                    $posts[] = $file;
                    $year = date("Y", $file->getMTime());
                    $minYear = min($minYear, $year);
                    $maxYear = max($maxYear, $year);
                }
            }
        }

        if (empty($posts)) {
            self::handleNotFound();
            exit;
        }

        usort($posts, function($a, $b) {
            return $b->getMTime() - $a->getMTime();
        });

        $isFirst = true;
        foreach ($posts as $post) {
            if ($isFirst) {
                echo "\n\n\n";
                $isFirst = false;
            } else {
                echo "\n\n\n\n";
            }
            self::renderPost($post, true);
        }

        echo (self::$config['show_powered_by'] ? "\n\n\n\n" : "\n\n\n");
        self::renderFooter($minYear == $maxYear ? $minYear : "{$minYear}-{$maxYear}");
        return true;
    }

    /**
     * Renders a random post from all available posts.
     */
    private static function renderRandomPost() {
        $posts = self::fetchAllPosts();
        if (empty($posts)) {
            self::handleNotFound();
            exit;
        }

        $randomIndex = array_rand($posts);
        $randomPost = $posts[$randomIndex];
        $randomPostFile = new SplFileInfo($randomPost['path']);

        echo "\n\n\n";
        self::renderPost($randomPostFile);
        echo (self::$config['show_powered_by'] ? "\n\n\n\n" : "\n\n\n");
        self::renderFooter(date("Y", $randomPost['date']));
    }

    /**
     * Formats the About section header with "About" on the left and the author's name centered.
     *
     * @param string $authorName The author's name to be centered.
     * @return string The formatted header string.
     */
    private static function formatAboutHeader($authorName) {
        $lineWidth = self::$config['line_width'];

        if (!self::isMobileDevice()) {
            $leftText = "About";
        } else {
    	    $leftText = '';
        }

        $centerText = $authorName;
        $rightText = '';

        $leftWidth = mb_strlen($leftText);
        $centerWidth = mb_strlen($centerText);
        $rightWidth = mb_strlen($rightText);

        $totalTextWidth = $leftWidth + $centerWidth + $rightWidth;

        $availableSpace = $lineWidth - $totalTextWidth;
        $spaceToLeft = (int)(($lineWidth - $centerWidth) / 2);
        $spaceToRight = $lineWidth - $spaceToLeft - $centerWidth;

        if (self::isMobileDevice() && ($centerWidth % 2) !== 0) {
            $spaceToLeft = $spaceToLeft + 2;
	}

        return "\n\n\n" . sprintf(
            "%s%s%s%s%s",
            $leftText,
            str_repeat(" ", $spaceToLeft - $leftWidth),
            $centerText,
            str_repeat(" ", $spaceToRight - $rightWidth),
            $rightText
        ) . "\n\n\n";
    }

    /**
     * Formats a paragraph from the about text.
     * @param string $paragraph The paragraph to format.
     * @return string The formatted paragraph.
     */
    private static function formatAboutText($aboutText) {
       	$paragraphs = explode("\n", $aboutText);
        $formattedAboutText = '';

        foreach ($paragraphs as $paragraph) {
            if (!self::isMobileDevice()) {
                $formattedParagraph = preg_replace('/\.(\s)/', '. $1', rtrim($paragraph));
            } else {
                $formattedParagraph = $paragraph;
	    }
            $formattedAboutText .= self::formatParagraph($formattedParagraph) . "\n";
        }

        if (self::$config['show_separator']) {
            $separator = "\n\n\n" . str_repeat(' ', self::isMobileDevice() ? self::$config['prefix_length'] : 0) . str_repeat('_', self::$config['line_width'] - (self::isMobileDevice() ? self::$config['prefix_length'] : 0)) . "\n\n\n\n\n";
            $formattedAboutText .= $separator;
        } else {
            $formattedAboutText .= "\n\n\n\n\n";
        }

        return $formattedAboutText;
    }

    /**
     * Formats the header of a post, including category, title, and publication date.
     * Adjusts dynamically based on device type and enabled settings.
     *
     * @param string $title The title of the post.
     * @param string $category The category of the post (optional).
     * @param string $date The publication date of the post (optional).
     * @return string The formatted header.
     */
    private static function formatPostHeader($title = '', $category = '', $date = '') {
        if (substr($title, 0, 1) === '~') {
            $title = '* * *';
        }

        $lineWidth = self::$config['line_width'];

        $includeCategory = self::$config['show_category'] && !empty($category);
        $includeDate = self::$config['show_date'] && !empty($date);

        $availableWidth = $lineWidth;
        $categoryWidth = $includeCategory ? 20 : 0;
        $dateWidth = $includeDate ? 20 : 0;
        $titleWidth = $availableWidth - $categoryWidth - $dateWidth;

        $titlePaddingLeft = (int) (($titleWidth - mb_strlen($title)) / 2);
        $titlePaddingRight = $titleWidth - mb_strlen($title) - $titlePaddingLeft;

        if (self::isMobileDevice() && ($titleWidth % 2) !== 0) {
            $titlePaddingLeft = $titlePaddingLeft + 2;
        }

        $formattedTitle = str_repeat(' ', $titlePaddingLeft) . $title . str_repeat(' ', $titlePaddingRight);

        $header = '';
        if ($includeCategory) {
            $header .= str_pad($category, $categoryWidth);
        }
        $header .= $formattedTitle;
        if ($includeDate) {
            $header .= str_pad($date, $dateWidth, ' ', STR_PAD_LEFT);
        }

        return $header;
    }

    /**
     * Formats the content of a post into paragraphs.
     * @param string $content The raw content of the post.
     * @return string The formatted content.
     */
    private static function formatPostContent($content) {
        $paragraphs = explode("\n", $content);
        $formattedContent = '';

        foreach ($paragraphs as $paragraph) {
            if (!self::isMobileDevice()) {
                $formattedParagraph = preg_replace('/\.(\s)/', '. $1', rtrim($paragraph));
            } else {
       	        $formattedParagraph = $paragraph;
            }
            $formattedContent .= self::formatParagraph($formattedParagraph) . "\n";
        }

        return $formattedContent;
    }

    /**
     * Renders the footer with dynamic copyright information based on the post dates or a specific year if provided.
     * @param int|null $year The specific year for the post page, null for the main page.
     */
    private static function renderFooter($year = null) {
        if (!self::$config['show_copyright']) {
            return;
        }

        $authorEmail = self::$config['author_email'] ?? self::$config['author_name'];

        if ($year !== null) {
            $copyrightText = "Copyright (c) $year $authorEmail";
        } else {
            $postYears = self::getPostYearsRange();
            $earliestYear = $postYears['min'];
            $latestYear = $postYears['max'];
            $currentYear = date("Y");
            if ($earliestYear === $latestYear) {
                $copyrightText = "Copyright (c) $earliestYear $authorEmail";
            } else {
                $copyrightText = "Copyright (c) $earliestYear-$latestYear $authorEmail";
            }
        }

        echo self::centerText($copyrightText);

        if (self::$config['show_powered_by']) {
            echo "\n\n";

            $poweredByText = "Powered by Weblog v" . self::VERSION;
            echo self::centerText($poweredByText);
        }

        echo "\n\n";
    }

    /**
     * Renders the sitemap in XML format, listing all posts, including the main page.
     * Sorts posts from newest to oldest.
     * @return string The XML content of the sitemap.
     */
    private static function renderSitemap() {
        $weblogDir = self::$config['weblog_dir'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($weblogDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $files = iterator_to_array($iterator);

        usort($files, function($a, $b) {
            return $b->getMTime() - $a->getMTime();
        });

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $urlset = $dom->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dom->appendChild($urlset);

        $mainUrl = $dom->createElement('url');
        $mainLoc = $dom->createElement('loc', self::$config['url'] . '/');
        $mainUrl->appendChild($mainLoc);

        $lastmodDate = $files ? date('Y-m-d', $files[0]->getMTime()) : date('Y-m-d');
        $mainLastmod = $dom->createElement('lastmod', $lastmodDate);
        $mainUrl->appendChild($mainLastmod);

        $mainPriority = $dom->createElement('priority', '1.0');
        $mainUrl->appendChild($mainPriority);

        $mainChangefreq = $dom->createElement('changefreq', 'daily');
        $mainUrl->appendChild($mainChangefreq);

        $urlset->appendChild($mainUrl);

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $url = $dom->createElement('url');
                $loc = $dom->createElement('loc', self::$config['url'] . '/' . self::slugify(basename($file->getFilename(), '.txt')) . '/');
                $url->appendChild($loc);

                $lastmod = $dom->createElement('lastmod', date('Y-m-d', $file->getMTime()));
                $url->appendChild($lastmod);

                $priority = $dom->createElement('priority', '1.0');
                $url->appendChild($priority);

                $changefreq = $dom->createElement('changefreq', 'weekly');
                $url->appendChild($changefreq);

                $urlset->appendChild($url);
            }
        }

        return $dom->saveXML();
    }

    /**
     * Generates an RSS feed for the Weblog.
     * @return string The RSS feed as an XML format string.
     */
    private static function generateRSS($posts = null, $lastModifiedDate = null, $category = null) {
        if ($posts === null) {
            $posts = self::fetchAllPosts();
        }

        if ($lastModifiedDate === null) {
            $lastModifiedDate = !empty($posts) ? max(array_column($posts, 'date')) : time();
        }

        $allPosts = self::fetchAllPosts();
        $titleSuffix = $category ? ' - ' . ucfirst($category) : '';

        $rssTemplate = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $rssTemplate .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $rssTemplate .= '<channel>' . "\n";
        $rssTemplate .= '<title>' . htmlspecialchars(self::$config['author_name']) . $titleSuffix . '</title>' . "\n";
        $rssTemplate .= '<link>' . htmlspecialchars(self::$config['url']) . '/' . '</link>' . "\n";
        $rssTemplate .= '<atom:link href="' . htmlspecialchars(self::$config['url']) . '/rss' . ($category ? '/' . $category : '') . '/" rel="self" type="application/rss+xml" />' . "\n";
       	$rssTemplate .= '<description>' . htmlspecialchars(strstr(self::$config['about_text'], "\n\n\n", true)) . '</description>' . "\n";
        $rssTemplate .= '<language>' . 'en' . '</language>' . "\n";
        $rssTemplate .= '<generator>Weblog ' . 'v' . self::VERSION . '</generator>' . "\n";

        if ($lastModifiedDate) {
            $lastBuildDate = date(DATE_RSS, $lastModifiedDate);
            $rssTemplate .= '<lastBuildDate>' . $lastBuildDate . '</lastBuildDate>' . "\n";
        }

        $totalCount = count($allPosts);

        foreach ($posts as $index => $post) {
            $globalIndex = array_search($post, $allPosts);
            $title = $post['title'];
            if (substr($title, 0, 1) === '~') {
                $title = '* * *';
            }
            $paragraphs = explode("\n", trim($post['content']));
            $formattedContent = '';
            $lastParagraphKey = count($paragraphs) - 1;

            foreach ($paragraphs as $key => $paragraph) {
                if (!empty($paragraph)) {
                    $formattedContent .= '&lt;p&gt;' . htmlspecialchars($paragraph) . '&lt;/p&gt;';
                }
            }

            $rssTemplate .= '<item>' . "\n";
            $rssTemplate .= '<title>' . htmlspecialchars($title) . '</title>' . "\n";
            $rssTemplate .= '<guid isPermaLink="false">' . ($totalCount - $globalIndex) . '</guid>' . "\n";
            $rssTemplate .= '<link>' . htmlspecialchars(self::$config['url']) . '/' . htmlspecialchars($post['slug']) . '/' . '</link>' . "\n";
            $rssTemplate .= '<pubDate>' . date(DATE_RSS, $post['date']) . '</pubDate>' . "\n";
            $rssTemplate .= '<category>' . htmlspecialchars($post['category']) . '</category>' . "\n";
            $rssTemplate .= '<description>' . $formattedContent . '</description>' . "\n";
            $rssTemplate .= '</item>' . "\n";
        }

        $rssTemplate .= '</channel>' . "\n";
        $rssTemplate .= '</rss>' . "\n";
        return $rssTemplate;
    }

    /**
     * Generates an RSS feed for a specified category.
     *
     * @param string $category The category for which to generate the RSS feed.
     * @return string The RSS feed as an XML format string.
     */
    private static function generateCategoryRSS($category) {
        $posts = self::fetchAllPosts();
        $categoryPosts = array_filter($posts, function($post) use ($category) {
            return strcasecmp($post['category'], $category) == 0;
        });

        if (empty($categoryPosts)) {
            self::handleNotFound();
            exit;
        }

        $lastModifiedDate = 0;
        foreach ($categoryPosts as $post) {
            if ($post['date'] > $lastModifiedDate) {
                $lastModifiedDate = $post['date'];
            }
        }

        return self::generateRSS($categoryPosts, $lastModifiedDate, $category);
    }

    /**
     * Handles the "Not Found" response with a randomized easter egg.
     */
    private static function handleNotFound() {
        header('Content-Type: text/plain; charset=utf-8');
        if (rand(1, 10) != 1) {
            echo "404 Not Found\n";
        } else {
            echo "404 Cat Found\n\n  ／l、meow\n（ﾟ､ ｡ ７\n  l  ~ヽ\n  じしf_,)ノ\n";
        }
        http_response_code(404);
    }

    /**
     * Renders the home page.
     */
    private static function renderHome() {
        echo self::formatAboutHeader(self::$config['author_name']);
       	echo self::formatAboutText(self::$config['about_text']);
        self::renderAllPosts();
        self::renderFooter();
    }
}

Weblog::run();
