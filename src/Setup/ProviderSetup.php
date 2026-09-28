<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Setup;

final class ProviderSetup
{
    /**
     * @param list<SetupGuide> $guides
     */
    public function __construct(
        private readonly array $guides,
        private readonly HiddenLine $prompt,
        private readonly EnvFragment $files,
        private readonly TextIo $output,
    ) {
    }

    public function run(string $path): int
    {
        $saved = $this->collect();

        return $this->persist($path, $saved);
    }

    /**
     * @return array<string, string>
     */
    private function collect(): array
    {
        $saved = [];
        foreach ($this->guides as $guide) {
            $saved = array_merge($saved, $this->attempt($guide));
        }

        return $saved;
    }

    /**
     * @return array<string, string>
     */
    private function attempt(SetupGuide $guide): array
    {
        $this->output->write($guide->instructions());
        $values = $this->answers($guide);
        if ($guide->verify($values)) {
            $this->output->write($guide->id() . " verified.\n");

            return $values;
        }
        $this->output->write($guide->failure() . "\n");

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function answers(SetupGuide $guide): array
    {
        $values = [];
        foreach ($guide->fields() as $field) {
            $values[$field->key()] = $this->prompt->read($field->label(), $field->hidden());
        }

        return $values;
    }

    /**
     * @param array<string, string> $saved
     */
    private function persist(string $path, array $saved): int
    {
        if ($saved === []) {
            $this->output->write("No credentials were written.\n");

            return 1;
        }
        $this->files->write($path, $saved);
        $this->output->write('Wrote ' . $path . " mode 0600. Do not commit this file.\n");

        return 0;
    }
}
