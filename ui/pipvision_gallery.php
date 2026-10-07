<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
synthRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
]);
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'visual_context.php');

$isEmbed = strval($_GET['embed'] ?? '') === '1';
$message = '';
$error = '';
header('Cache-Control: no-store');
if (session_status()===PHP_SESSION_NONE && !session_start()) {
    http_response_code(503);exit('Gallery session unavailable.');
}
$csrfToken=$_SESSION['synth_gallery_csrf'] ?? null;
if (!is_string($csrfToken) || !preg_match('/^[a-f0-9]{64}$/D',$csrfToken)) {
    $_SESSION['synth_gallery_csrf']=$csrfToken=bin2hex(random_bytes(32));
}
session_write_close();

function pipVisionGalleryH($value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $action = strval($_POST['action'] ?? '');
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($csrfToken,$_POST['csrf_token'])) {
            http_response_code(403);throw new LogicException('Reload the gallery before confirming this change.');
        }
        if ($action === 'save') {
            $record=$GLOBALS['db']->fetchOne('SELECT metadata FROM synth.visual_context WHERE id='.$id);
            $metadata=json_decode(strval($record['metadata'] ?? '{}'),true);
            $values=[
                'locked' => !empty($_POST['locked']),
                'active' => !empty($_POST['active']),
            ];
            if (($metadata['schema'] ?? '')!=='synth.visual_context.capture.v2') $values['description']=$_POST['description'] ?? '';
            if (!synthVisualContextUpdate($id,$values)) {
                throw new RuntimeException('PipVision record update failed');
            }
            $message = 'PipVision context updated.';
        } elseif ($action === 'delete') {
            $record = $GLOBALS['db']->fetchOne('SELECT image_path FROM synth.visual_context WHERE id=' . $id);
            if (!synthVisualContextDelete($id)) {
                throw new RuntimeException('PipVision record deletion failed');
            }
            $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, strval($record['image_path'] ?? ''));
            $galleryRoot = realpath($enginePath . 'data' . DIRECTORY_SEPARATOR . 'pictures' . DIRECTORY_SEPARATOR . 'gallery');
            $candidate = realpath($enginePath . $relative);
            if ($galleryRoot && $candidate && str_starts_with($candidate, $galleryRoot . DIRECTORY_SEPARATOR)) {
                @unlink($candidate);
            }
            $message = 'PipVision capture deleted.';
        }
    } catch (Throwable $e) {
        $error = $e instanceof LogicException ? $e->getMessage() : 'Gallery change failed. Refresh before trying again.';
    }
}

$records = synthVisualContextList(500);
$scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
$uiPosition = strpos($scriptPath, '/ui/');
$webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
$webRoot = rtrim($webRoot, '/');

$TITLE = 'PipVision Gallery';
$BODY_CLASS = 'hub-page';
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?>
<style>
body{background:#1d1d1d;color:#f5f5f5}.pv-gallery{padding:18px;max-width:1400px;margin:0 auto}.pv-gallery h1{font-size:1.7rem;color:var(--synth-accent);margin:0 0 4px}.pv-sub{color:#aaa;margin-bottom:16px}.pv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:12px}.pv-card{background:#292929;border:1px solid #474747;border-radius:6px;overflow:hidden}.pv-card.locked{border-color:var(--synth-accent)}.pv-image{display:block;width:100%;height:210px;object-fit:cover;background:#111}.pv-body{padding:12px}.pv-title{display:flex;justify-content:space-between;gap:8px;margin-bottom:5px}.pv-meta{font-size:.82rem;color:#aaa;margin-bottom:9px;overflow-wrap:anywhere}.pv-body textarea{width:100%;min-height:105px;box-sizing:border-box;background:#181818;color:#fff;border:1px solid #555;border-radius:4px;padding:8px;resize:vertical}.pv-options,.pv-actions{display:flex;flex-wrap:wrap;align-items:center;gap:9px;margin-top:9px}.pv-button{border:1px solid #666;background:#353535;color:#fff;border-radius:4px;padding:7px 10px;cursor:pointer}.pv-button.primary{background:var(--synth-accent);border-color:var(--synth-accent);color:#151515}.pv-message{padding:10px;border-radius:4px;margin-bottom:12px;background:#213a29;border:1px solid #3e8050}.pv-error{background:#4a2222;border-color:#9b4949}.pv-empty{padding:30px;text-align:center;background:#292929;border:1px solid #474747;border-radius:6px}
</style>
<style>
.pv-grid{grid-template-columns:repeat(auto-fill,minmax(min(330px,100%),1fr))}
.pv-title{overflow-wrap:anywhere}.pv-body a{color:var(--synth-accent)}.pv-image-missing{display:grid;place-items:center;color:#aaa}
.pv-gallery :focus-visible{outline:2px solid var(--synth-accent);outline-offset:3px}.pv-sub a{color:var(--synth-accent)}
</style>
<main class="pv-gallery">
  <h1>PipVision Gallery</h1><div class="pv-sub">Recent visual context captured in game.</div>
  <?php if($message!==''):?><div class="pv-message"><?=pipVisionGalleryH($message)?></div><?php endif;?>
  <?php if($error!==''):?><div class="pv-message pv-error"><?=pipVisionGalleryH($error)?></div><?php endif;?>
  <?php if(!$records):?><div class="pv-empty">No PipVision captures yet. Use the PipVision hotkey in game to capture the current scene.</div><?php endif;?>
  <div class="pv-grid">
    <?php foreach($records as $record):
        $locked=!empty($record['locked'])&&$record['locked']!=='f'; $active=!empty($record['active'])&&$record['active']!=='f';
        $metadata=json_decode(strval($record['metadata']??'{}'),true);
        $native=($metadata['schema']??'')==='synth.visual_context.capture.v2';
        $media=strval($record['media_id']??'');$imageUrl='';
        $path=str_replace('\\','/',strval($record['image_path']??''));
        if (preg_match('#^media/[a-f0-9]{64}$#D',$media)) $imageUrl=$webRoot.'/media.php/'.$media;
        elseif (preg_match('#^data/pictures/gallery/[A-Za-z0-9_./-]+$#D',$path) && !in_array('..',explode('/',$path),true)) $imageUrl=$webRoot.'/'.$path;
    ?>
    <article class="pv-card <?=$locked?'locked':''?>">
      <?php if($imageUrl!==''):?><a href="<?=pipVisionGalleryH($imageUrl)?>" target="_blank" rel="noopener"><img class="pv-image" loading="lazy" src="<?=pipVisionGalleryH($imageUrl)?>" alt="PipVision capture"></a>
      <?php else:?><div class="pv-image pv-image-missing">Image unavailable</div><?php endif;?>
      <div class="pv-body"><div class="pv-title"><strong><?=pipVisionGalleryH($record['subject_name']?:ucfirst($record['subject_type']))?></strong><span><?=$locked?'LOCKED':''?></span></div>
      <div class="pv-meta"><?=pipVisionGalleryH($record['worldspace_name'])?><?=!empty($record['worldspace_name'])&&!empty($record['location_name'])?' / ':''?><?=pipVisionGalleryH($record['location_name'])?><br><?=pipVisionGalleryH($record['captured_at'])?> | <?=pipVisionGalleryH($record['provider'])?> / <?=pipVisionGalleryH($record['model'])?></div>
      <form method="post"><input type="hidden" name="csrf_token" value="<?=pipVisionGalleryH($csrfToken)?>"><input type="hidden" name="id" value="<?=intval($record['id'])?>"><textarea name="description" aria-label="PipVision original description" <?=$native?'readonly':''?>><?=pipVisionGalleryH($record['description'])?></textarea>
      <div class="pv-options"><label title="Across saves: keep this capture beyond the scene time limit."><input type="checkbox" name="locked" value="1" <?=$locked?'checked':''?>> Lock as location context</label><label title="Across saves: allow eligible snapshots to use this capture."><input type="checkbox" name="active" value="1" <?=$active?'checked':''?>> Include in prompts</label></div>
      <div class="pv-actions"><button class="pv-button primary" type="submit" name="action" value="save"><?=$native?'Save policy':'Save'?></button><button class="pv-button" type="submit" name="action" value="delete" onclick="return confirm('Remove this capture from the gallery across saves? Accepted history and shared native media are retained.');">Delete</button></div></form></div>
    </article>
    <?php endforeach; ?>
  </div>
</main>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = ob_get_clean();
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $TITLE . '$3', $buffer);
echo $buffer;
