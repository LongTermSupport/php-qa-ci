<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * A position in a parsed GitHub Actions workflow, read without trusting its shape.
 *
 * A workflow is data written by hand, so any key can be missing and any value can be a different
 * type from the one a test expects. Asking a node for a child that is not there gives a node that
 * is not there, and asking for a type the value is not gives null (or nothing), so a test states
 * what it expects of the workflow in one assertion instead of guarding each step down to it.
 */
final readonly class WorkflowNode
{
    private function __construct(private mixed $value)
    {
    }

    public static function ofFile(string $file): self
    {
        return new self(Yaml::parse(\Safe\file_get_contents($file)));
    }

    public static function ofYaml(string $yaml): self
    {
        return new self(Yaml::parse($yaml));
    }

    public static function ofJson(string $json): self
    {
        return new self(\Safe\json_decode($json, true));
    }

    /** The node under these keys, in turn; a node that is not there when any is missing. */
    public function child(int|string ...$path): self
    {
        $value = $this->value;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return new self(null);
            }

            $value = $value[$key];
        }

        return new self($value);
    }

    /** Whether this is a mapping or a list holding the key; true for a key whose value is empty. */
    public function has(int|string $key): bool
    {
        return \is_array($this->value) && \array_key_exists($key, $this->value);
    }

    public function string(): ?string
    {
        return \is_string($this->value) ? $this->value : null;
    }

    /** The string, or the empty string for a value that is not one: a test that searches the text finds nothing in it. */
    public function text(): string
    {
        return \is_string($this->value) ? $this->value : '';
    }

    public function integer(): ?int
    {
        return \is_int($this->value) ? $this->value : null;
    }

    public function boolean(): ?bool
    {
        return \is_bool($this->value) ? $this->value : null;
    }

    /** @return list<string> the keys of a mapping, empty for any other node */
    public function keys(): array
    {
        return \is_array($this->value) ? array_map(strval(...), array_keys($this->value)) : [];
    }

    /** @return array<array-key, self> the children of a mapping by key (a key of digits is an int), empty for any other node */
    public function entries(): array
    {
        $entries = [];
        if (\is_array($this->value)) {
            foreach ($this->value as $key => $child) {
                $entries[$key] = new self($child);
            }
        }

        return $entries;
    }

    /** @return list<self> the items of a list, empty for any other node */
    public function items(): array
    {
        return \is_array($this->value) ? array_map(static fn (mixed $item): self => new self($item), array_values($this->value)) : [];
    }

    /** @return list<string> the string items of a list, empty for any other node */
    public function strings(): array
    {
        return array_values(array_filter(
            array_map(static fn (self $item): ?string => $item->string(), $this->items()),
            static fn (?string $item): bool => null !== $item,
        ));
    }

    /** @return array<array-key, string> a mapping's string values by key; any other value is left out */
    public function stringMap(): array
    {
        $map = [];
        foreach ($this->entries() as $key => $entry) {
            $string = $entry->string();
            if (null !== $string) {
                $map[$key] = $string;
            }
        }

        return $map;
    }

    /** @return list<string> every string at any depth under a key of this name, in document order */
    public function stringsUnderKey(string $name): array
    {
        $found = [];
        foreach ($this->entries() as $key => $entry) {
            $string = $entry->string();
            if ((string)$key === $name && null !== $string) {
                $found[] = $string;
            }

            $found = [...$found, ...$entry->stringsUnderKey($name)];
        }

        return $found;
    }
}
