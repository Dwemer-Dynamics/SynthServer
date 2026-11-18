# Talk Awareness

Reference extension for the SYNTH extension system. It tells a companion that this is the first time
they speak with the player, or that they have spoken before and where. State lives in
`data/extensions/talk-awareness.json` (not the database).

Hooks: `dialogue.context.sections` (adds `<talk_awareness>`), `dialogue.reply.completed` (records the talk).

```text
php bin/extensions.php enable talk-awareness
php bin/extensions.php disable talk-awareness
```
