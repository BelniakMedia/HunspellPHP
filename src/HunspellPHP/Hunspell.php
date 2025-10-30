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
     * @param string $word word to find
     * @return HunspellStemResponse
     */
    public function stem(string $word): HunspellStemResponse
    {
        $result = explode(PHP_EOL, $this->findCommand($word, true));
        $result['input'] = $word;
        return $this->stemParse($result);
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
        $dictionary_file = $this->dictionary_path
            ? rtrim($this->dictionary_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->dictionary
            : $this->dictionary;

        $cmd = ['hunspell', '-a', '-d', trim($dictionary_file), '-i', $encoding];
        if($stemSwitch) { $cmd[] = '-s'; }

        if($this->custom_words_file) {
            if(!file_exists($this->custom_words_file)) {
                error_log('WARNING: HunspellPHP - $custom_words_file "' . $this->custom_words_file . '" not found.');
            } else {
                $cmd[] = '-p';
                $cmd[] = $this->custom_words_file;
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $env = getenv();
        $env['LC_ALL'] = $env['LANG'] = PHP_OS_FAMILY === 'Windows' ? "$this->dictionary.$encoding" : "C.$encoding";
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            return ['', 'proc_open failed', 1];
        }

        // Write the input word(s) followed by newline, as hunspell expects one per line.
        fwrite($pipes[0], $input . "\n");
        fclose($pipes[0]);

        // Simple, bounded read with a timeout.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $out = '';
        $err = '';
        do {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = [];
            $left = max(0, (int)round(($deadline - microtime(true)) * 1_000_000));
            if ($left === 0) {
                break;
            }
            if (@stream_select($read, $write, $except, 0, $left) !== false) {
                foreach ($read as $r) {
                    if ($r === $pipes[1]) {
                        $out .= stream_get_contents($pipes[1]) ?: '';
                    }
                    if ($r === $pipes[2]) {
                        $err .= stream_get_contents($pipes[2]) ?: '';
                    }
                }
            }
        } while (microtime(true) < $deadline);

        // Drain remaining data.
        $out .= stream_get_contents($pipes[1]) ?: '';
        $err .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_get_status($proc);
        $exit = $status['exitcode'] ?? 0;
        proc_close($proc);

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
        $result = explode("\n", trim($input));
        array_shift($result);
        $words = array_map('trim', preg_split('/\W/', $words));

        if (sizeof($result) != sizeof($words)) {
            return [];
        }
        return array_combine($words, $result);
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
                        $matches['offset'],
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
        $input = $matches['input'];
        unset($matches['input']);
        $stems = [];
        foreach ($matches as $match) {
            $stem = explode(' ', $match);
            if (!empty($stem[1])) {
                if (!in_array($stem[1], $stems)) {
                    $stems[] = $stem[1];
                }
            } elseif (!empty($stem[0])) {
                if (!in_array($stem[0], $stems)) {
                    $stems[] = $stem[0];
                }
            }
        }
        return new HunspellStemResponse($input, $stems);
    }

}
