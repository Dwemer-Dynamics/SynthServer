<?php
declare(strict_types=1);

// Management archive only: these reads do not change diary recall or save ownership.
function synth_diary_browser_filters(array $query): array
{
    $values = ['author'=>'', 'role'=>'', 'save'=>'', 'search'=>'', 'page'=>'1'];
    foreach ($values as $key=>$default) {
        $value=$query[$key] ?? $default;
        if (!is_string($value) || !preg_match('//u',$value)) throw new InvalidArgumentException('Invalid diary filter.');
        $values[$key]=$value;
    }
    if ($values['author']!=='' && !preg_match('/^(npc|player|narrator):[1-9][0-9]{0,17}$/D',$values['author']))
        throw new InvalidArgumentException('Invalid diary author.');
    if (!in_array($values['role'],['','npc','player','narrator'],true)
        || ($values['save']!=='' && !preg_match('/^[a-f0-9]{12}$/D',$values['save']))
        || strlen($values['search'])>512 || !preg_match('/^[1-9][0-9]{0,4}$/D',$values['page']))
        throw new InvalidArgumentException('Invalid diary filter.');
    $values['page']=(int)$values['page'];
    return $values;
}

function synth_diary_browser_cte(): string
{
    return \Synth\Core\DbManager\Dao\DiaryResultDao::BROWSER_CTE;
}

// A statement failure keeps the message the archive page always showed.
function synth_diary_browser_read(callable $read): mixed
{
    try { return $read(); }
    catch (\Doctrine\DBAL\Exception $error) { throw new RuntimeException('Diaries could not be loaded.',0,$error); }
}

function synth_diary_browser_summary($db): array
{
    $latest=synth_diary_browser_read(static fn()=>\Synth\Core\DbManager\Dao\DiaryResultDao::current($db)->browserLatest());
    return ['total'=>(int)($latest['total'] ?? 0),'latest'=>$latest];
}

function synth_diary_browser_load($db, array $query): array
{
    $filters=synth_diary_browser_filters($query);
    $diaries=\Synth\Core\DbManager\Dao\DiaryResultDao::current($db);
    $total=synth_diary_browser_read(static fn()=>$diaries->browserCount($filters));
    $page=min($filters['page'],max(1,(int)ceil($total/20)));
    $filters['page']=$page;
    $entries=synth_diary_browser_read(static fn()=>$diaries->browserEntries($filters,20,($page-1)*20));
    $authors=synth_diary_browser_read(static fn()=>$diaries->browserAuthors());
    $saves=synth_diary_browser_read(static fn()=>$diaries->browserSaves());
    return compact('filters','entries','authors','saves','total')+['pages'=>max(1,(int)ceil($total/20))];
}

function synth_diary_browser_h($value): string
{
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function synth_diary_browser_time(string $value): string
{
    $time=strtotime($value);
    return $time===false ? 'Date unavailable' : gmdate('d M Y, H:i',$time).' UTC';
}

function synth_diary_browser_save_label(array $row): string
{
    return ($row['player_name'] ?? 'Sole Survivor').' · '.synth_diary_browser_time($row['written_at'] ?? '')
        .' · '.substr($row['save_key'] ?? '',0,4);
}
