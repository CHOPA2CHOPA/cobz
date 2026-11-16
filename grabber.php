<?php
declare(strict_types=1);
/* MiniGrabber v2 — конвейер GrabberCore + транзакции + запуск по cron */
require __DIR__ . '/lib.php';

final class Grabber {
    private array $feed = [], $p = [], $state = [];
    public function __construct(private array $cfg, private Store $st, private Http $http,
                                private WP $wp, private bool $test = false) {}

    /* аналог execute() + транзакционная модель */
    public function execute(array $feed): bool {
        $this->feed = $feed; $this->p = $feed['params'] ?? [];
        $name = (string)$feed['name'];
        $this->state = $this->st->loadState($name)
            ?? ['i' => 0, 'links' => [], 'titles' => [], 'descs' => [], 'intros' => [],
                'started' => time(), 'count' => 0, 'link_count' => 0];
        if (!$this->state['links']) {
            Log::w("=== Лента: {$name} ===");
            $c = $this->collect();
            if (!$c['links']) { Log::w('Ссылок для импорта нет'); $this->finish(0); return true; }
            $this->state = array_merge($this->state, $c, ['link_count' => count($c['links'])]);
            Log::w('Ссылок для импорта: ' . count($c['links']));
            $this->st->saveState($name, $this->state);
        }
        $budget = (int)($this->cfg['phpTimeLimit'] ?? 25); $t0 = time();
        while (isset($this->state['links'][$this->state['i']])) {
            if ($budget > 0 && time() - $t0 >= $budget && !empty($this->cfg['useTransactionModel']) && !$this->test) {
                $this->st->saveState($name, $this->state);
                Log::w("Транзакция: пауза на #{$this->state['i']}, продолжим в следующем запуске");
                return false;
            }
            try { $this->link((string)$this->state['links'][$this->state['i']]); }
            catch (Throwable $e) { Log::w('Исключение: ' . $e->getMessage()); }
            $this->state['i']++;
            $this->st->saveState($name, $this->state);
        }
        $this->finish((int)$this->state['count']);
        return true;
    }

    /* аналог _import (ветки html/rss) + getLinks */
    private function collect(): array {
        $f = $this->feed; $p = $this->p; $type = (string)($f['type'] ?? 'html');
        $out = ['links' => [], 'titles' => [], 'descs' => [], 'intros' => []];
        $raw = $this->http->get((string)$f['url']);
        if ($raw === false) return $out;
        $raw = Txt::replace('index', Txt::utf($raw, (string)($f[$type === 'rss' ? 'rss_encoding' : 'html_encoding'] ?? '')), $p);
        $items = [];
        if ($type === 'rss') {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw);
            if ($xml === false) { Log::w('RSS не разобран'); return $out; }
            if (isset($xml->channel->item)) {
                foreach ($xml->channel->item as $it)
                    $items[(string)$it->link] = ['t' => (string)$it->title, 'd' => (string)($it->description ?? '')];
            } elseif (isset($xml->entry)) {
                foreach ($xml->entry as $e) {
                    $l = '';
                    foreach ($e->link as $ln) if ((string)$ln['rel'] !== 'self') { $l = (string)$ln['href']; break; }
                    if ($l !== '') $items[$l] = ['t' => (string)$e->title, 'd' => (string)($e->content ?? $e->summary ?? '')];
                }
            }
        } else {
            $base = Txt::baseHref($raw) ?? (string)$f['url'];
            if (!empty($p['autoIntroOn']) && !empty($p['introLinkTempl'])) {
                $flip = !empty($p['orderLinkIntro']);
                if (preg_match_all((string)$p['introLinkTempl'], $raw, $m, PREG_SET_ORDER))
                    foreach ($m as $row)
                        $items[Txt::abs($row[$flip ? 2 : 1], $base)] = ['i' => $row[$flip ? 1 : 2]];
            } elseif (!empty($f['links']) && preg_match_all('~' . $f['links'] . '~is', $raw, $m)) {
                foreach ($m[1] as $u) { $u = Txt::abs($u, $base); if ($u !== '') $items[$u] = []; }
            }
        }
        $urls = array_keys($items);
        if (!$this->test) $urls = array_values(array_filter($urls, fn($u) => !$this->st->has(md5('url|' . $u))));
        if (empty($p['start_top'])) $urls = array_reverse($urls);
        if (($s = (int)($p['start_link'] ?? 0)) > 0) $urls = array_slice($urls, $s);
        if (($mx = (int)($p['max_items'] ?? 5)) > 0) $urls = array_slice($urls, 0, $mx);
        foreach ($urls as $u) {
            $out['links'][] = $u;
            $out['titles'][$u] = (string)($items[$u]['t'] ?? '');
            $out['descs'][$u]  = (string)($items[$u]['d'] ?? '');
            $out['intros'][$u] = (string)($items[$u]['i'] ?? '');
        }
        return $out;
    }

    /* аналог _saveLink */
    private function link(string $url): void {
        $f = $this->feed; $p = $this->p;
        if (!$this->test && $this->st->has(md5('url|' . $url))) return;
        $base = $url;
        if (($f['type'] ?? 'html') === 'rss' && !empty($p['rss_textmod'])) {
            $page = (string)($this->state['descs'][$url] ?? '');
            $title = (string)($this->state['titles'][$url] ?? '');
            $text = $page;
        } else {
            $raw = $this->http->get($url);
            if ($raw === false) { if (!empty($p['skip_error_urls'])) $this->mark($url); return; }
            $page = Txt::replace('page', Txt::utf($raw, (string)($f['html_encoding'] ?? '')), $p);
            $base = Txt::baseHref($page) ?: ($this->http->currentUrl ?: $url);
            $title = (string)($this->state['titles'][$url] ?? '');
            if ($title === '' && !empty($f['title'])) $title = (string)Txt::match((string)$f['title'], $page);
            $cut = Txt::cut($page, (string)($f['text_start'] ?? ''), (string)($f['text_end'] ?? ''));
            if ($cut === null) { Log::w("Текст не найден: {$url}"); if (!empty($p['skip_error_urls'])) $this->mark($url); return; }
            $text = $cut;
        }
        if (trim($page) === '') { Log::w("Пустая страница: {$url}"); if (!empty($p['skip_error_urls'])) $this->mark($url); return; }
        $this->save($url, trim(html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8')), $text, $page, $base);
    }

    /* аналог save() — порядок операций как в ядре */
    private function save(string $url, string $title, string $text, string $page, string $base): void {
        $p = $this->p; $name = (string)$this->feed['name'];
        Log::w("→ {$url}");
        if (!empty($p['autoIntroOn']) && !empty($this->state['intros'][$url]))
            $text = Txt::replace('intro', (string)$this->state['intros'][$url], $p) . '{{{MORE}}}' . $text;
        if (!Txt::filterPass($title, $text, $p)) { Log::w('Отсев фильтр-словами'); $this->mark($url); return; }
        $hTitle = md5('title|' . mb_strtolower($title));
        if (!$this->test && !empty($p['titleUniqueOn']) && $this->st->has($hTitle)) { Log::w('Дубль заголовка'); $this->mark($url); return; }

        $text  = Txt::stripScripts($text, $p);
        $text  = Txt::replace('text', $text, $p);
        $title = Txt::replace('title', $title, $p);
        $case = (int)($p['case_title'] ?? 0);
        if ($case === 1) $title = mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');
        if ($case === 2) $title = mb_convert_case($title, MB_CASE_UPPER, 'UTF-8');
        $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8'));
        if ($title === '') { Log::w('Пустой заголовок'); $this->mark($url); return; }
        if (!empty($p['strip_tags'])) $text = Txt::clean($text, (string)($p['allowed_tags'] ?? ''));
        if (!empty($p['no_save_without_pic']) && !preg_match('~<img~i', $text)) { Log::w('Нет картинок — пропуск'); $this->mark($url); return; }
        if (mb_strlen(trim(strip_tags($text))) < (int)($p['min_text_size'] ?? 0)) { Log::w('Короткий текст — пропуск'); $this->mark($url); return; }

        $img = new Images($p, $this->cfg, $this->http, $this->wp, $title, $this->test);
        $text = $img->process($text, $base);
        if (!empty($p['fulltext_size_on']))
            $text = Txt::limit($text, (int)($p['post_full_size'] ?? 370), (string)($p['postFulltextSymbolEnd'] ?? '.'));

        foreach ([1, 2] as $pass) {
            $key = $pass === 1 ? 'translate' : 'translate2';
            if (empty($p[$key]['on'])) continue;
            $tr = new Translator($this->cfg, $this->http, $this->st);
            $nt = $tr->run($text, $p[$key]);
            $nh = $tr->run($title, $p[$key] + ['format' => 'text']);
            if (($nt === null || $nh === null || md5($nt) === md5($text)) && !empty($p['nosave_if_not_translate'])) {
                Log::w("Перевод #{$pass} не выполнен — пропуск"); $img->rollback(); $this->mark($url); return;
            }
            $text = $nt ?? $text; $title = $nh ?? $title;
        }
        if (!empty($p['synonymizeEnabled']) && !empty($this->cfg['synonymize']['on'])) {
            $sy = new Synonymizer($this->cfg['synonymize'], $this->http);
            $r = $sy->run($text);
            if ($r['ok'] && $r['percent'] >= (int)($p['minSynonymPercentage'] ?? 0)) {
                $text = $r['text']; Log::w("Синонимизация: {$r['percent']}%");
                $rt = $sy->run($title, true); if ($rt['ok']) $title = $rt['text'];
            } elseif (!empty($p['ignoreRecordOnSynonymizeError'])) {
                Log::w('Синонимизация ниже порога — пропуск'); $img->rollback(); $this->mark($url); return;
            } else Log::w('Синонимизация не применена — исходный текст');
        }
        if (trim(strip_tags($text)) === '') { Log::w('Пустой контент — пропуск'); $img->rollback(); $this->mark($url); return; }
        if ($this->test) { Log::w('TEST: ' . $title . ' (' . mb_strlen(strip_tags($text)) . ' симв., картинок: ' . substr_count($text, '<img') . ')'); return; }

        $date = !empty($p['post_date_on']) ? Txt::date(Txt::match((string)($p['post_date_scrape'] ?? ''), $page)) : null;
        if (!$date && ($p['post_date_type'] ?? 'runtime') === 'runtime') $date = date('Y-m-d H:i:s');
        [$intro, $content] = Txt::more($text);
        $content = strtr((string)($p['template_full_text'] ?? '%FULL_TEXT%'),
            ['%FULL_TEXT%' => $content, '%INTRO_TEXT%' => $intro, '%TITLE%' => $title, '%SOURCE%' => $url]);

        $fields = [
            'title' => $title, 'content' => $content, 'excerpt' => $intro,
            'status' => (string)($p['post_status'] ?? 'draft'),
            'categories' => array_map('intval', (array)($p['catid'] ?? [])),
            'date' => $date,
        ];
        if (!empty($p['postSlugOn'])) $fields['slug'] = mb_substr(Txt::slug($title), 0, (int)($p['aliasSize'] ?? 80));
        $tags = Txt::tags($page, $p);
        if ($tags) $fields['tags'] = $this->wp->termIds($tags);
        if ($img->featuredId) $fields['featured_media'] = $img->featuredId;
        if (!empty($p['meta_on'])) $fields['meta'] = [
            (string)($p['meta_desc_key'] ?? '_yoast_wpseo_metadesc') => Txt::description($text, (int)($p['metaDescSize'] ?? 300)),
            (string)($p['meta_keys_key'] ?? '_yoast_wpseo_focuskw')  => Txt::keywords($text, (int)($p['metaKeysSize'] ?? 8)),
        ];
        $post = $this->wp->post(array_filter($fields, fn($v) => $v !== null && $v !== '' && $v !== []));
        if (!$post) { Log::w('Публикация не удалась (повторим в следующем запуске)'); $img->rollback(); return; }
        $this->st->add(md5('url|' . $url), $name, $url);
        $this->st->add($hTitle, $name, $url);
        $this->state['count']++;
        Log::w("OK #{$post['id']}: {$title}");
    }

    private function mark(string $url): void { $this->st->add(md5('url|' . $url), (string)$this->feed['name'], $url); }
    private function finish(int $count): void {
        $t = time() - (int)$this->state['started'];
        $this->st->updateFeed((string)$this->feed['name'],
            ['last_update' => time(), 'work_time' => $t, 'last_count' => $count, 'link_count' => (int)$this->state['link_count']]);
        $this->st->clearState((string)$this->feed['name']);
        Log::w("Лента '{$this->feed['name']}': опубликовано {$count}, {$t} c");
    }
}

/* ================= RUNNER ================= */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$o = getopt('', ['config::', 'feed::', 'test', 'all', 'list', 'force']);
$cfgFile = $o['config'] ?? __DIR__ . '/config.json';
$cfg = json_decode((string)@file_get_contents($cfgFile), true);
if (!is_array($cfg)) exit("Нет/битый конфиг: {$cfgFile}\n");
foreach (['tmpDir', 'imgPath'] as $d) if (!is_dir($cfg[$d])) @mkdir($cfg[$d], 0755, true);
Log::$file = (string)$cfg['logFile']; Log::$dir = (string)$cfg['tmpDir']; Log::$debug = (bool)($cfg['debug'] ?? false);

$lock = fopen($cfg['tmpDir'] . '/grabber.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Уже запущен\n");

$st = new Store((string)$cfg['dbFile']);
$http = new Http($cfg['http'], $cfg['tmpDir'] . '/cookies.txt');
$wp = new WP($cfg['wordpress'], $http);

if (isset($o['list'])) {
    printf("%-28s %-4s %-8s %-19s %6s %6s %6s\n", 'ЛЕНТА', 'ВКЛ', 'ТИП', 'ОБНОВЛЕНИЕ', 'ССЫЛ', 'ПОСТ', 'СЕК');
    foreach ($cfg['feeds'] as $f) {
        $s = $st->feedStats((string)$f['name']);
        printf("%-28s %-4s %-8s %-19s %6d %6d %6d\n", mb_substr((string)$f['name'], 0, 28),
            !empty($f['published']) ? 'да' : 'нет', $f['type'] ?? 'html',
            $s['last_update'] ? date('d.m.Y H:i:s', (int)$s['last_update']) : '—',
            $s['link_count'], $s['last_count'], $s['work_time']);
    }
    exit;
}

$g = new Grabber($cfg, $st, $http, $wp, isset($o['test']));
$limit = (int)($cfg['countUpdateFeeds'] ?? 2); $done = 0;
foreach ($cfg['feeds'] as $feed) {
    $name = (string)$feed['name'];
    if (isset($o['feed']) && $name !== $o['feed']) continue;
    $forced = isset($o['force']) || isset($o['feed']) || isset($o['test']);
    if (empty($feed['published']) && !$forced) continue;
    $s = $st->feedStats($name);
    $due = (int)$s['last_update'] + (int)($feed['interval'] ?? 1800) <= time();
    if (!$due && !$forced && !$st->hasState($name)) continue;
    do { $finished = $g->execute($feed); } while (!$finished && isset($o['all']));
    if (++$done >= $limit && !isset($o['feed'])) break;
}
flock($lock, LOCK_UN); fclose($lock);
