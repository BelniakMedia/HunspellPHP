<?php
/** @noinspection PhpUnused */

namespace HunspellPHP;

use function is_resource;

class Hunspell
{
    const OK = '*';
    const ROOT = '+';
    const MISS = '&';
    const NONE = '#';
    const COMPOUND = '-';
    const STATUSES_NAME = [
        Hunspell::OK => 'OK',
        Hunspell::ROOT => 'ROOT',
        Hunspell::MISS => 'MISS',
        Hunspell::NONE => 'NONE',
        Hunspell::COMPOUND => 'COMPOUND'
    ];

    private array $env;

    protected string $encoding;
    protected string $dictionary;
    protected string $dictionary_path;
    protected string $custom_words_file;
    protected string $matcher =
        '/(?P<type>\*|\+|&|#|-)\s?(?P<original>\w+)?\s?(?P<count>\d+)?\s?(?P<offset>\d+)?:?\s?(?P<misses>.*+)?/u';

    /**
     * @param string $dictionary Dictionary name e.g.: 'en_US' (default)
     * @param string $encoding Encoding e.g.: 'UTF-8' (default)
     * @param string|null $dictionary_path Specify the directory of the dictionary file (optional)
     * @param string|null $custom_words_file Specify the path to the custom words file (optional)
     */
    public function __construct(
        string $dictionary = 'en_US',
        string $encoding = 'UTF-8',
        ?string $dictionary_path = null,
        ?string $custom_words_file = null
    ) {
        $this->dictionary = $this->clear($dictionary);
        $this->encoding = $this->clear($encoding);
        $this->dictionary_path = $dictionary_path ?? '';
        $this->custom_words_file = $custom_words_file ?? '';

        $this->env = getenv();
    }


    /**
     * @return string
     */
    public function getEncoding(): string
    {
        return $this->encoding;
    }

    /**
     * @return string
     */
    public function getDictionary(): string
    {
        return $this->dictionary;
    }

    /**
     * @return string
     */
    public function getDictionaryPath(): string
    {
        return $this->dictionary_path;
    }

    /**
     * @param string $dictionary Language code e.g.: 'en_US'
     */
    public function setDictionary(string $dictionary): void
    {
        $this->dictionary = $this->clear($dictionary);
    }

    /**
     * @param string $dictionary_path The path to load the dictionary files from
     */
    public function setDictionaryPath(string $dictionary_path): void
    {
        $this->dictionary_path = $dictionary_path;
    }


    /**
     * @param string $encoding Encoding value (includes language code) e.g.: 'en_US.utf-8'
     */
    public function setEncoding(string $encoding): void
    {
        $this->encoding = $this->clear($encoding);
    }

    /**
     * @param string $words
     * @return array
     * @throws InvalidMatchTypeException
     */
    public function find(string $words): array
    {
        $results = $this->preParse($this->findCommand($words), $words);

        $response = [];
        foreach ($results as $word => $result) {
            $matches = ['type' => null];
            preg_match($this->matcher, $result, $matches);
            $matches['input'] = $word;
            $matches['type'] = $matches['type'] ?? null;
            $matches['original'] = $matches['original'] ?? '';
            $matches['misses'] = $matches['misses'] ?? [];
            $matches['offset'] = $matches['offset'] ?? null;
            $matches['count'] = $matches['count'] ?? null;
            $response[] = $this->parse($matches);
        }
        return $response;
    }

    /**
     * @param string $words word to find
     * @return HunspellStemResponse
     */
    public function stem(string $words): HunspellStemResponse
    {
        $raw = $this->findCommand($words, true);

        // Normalize newlines
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = preg_split('/\n/', $raw) ?: [];

        // Keep only real stem result lines
        $lines = array_values(array_filter(array_map('trim', $lines), static function (string $line): bool {
            if ($line === '') {
                return false;
            }
            if (str_starts_with($line, '@(#)')) {
                return false;
            }
            // stem lines contain at least two tokens
            return preg_match('/\S+\s+\S+/u', $line) === 1;
        }));

        return $this->stemParse([
            'input' => $words,
            'lines' => $lines,
        ]);
    }

    /**
     * @param string $input
     * @return string
     */
    protected function clear(string $input): string
    {
        return (string)preg_replace('[^a-zA-Z0-9_-\.]', '', $input);
    }

    protected function hunspellSuggest(string $input, bool $stemSwitch): array
    {
        $timeoutMs = 1000;

        $encoding = strtoupper(trim($this->encoding));
        $dictionaryFile = $this->dictionary_path
            ? rtrim($this->dictionary_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->dictionary
            : $this->dictionary;

        // Build command
        $cmd = ['hunspell'];

        if ($stemSwitch) {
            // Stem mode
            $cmd[] = '-s';
        } else {
            // Spellcheck (interactive) mode
            $cmd[] = '-a';
        }

        $cmd[] = '-d';
        $cmd[] = trim($dictionaryFile);
        $cmd[] = '-i';
        $cmd[] = $encoding;

        if (!empty($this->custom_words_file) && file_exists($this->custom_words_file)) {
            $cmd[] = '-p';
            $cmd[] = $this->custom_words_file;
        } elseif (!empty($this->custom_words_file)) {
            error_log('WARNING: HunspellPHP - $custom_words_file "' . $this->custom_words_file . '" not found.');
        }

        $tokens = preg_split('/\R+|\s+/u', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($tokens)) {
            return ['', '', 0];
        }
        $batchedInput = implode("\n", $tokens) . "\n";

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Build minimal env with locale data to pass to proc
        $this->env['LC_ALL'] = $this->env['LANG'] = PHP_OS_FAMILY === 'Windows'
            ? $this->dictionary . '.' . $encoding
            : 'C.UTF-8';

        $proc = proc_open($cmd, $descriptors, $pipes, null, $this->env);
        if (!is_resource($proc)) {
            return ['', 'proc_open failed', 1];
        }

        // Write all in one go and close stdin so hunspell can exit cleanly
        fwrite($pipes[0], $batchedInput);
        fclose($pipes[0]);

        // Non-blocking read
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Enforce Deadline
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $out = '';
        $err = '';

        while (true) {
            $out .= stream_get_contents($pipes[1]) ?: '';
            $err .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                // IMPORTANT: terminate, otherwise proc_close() can still block.
                proc_terminate($proc);
                break;
            }

            // Avoid hammer locking cpu during loop
            usleep(1000);
        }

        // Drain the pipes
        $out .= stream_get_contents($pipes[1]) ?: '';
        $err .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($proc);

        return [$out, $err, $exit];
    }

    /**
     * @param string $input
     * @param bool $stem_mode
     * @return string
     */
    protected function findCommand(string $input, bool $stem_mode = false): string
    {
        [$stdout, $stderr] = $this->hunspellSuggest($input, $stem_mode);
        if($stderr !== '') {
            error_log('hunspell stderr: ' . trim($stderr));
        }
        return $stdout;
    }

    /**
     * @param string $input
     * @param string $words
     * @return array
     */
    protected function preParse(string $input, string $words): array
    {
        $input = str_replace(["\r\n", "\r"], "\n", $input);

        // Tokenize words the same way the batched hunspell call does: whitespace/newlines.
        $tokens = preg_split('/\s+/u', trim($words), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_map('trim', $tokens));

        if (empty($tokens)) {
            return [];
        }

        // Split stdout into blocks separated by blank lines.
        // Skip the hunspell banner/header lines starting with "@(#)".
        $rawLines = preg_split('/\n/', $input);
        $blocks = [];
        $current = [];

        foreach ($rawLines as $line) {
            $t = trim($line);

            if ($t !== '' && str_starts_with($t, '@(#)')) {
                continue;
            }

            if ($t === '') {
                if (!empty($current)) {
                    $blocks[] = $current;
                    $current = [];
                }
                continue;
            }

            $current[] = $t;
        }

        if (!empty($current)) {
            $blocks[] = $current;
        }

        if (count($blocks) !== count($tokens)) {
            return [];
        }

        // Normalize each block to a single line compatible with the existing matcher.
        $out = [];
        foreach ($tokens as $i => $token) {
            $lines = $blocks[$i];
            $first = $lines[0] ?? '';

            // Merge any extra lines (e.g. ", ASTM") into the "misses" list.
            // We strip a leading comma/space and append as additional misses.
            $extras = [];
            for ($j = 1; $j < count($lines); $j++) {
                $extra = trim($lines[$j]);
                if ($extra === '') {
                    continue;
                }

                // Hunspell often prefixes extra suggestions with ",".
                $extra = preg_replace('/^[,]\s*/u', '', $extra);
                if ($extra !== '') {
                    $extras[] = $extra;
                }
            }

            if (!empty($extras) && preg_match('/^(?:&|#|\+|-)/u', $first)) {
                // If the first line already has a ":" misses list, append to it.
                if (str_contains($first, ':')) {
                    $first .= ', ' . implode(', ', $extras);
                } else {
                    // Otherwise create a misses list.
                    $first .= ': ' . implode(', ', $extras);
                }
            }

            $out[$token] = $first;
        }

        return $out;
    }

    /**
     * @param array $matches
     * @return HunspellResponse
     * @throws InvalidMatchTypeException
     */
    protected function parse(array $matches): HunspellResponse
    {
        if ($matches['type'] == Hunspell::OK || $matches['type'] == Hunspell::COMPOUND) {
            return new HunspellResponse(
                $matches['input'],
                $matches['input'],
                $matches['type']
            );
        } else {
            if ($matches['type'] == Hunspell::ROOT) {
                return new HunspellResponse(
                    $matches['original'],
                    $matches['input'],
                    $matches['type']
                );
            } else {
                if ($matches['type'] == Hunspell::MISS) {
                    return new HunspellResponse(
                        '',
                        $matches['original'],
                        $matches['type'],
                        intval($matches['offset']),
                        explode(", ", $matches['misses'])
                    );
                } else {
                    if ($matches['type'] == Hunspell::NONE) {
                        return new HunspellResponse(
                            '',
                            $matches['input'],
                            $matches['type'],
                            $matches['count']
                        );
                    }
                }
            }
        }

        throw new InvalidMatchTypeException(sprintf("Match type %s is invalid", $matches['type']));
    }

    /**
     * @param array $matches
     * @return HunspellStemResponse
     */
    protected function stemParse(array $matches): HunspellStemResponse
    {
        $input = (string)($matches['input'] ?? '');
        $lines = $matches['lines'] ?? [];

        $stems = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            // Split by any whitespace; hunspell can separate with multiple spaces/tabs.
            $parts = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 2) {
                continue;
            }

            $stem = $parts[1] ?? '';
            if ($stem !== '' && !in_array($stem, $stems, true)) {
                $stems[] = $stem;
            }
        }

        return new HunspellStemResponse($input, $stems);
    }

}
