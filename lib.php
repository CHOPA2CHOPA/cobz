<?php
declare(strict_types=1);
/* MiniGrabber v2 — библиотека. Симбиоз GrabberCore + WPGTools/WPGHelper, публикация через WP REST. */

final class Log {
    public static string $file = '', $dir = '';
    public static bool $debug = false;
    public static function w(string $m): void {
        $s = '[' . date('H:i:s') . "] {$m}";
        echo $s, PHP_EOL;
        if (self::$file !== '') @file_put_contents(self::$file, $s . PHP_EOL, FILE_APPEND);
    }
    public static function dump(string $name, string $data): void {
        if (self::$debug && self::$dir !== '') @file_put_contents(rtrim(self::$dir, '/') . '/' . $name, $data);
    }
}

final class Http {
    public string $currentUrl = '';
    public function __construct(private array $c, private string $cookie = '') {}
    private function enc(string $u): string {
        return (string)preg_replace_callback('~[\x80-\xFF]+~', fn($m) => rawurlencode($m[0]), str_replace(' ', '%20', $u));
    }
    private function proxy(): array {
        $p = $this->c['proxy'] ?? [];
        if (empty($p['on'])) return [];
        $host = trim((string)($p['host'] ?? ''));
        if (!empty($p['listOn']) && trim((string)($p['list'] ?? '')) !== '') {
            $l = array_values(array_filter(array_map('trim', preg_split('~\R~', (string)$p['list']) ?: [])));
            if ($l) { shuffle($l); $host = (string)array_pop($l); }
        }
        if ($host === '') return [];
        $types = [0 => CURLPROXY_HTTP, 1 => CURLPROXY_SOCKS5, 2 => CURLPROXY_SOCKS4A, 3 => CURLPROXY_SOCKS5_HOSTNAME];
        $o = [CURLOPT_PROXY => $host, CURLOPT_PROXYTYPE => $types[(int)($p['type'] ?? 0)] ?? CURLPROXY_HTTP];
        if (!empty($p['userPwd'])) $o[CURLOPT_PROXYUSERPWD] = $p['userPwd'];
        Log::w("proxy: {$host}");
        return $o;
    }
    private function opts(): array {
        $o = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => (bool)($this->c['redirectOn'] ?? true),
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => (int)($this->c['timeout'] ?? 30),
            CURLOPT_ENCODING       => ($this->c['gzipOn'] ?? true) ? 'gzip, deflate' : '',
            CURLOPT_USERAGENT      => $this->c['userAgent'] ?? 'MiniGrabber/2.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_AUTOREFERER    => true,
        ];
        if ($this->cookie !== '') { $o[CURLOPT_COOKIEFILE] = $this->cookie; $o[CURLOPT_COOKIEJAR] = $this->cookie; }
        return $o + $this->proxy();
    }
    public function get(string $url): string|false {
        $this->currentUrl = $url;
        if ((int)($this->c['method'] ?? 0) === 1) {
            $ctx = stream_context_create(['http' => ['timeout' => (int)($this->c['timeout'] ?? 30), 'user_agent' => $this->c['userAgent'] ?? 'MiniGrabber/2.0']]);
            $b = @file_get_contents($this->enc($url), false, $ctx);
            if ($b === false) { Log::w("HTTP FAIL {$url}"); return false; }
        } else {
            $o = $this->opts();
            $o[CURLOPT_HTTPHEADER] = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: ru,en;q=0.8'];
            $ch = curl_init($this->enc($url)); curl_setopt_array($ch, $o);
            $b = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $this->currentUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $e = curl_error($ch); curl_close($ch);
            if ($b === false || $code >= 400) { Log::w("HTTP FAIL {$url} [{$code}] {$e}"); return false; }
        }
        Log::dump(md5($url) . '.html', (string)$b);
        if (($d = (int)($this->c['stopTime'] ?? 0)) > 0) sleep($d);
        return (string)$b;
    }
    public function api(string $method, string $url, string $body = '', array $headers = []): string|false {
        $o = $this->opts();
        $o[CURLOPT_CUSTOMREQUEST] = $method;
        $o[CURLOPT_HTTPHEADER] = $headers;
        $o[CURLOPT_TIMEOUT] = 90;
        if ($body !== '') $o[CURLOPT_POSTFIELDS] = $body;
        $ch = curl_init($url); curl_setopt_array($ch, $o);
        $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $e = curl_error($ch); curl_close($ch);
        if ($r === false || $code >= 400) { Log::w("API FAIL {$method} {$url} [{$code}] {$e} " . substr((string)$r, 0, 200)); return false; }
        return (string)$r;
    }
}

final class Txt {
    private const MONTHS = [
        'января'=>'january','февраля'=>'february','марта'=>'march','апреля'=>'april','мая'=>'may','июня'=>'june',
        'июля'=>'july','августа'=>'august','сентября'=>'september','октября'=>'october','ноября'=>'november','декабря'=>'december',
        'januar'=>'january','februar'=>'february','märz'=>'march','juni'=>'june','juli'=>'july','oktober'=>'october','dezember'=>'december',
        'janvier'=>'january','février'=>'february','avril'=>'april','juin'=>'june','juillet'=>'july','août'=>'august',
        'septembre'=>'september','octobre'=>'october','novembre'=>'november','décembre'=>'december',
        'ocak'=>'january','şubat'=>'february','nisan'=>'april','mayıs'=>'may','haziran'=>'june','temmuz'=>'july',
        'ağustos'=>'august','eylül'=>'september','ekim'=>'october','kasım'=>'november','aralık'=>'december',
        'janeiro'=>'january','fevereiro'=>'february','março'=>'march','abril'=>'april','maio'=>'may','junho'=>'june',
        'julho'=>'july','agosto'=>'august','setembro'=>'september','outubro'=>'october','novembro'=>'november','dezembro'=>'december',
    ];
    private const TRANS = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'jo','ж'=>'zh','з'=>'z','и'=>'i',
        'й'=>'j','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f',
        'х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'shh','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'ju','я'=>'ja',
        'є'=>'ye','і'=>'i','ї'=>'yi','ґ'=>'g','ў'=>'u'];

    public static function utf(string $s, string $from = ''): string {
        if ($from !== '' && stripos($from, 'utf-8') === false) return mb_convert_encoding($s, 'UTF-8', $from);
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
    }
    public static function replace(string $stage, string $s, array $p): string {
        if (empty($p['user_replace_on']) || empty($p['replace'][$stage])) return $s;
        foreach ($p['replace'][$stage] as $r) {
            if (empty($r['search'])) continue;
            $s = preg_replace($r['search'], (string)($r['replace'] ?? ''), $s, (int)($r['limit'] ?? -1) ?: -1) ?? $s;
        }
        return $s;
    }
    public static function cut(string $html, string $start, string $end): ?string {
        if ($start === '' && $end === '') return $html;
        $re = '~' . addcslashes($start, '&|') . '(.*?)' . addcslashes($end, '&|') . '~is';
        return preg_match($re, $html, $m) ? $m[1] : null;
    }
    public static function match(string $body, string $s): ?string {
        if ($body === '') return null;
        return preg_match('~' . $body . '~is', $s, $m) ? (string)($m[1] ?? $m[0]) : null;
    }
    public static function baseHref(string $html): ?string {
        return preg_match('~<base[^>]*href\s*=\s*["\']?([^"\'\s>]+)~i', $html, $m) ? $m[1] : null;
    }
    public static function abs(string $u, string $base): string {
        $u = html_entity_decode(trim($u), ENT_QUOTES);
        if ($u === '' || str_starts_with($u, 'data:') || str_starts_with($u, '#')) return '';
        if (preg_match('~^https?://~i', $u)) return $u;
        $p = parse_url($base); $sc = $p['scheme'] ?? 'https'; $root = $sc . '://' . ($p['host'] ?? '');
        if (str_starts_with($u, '//')) return $sc . ':' . $u;
        if (str_starts_with($u, '/')) return $root . $u;
        return $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $u;
    }
    public static function filterPass(string $title, string $text, array $p): bool {
        if (empty($p['filter_words_on'])) return true;
        $w = array_filter(array_map('trim', explode(',', (string)($p['filter_words_list'] ?? ''))));
        if (!$w) return true;
        $subj = match ((string)($p['filter_words_where'] ?? 'text')) {
            'title' => $title, 'title+text' => $title . ' ' . $text, default => $text };
        $found = (bool)preg_match('~(' . implode('|', array_map(fn($x) => preg_quote($x, '~'), $w)) . ')~iu', $subj);
        return !empty($p['filter_words_save']) ? !$found : $found;
    }
    public static function stripScripts(string $s, array $p): string {
        if (empty($p['js_script_no_del'])) $s = (string)preg_replace('~<script.*?</script>~is', '', $s);
        if (empty($p['css_no_del']))       $s = (string)preg_replace('~<style.*?</style>~is', '', $s);
        return $s;
    }
    public static function clean(string $html, string $allowed): string {
        $html = strip_tags($html, $allowed !== '' ? $allowed : '<p><br><img><b><strong><i><em><a><ul><ol><li><h2><h3><blockquote>');
        $html = (string)preg_replace('~\son\w+\s*=\s*(["\']).*?\1~is', '', $html);
        $html = (string)preg_replace('~\s(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2~is', '', $html);
        return trim((string)preg_replace('~<(p|div|h[1-6])>\s*</\1>~iu', '', $html));
    }
    public static function limit(string $html, int $size, string $end = '.'): string {
        $plain = self::plain($html);
        $chunk = mb_substr($plain, 0, $size + 10);
        $pos = mb_strripos($chunk, $end === '' ? ' ' : $end);
        return mb_substr($plain, 0, $pos === false ? $size : $pos) . ($end === '' ? '...' : $end);
    }
    public static function more(string $text): array {
        if (str_contains($text, '{{{MORE}}}')) {
            [$a, $b] = explode('{{{MORE}}}', $text, 2);
            return [self::plain($a), $a . '<!--more-->' . $b];
        }
        return ['', $text];
    }
    public static function slug(string $s): string {
        $s = strtr(mb_strtolower(trim($s)), self::TRANS);
        return trim((string)preg_replace('~[^a-z0-9]+~', '-', $s), '-');
    }
    public static function plain(string $html): string {
        return trim((string)preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }
    public static function keywords(string $text, int $n = 8, string $stop = ''): string {
        preg_match_all('~[\p{L}]{4,}~u', mb_strtolower(self::plain($text)), $m);
        $bad = array_filter(array_map('trim', explode(',', mb_strtolower($stop))));
        $f = array_count_values(array_diff($m[0], $bad)); arsort($f);
        return implode(', ', array_slice(array_keys($f), 0, $n));
    }
    public static function description(string $text, int $len = 300): string {
        $p = self::plain($text);
        if (mb_strlen($p) <= $len) return $p;
        $cut = mb_substr($p, 0, $len);
        return mb_substr($cut, 0, mb_strrpos($cut, ' ') ?: $len);
    }
    public static function date(?string $raw): ?string {
        if (!$raw) return null;
        $s = trim(str_ireplace(array_keys(self::MONTHS), array_values(self::MONTHS), strip_tags($raw)));
        $t = strtotime($s);
        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }
    public static function tags(string $page, array $p): array {
        if (empty($p['post_tags_on'])) return [];
        $tags = [];
        if (!empty($p['tags_mode'])) {
            $lines = @file((string)($p['tags_file'] ?? ''), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if ($lines) $tags = explode(',', trim($lines[array_rand($lines)]));
        } elseif (preg_match_all('~' . addcslashes((string)($p['tagsScrape'] ?? ''), '&|') . '~is', $page, $m) && !empty($m[1])) {
            $tags = $m[1];
            if (count($tags) === 1 && !empty($p['tagsScrapeSeparator']))
                $tags = explode((string)$p['tagsScrapeSeparator'], str_replace("\xC2\xA0", ' ', $tags[0]));
        }
        $tags = array_values(array_unique(array_filter(array_map('trim', $tags))));
        return array_slice($tags, 0, (int)($p['tagsScrapeCount'] ?? 10));
    }
}

final class WP {
    public function __construct(private array $c, private Http $http) {}
    private function req(string $method, string $path, array|string|null $body = null, array $extra = []): ?array {
        $url = rtrim($this->c['url'], '/') . '/wp-json/' . ltrim($path, '/');
        $h = array_merge(['Authorization: Basic ' . base64_encode($this->c['user'] . ':' . $this->c['appPassword'])], $extra);
        $raw = '';
        if (is_array($body)) { $raw = (string)json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
        elseif (is_string($body)) $raw = $body;
        $r = $this->http->api($method, $url, $raw, $h);
        if ($r === false) return null;
        $j = json_decode($r, true);
        return is_array($j) ? $j : null;
    }
    public function post(array $f): ?array { $r = $this->req('POST', 'wp/v2/posts', $f); return isset($r['id']) ? $r : null; }
    public function media(string $file, string $name): ?array {
        $bin = (string)@file_get_contents($file);
        $info = @getimagesizefromstring($bin);
        $r = $this->req('POST', 'wp/v2/media', $bin, [
            'Content-Type: ' . ($info['mime'] ?? 'image/jpeg'),
            'Content-Disposition: attachment; filename="' . $name . '"',
        ]);
        return isset($r['id'], $r['source_url']) ? $r : null;
    }
    public function deleteMedia(int $id): void { $this->req('DELETE', 'wp/v2/media/' . $id . '?force=true'); }
    public function termIds(array $names, string $tax = 'tags'): array {
        $ids = [];
        foreach ($names as $n) {
            $n = trim($n); if ($n === '') continue;
            $found = $this->req('GET', 'wp/v2/' . $tax . '?search=' . rawurlencode($n) . '&per_page=1');
            $id = $found[0]['id'] ?? null;
            if (!$id) { $c = $this->req('POST', 'wp/v2/' . $tax, ['name' => $n]); $id = $c['id'] ?? null; }
            if ($id) $ids[] = (int)$id;
        }
        return $ids;
    }
}

final class Images {
    public ?int $featuredId = null;
    private array $saved = [], $media = [];
    public function __construct(private array $p, private array $cfg, private Http $http,
                                private WP $wp, private string $title, private bool $test) {}

    private static function ext(string $mime): string {
        return match ($mime) { 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'jpg' };
    }
    private function transform(string $bin, string $dst, int $w, int $h, int $q, bool $crop): bool {
        $i = @getimagesizefromstring($bin); if (!$i) return false;
        [$sw, $sh] = $i;
        if ($crop && $w > 0 && $h > 0) { $tw = $w; $th = $h; }
        else {
            $scale = $w > 0 && $h > 0 ? min($w / $sw, $h / $sh) : ($w > 0 ? $w / $sw : ($h > 0 ? $h / $sh : 1));
            if ($scale > 1) $scale = 1;
            $tw = max(1, (int)round($sw * $scale)); $th = max(1, (int)round($sh * $scale));
            if ($tw === $sw && $th === $sh) return (bool)file_put_contents($dst, $bin);
        }
        $src = @imagecreatefromstring($bin); if (!$src) return false;
        $out = imagecreatetruecolor($tw, $th);
        if ($crop) {
            $s = max($tw / $sw, $th / $sh);
            $cw = (int)round($tw / $s); $ch = (int)round($th / $s);
            imagecopyresampled($out, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $tw, $th, $cw, $ch);
        } else imagecopyresampled($out, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        $ok = match ($i['mime']) {
            'image/png' => imagepng($out, $dst), 'image/gif' => imagegif($out, $dst),
            'image/webp' => imagewebp($out, $dst, $q), default => imagejpeg($out, $dst, $q),
        };
        imagedestroy($src); imagedestroy($out);
        return (bool)$ok;
    }
    private function store(string $bin, array $info, string $srcUrl): array {
        $dir = rtrim($this->cfg['imgPath'], '/') . '/' . date('Ymd');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $base = !empty($this->p['image_name_from_title_on']) ? mb_substr(Txt::slug($this->title), 0, 100) : 'img';
        $name = $base . '-' . substr(md5($srcUrl . microtime()), 0, 7) . '.' . self::ext($info['mime']);
        $file = $dir . '/' . $name;
        $ok = !empty($this->p['image_resize'])
            ? $this->transform($bin, $file, (int)($this->p['text_pic_width'] ?? 0), (int)($this->p['text_pic_height'] ?? 0),
                               (int)($this->p['text_pic_quality'] ?? 90), !empty($this->p['img_text_crop']))
            : (bool)file_put_contents($file, $bin);
        if (!$ok) return ['url' => $srcUrl, 'id' => null, 'w' => $info[0], 'h' => $info[1]];
        $this->saved[] = $file;
        $m = $this->wp->media($file, $name);
        if ($m === null) return ['url' => $srcUrl, 'id' => null, 'w' => $info[0], 'h' => $info[1]];
        $this->media[] = (int)$m['id'];
        $d = @getimagesize($file) ?: $info;
        return ['url' => (string)$m['source_url'], 'id' => (int)$m['id'], 'w' => $d[0], 'h' => $d[1]];
    }
    
    public function process(string $html, string $base): string {
        if (trim($html) === '') return $html;

        // === ДИАГНОСТИКА КАРТИНОК ===
        $imgCountBefore = substr_count(strtolower($html), '<img');
        if ($imgCountBefore > 0) {
            Log::w("✓ Найдено тегов <img> в тексте до обработки: {$imgCountBefore}");
        } else {
            Log::w("✗ ВНИМАНИЕ: Теги <img> отсутствуют в извлеченном тексте!");
            Log::w("  Фрагмент текста (300 симв): " . mb_substr(strip_tags($html), 0, 300));
        }
        // ============================

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="wpgroot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xp = new DOMXPath($doc);
        $root = $xp->query('//div[@id="wpgroot"]')->item(0);
        if (!$root) {
            Log::w("  DOMDocument не смог распарсить HTML, возвращаем исходный текст.");
            return $html;
        }
        
        $nodes = iterator_to_array($xp->query('.//img', $root));
        if (count($nodes) === 0) {
            Log::w("  DOM XPath не нашел ни одного тега <img>. Возможно, strip_tags их удалил или HTML поврежден.");
            return $html;
        }

        $legacy = ['align', 'border', 'hspace', 'vspace', 'longdesc'];
        $max = (int)($this->p['max_images'] ?? 0);
        $repl = []; $n = 0;
        foreach ($nodes as $i => $img) {
            $ph = '{{WPGIMG' . $i . '}}'; $tpl = '';
            $allowedByLimit = (empty($this->p['limit_image_output_on']) || $i === 0) && ($max <= 0 || $n < $max);
            $src = '';
            if ($allowedByLimit) foreach (['data-src', 'data-original', 'data-lazy-src', 'src'] as $a) {
                $v = trim($img->getAttribute($a));
                if ($v !== '' && !str_starts_with($v, 'data:')) { $src = Txt::abs($v, $base); break; }
            }
            if ($src !== '') {
                $bin = $this->http->get($src);
                $info = $bin === false ? false : @getimagesizefromstring($bin);
                if ($info && str_starts_with($info['mime'], 'image/')) {
                    $n++;
                    $adds = [];
                    foreach ($img->attributes as $at)
                        if (in_array($at->name, $legacy, true)) $adds[] = $at->name . '="' . htmlspecialchars($at->value, ENT_QUOTES) . '"';
                    if (!empty($this->p['image_alt_make_on'])) {
                        $cur = $img->getAttribute('alt'); $t = $img->getAttribute('title');
                        $alt = (!empty($this->p['image_alt_replace']) || $cur === '')
                            ? ((!empty($this->p['image_alt_from_attr_title']) && $t !== '') ? $t : $this->title) . ' ' . ($i + 1)
                            : $cur;
                        $adds[] = 'alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"';
                    }
                    if (!empty($this->p['image_class_name_on']) && !empty($this->p['image_class_name_custom']))
                        $adds[] = 'class="' . htmlspecialchars((string)$this->p['image_class_name_custom'], ENT_QUOTES) . '"';
                    $r = (!$this->test && !empty($this->p['image_save']))
                        ? $this->store($bin, $info, $src)
                        : ['url' => $src, 'id' => null, 'w' => $info[0], 'h' => $info[1]];
                    $tpl = strtr((string)($this->p['imageHtmlCode'] ?? '<img src="%PATH%" %ADDS% %ATTR% />'), [
                        '%TITLE%' => htmlspecialchars($this->title, ENT_QUOTES),
                        '%PATH%'  => $r['url'],
                        '%ADDS%'  => implode(' ', $adds),
                        '%ATTR%'  => 'width="' . $r['w'] . '" height="' . $r['h'] . '"',
                    ]);
                    if ($this->featuredId === null && !empty($this->p['post_thumb_on']) && $r['id']) {
                        $this->featuredId = (int)$r['id'];
                        if (!empty($this->p['delFirstPic']) && !$this->test) $tpl = '';
                    }
                    if (!empty($this->p['image_nextpage_quan']) && $n > 1 && $n % (int)$this->p['image_nextpage_quan'] === 0)
                        $tpl .= '<!--nextpage-->';
                    Log::w("img {$n}: {$src} → {$r['url']}");
                }
            }
            $repl[$ph] = $tpl;
            $img->parentNode?->replaceChild($doc->createTextNode($ph), $img);
        }
        $out = '';
        foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
        return strtr($out, $repl);
    }
    public function rollback(): void {
        foreach ($this->saved as $f) @unlink($f);
        foreach ($this->media as $id) $this->wp->deleteMedia($id);
        if ($this->saved || $this->media) Log::w('cleanImages: откат картинок');
        $this->saved = $this->media = [];
    }
}

final class Translator {
    public function __construct(private array $cfg, private Http $http, private Store $st) {}
    public function run(string $text, array $t): ?string {
        if (trim($text) === '') return $text;
        $parts = $this->chunks($text, (int)($t['split'] ?? 4000), ($t['format'] ?? 'html') === 'html');
        $out = '';
        foreach ($parts as $p) {
            $r = $this->call($p, $t);
            if ($r === null) return null;
            $out .= $r;
            if (count($parts) > 1) usleep(250000);
        }
        return $out;
    }
    private function chunks(string $s, int $w, bool $html): array {
        if (mb_strlen($s) <= $w) return [$s];
        $pieces = ($html ? preg_split('~(?<=>)~u', $s) : preg_split('~(?<=[.!?]\s)~u', $s)) ?: [$s];
        $res = []; $buf = '';
        foreach ($pieces as $p) {
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($p) > $w) { $res[] = $buf; $buf = ''; }
            $buf .= $p;
        }
        if ($buf !== '') $res[] = $buf;
        return $res;
    }
    private function iam(): ?string {
        $c = $this->st->cacheGet('yandex_iam');
        if ($c && $c['exp'] > time()) return $c['val'];
        $r = $this->http->api('POST', 'https://iam.api.cloud.yandex.net/iam/v1/tokens',
            (string)json_encode(['yandexPassportOauthToken' => $this->cfg['translate']['yandex']['oauth'] ?? '']),
            ['Content-Type: application/json']);
        $j = $r === false ? null : json_decode($r, true);
        if (empty($j['iamToken'])) return null;
        $this->st->cacheSet('yandex_iam', (string)$j['iamToken'], time() + 6 * 3600);
        return (string)$j['iamToken'];
    }
    private function call(string $q, array $t): ?string {
        $prov = (string)($t['provider'] ?? 'google');
        [$from, $to] = array_pad(explode('|', (string)($t['lang'] ?? 'en|ru')), 2, '');
        $html = ($t['format'] ?? 'html') === 'html';
        $K = $this->cfg['translate'] ?? [];
        switch ($prov) {
            case 'google':
                $r = $this->http->api('POST', 'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode($K['google']['key'] ?? ''),
                    (string)json_encode(['q' => $q, 'source' => $from, 'target' => $to, 'format' => $html ? 'html' : 'text'], JSON_UNESCAPED_UNICODE),
                    ['Content-Type: application/json']);
                $j = $r === false ? null : json_decode($r, true);
                return isset($j['data']['translations'][0]['translatedText']) ? html_entity_decode((string)$j['data']['translations'][0]['translatedText'], ENT_QUOTES, 'UTF-8') : null;
            case 'deepl':
                $r = $this->http->api('POST', 'https://api.deepl.com/v2/translate',
                    http_build_query(['text' => $q, 'source_lang' => strtoupper($from), 'target_lang' => strtoupper($to),
                                      'tag_handling' => $html ? 'html' : 'xml', 'split_sentences' => '0']),
                    ['Authorization: DeepL-Auth-Key ' . ($K['deepl']['key'] ?? ''), 'Content-Type: application/x-www-form-urlencoded']);
                $j = $r === false ? null : json_decode($r, true);
                return isset($j['translations'][0]['text']) ? (string)$j['translations'][0]['text'] : null;
            case 'yandex':
                $iam = $this->iam(); if (!$iam) return null;
                $r = $this->http->api('POST', 'https://translate.api.cloud.yandex.net/translate/v2/translate',
                    (string)json_encode(['texts' => [$q], 'sourceLanguageCode' => $from, 'targetLanguageCode' => $to,
                        'format' => $html ? 'HTML' : 'PLAIN_TEXT', 'folderId' => $K['yandex']['folderId'] ?? ''], JSON_UNESCAPED_UNICODE),
                    ['Authorization: Bearer ' . $iam, 'Content-Type: application/json']);
                $j = $r === false ? null : json_decode($r, true);
                return isset($j['translations'][0]['text']) ? (string)$j['translations'][0]['text'] : null;
            case 'lingvanex':
                $r = $this->http->api('POST', 'https://api-b2b.backenster.com/b1/api/v3/translate',
                    (string)json_encode(['from' => $from, 'to' => $to, 'data' => $q, 'platform' => 'api',
                        'translateMode' => $html ? 'html' : ''], JSON_UNESCAPED_UNICODE),
                    ['Authorization: ' . ($K['lingvanex']['key'] ?? ''), 'Content-Type: application/json', 'Accept: application/json']);
                $j = $r === false ? null : json_decode($r, true);
                return empty($j['err']) && isset($j['result']) ? (string)$j['result'] : null;
        }
        Log::w("Неизвестный провайдер перевода: {$prov}");
        return null;
    }
}

final class Synonymizer {
    public function __construct(private array $c, private Http $http) {}
    public function run(string $text, bool $plain = false): array {
        if (trim($text) === '') return ['ok' => false, 'text' => $text, 'percent' => 0];
        if (($this->c['mode'] ?? 'local') === 'textorobot') {
            $r = $this->http->api('POST', (string)($this->c['endpoint'] ?? ''),
                (string)json_encode(['apiKey' => $this->c['apiKey'] ?? '', 'text' => $text], JSON_UNESCAPED_UNICODE),
                ['Content-Type: application/json']);
            $j = $r === false ? null : json_decode($r, true);
            if (!empty($j['processedText']))
                return ['ok' => true, 'text' => (string)$j['processedText'], 'percent' => (int)($j['synonymPercentage'] ?? 0)];
            return ['ok' => false, 'text' => $text, 'percent' => 0];
        }
        $dict = json_decode((string)@file_get_contents((string)($this->c['dict'] ?? '')), true) ?: [];
        preg_match_all('~\p{L}+~u', $text, $all);
        $words = max(1, count($all[0])); $done = 0; $out = $text;
        foreach ($dict as $w => $syns) {
            $syns = (array)$syns; if (!$syns) continue;
            $out = preg_replace_callback('~(?<![\p{L}])(' . preg_quote((string)$w, '~') . ')(?![\p{L}])~iu',
                function ($m) use ($syns, &$done) {
                    $done++;
                    $pick = (string)$syns[array_rand($syns)];
                    $first = mb_substr($m[1], 0, 1);
                    return $first === mb_strtoupper($first) ? mb_convert_case($pick, MB_CASE_TITLE, 'UTF-8') : $pick;
                }, $out) ?? $out;
        }
        return ['ok' => $done > 0, 'text' => $out, 'percent' => (int)round($done / $words * 100)];
    }
}

final class Store {
    private PDO $db;
    public function __construct(string $file) {
        $this->db = new PDO('sqlite:' . $file);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE IF NOT EXISTS seen(h TEXT PRIMARY KEY, feed TEXT, url TEXT, ts INTEGER)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS feeds(name TEXT PRIMARY KEY, last_update INTEGER DEFAULT 0,
                         work_time INTEGER DEFAULT 0, last_count INTEGER DEFAULT 0, link_count INTEGER DEFAULT 0)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS state(name TEXT PRIMARY KEY, payload TEXT, ts INTEGER)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS cache(k TEXT PRIMARY KEY, val TEXT, exp INTEGER)');
    }
    public function has(string $h): bool {
        $s = $this->db->prepare('SELECT 1 FROM seen WHERE h=?'); $s->execute([$h]); return (bool)$s->fetchColumn();
    }
    public function add(string $h, string $feed, string $url): void {
        $this->db->prepare('INSERT OR IGNORE INTO seen VALUES(?,?,?,?)')->execute([$h, $feed, $url, time()]);
    }
    public function feedStats(string $n): array {
        $s = $this->db->prepare('SELECT * FROM feeds WHERE name=?'); $s->execute([$n]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: ['last_update' => 0, 'work_time' => 0, 'last_count' => 0, 'link_count' => 0];
    }
    public function updateFeed(string $n, array $f): void {
        $this->db->prepare('INSERT OR IGNORE INTO feeds(name) VALUES(?)')->execute([$n]);
        $set = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
        $this->db->prepare("UPDATE feeds SET {$set} WHERE name=?")->execute([...array_values($f), $n]);
    }
    public function loadState(string $n): ?array {
        $s = $this->db->prepare('SELECT payload FROM state WHERE name=?'); $s->execute([$n]);
        $p = $s->fetchColumn();
        return $p ? json_decode((string)$p, true) : null;
    }
    public function saveState(string $n, array $st): void {
        $this->db->prepare('INSERT OR REPLACE INTO state VALUES(?,?,?)')
                 ->execute([$n, (string)json_encode($st, JSON_UNESCAPED_UNICODE), time()]);
    }
    public function hasState(string $n): bool {
        $s = $this->db->prepare('SELECT 1 FROM state WHERE name=?'); $s->execute([$n]); return (bool)$s->fetchColumn();
    }
    public function clearState(string $n): void { $this->db->prepare('DELETE FROM state WHERE name=?')->execute([$n]); }
    public function cacheGet(string $k): ?array {
        $s = $this->db->prepare('SELECT val,exp FROM cache WHERE k=?'); $s->execute([$k]);
        $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }
    public function cacheSet(string $k, string $v, int $exp): void {
        $this->db->prepare('INSERT OR REPLACE INTO cache VALUES(?,?,?)')->execute([$k, $v, $exp]);
    }
}
