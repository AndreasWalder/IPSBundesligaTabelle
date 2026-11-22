<?php
class BundesligaTabelle extends IPSModule
{
    private const API_BASE = 'https://api.openligadb.de';
    private const BAYERN_RSS = 'https://fcbayern.com/de/api/rss/contentliste/news';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('LigaSelection', 'Liga1');
        $this->RegisterPropertyString('TextFarbcode', 'FFFFFF');
        $this->RegisterPropertyString('TextSize', '12');
        $this->RegisterPropertyBoolean('FarbenAktiv', true);
        $this->RegisterPropertyInteger('Intervall', 1800);
        $this->RegisterTimer('UpdateTabelle', 0, 'BLT_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $html = $this->RegisterVariableString('HTMLBoxTabelle', 'Bundesliga – Tabelle und Infos', '~HTMLBox');
        IPS_SetIcon($html, 'Soccer');
        $this->RegisterVariableString('StandingsJson', 'Tabellendaten (JSON)');
        $this->RegisterVariableString('BayernNewsJson', 'FC Bayern News (JSON)');
        $this->RegisterVariableString('LastUpdate', 'Letzte Aktualisierung');
        $this->RegisterVariableString('LastError', 'Datenstatus');
        foreach (['StandingsJson', 'BayernNewsJson', 'LastUpdate', 'LastError'] as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false) IPS_SetHidden($id, true);
        }
        $this->SetTimerInterval('UpdateTabelle', max(300, $this->ReadPropertyInteger('Intervall')));
        $this->Update();
    }

    public function Update()
    {
        try {
            $league = $this->leagueConfig();
            $table = $this->requestJson(self::API_BASE . '/getbltable/' . $league['shortcut'] . '/' . $this->seasonStartYear());
            if (!is_array($table) || count($table) < 2) {
                throw new RuntimeException('Der Tabellenanbieter hat keine verwertbare Tabelle geliefert.');
            }
            $rows = [];
            foreach ($table as $entry) {
                if (!is_array($entry) || empty($entry['teamName'])) continue;
                $rows[] = [
                    'rank' => count($rows) + 1,
                    'team' => (string)$entry['teamName'],
                    'shortName' => (string)($entry['shortName'] ?? $entry['teamName']),
                    'logo' => (string)($entry['teamIconUrl'] ?? ''),
                    'played' => (int)($entry['matches'] ?? 0),
                    'won' => (int)($entry['won'] ?? 0),
                    'draw' => (int)($entry['draw'] ?? 0),
                    'lost' => (int)($entry['lost'] ?? 0),
                    'goalsFor' => (int)($entry['goals'] ?? 0),
                    'goalsAgainst' => (int)($entry['opponentGoals'] ?? 0),
                    'goalDiff' => (int)($entry['goalDiff'] ?? 0),
                    'points' => (int)($entry['points'] ?? 0)
                ];
            }
            if (!$rows) throw new RuntimeException('Die Antwort enthielt keine Vereine.');
            $news = $league['shortcut'] === 'bl1' ? $this->fetchBayernNews() : [];
            $payload = ['league' => $league['name'], 'season' => $this->seasonLabel(), 'updatedAt' => date(DATE_ATOM), 'source' => 'OpenLigaDB', 'teams' => $rows];
            $this->SetValue('StandingsJson', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $this->SetValue('BayernNewsJson', json_encode($news, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $this->SetValue('LastUpdate', date('d.m.Y H:i:s'));
            $this->SetValue('LastError', '');
            $this->SetValue('HTMLBoxTabelle', $this->render($payload, $news));
        } catch (Throwable $e) {
            $this->SetValue('LastError', $e->getMessage());
            $this->SetValue('HTMLBoxTabelle', $this->errorPage($e->getMessage()));
            IPS_LogMessage('BundesligaTabelle', 'Aktualisierung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function leagueConfig()
    {
        switch ($this->ReadPropertyString('LigaSelection')) {
            case 'Liga2': return ['shortcut' => 'bl2', 'name' => '2. Bundesliga'];
            case 'Liga3': return ['shortcut' => 'bl3', 'name' => '3. Liga'];
            default: return ['shortcut' => 'bl1', 'name' => 'Bundesliga'];
        }
    }

    private function seasonStartYear()
    {
        $date = new DateTimeImmutable('now');
        return (int)$date->format('n') >= 7 ? (int)$date->format('Y') : (int)$date->format('Y') - 1;
    }

    private function seasonLabel()
    {
        $year = $this->seasonStartYear();
        return $year . '/' . substr((string)($year + 1), -2);
    }

    private function requestJson($url)
    {
        $context = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: Symcon-BundesligaTabelle/2.0\r\nAccept: application/json\r\n"], 'https' => ['timeout' => 12, 'header' => "User-Agent: Symcon-BundesligaTabelle/2.0\r\nAccept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || trim($body) === '') throw new RuntimeException('OpenLigaDB ist nicht erreichbar. Netzwerk/DNS prüfen.');
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('OpenLigaDB lieferte ungültige JSON-Daten.');
        return $data;
    }

    private function fetchBayernNews()
    {
        $context = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: Symcon-BundesligaTabelle/2.0\r\n"], 'https' => ['timeout' => 8, 'header' => "User-Agent: Symcon-BundesligaTabelle/2.0\r\n"]]);
        $xml = @file_get_contents(self::BAYERN_RSS, false, $context);
        if ($xml === false) return [];
        $feed = @simplexml_load_string($xml);
        if (!$feed || empty($feed->channel->item)) return [];
        $items = [];
        foreach ($feed->channel->item as $item) {
            $title = trim((string)$item->title);
            if ($title === '') continue;
            $items[] = ['title' => $title, 'url' => trim((string)$item->link), 'date' => trim((string)$item->pubDate)];
            if (count($items) >= 4) break;
        }
        return $items;
    }

    private function render($payload, $news)
    {
        $escape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $teams = $payload['teams'];
        $bayern = null;
        foreach ($teams as $team) if (stripos($team['team'], 'Bayern') !== false) { $bayern = $team; break; }
        $updated = date('d.m.Y H:i', strtotime($payload['updatedAt']));
        $font = max(10, min(24, (int)$this->ReadPropertyString('TextSize')));
        $html = '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
        *{box-sizing:border-box}body{margin:0;padding:clamp(10px,2vw,24px);font:'.$font.'px/1.4 Arial,sans-serif;color:#f8f8fb;background:linear-gradient(145deg,#071b3b,#0b1120 48%,#17131a)}
        .wrap{max-width:1400px;margin:auto}.hero{padding:20px 22px;margin-bottom:14px;border-radius:16px;background:linear-gradient(110deg,#c8102e,#8e1029 55%,#163b70);box-shadow:0 8px 28px #0006}
        .eyebrow{font-size:.8em;letter-spacing:.13em;text-transform:uppercase;opacity:.85}.hero h1{margin:4px 0;font-size:clamp(22px,4vw,34px)}.sub{opacity:.85}
        .table-card,.info-card{background:#101a2beF;border:1px solid #ffffff1c;border-radius:14px;overflow:hidden;box-shadow:0 5px 18px #0004}
        .section-title{padding:14px 16px;margin:0;background:#ffffff08;font-size:1.15em}.scroll{overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:700px}th,td{padding:9px 8px;text-align:center;border-bottom:1px solid #ffffff12;white-space:nowrap}
        th{font-size:.85em;color:#b9c8de;background:#0d1523}td.club{text-align:left;font-weight:600}.rank{font-weight:700;width:52px}
        tr.bayern{background:linear-gradient(90deg,#bd1734aa,#8e102933);box-shadow:inset 4px 0 #e31b3b}
        tr:hover{background-color:#ffffff0c}.logo{width:24px;height:24px;object-fit:contain;vertical-align:middle;margin-right:8px}
        .points{font-size:1.12em;font-weight:800;color:#fff}.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin-top:14px}
        .info-card{padding:16px}.info-card h2{margin:0 0 10px;font-size:1em;color:#f3b8c4}.stat{font-size:1.35em;font-weight:700}.muted{color:#aebbd0;font-size:.85em}.news{padding:8px 0;border-top:1px solid #ffffff15}
        a{color:#ffd1d9;text-decoration:none}a:hover{text-decoration:underline}.foot{padding:12px 2px;color:#9daac0;font-size:.78em}
        @media(max-width:600px){body{padding:8px}.hero{padding:16px}.section-title{padding:12px}}
        </style><div class="wrap"><header class="hero"><div class="eyebrow">Bundesliga · '.$escape($payload['season']).'</div><h1>Die aktuelle Tabelle</h1><div class="sub">FC Bayern Look · Stand '.$escape($updated).' Uhr</div></header>
        <section class="table-card"><h2 class="section-title">'.$escape($payload['league']).' – alle Teams</h2><div class="scroll"><table><thead><tr><th>Platz</th><th colspan="2">Verein</th><th>Sp.</th><th>S</th><th>U</th><th>N</th><th>Tore</th><th>Diff.</th><th>Pkt.</th></tr></thead><tbody>';
        foreach ($teams as $team) {
            $isBayern = stripos($team['team'], 'Bayern') !== false;
            $logo = filter_var($team['logo'], FILTER_VALIDATE_URL) ? '<img class="logo" loading="lazy" src="'.$escape($team['logo']).'" alt="">' : '';
            $html .= '<tr'.($isBayern ? ' class="bayern"' : '').'><td class="rank">'.$escape($team['rank']).'</td><td>'.$logo.'</td><td class="club">'.$escape($team['team']).($isBayern ? ' ★' : '').'</td><td>'.$escape($team['played']).'</td><td>'.$escape($team['won']).'</td><td>'.$escape($team['draw']).'</td><td>'.$escape($team['lost']).'</td><td>'.$escape($team['goalsFor']).':'.$escape($team['goalsAgainst']).'</td><td>'.$escape(($team['goalDiff'] > 0 ? '+' : '').$team['goalDiff']).'</td><td class="points">'.$escape($team['points']).'</td></tr>';
        }
        $html .= '</tbody></table></div></section><section class="info-grid"><article class="info-card"><h2>Bundesliga im Überblick</h2><div class="stat">'.$escape(count($teams)).' Mannschaften</div><div class="muted">Tabelle und Spielwerte der Saison '.$escape($payload['season']).'.</div></article>';
        if ($bayern) $html .= '<article class="info-card"><h2>FC Bayern München</h2><div class="stat">Platz '.$escape($bayern['rank']).' · '.$escape($bayern['points']).' Punkte</div><div>'.$escape($bayern['played']).' Spiele · '.$escape($bayern['won']).' Siege, '.$escape($bayern['draw']).' Remis, '.$escape($bayern['lost']).' Niederlagen</div><div class="muted">Torverhältnis '.$escape($bayern['goalsFor']).':'.$escape($bayern['goalsAgainst']).' ('.$escape(($bayern['goalDiff'] > 0 ? '+' : '').$bayern['goalDiff']).')</div></article>';
        $html .= '<article class="info-card"><h2>Neuigkeiten vom FC Bayern</h2>';
        if ($news) foreach ($news as $item) $html .= '<div class="news"><a href="'.$escape($item['url']).'" target="_blank" rel="noopener">'.$escape($item['title']).'</a><div class="muted">'.$escape($item['date']).'</div></div>';
        else $html .= '<div class="muted">Der Nachrichtenfeed ist gerade nicht erreichbar.</div>';
        $html .= '</article></section><div class="foot">Tabelle: OpenLigaDB · Vereinsmeldungen: FC Bayern München · Aktualisierung automatisch</div></div></html>';
        return $html;
    }

    private function errorPage($message)
    {
        return '<!doctype html><html lang="de"><meta charset="utf-8"><style>body{font:16px Arial;background:#101a2b;color:#fff;padding:24px}h1{color:#e31b3b}</style><h1>Bundesliga-Tabelle</h1><p>Aktualisierung fehlgeschlagen.</p><p>'.htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p><p>Die letzte erfolgreiche Tabelle bleibt in den JSON-Daten erhalten.</p></html>';
    }
}
?>