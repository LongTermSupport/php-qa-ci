<?php

declare(strict_types=1);

namespace LTS\PHPQA\PhpFile;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reads the namespace a PHP file declares, from the file's parse and never from its text.
 *
 * A pattern over the text cannot tell `namespace Foo;` from a comment or heredoc line, or from a
 * string, that starts the same way, and misses the braced form; the parser can. The namespace is
 * the name of the first namespace declaration, braced or not, and the empty string when the file
 * declares none or only the global namespace.
 *
 * @internal
 */
final readonly class DeclaredNamespaceReader
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
    }

    /** The namespace the file at $path declares, '' for the global namespace, or null when it does not parse. */
    public function fromFile(string $path): ?string
    {
        return $this->fromSource(\Safe\file_get_contents($path));
    }

    /** The namespace $source declares, '' for the global namespace, or null when it does not parse. */
    public function fromSource(string $source): ?string
    {
        $errors     = new Collecting();
        $statements = $this->parser->parse($source, $errors);
        if ($errors->hasErrors() || null === $statements) {
            return null;
        }

        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                return $statement->name instanceof Name ? $statement->name->toString() : '';
            }
        }

        return '';
    }
}
