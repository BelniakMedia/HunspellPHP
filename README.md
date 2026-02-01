# Hunspell PHP wrapper
Forked from [johnzuk/HunspellPHP](https://github.com/johnzuk/HunspellPHP)

### Version 4.x (Optimization +Batch Mode)
This version changes find() and possibly stem() (I'm not exactly sure how stem() functioned before as I did not use it, but I updated to be compatible with the changes made under the hood to `hunspellSuggest()`). The changes to `hunspellSuggest()` can now take a space-separated string of words to batch process. This change allows a single process call to handle many spell checks (and stems) rather than having to invoke the process once for each word. The update also ensures the 1000ms timeout "deadline" is not forcing the process to wait that time before ending which appeared to be the case in previous versions.

### Version 3.0.0 (Very minor backward breaking change)
This version updates the constructor signature with a different (better?) default value for `$encoding`, so if anyone was using that this would be a backward breaking change. Otherwise, a new constructor argument $custom_word_file (path) has been added and will bind your provided custom word list with your dictionary in real time.

The other change this version takes care of is using `proc_open` and better env/encoding handling in general. We also now emmit an `error_log()` call so stderr output from the hunspell process are logged properly.

### Version 2.x
Version 2.0.0 and above requires PHP ^8.0.0 and includes an important fix to the result matcher regex. If you need this for an older version of PHP I recommend that you fork 1.2 and update the regex matcher property of the Hunspell class to what is set in the current version of the code.

[View Changelog](CHANGELOG.md)

### The reason for this fork
This project was initially forked because the shell commands used were for a non-bash shell. This fork's main purpose was to convert the shell commands to a BASH compatible syntax and add support for Windows powershell. As such this fork will not work correctly outside of a bash or powershell environment.

An additional change was made to the parsing of the return value as the `PHP_EOL` value used in the original source was not working in my testing. This was changed to "\n" which resolved the issue.

Example
===================
```php
$hunspell = new \HunspellPHP\Hunspell();
var_dump($hunspell->find('otwórz'));
```
