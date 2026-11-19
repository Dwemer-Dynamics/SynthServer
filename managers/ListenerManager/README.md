# ListenerManager

Decides who a reply line is addressed to.

`ListenerRouter::parse()` turns the model's `listener` field ("Piper, Nick and Preston") into a list of names with the
same rules as legacy `parseDialogueListenerNames()` in `lib/chat_helper_functions.php`; `ListenerRouter::pick()` selects
the listener of line N (rotation clamped to the last name, as `SCRIPTLINE_LISTENER_CYCLE` does).

It is pure and not yet called: the legacy functions stay the live path (they also read `PLAYER_NAME` and narrator
globals) and become thin hooks over this class when the reply pipeline moves. Chevo's name matching against present
actors, per-sentence segmentation and player aliases are not implemented because SYNTH has no such behaviour today.
