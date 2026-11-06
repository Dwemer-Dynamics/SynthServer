<?php
$h='synth_diary_browser_h';
$base=rtrim((string)($webRoot ?? ''),'/').'/ui/diaries.php';
$link=static function(array $values) use($base,$isEmbed): string {
    if (!empty($isEmbed)) $values['embed']='1';
    return $base.'?'.http_build_query(array_filter($values,static fn($v)=>$v!==''), '', '&', PHP_QUERY_RFC3986);
};
$roleNames=['npc'=>'NPC','player'=>'Player','narrator'=>'Narrator'];
?>
<main class="diary-browser <?php echo !empty($isEmbed) ? 'diary-browser-embedded' : ''; ?>">
    <header class="diary-heading">
        <div><p class="diary-eyebrow">SYNTH · JOURNALS</p><h1>Diaries</h1>
        <p>Read what your characters have written, by author and playthrough.</p></div>
        <a class="diary-button" href="<?php echo $h($link($view['filters'] ?? [])); ?>">Refresh diaries</a>
    </header>
    <?php if ($error!==''): ?>
        <p class="diary-empty" role="alert"><?php echo $h($error); ?></p>
    <?php else: $filters=$view['filters']; ?>
    <form class="diary-filters" method="get" action="<?php echo $h($base); ?>">
        <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>Author<select name="author"><option value="">All authors</option>
            <?php foreach ($view['authors'] as $author): ?>
            <option value="<?php echo $h($author['author_key']); ?>" <?php echo $filters['author']===$author['author_key'] ? 'selected' : ''; ?>><?php echo $h($author['author'].' · '.$roleNames[$author['role']]); ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Playthrough<select name="save"><option value="">All playthroughs</option>
            <?php foreach ($view['saves'] as $save): ?>
            <option value="<?php echo $h($save['save_key']); ?>" <?php echo $filters['save']===$save['save_key'] ? 'selected' : ''; ?>><?php echo $h(synth_diary_browser_save_label($save)); ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Search entries<input type="search" name="search" maxlength="512" placeholder="Title or diary text" value="<?php echo $h($filters['search']); ?>"></label>
        <button class="diary-button" type="submit">Find entries</button>
        <a class="diary-reset" href="<?php echo $h($link([])); ?>">Clear</a>
    </form>
    <div class="diary-list-heading"><span><?php echo $view['total']; ?> <?php echo $view['total']===1 ? 'entry' : 'entries'; ?></span><span>Newest first</span></div>
    <?php if (!$view['entries']): ?>
        <section class="diary-empty"><h2><?php echo $filters['author']!=='' || $filters['save']!=='' || $filters['search']!=='' ? 'No matching entries' : 'No diaries written yet'; ?></h2>
        <p><?php echo $view['total']===0 && !$view['authors'] ? 'In game, point at an NPC, open SYNTH Control and choose Write Diary. Once generation finishes, refresh this page.' : 'Try another author, playthrough or search.'; ?></p></section>
    <?php endif; ?>
    <div class="diary-entries">
    <?php foreach ($view['entries'] as $index=>$entry): ?>
        <details class="diary-card" <?php echo $index===0 ? 'open' : ''; ?>>
            <summary><span class="diary-card-title"><?php echo $h($entry['topic']); ?></span>
                <span class="diary-card-meta"><span><?php echo $h($entry['author']); ?></span><span class="diary-role"><?php echo $h($roleNames[$entry['role']]); ?></span><time><?php echo $h(synth_diary_browser_time($entry['written_at'])); ?></time></span>
            </summary>
            <div class="diary-card-body">
                <?php if ($entry['location']!==''): ?><p class="diary-location"><?php echo $h($entry['location']); ?></p><?php endif; ?>
                <div class="diary-prose"><?php echo $h($entry['content']); ?></div>
                <p class="diary-save">Playthrough <?php echo $h(substr($entry['save_key'],0,4)); ?> · Original written entry</p>
            </div>
        </details>
    <?php endforeach; ?>
    </div>
    <?php if ($view['pages']>1): ?>
    <nav class="diary-pagination" aria-label="Diary pages">
        <?php if ($filters['page']>1): ?><a class="diary-button" href="<?php echo $h($link(array_replace($filters,['page'=>$filters['page']-1]))); ?>">Previous</a><?php endif; ?>
        <span>Page <?php echo $filters['page']; ?> of <?php echo $view['pages']; ?></span>
        <?php if ($filters['page']<$view['pages']): ?><a class="diary-button" href="<?php echo $h($link(array_replace($filters,['page'=>$filters['page']+1]))); ?>">Next</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
    <footer class="diary-help"><p>Use <strong>Write Diary</strong> in SYNTH Control to request the selected NPC’s next entry.</p>
        <a href="<?php echo $h(rtrim((string)($webRoot ?? ''),'/').'/ui/diarylog.php'.(!empty($isEmbed) ? '?embed=1' : '')); ?>">Older diary log</a>
    </footer>
</main>
