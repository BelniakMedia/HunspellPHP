## Changelog
### Version 3.0.0
#### Added
- New optional constructor argument `$custom_words_file` which takes a path to a custom word list to be merged with the dictionary at runtime.
- Windows/Linux environments now use the same process execution code.
- Hunspell process invocation is now handled through `proc_open` instead of `shell_exec`.
- Hunspell `stderr` output is now logged via `error_log()` call.
#### Changed
- Changed constructor argument `$encoding` default value from 'en_US.utf-8' to 'UTF-8'.

### Version 2.0.0
#### Added
- Added PHP8.0 typed class, 
- Added constructor to main `HunspellPHP` class where the `$dictionary`, `$encoding` and `$dictionary_path` cal be set/overridden during initialization.
- Added `$dictionary_path` as a new argument were the dictionary files path may be specified (system default search locations are used otherwise). Additional `get()` and `set()`methods added. 
- Added functionality to `findCommand` method via new `(bool)$stem_mode` argument.
#### Removed
- Removed `findStemCommand` method.
- Removed unused exception classes.
- Removed `HunspellPHP\Exceptions` namespace.
- Removed composer.lock from repo.
#### Fixed
- Renamed `$language` more appropriately `$dictionary` since that is what that property is referencing.
- Moved HunspellMatchTypeException up one directory to \HunspellPHP namespace.
- Fixed an issue where not all `$match` values were returned from the command response resulting in PHP warnings.
- Fixed a missing type `-` extraction from the matcher regex which resulted in PHP warnings and bad responses.