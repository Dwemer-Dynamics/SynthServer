# managers/ActionsManager/Native

Action catalogue, jev, attack, wait, gift, social and combat logic moved from lib/.

Files: `action_catalog.php` `narrator_actions.php` `power_awareness.php` `rpg_comment.php` `scriptproxy_fallout.php` `synth_action_inventory.php` `synth_attack_actions.php` `synth_combat_target.php` `synth_command_payload.php` `synth_gift_menu.php` `synth_jev_actions.php` `synth_player_gifts.php` `synth_social_contract.php` `synth_social_response.php` `synth_social_tasks.php` `synth_wait_actions.php` 

The matching `lib/` paths are thin `require_once` hooks over these files, so entry points, tools and tests are unchanged. The functions are still global; they move into namespaced classes in later steps.
