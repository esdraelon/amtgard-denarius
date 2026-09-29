<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Scanner: lists {@see __METHOD__} strings for every DenariusLog trace/enter site under {@see src/}. */
final class TracedMethodCatalog
{
    public function __construct(
        private readonly string $srcRoot,
    ) {
    }

    public static function forProject(): self
    {
        return new self(dirname(__DIR__, 2) . '/src');
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $methods = [];
        foreach ($this->phpFilesUnder($this->srcRoot) as $path) {
            foreach ($this->methodsInFile($path) as $method) {
                $methods[$method] = true;
            }
        }

        $sorted = array_keys($methods);
        sort($sorted, SORT_STRING);

        return $sorted;
    }

    /**
     * @return list<string>
     */
    public function methodsInFile(string $absolutePath): array
    {
        $content = file_get_contents($absolutePath);
        if ($content === false) {
            return [];
        }

        $namespace = $this->namespaceFrom($content);
        $typeStarts = $this->typeStartsByLine($content);
        if ($typeStarts === []) {
            return [];
        }

        $functionStarts = $this->functionStartsByLine($content);
        $lines = preg_split("/\r\n|\n|\r/", $content);
        if ($lines === false) {
            return [];
        }

        $found = [];
        foreach ($lines as $index => $line) {
            if (! preg_match('/DenariusLog::(trace|enter)\s*\(\s*__METHOD__/', $line)) {
                continue;
            }

            $lineNumber = $index + 1;
            $typeName = $this->enclosingName($typeStarts, $lineNumber);
            $function = $this->enclosingFunction($functionStarts, $lineNumber);
            if ($typeName === null || $function === null) {
                continue;
            }

            $qualifiedType = $namespace !== '' ? $namespace . '\\' . $typeName : $typeName;
            $found[$qualifiedType . '::' . $function] = true;
        }

        $sorted = array_keys($found);
        sort($sorted, SORT_STRING);

        return $sorted;
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    private function namespaceFrom(string $content): string
    {
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $content, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    /**
     * @return array<int, string> line number => type name
     */
    private function typeStartsByLine(string $content): array
    {
        $starts = [];
        if (preg_match_all(
            '/^\s*(?:final\s+|abstract\s+)?(?:class|enum)\s+(\w+)/m',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) !== false) {
            foreach ($matches[1] as $match) {
                $name = $match[0];
                $offset = $match[1];
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $starts[$line] = $name;
            }
        }

        ksort($starts);

        return $starts;
    }

    /**
     * @return array<int, string> line number => function name
     */
    private function functionStartsByLine(string $content): array
    {
        $starts = [];
        if (preg_match_all(
            '/^\s*(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/m',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        ) !== false) {
            foreach ($matches[1] as $match) {
                $name = $match[0];
                $offset = $match[1];
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $starts[$line] = $name;
            }
        }

        ksort($starts);

        return $starts;
    }

    /**
     * @param array<int, string> $starts
     */
    private function enclosingName(array $starts, int $lineNumber): ?string
    {
        $current = null;
        foreach ($starts as $startLine => $name) {
            if ($startLine > $lineNumber) {
                break;
            }
            $current = $name;
        }

        return $current;
    }

    /**
     * @param array<int, string> $functionStarts
     */
    private function enclosingFunction(array $functionStarts, int $lineNumber): ?string
    {
        return $this->enclosingName($functionStarts, $lineNumber);
    }
}
